<?php

declare(strict_types=1);

namespace OCA\Smail\Service;

use OCP\IUser;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages a single Nextcloud user's Stalwart Application Passwords via JMAP.
 *
 * Architecture:
 *   1. Get the user's NC → mail-address mapping from souvera_central's
 *      `StalwartService::mailFor(IUser)` (injected via the service container —
 *      we don't hard-depend on souvera_central, so this resolves at runtime).
 *   2. Get the Stalwart account ID by calling `StalwartService::findAccountId(
 *      $email, 'User')` — Stalwart 0.16 accounts are opaque IDs, not names.
 *   3. Acquire a user-scoped Bearer JWT from H2CK/oidc via
 *      {@see OidcProviderService::generateAccessToken()}.
 *   4. Dispatch `x:AppPassword/{get|set}` against `<api_url>/jmap`.
 *
 * Why user JWTs and not admin Basic Auth?
 * --------------------------------------
 * Stalwart 0.16's design constraint (see docs/auth/authentication/app-password):
 * "Administrators have limited control over Application Passwords. They can
 *  view and revoke a user's Application Passwords but cannot create new ones
 *  on a user's behalf." So we use the user's own JWT for *all* AppPassword
 * operations — that also keeps the audit trail clean (Stalwart sees the
 * actual user, not an admin acting on behalf).
 */
class AppPasswordService
{
    private const SOUVERA_CENTRAL_APP_ID = 'souvera_central';
    private const STALWART_SERVICE_FQN = 'OCA\\SouveraCentral\\Service\\StalwartService';

    public function __construct(
        private StalwartAdminService $stalwart,
        private OidcProviderService $oidc,
        private IUserManager $userManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->stalwart->isConfigured()
            && $this->oidc->isProviderAvailable()
            && \class_exists(self::STALWART_SERVICE_FQN);
    }

    /**
     * @return list<array{id: string, description: string, createdAt: ?string, expiresAt: ?string}>
     */
    public function listForUser(string $userId): array
    {
        $accountId = $this->resolveAccountId($userId);
        $bearer = $this->resolveBearer($userId);

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

        $list = $this->extractMethodResponse($response, 'x:AppPassword/get');
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

        $accountId = $this->resolveAccountId($userId);
        $bearer = $this->resolveBearer($userId);

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

        $setResp = $this->extractMethodResponse($response, 'x:AppPassword/set');

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
        $accountId = $this->resolveAccountId($userId);
        $bearer = $this->resolveBearer($userId);

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

        $setResp = $this->extractMethodResponse($response, 'x:AppPassword/set');
        $destroyed = $setResp['destroyed'] ?? [];
        if (!\in_array($appPasswordId, $destroyed, true)) {
            $err = $setResp['notDestroyed'][$appPasswordId] ?? null;
            throw new \RuntimeException(
                'Stalwart refused AppPassword revocation: '
                . ($err !== null ? \json_encode($err, JSON_UNESCAPED_SLASHES) : 'id not in destroyed list')
            );
        }
    }

    private function resolveAccountId(string $userId): string
    {
        $user = $this->userManager->get($userId);
        if (!$user instanceof IUser) {
            throw new \RuntimeException("Nextcloud user '{$userId}' not found");
        }

        if (!\class_exists(self::STALWART_SERVICE_FQN)) {
            throw new \RuntimeException(
                'souvera_central is not installed — Souvera Mail cannot resolve Stalwart principal mapping'
            );
        }

        $stalwartService = $this->container->get(self::STALWART_SERVICE_FQN);
        $email = $stalwartService->mailFor($user);
        if (!\is_string($email) || $email === '') {
            throw new \RuntimeException(
                "souvera_central StalwartService returned no mail address for user '{$userId}'"
            );
        }
        $accountId = $stalwartService->findAccountId($email, 'User');
        if (!\is_string($accountId) || $accountId === '') {
            throw new \RuntimeException(
                "Stalwart account not found for '{$email}' — has souvera_central provisioned the mailbox?"
            );
        }
        return $accountId;
    }

    private function resolveBearer(string $userId): string
    {
        $token = $this->oidc->generateAccessToken($userId);
        if (!\is_string($token) || $token === '') {
            throw new \RuntimeException(
                "Could not obtain OIDC access token for user '{$userId}' (H2CK/oidc missing or smail client not registered?)"
            );
        }
        return $token;
    }

    /**
     * @param array<string, mixed> $jmapResponse
     * @return array<string, mixed>
     */
    private function extractMethodResponse(array $jmapResponse, string $expectedMethod): array
    {
        $calls = $jmapResponse['methodResponses'] ?? [];
        if (!\is_array($calls)) {
            throw new \RuntimeException('Stalwart JMAP envelope missing methodResponses');
        }
        foreach ($calls as $call) {
            if (!\is_array($call) || \count($call) < 2) {
                continue;
            }
            $name = (string) ($call[0] ?? '');
            if ($name === 'error') {
                $err = $call[1] ?? [];
                throw new \RuntimeException(
                    'Stalwart JMAP error: ' . \json_encode($err, JSON_UNESCAPED_SLASHES)
                );
            }
            if ($name === $expectedMethod && \is_array($call[1])) {
                return $call[1];
            }
        }
        throw new \RuntimeException("Stalwart JMAP response did not include {$expectedMethod}");
    }
}
