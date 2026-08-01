<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use Psr\Log\LoggerInterface;

/**
 * Security guard — makes sure the Nextcloud user we are about to open a
 * mailbox for actually owns the mailbox on the Stalwart side.
 *
 * Why this exists
 * ---------------
 * Reported at SEG Marburg (2026-02-18): after Central provisioned a
 * fresh user `joerg@gratify.it`, logging in as joerg opened the
 * mailbox of `hello@gratify.it`. Root cause was upstream (Central
 * hadn't actually created a Stalwart account for joerg yet), but our
 * app happily rode along and served whichever mailbox Stalwart
 * associated with joerg's OIDC token — a cross-tenant data leak.
 *
 * Defence in depth: we now REQUIRE the following two invariants
 * BEFORE Snappymail is allowed to log in a user:
 *
 *   1. Stalwart accepts the user's OIDC JWT (HTTP 200 on
 *      `GET /jmap/session`).
 *   2. The `username` field in the JMAP session response matches
 *      the email Souvera Mail resolved (case-insensitive).
 *
 * If either fails we throw {@see MailboxAccessDenied} — Snappymail's
 * login is aborted, the user sees a clear error page, and NOTHING
 * ELSE'S MAIL IS EVER SHOWN.
 *
 * The check is best-effort: if the Stalwart admin API is unreachable
 * at all (e.g. transient network hiccup) we FAIL CLOSED — the user
 * gets an error instead of a mailbox. Availability trades against
 * safety here and we choose safety.
 */
