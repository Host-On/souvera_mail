<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCA\SouveraMail\Db\AppPasswordMapping;
use OCA\SouveraMail\Db\AppPasswordMappingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use Psr\Log\LoggerInterface;

/**
 * Manages a single Nextcloud user's Stalwart Application Passwords via JMAP,
 * paired with a Nextcloud auth-token that shares the SAME plaintext secret —
 * so the ONE credential unlocks both mail (IMAP/SMTP/Sieve) and DAV
 * (WebDAV/CalDAV/CardDAV) with a single copy-and-paste.
 *
 * Why user JWTs and not admin Basic Auth (for the Stalwart leg)?
 * --------------------------------------------------------------
 * Stalwart 0.16's design constraint (docs/auth/authentication/app-password):
 * "Administrators have limited control over Application Passwords. They can
 *  view and revoke a user's Application Passwords but cannot create new ones
 *  on a user's behalf." So we use the user's own JWT for *all* AppPassword
 * operations — that also keeps the audit trail clean (Stalwart sees the
 * actual user, not an admin acting on behalf).
 *
 * The Nextcloud leg (since v0.14.0)
 * ---------------------------------
 * After Stalwart returns the ONCE-shown plaintext `secret`, we hand the
 * exact same string to `\OCP\Authentication\Token\IProvider::generateToken`
 * with `type = PERMANENT_TOKEN` and `scope = ['filesystem' => true]`. That
 * plants a permanent app-token in `oc_authtoken` — DAV clients (DAVx5,
 * Thunderbird, Apple Calendar) can now authenticate with the same string
 * that Thunderbird uses for IMAP. Password field is `null` because our
 * users log in via H2CK/oidc — the token itself IS the credential.
 *
 * The mapping is persisted in `oc_souvera_mail_apppwd` so
 * {@see revokeForUser} can atomically dismiss BOTH sides, and so the UI
 * can flag pre-v0.14.0 Stalwart-only passwords as `legacy` (Mail-only).
 */
class AppPasswordService
{
    /**
     * Permission list assigned to App Passwords created by Souvera Mail.
     *
     * We use Stalwart's `Replace` mode (instead of `Inherit`) so the app
     * password's permissions are fully under our control — regardless of
     * what the operator's principal-level role grants. The crucial
     * permission is **`authenticateWithAlias`**: without it Stalwart's
     * PLAIN/LOGIN auth refuses any username that isn't the principal's
     * canonical `name`. The default Stalwart user role does include it,
     * but custom deploys (and the operator that reported this bug)
     * sometimes omit it — which made e-mail-as-username silently fail
     * with `AUTHENTICATIONFAILED` even though the secret was correct.
     *
     * The remaining permissions cover the full IMAP/POP3/Sieve surface
     * a legacy mail client needs (Thunderbird, Outlook, iPhone Mail …)
     * plus `emailSend` / `emailReceive` for SMTP submission. Verified
     * against Stalwart 0.16's `stalw.art/docs/ref/permissions` listing.
     *
     * @var list<string>
     */
    private const APP_PASSWORD_PERMISSIONS = [
        // ── User authentication ──────────────────────────────────────
        // `authenticate` is the gate; `authenticateWithAlias` is the
        // one that lets the user log in with their e-mail address
        // instead of the bare principal name.
        'authenticate',
        'authenticateWithAlias',

        // ── Mail delivery ────────────────────────────────────────────
        'emailSend',
        'emailReceive',

        // ── IMAP (full feature set so e.g. Thunderbird's APPEND
        // sent-folder, IDLE, MOVE, SEARCH/SORT/THREAD all work) ──────
        'imapAuthenticate',
        'imapCapability', 'imapId', 'imapEnable', 'imapNamespace',
        'imapList', 'imapLsub', 'imapSubscribe',
        'imapSelect', 'imapExamine', 'imapStatus',
        'imapAppend', 'imapFetch', 'imapStore',
        'imapCopy', 'imapMove',
        'imapSearch', 'imapSort', 'imapThread',
        'imapCreate', 'imapDelete', 'imapRename', 'imapExpunge',
        'imapIdle',
        'imapAclGet', 'imapMyRights', 'imapListRights',

        // ── POP3 (some legacy clients still default to it) ──────────
        'pop3Authenticate',
        'pop3List', 'pop3Uidl', 'pop3Stat', 'pop3Retr', 'pop3Dele',

        // ── ManageSieve (server-side filtering) ─────────────────────
        'sieveAuthenticate',
        'sieveListScripts', 'sieveSetActive',
        'sieveGetScript', 'sievePutScript', 'sieveDeleteScript',
        'sieveRenameScript', 'sieveCheckScript', 'sieveHaveSpace',

        // ── JMAP (Souvera Android App) ──────────────────────────────
        'jmapAuthenticate',
        'jmapEmailGet', 'jmapEmailQuery', 'jmapEmailSet',
        'jmapEmailSubmission',
        'jmapMailboxGet', 'jmapMailboxQuery',
        'jmapBlobGet',
    ];

