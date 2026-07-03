<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

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
 * a fresh RFC 9068 JWT bound to (souvera_mail-client, current-NC-user). The mail server
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
    public const SOUVERA_MAIL_CLIENT_KEY = 'oidc-client-id';
    public const SOUVERA_MAIL_CLIENT_NAME_DEFAULT = 'souvera_mail';
    public const TOKEN_GENERATION_EVENT_FQN = 'OCA\\OIDCIdentityProvider\\Event\\TokenGenerationRequestEvent';

    private const CACHE_PREFIX = 'souvera_mail.oidc.token.';
    // Refuse to hand out (or cache for longer than) any JWT with less than this
    // many seconds of remaining lifetime. Mirrors the IMAP/SMTP/Sieve subrequest
    // path's worst-case roundtrip: token minted on the NC web request → sent on
    // the engine subrequest → received and validated by Stalwart. 60 s buys
    // generous headroom against clock drift between NC and Stalwart.
    private const CACHE_SAFETY_MARGIN_SECONDS = 60;
    // Only used for opaque (non-JWT) tokens — H2CK pre-1.x default. JWT tokens
    // always get an exp-derived TTL. NEVER use this as a fallback for a parsed
    // JWT that is already (near-)expired — that would extend the cached entry
    // past the token's lifetime and trigger Stalwart's ExpiredSignature reject.
    private const FALLBACK_TTL_SECONDS = 60;

    private ICache $cache;

    public function __construct(
        private IAppManager $appManager,
        private IEventDispatcher $eventDispatcher,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        ICacheFactory $cacheFactory,
    ) {
        $this->cache = $cacheFactory->createDistributed('souvera_mail/oidc');
    }

    /**
     * True iff the H2CK/oidc app is installed, enabled, and ABI-compatible
     * (the TokenGenerationRequestEvent class exists in the running install).
     *
     * NOTE: does NOT check whether the souvera_mail client is actually
     * registered inside H2CK — a fresh install may still be functional if
     * H2CK is configured to accept the default client name. Use
     * {@see diagnoseAvailability} for a richer, human-readable check that
     * DOES surface the missing-client-id case.
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
     * Human-readable reason WHY the provider is unavailable — or `null`
     * when everything is in place.
     *
     * Broader than {@see isProviderAvailable}: also reports the "silent
     * empty token" case where H2CK is up and running but our
     * `souvera_mail/oidc-client-id` app-config is empty, which is the
     * single largest cause of "OIDC just stopped working" reports after
     * a deploy that recreated the app without preserving app-config.
     *
     * Never used to gate token issuance (that would risk locking out
     * pre-v0.14.3 installs that never explicitly registered a client
     * because H2CK accepted the literal `souvera_mail` name). Only used
     * for diagnostics — the actual gate is {@see isProviderAvailable}
     * plus the "empty token" branch inside {@see generateAccessToken}.
     */
    public function diagnoseAvailability(): ?string
    {
        if (!$this->appManager->isInstalled(self::OIDC_APP_ID)) {
            return 'H2CK/oidc app is NOT installed — run `occ app:install oidc`';
        }
        if (!$this->appManager->isEnabledForUser(self::OIDC_APP_ID)) {
            return 'H2CK/oidc app is installed but DISABLED — run `occ app:enable oidc`';
        }
        if (!\class_exists(self::TOKEN_GENERATION_EVENT_FQN)) {
            return 'H2CK/oidc app is enabled but its TokenGenerationRequestEvent class is missing '
                . '(ABI mismatch — need H2CK/oidc 1.17+)';
        }
        // Info-level: the app-config might be empty and H2CK might still
        // accept the default name — but this is by far the most common
        // silent failure after a re-deploy that dropped app-config keys.
        $customId = $this->appConfig->getValueString('souvera_mail', self::SOUVERA_MAIL_CLIENT_KEY, '');
        if ($customId === '') {
            return "Souvera Mail OIDC client identifier is NOT persisted in app-config "
                . '(souvera_mail/oidc-client-id is empty). This is the #1 cause of '
                . '"H2CK returned an empty token" after a deploy. '
                . 'Run `occ souvera_mail:oidc:register-client --force` to re-register '
                . "and persist the client id (the H2CK client itself may already exist — "
                . '--force will reconcile).';
        }
        return null;
    }

    /**
     * Returns the souvera_mail OIDC client identifier registered in H2CK/oidc.
     * Defaults to `souvera_mail` when the operator has not overridden it via
     * `occ souvera_mail:oidc:register-client --name <custom>`.
     */
    public function getClientIdentifier(): string
    {
        $custom = $this->appConfig->getValueString('souvera_mail', self::SOUVERA_MAIL_CLIENT_KEY, '');
        return $custom !== '' ? $custom : self::SOUVERA_MAIL_CLIENT_NAME_DEFAULT;
    }

    /**
     * Issue (or fetch from cache) an OIDC access token for the given user.
     * Returns null when H2CK/oidc is unavailable or the event dispatch fails;
     * never throws — admins see a friendly message via the surrounding code.
     *
     * Caching is *defence-in-depth* TTL-aware: we honour the distributed
     * cache's TTL AND re-validate the cached JWT's `exp` claim on every hit.
     * Different cache backends (Redis, APCu, Memcached, NoLocal) honour TTLs
     * with subtly different semantics, and minor clock drift between the NC
     * pod and Stalwart can compound — so the only safe contract is to refuse
     * to hand out any JWT whose remaining lifetime is below the safety margin.
     * This is exactly what the IMAP/SMTP/Sieve subrequest path needs: a fresh
     * (or near-fresh) token on every connect, never a stale one.
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
        // Loud diagnostic when H2CK is up but our own app-config is
        // missing the client-id — this is the "silent empty token"
        // failure mode the alarm case surfaced. Info-level so it lands
        // in nextcloud.log without being scary; the actual token attempt
        // continues in case H2CK accepts the default name.
        $availabilityHint = $this->diagnoseAvailability();
        if ($availabilityHint !== null) {
            $this->logger->info('Souvera Mail: OIDC diagnostic — ' . $availabilityHint);
        }

        $clientId = $this->getClientIdentifier();
        $cacheKey = self::CACHE_PREFIX . $clientId . '.' . $userId;

        $cached = $this->cache->get($cacheKey);
        if (\is_string($cached) && $cached !== '' && $this->isJwtStillSafe($cached)) {
            return $cached;
        }
        // Cached entry was either missing, opaque, or its `exp` is too close
        // (or past) — evict so we don't keep handing the same dead token out
        // to other workers hitting this code path within the same second.
        if ($cached !== null && $cached !== false) {
            $this->cache->remove($cacheKey);
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
                    . ' Run `occ souvera_mail:status` for diagnostics.'
                );
                return null;
            }

            $ttl = $this->extractCacheTtl($accessToken);
            // Only persist tokens that buy callers at least one second of
            // usable lifetime — anything tighter is a "we'll be re-minting in
            // a flash" race we'd rather not bake into the distributed cache.
            if ($ttl > 0) {
                $this->cache->set($cacheKey, $accessToken, $ttl);
            }

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
     * True when the given JWT can still be safely handed out for OAUTHBEARER
     * — i.e. its `exp` claim is at least {@see CACHE_SAFETY_MARGIN_SECONDS}
     * seconds in the future. Opaque tokens (no parseable `exp`) are treated
     * as safe because we have no expiry hint and the surrounding cache TTL
     * (60 s {@see FALLBACK_TTL_SECONDS}) keeps the blast radius small.
     */
    private function isJwtStillSafe(string $jwt): bool
    {
        $exp = $this->extractJwtExp($jwt);
        if ($exp === null) {
            // Opaque token — no way to verify, trust the cache TTL.
            return true;
        }
        return ($exp - \time()) >= self::CACHE_SAFETY_MARGIN_SECONDS;
    }

    /**
     * Parse the JWT `exp` claim (UNIX seconds) without verifying the
     * signature — we already know the token came from H2CK/oidc, and
     * Stalwart re-verifies the signature on its side. Returns null when
     * the token is opaque (not a 3-part dot-separated JWT) or the
     * payload has no integer `exp` claim.
     */
    private function extractJwtExp(string $jwt): ?int
    {
        $parts = \explode('.', $jwt);
        if (\count($parts) < 2) {
            return null;
        }
        $b64 = \strtr($parts[1], '-_', '+/');
        $b64 .= \str_repeat('=', (4 - \strlen($b64) % 4) % 4);
        $decoded = \base64_decode($b64, true);
        if ($decoded === false) {
            return null;
        }
        $payload = \json_decode($decoded, true);
        if (!\is_array($payload) || !isset($payload['exp']) || !\is_int($payload['exp'])) {
            return null;
        }
        return $payload['exp'];
    }

    /**
     * Decode the JWT `exp` claim and compute a safe TTL for the cache entry.
     * Falls back to {@see FALLBACK_TTL_SECONDS} ONLY for opaque tokens (no
     * parseable `exp`). A JWT whose remaining lifetime is at or under the
     * safety margin gets a TTL of 0 — caller must not cache it, otherwise
     * the cache entry would outlive the token and Stalwart would reject the
     * subsequent connect with ExpiredSignature (exactly the bug this method
     * is here to prevent).
     */
    private function extractCacheTtl(string $jwt): int
    {
        $exp = $this->extractJwtExp($jwt);
        if ($exp === null) {
            // Opaque (pre-JWT) token — admin should run
            // `occ config:app:set oidc default_token_type --value jwt`.
            return self::FALLBACK_TTL_SECONDS;
        }
        $remaining = $exp - \time() - self::CACHE_SAFETY_MARGIN_SECONDS;
        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Invalidate cached tokens for one user (called on logout) or all users
     * (passed `null` — used by `occ souvera_mail:reset`).
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
