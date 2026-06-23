<?php

declare(strict_types=1);

namespace OCA\Smail\Service;

use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Server-side bridge to the H2CK/oidc Nextcloud OIDC Provider app.
 *
 * Souvera Mail does not authenticate the user itself — Nextcloud already did
 * that through whatever backend the operator configured (local password, LDAP,
 * user_oidc against an external IdP, SAML, passkey, …). What Souvera Mail
 * needs is an OIDC access token that the mail server (Stalwart / Dovecot)
 * will accept for IMAP/SMTP OAUTHBEARER authentication.
 *
 * That token is obtained by dispatching H2CK/oidc's `TokenGenerationRequestEvent`
 * (in-process PHP event — no browser redirect, no HTTP round-trip). H2CK returns
 * a fresh RFC 9068 JWT bound to (smail-client, current-NC-user). The mail server
 * then validates the JWT signature against `<NC>/index.php/apps/oidc/jwks`.
 *
 * Defensive: every public method tolerates the H2CK/oidc app being missing or
 * disabled and returns `null` rather than throwing, so the engine surfaces a
 * friendly admin error instead of crashing.
 *
 * Caching: tokens are cached in NC's distributed cache for `exp - 60s` so the
 * IMAP middleware can reuse the same JWT across consecutive requests within
 * one user session without re-dispatching the event each time.
 *
 * @see https://github.com/H2CK/oidc#access-token--id-token-generation-and-validation-via-events-by-other-nextcloud-apps
 */
class OidcProviderService
{
    public const OIDC_APP_ID = 'oidc';
    public const SMAIL_CLIENT_KEY = 'oidc-client-id';
    public const SMAIL_CLIENT_NAME_DEFAULT = 'smail';
    public const TOKEN_GENERATION_EVENT_FQN = 'OCA\\OIDCIdentityProvider\\Event\\TokenGenerationRequestEvent';

    private const CACHE_PREFIX = 'smail.oidc.token.';
    private const CACHE_SAFETY_MARGIN_SECONDS = 60;
    private const FALLBACK_TTL_SECONDS = 60;

    private ICache $cache;

    public function __construct(
        private IAppManager $appManager,
        private IEventDispatcher $eventDispatcher,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        ICacheFactory $cacheFactory,
    ) {
        $this->cache = $cacheFactory->createDistributed('smail/oidc');
    }

    /**
     * True iff the H2CK/oidc app is installed, enabled, and ABI-compatible
     * (the TokenGenerationRequestEvent class exists in the running install).
     */
    public function isProviderAvailable(): bool
    {
        if (!$this->appManager->isInstalled(self::OIDC_APP_ID)) {
            return false;
        }
        if (!$this->appManager->isEnabledForUser(self::OIDC_APP_ID)) {
            return false;
        }
        return \class_exists(self::TOKEN_GENERATION_EVENT_FQN);
    }

    /**
     * Returns the smail OIDC client identifier registered in H2CK/oidc.
     * Defaults to `smail` when the operator has not overridden it via
     * `occ smail:oidc:register-client --name <custom>`.
     */
    public function getClientIdentifier(): string
    {
        $custom = $this->appConfig->getValueString('smail', self::SMAIL_CLIENT_KEY, '');
        return $custom !== '' ? $custom : self::SMAIL_CLIENT_NAME_DEFAULT;
    }

    /**
     * Issue (or fetch from cache) an OIDC access token for the given user.
     * Returns null when H2CK/oidc is unavailable or the event dispatch fails;
     * never throws — admins see a friendly message via the surrounding code.
     */
    public function generateAccessToken(string $userId): ?string
    {
        if ($userId === '') {
            return null;
        }
        if (!$this->isProviderAvailable()) {
            $this->logger->debug(
                'Souvera Mail: H2CK/oidc provider not available — token issuance skipped. '
                . 'Run `occ app:install oidc && occ app:enable oidc`.'
            );
            return null;
        }

        $clientId = $this->getClientIdentifier();
        $cacheKey = self::CACHE_PREFIX . $clientId . '.' . $userId;

        $cached = $this->cache->get($cacheKey);
        if (\is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $eventClass = self::TOKEN_GENERATION_EVENT_FQN;
            /** @psalm-suppress UndefinedClass */
            $event = new $eventClass($clientId, $userId);
            $this->eventDispatcher->dispatchTyped($event);

            if (!\method_exists($event, 'getAccessToken')) {
                $this->logger->warning(
                    'Souvera Mail: H2CK/oidc event class has no getAccessToken() — version mismatch (need 1.17+)?'
                );
                return null;
            }

            $accessToken = $event->getAccessToken();
            if (!\is_string($accessToken) || $accessToken === '') {
                $this->logger->warning(
                    'Souvera Mail: H2CK/oidc returned an empty access token for user '
                    . $userId . ' / client ' . $clientId
                    . ' — is the client registered and `default_token_type=jwt` set?'
                    . ' Run `occ smail:status` for diagnostics.'
                );
                return null;
            }

            $ttl = $this->extractCacheTtl($accessToken);
            $this->cache->set($cacheKey, $accessToken, $ttl);

            return $accessToken;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: H2CK/oidc TokenGenerationRequestEvent dispatch failed for user '
                . $userId . ' / client ' . $clientId . ': ' . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Decode the JWT `exp` claim and compute a safe TTL for the cache entry.
     * Falls back to `FALLBACK_TTL_SECONDS` when the token is opaque or has no
     * parseable expiry — opaque tokens are H2CK's pre-JWT default and should
     * be migrated via `occ config:app:set oidc default_token_type --value jwt`.
     */
    private function extractCacheTtl(string $jwt): int
    {
        $parts = \explode('.', $jwt);
        if (\count($parts) < 2) {
            return self::FALLBACK_TTL_SECONDS;
        }
        $b64 = \strtr($parts[1], '-_', '+/');
        $b64 .= \str_repeat('=', (4 - \strlen($b64) % 4) % 4);
        $decoded = \base64_decode($b64, true);
        if ($decoded === false) {
            return self::FALLBACK_TTL_SECONDS;
        }
        $payload = \json_decode($decoded, true);
        if (!\is_array($payload) || !isset($payload['exp']) || !\is_int($payload['exp'])) {
            return self::FALLBACK_TTL_SECONDS;
        }
        $remaining = $payload['exp'] - \time() - self::CACHE_SAFETY_MARGIN_SECONDS;
        return $remaining > 0 ? $remaining : self::FALLBACK_TTL_SECONDS;
    }

    /**
     * Invalidate cached tokens for one user (called on logout) or all users
     * (passed `null` — used by `occ smail:reset`).
     */
    public function invalidate(?string $userId = null): void
    {
        if ($userId === null) {
            $this->cache->clear(self::CACHE_PREFIX);
            return;
        }
        $clientId = $this->getClientIdentifier();
        $this->cache->remove(self::CACHE_PREFIX . $clientId . '.' . $userId);
    }
}