    /**
     * PublicKeyTokenProvider::TOKEN_MIN_LENGTH is 22 (verified against
     * `nextcloud/server` master 2026-02). Stalwart secrets are
     * `app_<32 lowercase>` = 36 chars, well above the floor — but we
     * guard anyway so a future Stalwart change surfaces as a create
     * error instead of a corrupted mapping row.
     */
    private const NC_TOKEN_MIN_LENGTH = 22;

    /** Human-readable NC token-name suffix so users spot our tokens. */
    private const NC_TOKEN_NAME_SUFFIX = ' (Souvera Mail + DAV)';

    /**
     * Re-entrancy guard for the reverse-invalidation path.
     *
     * When {@see revokeForUser} calls `ncTokenProvider->invalidateTokenById()`,
     * Nextcloud fires a `TokenInvalidatedEvent`, which our
     * `NcTokenInvalidatedListener` picks up and forwards to
     * {@see revokeByNcTokenId}. Without this flag, that reverse call
     * would try to destroy the Stalwart password a SECOND time (already
     * gone → `notDestroyed: id not in destroyed list`) and re-delete the
     * mapping row.
     *
     * Declared here (v0.18.0) so PHP 8.2+ `\Error` on undeclared property
     * access no longer floods the log with
     *   `Undefined property: OCA\SouveraMail\Service\AppPasswordService::$inRevoke`.
     * Previous versions relied on the deprecated implicit-property
     * behaviour, which was removed in PHP 8.2.
     */
    private bool $inRevoke = false;

    public function __construct(
        private StalwartAdminService $stalwart,
        private StalwartUserContext $userContext,
        private ITokenProvider $ncTokenProvider,
        private AppPasswordMappingMapper $mappingMapper,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->stalwart->isConfigured() && $this->userContext->isAvailable();
    }

    /**
     * @return list<array{
     *     id: string, description: string,
     *     createdAt: ?string, expiresAt: ?string,
     *     kind: string, ncTokenId: ?int
     * }>
     */
    public function listForUser(string $userId): array
    {
        $accountId = $this->userContext->resolveAccountId($userId);
        $bearer = $this->userContext->resolveBearer($userId);

        $response = $this->stalwart->jmapCall($bearer, [
            [
                'x:AppPassword/get',
                [
                    'accountId' => $accountId,
                    'properties' => ['id', 'description', 'createdAt', 'expiresAt'],
                ],
                'c0',
            ],
        ]);

        $list = $this->stalwart->extractMethodResponse($response, 'x:AppPassword/get');

        // Snapshot our mapping table once so we can classify each entry
        // as `combined` (has a paired NC token) or `legacy` (Stalwart-only,
        // typically created before v0.14.0). O(n) instead of N + 1 lookup.
        $mappingByStalwart = [];
        foreach ($this->mappingMapper->findAllForUser($userId) as $row) {
            $mappingByStalwart[$row->getStalwartAppId()] = $row;
        }

        $items = [];
        foreach ($list['list'] ?? [] as $entry) {
            if (!\is_array($entry) || !isset($entry['id'])) {
                continue;
            }
            $stalwartId = (string) $entry['id'];
            $mapping = $mappingByStalwart[$stalwartId] ?? null;

            $items[] = [
                'id' => $stalwartId,
                'description' => (string) ($entry['description'] ?? ''),
                'createdAt' => isset($entry['createdAt']) ? (string) $entry['createdAt'] : null,
                'expiresAt' => isset($entry['expiresAt']) ? (string) $entry['expiresAt'] : null,
                // `combined` = Mail + Nextcloud/DAV; `legacy` = Mail only
                // (created before v0.14.0 or via stalwart-cli directly).
                'kind' => $mapping !== null ? 'combined' : 'legacy',
                'ncTokenId' => $mapping !== null ? $mapping->getNcTokenId() : null,
            ];
        }
        return $items;
    }

