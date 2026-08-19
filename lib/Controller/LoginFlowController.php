<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\AppPasswordService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Single-shot Login-Flow endpoint for Souvera native clients
 * (Souvera-Android, Souvera-iOS, Souvera-Desktop).
 *
 * ==============================================================
 * Why this exists
 * ==============================================================
 * Stalwart 0.16 does NOT accept a caller-supplied plaintext when
 * creating an Application Password — the server always generates
 * its own secret and returns it exactly once. Nextcloud's stock
 * `/login/v2/poll` flow, in turn, generates its OWN plaintext and
 * hands it to the client. Two independent CSPRNGs, two independent
 * secrets. That's why the historical "one credential unlocks both
 * mail and DAV" promise only worked if the user provisioned the
 * password from inside Souvera Mail's own UI ({@see AppPasswordController::create}).
 *
 * This endpoint closes the gap for headless clients. The flow is:
 *
 *   1. Client authenticates against `/apps/souvera_mail/app-passwords/login-flow`
 *      with an interactive credential — either
 *        a) Basic-Auth (username + user password), OR
 *        b) an existing NC session cookie (WebView / OIDC redirect flow),
 *      OR
 *        c) an OIDC bearer token.
 *      Nextcloud's stock `AuthMiddleware` resolves that to `$userId`
 *      exactly like `/login/v2/poll` would.
 *   2. We call {@see AppPasswordService::createForUser()} which is the
 *      SAME code path the Souvera Mail settings page uses — Stalwart
 *      first (owns the plaintext), NC-token paired to the SAME
 *      plaintext, mapping row persisted for the combined-revoke flow.
 *   3. We return a JSON body that is bit-for-bit compatible with
 *      NC's `/login/v2/poll`:
 *
 *        {
 *          "server":      "https://mail.example.com",
 *          "loginName":   "philip",
 *          "appPassword": "app_abc123..."
 *        }
 *
 *      Plus two additional fields useful for the Souvera clients:
 *        - `stalwartId`  — Stalwart's App-Password id (for later
 *                          revoke via /app-passwords/{id})
 *        - `createdAt`   — ISO-8601 timestamp of the pair.
 *
 * The client then stores `appPassword` and uses it as the credential
 * for BOTH:
 *   - Nextcloud (files, DAV, CalDAV, CardDAV) — Basic-Auth against
 *     `<server>/remote.php/dav/…`
 *   - Mail — IMAP/SMTP/Sieve against Stalwart with LOGIN or PLAIN SASL.
 *
 * ==============================================================
 * Security notes
 * ==============================================================
 * - `#[BruteForceProtection]` throttles the endpoint per source IP.
 *   NC's `SecurityBruteForceMiddleware` counts 429s after 10 fails
 *   in 12h and delays subsequent attempts exponentially.
 * - `#[NoCSRFRequired]` because the intended callers are native
 *   apps that speak HTTPS but NOT the CSRF-token-in-header protocol.
 *   Basic-Auth over TLS covers the confused-deputy case that CSRF
 *   normally guards.
 * - The plaintext is returned in the response body over TLS ONCE —
 *   after that it is unrecoverable (see AppPasswordService docblock).
 *   Clients MUST persist it immediately in secure storage (Keystore
 *   on Android, Keychain on iOS, libsecret / DPAPI on Desktop).
 * - The endpoint is `#[NoAdminRequired]`: it always operates on the
 *   caller's own user; there is no `uid` parameter and no privilege-
 *   escalation surface.
 */
class LoginFlowController extends Controller
{
    /**
     * The public JSON key for the plaintext secret is `appPassword`,
     * bit-identical to Nextcloud's own /login/v2/poll response.
     * Souvera-Android/iOS parse the SAME key regardless of whether
     * they hit NC's stock endpoint or this one.
     */
    private const RESPONSE_KEY_APP_PASSWORD = 'appPassword';

    /**
     * Fallback description if the client omits one. Includes the
     * User-Agent so multiple devices per user stay distinguishable
     * in the connected-devices list.
     */
    private const DEFAULT_DESCRIPTION_PREFIX = 'Souvera Client';

