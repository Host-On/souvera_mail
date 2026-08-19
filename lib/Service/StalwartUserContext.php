<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared helper for any Souvera Mail service that needs to talk to Stalwart
 * as the currently-logged-in Nextcloud user:
 *
 *  1. Translate NC user id → mail address    (via souvera_central StalwartService)
 *  2. Translate NC user id → Stalwart JMAP accountId
 *  3. Issue a Bearer JWT for the user        (via H2CK/oidc TokenGenerationRequestEvent)
 *
 * Extracted so both {@see AppPasswordService} and {@see QuotaService} can
 * reuse the resolver chain without duplicating the souvera_central runtime
 * probe or the JWT acquisition logic.
 *
 * accountId resolution — v0.14.36 rewrite
 * ---------------------------------------
 * The legacy path used `souvera_central::StalwartService::findAccountId($email)`
 * — but a 2026-02 diagnosis showed that call returns a
 * TRUNCATED single character ('d' instead of 'd333333') under Stalwart 0.16,
 * which makes every downstream JMAP request 404 ("account does not exist").
 *
 * The JMAP spec (RFC 8620 §2) already carries the answer: `/jmap/session`
 * returns `primaryAccounts["urn:ietf:params:jmap:*"]` — the authoritative
 * accountId for each capability. We use that as the source of truth and
 * cache it per-request (the same NC request typically resolves the same
 * user id dozens of times — e.g. AppPasswordService loops over multiple
 * app-passwords, each calling resolveAccountId).
 */
class StalwartUserContext
{
    private const STALWART_SERVICE_FQN = 'OCA\\SouveraCentral\\Service\\StalwartService';

    /** JMAP capability we prefer to look up in `primaryAccounts`. Stalwart
     *  0.16 uses the same account id across mail/sieve/blob/quota, but we
     *  ask for `mail` first because every Stalwart deploy has it (even
     *  ones where Sieve is disabled globally). */
    private const JMAP_CAP_MAIL = 'urn:ietf:params:jmap:mail';
    private const JMAP_CAP_SIEVE = 'urn:ietf:params:jmap:sieve';

    /** @var array<string, string> per-request memoisation: userId → accountId. */
    private array $accountIdCache = [];

    public function __construct(
        private OidcProviderService $oidc,
        private IUserManager $userManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
        private StalwartAdminService $stalwartAdmin,
    ) {
    }

    public function isAvailable(): bool
    {
        return \class_exists(self::STALWART_SERVICE_FQN)
            && $this->oidc->isProviderAvailable();
    }

    /**
     * Resolve the Stalwart-side mail address for a Nextcloud user — the
     * same address the engine uses as the SASL identity. Returned to the
     * App Password UI so the user knows exactly which username to enter
     * in their legacy IMAP/SMTP client (Stalwart's Plain-Login does NOT
     * fall back to alias lookup by default — that requires the
     * `authenticateWithAlias` permission, which standard roles include
     * but custom Stalwart deploys may omit).
     *
     * @throws \RuntimeException on missing user / souvera_central / mailbox
     */
    public function resolveEmail(string $userId): string
    {
        $user = $this->userManager->get($userId);
        if (!$user instanceof IUser) {
            throw new \RuntimeException("Nextcloud user '{$userId}' not found");
        }

        if (!\class_exists(self::STALWART_SERVICE_FQN)) {
            throw new \RuntimeException(
                'souvera_central is not installed — Souvera Mail cannot resolve the Stalwart principal mapping'
            );
        }
        $stalwartService = $this->container->get(self::STALWART_SERVICE_FQN);

        $email = $stalwartService->mailFor($user);
        if (!\is_string($email) || $email === '') {
            throw new \RuntimeException(
                "souvera_central StalwartService returned no mail address for user '{$userId}'"
            );
        }
        return $email;
    }

