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
 *  2. Translate mail address → Stalwart account id (via souvera_central StalwartService)
 *  3. Issue a Bearer JWT for the user        (via H2CK/oidc TokenGenerationRequestEvent)
 *
 * Extracted so both {@see AppPasswordService} and {@see QuotaService} can
 * reuse the resolver chain without duplicating the souvera_central runtime
 * probe or the JWT acquisition logic.
 */
class StalwartUserContext
{
    private const STALWART_SERVICE_FQN = 'OCA\\SouveraCentral\\Service\\StalwartService';

    public function __construct(
        private OidcProviderService $oidc,
        private IUserManager $userManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
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
     * @throws \RuntimeException on missing user / souvera_central / mailbox
     */
    public function resolveAccountId(string $userId): string
    {
        $email = $this->resolveEmail($userId);
        $stalwartService = $this->container->get(self::STALWART_SERVICE_FQN);

        $accountId = $stalwartService->findAccountId($email, 'User');
        if (!\is_string($accountId) || $accountId === '') {
            throw new \RuntimeException(
                "Stalwart account not found for '{$email}' — has souvera_central provisioned the mailbox?"
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