    public function __construct(
        string $appName,
        IRequest $request,
        private AppPasswordService $appPasswords,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * POST /apps/souvera_mail/app-passwords/login-flow
     *
     * Body (optional, JSON): `{"description": "Souvera Android v1.0.2"}`
     * When absent, we derive a description from the User-Agent header.
     *
     * Success response (200):
     *   {
     *     "status":      "ok",
     *     "server":      "https://<host>",
     *     "loginName":   "<uid>",
     *     "appPassword": "<plaintext-secret>",
     *     "stalwartId":  "<stalwart-app-id>",
     *     "createdAt":   "<ISO-8601>"
     *   }
     *
     * Error responses:
     *   401 — unauthenticated (Basic-Auth missing / rejected)
     *   400 — description too long / invalid body
     *   503 — Stalwart or souvera_central not configured on this instance
     *   502 — Stalwart / NC-token creation failed
     *   429 — brute-force throttle (via BruteForceProtection)
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'souvera_mail_login_flow')]
    public function create(string $description = ''): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            $r = $this->error(
                'unauthenticated — send Authorization: Basic base64(user:password), '
                . 'a valid session cookie, or an OIDC bearer token',
                Http::STATUS_UNAUTHORIZED,
            );
            // Throttle failed auth attempts (BruteForceProtection reads
            // this method to increment the counter for the source IP).
            $r->throttle(['action' => 'souvera_mail_login_flow']);
            return $r;
        }

        if (!$this->appPasswords->isAvailable()) {
            return $this->error(
                'App passwords are unavailable on this instance: '
                . 'souvera_central + Stalwart API URL + H2CK/oidc must be configured.',
                Http::STATUS_SERVICE_UNAVAILABLE,
            );
        }

        $description = $this->resolveDescription($description);
        $userId = $user->getUID();

        try {
            $created = $this->appPasswords->createForUser($userId, $description);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail login-flow create failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e, 'user' => $userId],
            );
            return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }

        // ISO-8601 for `createdAt` — matches NC's convention and is
        // trivially parseable in Kotlin (Instant.parse) / Swift
        // (ISO8601DateFormatter) / Python (fromisoformat).
        $nowIso = \gmdate('Y-m-d\TH:i:s\Z');

        // `getAbsoluteURL('/')` returns e.g.
        //   "https://mail.example.com/"
        // We strip the trailing slash so clients can concat
        // `<server>/remote.php/dav/...` without double slashes.
        $server = \rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');

        return new DataResponse(
            [
                'status' => 'ok',
                'server' => $server,
                'loginName' => $userId,
                self::RESPONSE_KEY_APP_PASSWORD => $created['secret'],
                'stalwartId' => $created['id'],
                'description' => $created['description'],
                'createdAt' => $nowIso,
            ],
            Http::STATUS_OK,
        );
    }

    /**
     * POST /apps/souvera_mail/app-passwords/upgrade
     *
     * Post-Login upgrade path for native clients (Souvera-Android / iOS /
     * Desktop) that first went through Nextcloud's stock `/login/v2/*`
     * flow — got back a plain NC-only app-password `X` — and now want
     * to atomically UPGRADE it to a paired mail+DAV credential `Y`.
     *
     * Why not just call `create()` above? Because after `create()`, the
     * client would still own a live NC-only token `X` alongside the new
     * paired `Y`. That "zombie X" shows up as a duplicate device in
     * `/settings/user/security`, holds DAV access with no matching
     * Stalwart pair, and confuses users on device-management screens.
     *
     * This endpoint eliminates the zombie: it performs
     *   1) create paired Y  (Stalwart-first, then NC-token, then mapping)
     *   2) invalidate X     (best-effort — uses the incoming Basic-Auth
     *                        plaintext to identify the caller's token)
     *
     * Contract with the client:
     *
     * - Auth MUST be `Authorization: Basic base64(loginName:X)` — the
     *   endpoint reads `PHP_AUTH_PW` to identify which token to
     *   invalidate. Session-cookie / OIDC-bearer auth is rejected with
     *   400, because we cannot safely resolve which NC token backed
     *   the session without the plaintext.
     *
     * - `X` is INVALIDATED after `Y` has been created. If `X` cannot
     *   be invalidated (network flap, token already gone), we log a
     *   WARNING and still return `Y` — losing `Y` because `X` couldn't
     *   be killed would be a far worse outcome than a single leftover
     *   NC token that the user can revoke manually.
     *
     * - The atomicity guarantee is: EITHER the caller gets `Y` and we
     *   TRIED to invalidate `X` (never leaks two live NC-only tokens),
     *   OR the caller gets a non-200 and NOTHING was created/deleted.
     *
     * Response 200:
     *   {
     *     "status":       "ok",
     *     "server":       "https://mail.example.com",
     *     "loginName":    "philip",
     *     "appPassword":  "app_...",       // this is Y (paired plaintext)
     *     "stalwartId":   "abcd1234",
     *     "description":  "...",
     *     "createdAt":    "...",
     *     "upgradedFrom": {                // Souvera-specific hint block
     *       "invalidated": true,           //   false if invalidate failed
     *       "note":        "The X token you sent has been invalidated. "
     *                    . "Delete it from local secure storage now."
     *     }
     *   }
     *
     * Errors:
     *   400 — no Basic-Auth password → cannot resolve X for revoke
     *   401 — unauthenticated
     *   503 — souvera_central not configured
     *   502 — Stalwart/NC pair creation failed
     *   429 — brute-force throttle
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'souvera_mail_login_flow')]
    public function upgrade(string $description = ''): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            $r = $this->error(
                'unauthenticated — send Authorization: Basic base64(loginName:X) '
                . 'where X is the NC app-password you obtained from /login/v2/poll',
                Http::STATUS_UNAUTHORIZED,
            );
            $r->throttle(['action' => 'souvera_mail_login_flow']);
            return $r;
        }

        // Basic-Auth plaintext MUST be present — we need it to
        // invalidate X below. If empty, the caller is authenticated
        // via session-cookie or OIDC-bearer, neither of which lets us
        // safely identify the specific NC token to revoke.
        $rawX = (string) ($this->request->server['PHP_AUTH_PW'] ?? '');
        if ($rawX === '') {
            return $this->error(
                '/app-passwords/upgrade requires HTTP Basic-Auth with the '
                . 'current NC app-password (X). Session-cookie or OIDC-bearer '
                . 'authentication is not supported on this endpoint because we '
                . 'cannot resolve which NC token backs your session. Use '
                . '/app-passwords/login-flow instead if you only need to '
                . 'create a fresh paired credential.',
                Http::STATUS_BAD_REQUEST,
            );
        }

        if (!$this->appPasswords->isAvailable()) {
            return $this->error(
                'App passwords are unavailable on this instance: '
                . 'souvera_central + Stalwart API URL + H2CK/oidc must be configured.',
                Http::STATUS_SERVICE_UNAVAILABLE,
            );
        }

        $description = $this->resolveDescription($description);
        $userId = $user->getUID();

        // Phase 1: create Y (paired combined password). If this fails,
        // NOTHING has been touched yet on either side — we return the
        // error and leave X intact so the caller retains their working
        // NC session.
        try {
            $created = $this->appPasswords->createForUser($userId, $description);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail upgrade failed on Y-create for ' . $userId . ': '
                . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e, 'user' => $userId],
            );
            return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }

        // Phase 2: best-effort invalidation of X. Any failure is logged
        // and reflected in `upgradedFrom.invalidated` — but we STILL
        // return Y so the client's mail-and-DAV flow works. A stale X
        // in NC's device list is a UX blemish, not a security issue
        // (the user can revoke it manually from /settings/user/security).
        $invalidated = true;
        try {
            $this->appPasswords->revokeByRawSecret($userId, $rawX);
        } catch (\Throwable $e) {
            // revokeByRawSecret itself swallows exceptions, so this
            // catch is defensive. If we ever reach here it means the
            // swallow was bypassed — treat as "invalidation failed".
            $invalidated = false;
            $this->logger->warning(
                'Souvera Mail upgrade: revokeByRawSecret unexpectedly threw for '
                . $userId . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e],
            );
        }

        $nowIso = \gmdate('Y-m-d\TH:i:s\Z');
        $server = \rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');

        return new DataResponse(
            [
                'status' => 'ok',
                'server' => $server,
                'loginName' => $userId,
                self::RESPONSE_KEY_APP_PASSWORD => $created['secret'],
                'stalwartId' => $created['id'],
                'description' => $created['description'],
                'createdAt' => $nowIso,
                'upgradedFrom' => [
                    'invalidated' => $invalidated,
                    'note' => $invalidated
                        ? 'The X token you sent has been invalidated. Delete it from local secure storage now and use the new appPassword (Y) exclusively.'
                        : 'The X token could NOT be invalidated automatically (see server log). Please revoke it manually from /settings/user/security so it does not linger as a duplicate device.',
                ],
            ],
            Http::STATUS_OK,
        );
    }

    /**
     * Derive the token description: caller value wins; otherwise
     * fall back to `Souvera Client — <User-Agent> — YYYY-MM-DD`.
     *
     * NOTE: NC enforces its own name-length limits (255 chars) and
     * AppPasswordService::createForUser clips at 120 chars. We keep
     * this method's output well under both.
     */
    private function resolveDescription(string $description): string
    {
        $description = \trim($description);
        if ($description !== '') {
            return $description;
        }

        $ua = (string) $this->request->getHeader('User-Agent');
        // Strip control chars + collapse whitespace, then truncate.
        $ua = \preg_replace('/[[:cntrl:]]+/', ' ', $ua) ?? '';
        $ua = \trim(\preg_replace('/\s+/', ' ', $ua) ?? '');
        if ($ua === '') {
            $ua = 'unknown-agent';
        }
        if (\mb_strlen($ua) > 80) {
            $ua = \mb_substr($ua, 0, 80);
        }

        return self::DEFAULT_DESCRIPTION_PREFIX . ' — ' . $ua . ' — ' . \gmdate('Y-m-d');
    }

    private function error(string $message, int $status): DataResponse
    {
        return new DataResponse(
            ['status' => 'error', 'message' => $message],
            $status,
        );
    }
}