    /**
     * Creates a new App Password that works for BOTH mail (IMAP/SMTP/Sieve)
     * and Nextcloud/DAV (WebDAV/CalDAV/CardDAV). The plaintext `secret` is
     * returned ONCE and never recoverable afterwards.
     *
     * Two-phase commit: Stalwart first, then Nextcloud. If the NC leg
     * fails, we roll back the Stalwart password so the user never ends
     * up with a "half-created" credential.
     *
     * @return array{id: string, secret: string, description: string, username: string, ncTokenId: int}
     */
    public function createForUser(string $userId, string $description): array
    {
        $description = \trim($description);
        if ($description === '') {
            throw new \InvalidArgumentException('description must not be empty');
        }
        if (\mb_strlen($description) > 120) {
            $description = \mb_substr($description, 0, 120);
        }

        $email = $this->userContext->resolveEmail($userId);
        $accountId = $this->userContext->resolveAccountId($userId);
        $bearer = $this->userContext->resolveBearer($userId);

        // ── Phase 1: Stalwart (owns the plaintext secret) ────────────
        $created = $this->createStalwartAppPassword($accountId, $bearer, $description);
        $stalwartId = $created['id'];
        $secret = $created['secret'];

        // Guard against a future Stalwart change that would produce a
        // secret too short for NC's PublicKeyTokenProvider.
        if (\strlen($secret) < self::NC_TOKEN_MIN_LENGTH) {
            $this->destroyStalwartAppPassword($accountId, $bearer, $stalwartId, 'nc-token-min-length');
            throw new \RuntimeException(
                'Stalwart returned a secret shorter than ' . self::NC_TOKEN_MIN_LENGTH
                . ' chars — cannot pair with a Nextcloud auth token.'
            );
        }

        // ── Phase 2: Nextcloud auth-token (paired to same plaintext) ─
        try {
            $ncToken = $this->ncTokenProvider->generateToken(
                token: $secret,
                uid: $userId,
                loginName: $userId,
                // OIDC users have no locally-stored password. NC's own
                // AppPassword flow does the same for SSO users — the token
                // itself IS the credential, so no reauth-password is needed.
                password: null,
                name: $description . self::NC_TOKEN_NAME_SUFFIX,
                type: IToken::PERMANENT_TOKEN,
                remember: IToken::DO_NOT_REMEMBER,
                // Full DAV scope: WebDAV files, CalDAV, CardDAV — everything
                // the DAVx5 / Thunderbird / Apple stack expects from an
                // "app password".
                scope: ['filesystem' => true],
            );
        } catch (\Throwable $e) {
            // Roll back Stalwart so the user does not end up with a
            // Mail-only "phantom" password they cannot re-use for DAV.
            $this->destroyStalwartAppPassword($accountId, $bearer, $stalwartId, 'nc-generate-failed');
            $this->logger->error(
                'Souvera Mail: Nextcloud token creation failed after Stalwart create — rolled back Stalwart. Error: '
                . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            throw new \RuntimeException(
                'Nextcloud auth-token creation failed — no combined app password was created: '
                . $e->getMessage(),
                0,
                $e
            );
        }

        // ── Phase 3: persist the mapping so revoke can find both sides.
        try {
            $mapping = new AppPasswordMapping();
            $mapping->setUserId($userId);
            $mapping->setNcTokenId((int) $ncToken->getId());
            $mapping->setStalwartAppId($stalwartId);
            $mapping->setDescription($description);
            $mapping->setCreatedAt($this->time->getTime());
            $this->mappingMapper->insert($mapping);
        } catch (\Throwable $e) {
            // Roll both sides back — a mapping-less pair is
            // undeletable via the UI (we have no id → id link) and
            // therefore worse than no pair at all.
            try {
                $this->ncTokenProvider->invalidateTokenById($userId, (int) $ncToken->getId());
            } catch (\Throwable $ncErr) {
                $this->logger->warning(
                    'Souvera Mail: NC token rollback also failed: ' . $ncErr->getMessage(),
                    ['app' => 'souvera_mail']
                );
            }
            $this->destroyStalwartAppPassword($accountId, $bearer, $stalwartId, 'mapping-insert-failed');
            $this->logger->error(
                'Souvera Mail: Mapping insert failed — rolled back both sides. Error: '
                . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            throw new \RuntimeException(
                'AppPassword mapping persistence failed — no combined app password was created: '
                . $e->getMessage(),
                0,
                $e
            );
        }

        return [
            'id' => $stalwartId,
            'secret' => $secret,
            'description' => $description,
            'username' => $email,
            'ncTokenId' => (int) $ncToken->getId(),
        ];
    }

    /**
     * Best-effort revocation of an NC auth-token identified by its
     * plaintext secret. Used by {@see LoginFlowController::upgrade()}
     * to invalidate the ORIGINAL NC-only token (`X`) that a native
     * client obtained via `/login/v2/*`, after we've already handed
     * that client a fresh paired credential (`Y`).
     *
     * Design notes:
     *
     * - `ITokenProvider::invalidateToken()` takes the PLAINTEXT and
     *   hashes it internally — the only public accessor for that
     *   flow (`invalidateTokenById` needs the numeric id which we
     *   don't have without an extra lookup).
     *
     * - If the plaintext matches NOTHING in `oc_authtoken` (e.g. the
     *   caller was authenticated via session cookie so PHP_AUTH_PW
     *   is empty, or the caller passed the user's actual password
     *   rather than an app-password), NC silently no-ops. We do NOT
     *   raise — the whole method is best-effort.
     *
     * - If the plaintext DOES match a mapping row (very unlikely
     *   edge case: someone upgrades a token that was already
     *   Souvera-paired), the reverse-invalidation listener
     *   ({@see \OCA\SouveraMail\Listeners\NcTokenInvalidatedListener})
     *   cascades to Stalwart + mapping. That's the correct behaviour
     *   for a "clean the entire pair" call, so no re-entrancy guard
     *   is needed here.
     *
     * - Any thrown exception is logged and swallowed. A failed
     *   invalidation is not a data-integrity issue — worst case the
     *   user sees a stale "device" entry in `/settings/user/security`
     *   and can revoke it manually. Losing `Y` because `X` couldn't
     *   be killed would be far worse.
     */
    public function revokeByRawSecret(string $userId, string $rawSecret): void
    {
        if ($rawSecret === '') {
            return;
        }
        try {
            $this->ncTokenProvider->invalidateToken($rawSecret);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail upgrade: could not invalidate NC token by raw secret '
                . 'after successful Y-create for user ' . $userId . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail'],
            );
        }
    }

    /**
     * Revoke a combined app password. Order: NC token first, Stalwart
     * second, mapping row last. Rationale: an orphan Stalwart entry only
     * affects mail (user simply sees it disappear on the next list()),
     * while an orphan NC token would linger as a "zombie" in NC's
     * `/settings/user/security` device list — worse UX.
     *
     * Legacy (Stalwart-only, no mapping row) passwords are supported:
     * we skip the NC leg and destroy only the Stalwart side.
     */
    public function revokeForUser(string $userId, string $appPasswordId): void
    {
        if ($appPasswordId === '') {
            throw new \InvalidArgumentException('appPasswordId must not be empty');
        }

        $mapping = null;
        try {
            $mapping = $this->mappingMapper->findByStalwartId($userId, $appPasswordId);
        } catch (DoesNotExistException) {
            // Pre-v0.14.0 password (Mail only) — no mapping, no NC token
            // to revoke. Fall through to Stalwart-only path.
        }

        // ── NC token first (see rationale above) ─────────────────────
        if ($mapping !== null) {
            // Guard against re-entering via TokenInvalidatedEvent →
            // NcTokenInvalidatedListener → revokeByNcTokenId → Stalwart
            // destroy. The listener MUST no-op while this flag is set.
            $this->inRevoke = true;
            try {
                $this->ncTokenProvider->invalidateTokenById(
                    $userId,
                    $mapping->getNcTokenId(),
                );
            } catch (InvalidTokenException $e) {
                // Token was already removed (e.g. user clicked
                // "Revoke" in `/settings/user/security` first). Not
                // fatal — proceed to destroy Stalwart side.
                $this->logger->info(
                    'Souvera Mail: NC token already gone during revoke — continuing with Stalwart side. '
                    . $e->getMessage(),
                    ['app' => 'souvera_mail']
                );
            } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                // NC 33+: PublicKeyTokenProvider::invalidateTokenById()
                // internally calls getTokenById() which throws
                // DoesNotExistException (not InvalidTokenException) if
                // the token row is already gone. Same recovery.
                $this->logger->info(
                    'Souvera Mail: NC token row already deleted during revoke: '
                    . $e->getMessage(),
                    ['app' => 'souvera_mail']
                );
            } catch (\Throwable $e) {
                // Any other NC internal — log full context but DO NOT
                // abort. If we bail here, the user's Stalwart password
                // stays, the mapping row stays, and there's no path
                // through the UI to fix it. Better: log + press on.
                $this->logger->warning(
                    'Souvera Mail: unexpected NC invalidateTokenById error during revoke: '
                    . $e->getMessage(),
                    ['app' => 'souvera_mail', 'exception' => $e]
                );
            } finally {
                $this->inRevoke = false;
            }
        }

        // ── Stalwart ─────────────────────────────────────────────────
        $accountId = $this->userContext->resolveAccountId($userId);
        $bearer = $this->userContext->resolveBearer($userId);
        $this->destroyStalwartAppPassword($accountId, $bearer, $appPasswordId, 'user-revoke');

        // ── Mapping row (last, so a Stalwart-destroy retry is trivial) ─
        if ($mapping !== null) {
            try {
                $this->mappingMapper->delete($mapping);
            } catch (\Throwable $e) {
                // Log but do not throw — the credential itself is dead
                // on both sides. A stale mapping row is a housekeeping
                // problem, not a security one.
                $this->logger->warning(
                    'Souvera Mail: Mapping row delete failed after successful revoke: '
                    . $e->getMessage(),
                    ['app' => 'souvera_mail']
                );
            }
        }
    }

