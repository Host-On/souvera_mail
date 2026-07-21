<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\StalwartUserContext;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * GET /apps/souvera_mail/me — identity + server-hint endpoint.
 *
 * ==============================================================
 * Purpose
 * ==============================================================
 * Souvera native clients (Android/iOS/Desktop) need three pieces of
 * information to bootstrap themselves AFTER a successful login-flow
 * call:
 *
 *   1. The user's canonical mail address (may DIFFER from the
 *      Nextcloud loginName — e.g. loginName is `philip` but the
 *      IMAP/SMTP SASL user is `philip@grassegger.souvera.work`).
 *   2. The user's display name for the UI ("Philip Grassegger"
 *      rather than the raw uid).
 *   3. A machine-readable hint for the server's password-rotation
 *      policy so the client can schedule its automatic rotation
 *      timer without hard-coding a value.
 *
 * Previously the client had to piece this together from three
 * different calls (`/quota`, `/settings/user`, guessing at the
 * rotation interval). This endpoint returns everything in one JSON.
 *
 * Introduced in v0.18.1 (2026-02). Client-Guide references it:
 * `docs/LOGIN_FLOW_CLIENT_INTEGRATION.txt`.
 *
 * ==============================================================
 * Auth model
 * ==============================================================
 * Same three mechanisms as {@see LoginFlowController}:
 *   - Basic-Auth (username + password OR username + app-password)
 *   - Session cookie
 *   - OIDC bearer token
 *
 * Because this endpoint reads only public-safe info about the
 * calling user (no secrets), we don't add BruteForceProtection —
 * an authenticated user reading their own uid/email/displayName is
 * not a scanning surface.
 *
 * ==============================================================
 * Response
 * ==============================================================
 *
 *   {
 *     "status":      "ok",
 *     "uid":         "philip",
 *     "loginName":   "philip",
 *     "displayName": "Philip Grassegger",
 *     "email":       "philip@grassegger.souvera.work",
 *     "server":      "https://grassegger.souvera.work",
 *     "rotation": {
 *       "enabled":    true,
 *       "days":       90,
 *       "hint":       "The server recommends rotating this app password every 90 days."
 *     },
 *     "serverTime":  "2026-02-20T15:42:03Z"
 *   }
 *
 * Field guarantees / semantics:
 *   - `uid`         alias of `loginName`, provided under both names
 *                   so clients written against the login-flow response
 *                   (which uses `loginName`) can grep either key.
 *   - `email`       resolved via `StalwartUserContext::resolveEmail()` —
 *                   respects the H2CK/oidc + souvera_central alias map.
 *                   May match `getEMailAddress()` on plain instances,
 *                   or the operator's canonical mail address on
 *                   OIDC-provisioned users.
 *   - `rotation`
 *     .enabled      false when admin set `rotation_days = 0` (opt-out).
 *     .days         config value; default 90.
 *     .hint         human-readable UI string, EN — client MAY show
 *                   directly or replace with its own l10n copy.
 *   - `serverTime`  UTC clock — clients use this to detect skew
 *                   (a skew > ±5min will make Basic-Auth against
 *                   OIDC-signed tokens fail on some deployments).
 */
class MeController extends Controller
{
    /**
     * App-config key for the recommended rotation cadence. Value is
     * a positive integer (days) or `0` to signal "rotation disabled
     * on this instance". Admin sets it via:
     *
     *   occ config:app:set souvera_mail rotation_days --value=90
     *
     * See docs/PASSWORD_ROTATION.txt for the operator playbook.
     */
    public const CONFIG_KEY_ROTATION_DAYS = 'rotation_days';

    /** Default rotation cadence if the admin never set the config. */
    private const DEFAULT_ROTATION_DAYS = 90;

    /**
     * Sanity cap — refuse to render an absurd rotation-days value
     * that would either DoS the endpoint (>= 10 years) or make the
     * cadence meaningless (< 1 day). Values outside this window
     * are clamped and a WARN is logged so the admin notices.
     */
    private const ROTATION_DAYS_MIN = 1;
    private const ROTATION_DAYS_MAX = 3650;

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private StalwartUserContext $userContext,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(): DataResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->error(
                'unauthenticated — send Authorization: Basic base64(user:password), '
                . 'a session cookie, or an OIDC bearer token',
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $userId = $user->getUID();

        // Best-effort email resolution — falls back to the NC-stored
        // address if souvera_central isn't configured (e.g. dev
        // instance without Stalwart admin URL).
        $email = '';
        try {
            $email = $this->userContext->resolveEmail($userId);
        } catch (\Throwable $e) {
            $email = (string) ($user->getEMailAddress() ?? '');
            if ($email === '') {
                $this->logger->info(
                    'Souvera Mail /me: no email available for user ' . $userId . ': '
                    . $e->getMessage(),
                    ['app' => 'souvera_mail'],
                );
            }
        }

        $rotation = $this->resolveRotationPolicy();
        $server = \rtrim($this->urlGenerator->getAbsoluteURL('/'), '/');

        return new DataResponse(
            [
                'status' => 'ok',
                'uid' => $userId,
                'loginName' => $userId,
                'displayName' => $user->getDisplayName(),
                'email' => $email,
                'server' => $server,
                'rotation' => $rotation,
                'serverTime' => \gmdate('Y-m-d\TH:i:s\Z'),
            ],
            Http::STATUS_OK,
        );
    }

    /**
     * @return array{enabled: bool, days: int, hint: string}
     */
    private function resolveRotationPolicy(): array
    {
        $raw = $this->config->getAppValue(
            'souvera_mail',
            self::CONFIG_KEY_ROTATION_DAYS,
            (string) self::DEFAULT_ROTATION_DAYS,
        );
        // Config values are always stored as strings — coerce.
        $days = \filter_var($raw, FILTER_VALIDATE_INT);
        if ($days === false) {
            $this->logger->warning(
                'Souvera Mail /me: `rotation_days` config is not an integer (`' . $raw
                . '`) — falling back to default ' . self::DEFAULT_ROTATION_DAYS,
                ['app' => 'souvera_mail'],
            );
            $days = self::DEFAULT_ROTATION_DAYS;
        }

        // Zero = admin explicitly disabled rotation on this instance.
        if ($days === 0) {
            return [
                'enabled' => false,
                'days' => 0,
                'hint' => 'Password rotation is disabled on this instance.',
            ];
        }

        // Clamp positive values into the sane window.
        if ($days < self::ROTATION_DAYS_MIN || $days > self::ROTATION_DAYS_MAX) {
            $clamped = \max(
                self::ROTATION_DAYS_MIN,
                \min(self::ROTATION_DAYS_MAX, $days),
            );
            $this->logger->warning(
                'Souvera Mail /me: `rotation_days` = ' . $days
                . ' is outside [' . self::ROTATION_DAYS_MIN . ', '
                . self::ROTATION_DAYS_MAX . '] — clamped to ' . $clamped,
                ['app' => 'souvera_mail'],
            );
            $days = $clamped;
        }

        return [
            'enabled' => true,
            'days' => $days,
            'hint' => 'The server recommends rotating this app password every '
                . $days . ' days.',
        ];
    }

    private function error(string $message, int $status): DataResponse
    {
        return new DataResponse(
            ['status' => 'error', 'message' => $message],
            $status,
        );
    }
}