class MailboxAccessGuard
{
    private const CACHE_PREFIX = 'souvera_mail_guard';
    private const CACHE_OK = 'ok';
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private StalwartAdminService $stalwart,
        private StalwartUserContext $userContext,
        private LoggerInterface $logger,
        private ?\OCP\ICacheFactory $cacheFactory = null,
    ) {
    }

    /**
     * Assert that the given Nextcloud user is allowed to open the
     * mailbox Souvera Mail would map them to. Throws otherwise.
     *
     * Callers: {@see \OCA\SouveraMail\Util\EngineHelper::startApp}
     * right before it hands credentials to Snappymail's Actions.
     *
     * Success is cached for 60 seconds: the guard runs on EVERY engine
     * request (every AJAX call of the webmail, including the boot
     * sequence) and each uncached run costs a Stalwart JMAP round-trip —
     * with a slow or loaded Stalwart this made the webmail "preload"
     * take many seconds. Failures are never cached (fail-closed).
     *
     * @throws MailboxAccessDenied
     */
    public function assertMailboxOwnership(string $userId): void
    {
        $cache = $this->cacheFactory?->createDistributed(self::CACHE_PREFIX);
        if ($cache !== null && $cache->get($userId) === self::CACHE_OK) {
            return;
        }

        if (!$this->stalwart->isConfigured()) {
            // No Stalwart configured (e.g. dev/CI). Bail out — better
            // to break the login than to serve a mailbox we cannot
            // verify. Operators must configure Stalwart, not just
            // Snappymail's IMAP endpoint.
            throw new MailboxAccessDenied(
                "Stalwart is not configured on this Nextcloud (souvera_central.stalwart_api_url is empty) — "
                . 'cannot verify mailbox ownership for user "' . $userId . '". Refusing login.'
            );
        }

        // What email do WE think this user should open? (Same cascade
        // Snappymail's login would use — one shared source of truth.)
        try {
            $expectedEmail = $this->userContext->resolveEmail($userId);
        } catch (\Throwable $e) {
            throw new MailboxAccessDenied(
                'Could not resolve mailbox email for user "' . $userId . '": ' . $e->getMessage()
            );
        }

        // Ask Stalwart what account IT thinks this user owns.
        try {
            $bearer = $this->userContext->resolveBearer($userId);
        } catch (\Throwable $e) {
            throw new MailboxAccessDenied(
                'Could not obtain OIDC bearer for user "' . $userId . '": ' . $e->getMessage()
            );
        }

        try {
            $session = $this->stalwart->fetchSessionAsUser($bearer);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: Stalwart session fetch failed during ownership check for uid=' . $userId
                . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            throw new MailboxAccessDenied(
                'Stalwart is not reachable to verify mailbox ownership for user "' . $userId
                . '". Refusing login until the check can complete.'
            );
        }

        if ($session['status'] === 401 || $session['status'] === 403) {
            // Stalwart flatly rejected the JWT. Fresh user without a
            // provisioned mailbox lands here.
            throw new MailboxAccessDenied(
                'Stalwart refused authentication for user "' . $userId
                . '" (HTTP ' . $session['status'] . '). Most likely no mailbox has been provisioned yet — '
                . 'ask your administrator to complete account setup in Souvera Central.'
            );
        }
        if ($session['status'] !== 200) {
            throw new MailboxAccessDenied(
                'Stalwart returned an unexpected status ' . $session['status']
                . ' during mailbox ownership check for user "' . $userId . '". Refusing login.'
            );
        }

        // Pull the authoritative account identity out of the session
        // response. Stalwart's session body carries `username` at the
        // top level (RFC 8620 §2). Some JMAP implementations also or
        // only carry it inside `accounts.<id>.name` — check both, so
        // an upstream schema tweak doesn't silently defang the guard.
        $reportedIdentity = self::extractAuthenticatedIdentity($session['body']);
        if ($reportedIdentity === null) {
            throw new MailboxAccessDenied(
                'Stalwart session response did not carry an identifiable account name for user "'
                . $userId . '". Refusing login (this may indicate a JMAP schema change).'
            );
        }

        if (\strcasecmp($reportedIdentity, $expectedEmail) !== 0) {
            $this->logger->critical(
                'Souvera Mail: MAILBOX OWNERSHIP MISMATCH — refusing login. '
                . 'uid="' . $userId . '", expected email="' . $expectedEmail
                . '", Stalwart reported="' . $reportedIdentity . '". '
                . 'This is either a Central provisioning bug or a serious cross-tenant OIDC misconfiguration — '
                . 'do NOT ignore.',
                ['app' => 'souvera_mail']
            );
            throw new MailboxAccessDenied(
                'Mailbox ownership mismatch for user "' . $userId
                . '" — Souvera Mail expected to open "' . $expectedEmail
                . '" but Stalwart mapped the login to "' . $reportedIdentity . '". '
                . 'Login refused as a safety measure. Please contact your administrator; '
                . 'they can inspect the exact resolution chain with '
                . '`occ souvera_mail:whoami ' . $userId . '`.'
            );
        }

        // Only successful checks are cached — a denied/failed check must
        // be re-run on the next request (fail-closed, no lockout window
        // for legitimately re-provisioned users).
        $cache?->set($userId, self::CACHE_OK, self::CACHE_TTL_SECONDS);
    }

    /**
     * Pull the authenticated account name out of a JMAP session
     * response body. Tries the standard `username` top-level field
     * first, then falls back to `accounts.<primaryAccount>.name`.
     *
     * @param array<string, mixed> $body
     */
    public static function extractAuthenticatedIdentity(array $body): ?string
    {        $username = $body['username'] ?? null;
        if (\is_string($username) && $username !== '') {
            return $username;
        }

        $primary = $body['primaryAccounts'] ?? [];
        if (!\is_array($primary)) {
            return null;
        }
        // Take the first primary account id (there is normally exactly
        // one). Stalwart uses `urn:ietf:params:jmap:mail` as its
        // primary capability key but we must not hard-code that — the
        // key may vary across capability sets, and the caller only
        // cares about the account name, not which capability it hangs
        // off.
        $primaryId = null;
        foreach ($primary as $capId) {
            if (\is_string($capId) && $capId !== '') {
                $primaryId = $capId;
                break;
            }
        }
        if ($primaryId === null) {
            return null;
        }

        $accounts = $body['accounts'] ?? [];
        if (!\is_array($accounts)) {
            return null;
        }
        $entry = $accounts[$primaryId] ?? null;
        if (!\is_array($entry)) {
            return null;
        }
        $name = $entry['name'] ?? null;
        return \is_string($name) && $name !== '' ? $name : null;
    }
}
