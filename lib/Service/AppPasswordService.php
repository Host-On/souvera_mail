<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use Psr\Log\LoggerInterface;

/**
 * Manages a single Nextcloud user's Stalwart Application Passwords via JMAP.
 *
 * Why user JWTs and not admin Basic Auth?
 * --------------------------------------
 * Stalwart 0.16's design constraint (docs/auth/authentication/app-password):
 * "Administrators have limited control over Application Passwords. They can
 *  view and revoke a user's Application Passwords but cannot create new ones
 *  on a user's behalf." So we use the user's own JWT for *all* AppPassword
 * operations — that also keeps the audit trail clean (Stalwart sees the
 * actual user, not an admin acting on behalf).
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
    ];

    public function __construct(
        private StalwartAdminService $stalwart,
        private StalwartUserContext $userContext,
        private LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->stalwart->isConfigured() && $this->userContext->isAvailable();
    }

    /**
     * @return list<array{id: string, description: string, createdAt: ?string, expiresAt: ?string}>
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
        $items = [];
        foreach ($list['list'] ?? [] as $entry) {
            if (!\is_array($entry) || !isset($entry['id'])) {
                continue;
            }
            $items[] = [
                'id' => (string) $entry['id'],
                'description' => (string) ($entry['description'] ?? ''),
                'createdAt' => isset($entry['createdAt']) ? (string) $entry['createdAt'] : null,
                'expiresAt' => isset($entry['expiresAt']) ? (string) $entry['expiresAt'] : null,
            ];
        }
        return $items;
    }

    /**
     * Creates a new App Password. The plaintext `secret` is returned ONCE
     * and never recoverable afterwards (Stalwart stores only its hash).
     *
     * The `username` is the canonical Stalwart-side mail address — the
     * exact SASL identity the user must enter in their legacy IMAP/SMTP
     * client. Surfaced here (not just in the UI's "Identity" field)
     * because Stalwart's PLAIN/LOGIN auth path matches the principal
     * by its primary `name`/`emails[]` and does NOT fall back to alias
     * lookup unless the principal carries the `authenticateWithAlias`
     * permission. Returning the canonical username at create-time saves
     * the user from guessing.
     *
     * @return array{id: string, secret: string, description: string, username: string}
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
                            //   - `@type` must be "Replace" (or "Inherit" —
                            //     but that ignores the payload) — anything
                            //     else fails with `Missing or invalid '@type'`.
                            //   - The KEY under "Replace" is `permissions`,
                            //     NOT `value` / `perms` / `list` / `items` /
                            //     `set` (all rejected as "Invalid key for
                            //     object").
                            //   - The VALUE at `permissions` is a MAP of
                            //     `<perm-id> => bool`, NOT an array of
                            //     perm-id strings (array rejected as
                            //     "Invalid value for object property").
                            //     Setting a perm to `false` explicitly
                            //     revokes it; omission is treated as
                            //     implicit revoke by the Replace semantics.
                            //   - Perm IDs are the ones listed by
                            //     `stalwart-cli describe Permission` (see
                            //     the enum definition in the schema store
                            //     — DO NOT invent new ones, `Invalid key`
                            //     will be returned).
                            //
                            // The 0.13.18 attempt used
                            //   { "@type": "Replace", "value": [...] }
                            // and the earlier 0.13.17 attempt used
                            //   { "@type": "Replace", "permissions": [...] }
                            // Both were wrong on the SHAPE (array vs map).
                            'permissions' => [
                                '@type' => 'Replace',
                                'permissions' => \array_fill_keys(
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
        if (!\is_array($created) || !isset($created['id']) || !isset($created['secret'])) {
            throw new \RuntimeException(
                'Stalwart did not return the new AppPassword id/secret. Raw response: '
                . \json_encode($setResp, JSON_UNESCAPED_SLASHES)
            );
        }

        return [
            'id' => (string) $created['id'],
            'secret' => (string) $created['secret'],
            'description' => $description,
            'username' => $email,
        ];
    }

    public function revokeForUser(string $userId, string $appPasswordId): void
    {
        if ($appPasswordId === '') {
            throw new \InvalidArgumentException('appPasswordId must not be empty');
        }
        $accountId = $this->userContext->resolveAccountId($userId);
        $bearer = $this->userContext->resolveBearer($userId);

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
                'Stalwart refused AppPassword revocation: '
                . ($err !== null ? \json_encode($err, JSON_UNESCAPED_SLASHES) : 'id not in destroyed list')
            );
        }
    }
}
