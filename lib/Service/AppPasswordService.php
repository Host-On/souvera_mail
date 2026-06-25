<?php

declare(strict_types=1);

namespace OCA\Smail\Service;

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
     * @return array{id: string, secret: string, description: string}
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
                            // Frage 3a: inherit account's full permissions (IMAP+POP3+SMTP+JMAP).
                            'permissions' => ['@type' => 'Inherit'],
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