    /**
     * Return the Stalwart JMAP accountId for a Nextcloud user.
     *
     * Source of truth is `GET /jmap/session` — Stalwart's own response tells
     * us which accountId a given OIDC bearer maps to. That sidesteps the
     * broken `souvera_central::findAccountId` path (2026-02 diagnosis
     * 2026-02-19: returns truncated 'd' instead of full 'd333333').
     *
     * The result is memoised per-request. `AppPasswordService::listAppPasswords`
     * hits this once per app-password entry, so caching is worth doing —
     * without it we'd fetch `/jmap/session` N times per settings page load.
     *
     * @throws \RuntimeException on missing user / OIDC failure / session error
     */
    public function resolveAccountId(string $userId): string
    {
        if (isset($this->accountIdCache[$userId])) {
            return $this->accountIdCache[$userId];
        }

        $bearer = $this->resolveBearer($userId);
        $session = $this->stalwartAdmin->fetchSessionAsUser($bearer);
        if ($session['status'] !== 200) {
            throw new \RuntimeException(
                "Stalwart /jmap/session returned HTTP {$session['status']} for user '{$userId}' — "
                . 'H2CK/oidc bearer likely not accepted by Stalwart. Run '
                . '`occ souvera_mail:warmup-oidc <uid>` to diagnose.'
            );
        }

        $body = $session['body'];
        $primary = \is_array($body['primaryAccounts'] ?? null) ? $body['primaryAccounts'] : [];
        // Stalwart 0.16 exposes the same accountId under every capability;
        // we still probe mail first because it's the most portable field.
        foreach ([self::JMAP_CAP_MAIL, self::JMAP_CAP_SIEVE] as $cap) {
            $id = $primary[$cap] ?? null;
            if (\is_string($id) && $id !== '') {
                return $this->accountIdCache[$userId] = $id;
            }
        }

        // Last resort: the `accounts` map contains every accountId as a
        // top-level key. If Stalwart ever ships a session without
        // `primaryAccounts`, we take the first entry — but log it so we
        // can debug.
        $accounts = \is_array($body['accounts'] ?? null) ? $body['accounts'] : [];
        foreach ($accounts as $id => $_desc) {
            if (\is_string($id) && $id !== '') {
                $this->logger->warning(
                    "Souvera Mail: /jmap/session had no primaryAccounts entry for user '{$userId}', "
                    . 'falling back to first accounts-map key. This may indicate a Stalwart config issue.',
                    ['app' => 'souvera_mail']
                );
                return $this->accountIdCache[$userId] = $id;
            }
        }

        throw new \RuntimeException(
            "Stalwart /jmap/session for user '{$userId}' carries no accountId — "
            . 'neither primaryAccounts nor accounts is populated. Verify the '
            . 'mailbox has been provisioned in Stalwart for this principal.'
        );
    }

    /**
     * Legacy resolver, retained only for callers that still need the
     * souvera_central-side account row (NOT the JMAP accountId).
     *
     * @throws \RuntimeException on missing user / souvera_central / mailbox
     * @internal do NOT use for JMAP calls — use {@see resolveAccountId()} instead
     */
    public function resolveCentralAccountId(string $userId): string
    {
        $email = $this->resolveEmail($userId);
        if (!\class_exists(self::STALWART_SERVICE_FQN)) {
            throw new \RuntimeException(
                'souvera_central is not installed — cannot resolve central account row'
            );
        }
        $stalwartService = $this->container->get(self::STALWART_SERVICE_FQN);

        $accountId = $stalwartService->findAccountId($email, 'User');
        if (!\is_string($accountId) || $accountId === '') {
            throw new \RuntimeException(
                "souvera_central row not found for '{$email}' — has souvera_central provisioned the mailbox?"
            );
        }
        return $accountId;
    }

    /**
     * @throws \RuntimeException if H2CK/oidc cannot mint a token
     */
    public function resolveBearer(string $userId): string
    {
        $token = $this->oidc->generateAccessToken($userId);
        if (!\is_string($token) || $token === '') {
            throw new \RuntimeException(
                "Could not obtain OIDC access token for user '{$userId}' (H2CK/oidc missing or souvera_mail client not registered?)"
            );
        }
        return $token;
    }
}