    /**
     * Reverse-invalidation hook: called by the NC-security-page listener
     * when a user clicks "Revoke" on a combined token from within the
     * standard NC UI (i.e. WITHOUT going through Souvera Mail). We
     * mirror the deletion to Stalwart so mail auth stops working too.
     *
     * Re-entrancy: while {@see revokeForUser} is running the invalidate
     * call it will ALSO fire this listener via TokenInvalidatedEvent.
     * That case is handled by the `$this->inRevoke` guard — the outer
     * revokeForUser already destroys Stalwart + mapping, so this method
     * must no-op instead of double-destroying (which would fail with
     * `notDestroyed: id not in destroyed list`).
     */
    public function revokeByNcTokenId(string $userId, int $ncTokenId): void
    {
        if ($this->inRevoke) {
            // Nested call from TokenInvalidatedEvent inside our own
            // revokeForUser flow — the outer caller cleans up.
            return;
        }
        $rows = $this->mappingMapper->findAllForUser($userId);
        foreach ($rows as $row) {
            if ($row->getNcTokenId() !== $ncTokenId) {
                continue;
            }
            // Destroy Stalwart side.
            try {
                $accountId = $this->userContext->resolveAccountId($userId);
                $bearer = $this->userContext->resolveBearer($userId);
                $this->destroyStalwartAppPassword(
                    $accountId,
                    $bearer,
                    $row->getStalwartAppId(),
                    'nc-token-invalidated',
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Souvera Mail: Stalwart destroy failed after NC invalidate: ' . $e->getMessage(),
                    ['app' => 'souvera_mail']
                );
            }
            // Delete mapping row.
            try {
                $this->mappingMapper->delete($row);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Souvera Mail: Mapping delete failed after NC-side invalidate: ' . $e->getMessage(),
                    ['app' => 'souvera_mail']
                );
            }
            return;
        }
    }

    /**
     * Migration-scoped app password: Stalwart side ONLY (no NC-token,
     * no mapping row, no DAV surface). Returned secret is used exactly
     * once by MigrationService to POST /imap/migrate at provider.tools,
     * then revoked from BOTH the completion path (success/failure) and
     * the nightly MigrationCleanup cron (belt-and-suspenders).
     *
     * We deliberately DO NOT reuse {@see createForUser} for imports:
     *  - The user should not see the migration credential in their
     *    connected-devices list (it's not a user-facing token).
     *  - The DAV/filesystem scope on an NC auth token is unnecessary
     *    for an IMAP-only receiving side and expands the blast radius
     *    if the temp secret leaked before we revoke it.
     *  - No mapping row means no bookkeeping cascade with the combined
     *    revocation flow.
     *
     * @return array{id: string, secret: string, username: string}
     */
    public function createStalwartOnlyForMigration(string $userId, string $label): array
    {
        $label = \trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('label must not be empty');
        }
        if (\mb_strlen($label) > 120) {
            $label = \mb_substr($label, 0, 120);
        }
        $email = $this->userContext->resolveEmail($userId);
        $accountId = $this->userContext->resolveAccountId($userId);
        $bearer = $this->userContext->resolveBearer($userId);

        $created = $this->createStalwartAppPassword($accountId, $bearer, $label);
        return [
            'id' => $created['id'],
            'secret' => $created['secret'],
            'username' => $email,
        ];
    }

    /**
     * Revoke a migration-scoped app password (Stalwart side only).
     * Safe to call even if the password was already destroyed (idempotent):
     * the Stalwart destroy call itself is idempotent, and no mapping row
     * to clean up.
     */
    public function revokeStalwartOnlyForMigration(string $userId, string $stalwartAppId, string $reason = 'migration-finished'): void
    {
        if ($stalwartAppId === '') {
            return;
        }
        try {
            $accountId = $this->userContext->resolveAccountId($userId);
            $bearer = $this->userContext->resolveBearer($userId);
            $this->destroyStalwartAppPassword($accountId, $bearer, $stalwartAppId, $reason);
        } catch (\Throwable $e) {
            // Non-fatal — the nightly MigrationCleanup cron will retry.
            $this->logger->warning(
                'Souvera Mail: migration app-password revoke failed (will retry via cron): '
                . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
        }
    }

    /**
     * @return array{id: string, secret: string}
     */
    private function createStalwartAppPassword(string $accountId, string $bearer, string $description): array
    {
        $creationId = 'k1';
        $response = $this->stalwart->jmapCall($bearer, [
            [
                'x:AppPassword/set',
                [
                    'accountId' => $accountId,
                    'create' => [
                        $creationId => [
                            'description' => $description,
                            // Stalwart 0.16 CredentialPermissions wire-format
                            // (live-verified 2026-07-01 against Stalwart
                            // 0.16.10 on the operator's `fccec267` cluster
                            // by exhaustively fuzzing every plausible shape):
                            //   { "@type": "Replace",
                            //     "permissions": { "authenticate": true,
                            //                      "emailSend": true, … } }
                            // Key observations:
                            //   - `@type` must be "Replace".
                            //   - The KEY under "Replace" is `permissions`,
                            //     NOT `value` / `perms` / `list`.
                            //   - The VALUE at `permissions` is a MAP of
                            //     `<perm-id> => bool`, NOT an array of
                            //     perm-id strings.
                            'permissions' => [
                                '@type' => 'Replace',
                                'value' => \array_fill_keys(
                                    self::APP_PASSWORD_PERMISSIONS,
                                    true,
                                ),
                            ],
                            'allowedIps' => (object) [],
                        ],
                    ],
                ],
                'c0',
            ],
        ]);

        $setResp = $this->stalwart->extractMethodResponse($response, 'x:AppPassword/set');

        if (isset($setResp['notCreated'][$creationId])) {
            $err = $setResp['notCreated'][$creationId];
            throw new \RuntimeException(
                'Stalwart refused AppPassword creation: ' . \json_encode($err, JSON_UNESCAPED_SLASHES)
            );
        }

        $created = $setResp['created'][$creationId] ?? null;
        if (!\is_array($created) || !isset($created['id'], $created['secret'])) {
            throw new \RuntimeException(
                'Stalwart did not return the new AppPassword id/secret. Raw response: '
                . \json_encode($setResp, JSON_UNESCAPED_SLASHES)
            );
        }

        return [
            'id' => (string) $created['id'],
            'secret' => (string) $created['secret'],
        ];
    }

    private function destroyStalwartAppPassword(string $accountId, string $bearer, string $appPasswordId, string $reason): void
    {
        try {
            $response = $this->stalwart->jmapCall($bearer, [
                [
                    'x:AppPassword/set',
                    [
                        'accountId' => $accountId,
                        'destroy' => [$appPasswordId],
                    ],
                    'c0',
                ],
            ]);

            $setResp = $this->stalwart->extractMethodResponse($response, 'x:AppPassword/set');
            $destroyed = $setResp['destroyed'] ?? [];
            if (!\in_array($appPasswordId, $destroyed, true)) {
                $err = $setResp['notDestroyed'][$appPasswordId] ?? null;
                throw new \RuntimeException(
                    'Stalwart refused AppPassword revocation (' . $reason . '): '
                    . ($err !== null ? \json_encode($err, JSON_UNESCAPED_SLASHES) : 'id not in destroyed list')
                );
            }
        } catch (\Throwable $e) {
            // On the rollback path we want to log-and-continue instead
            // of throw — otherwise a Stalwart hiccup would leave the
            // caller believing the create succeeded.
            if ($reason !== 'user-revoke') {
                $this->logger->error(
                    'Souvera Mail: Stalwart destroy failed during ' . $reason . ' rollback: '
                    . $e->getMessage(),
                    ['app' => 'souvera_mail', 'exception' => $e]
                );
                return;
            }
            throw $e;
        }
    }
}
