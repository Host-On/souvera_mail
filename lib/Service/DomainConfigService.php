<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCA\SouveraMail\Util\EngineHelper;
use OCP\IConfig;

/**
 * Service to programmatically read/write engine domain config files.
 *
 * Domain configs are stored as JSON in:
 *   {datadir}/appdata_souvera_mail/_data_/_default_/domains/{domain}.json
 */
class DomainConfigService
{
    public function __construct(
        private IConfig $config,
        private ?EngineHelper $engineHelper = null,
    ) {
    }

    /**
     * Validate domain name to prevent path traversal.
     *
     * @throws \InvalidArgumentException if domain contains invalid characters
     */
    private function validateDomain(string $domain): void
    {
        if ($domain === '' || $domain === '.' || $domain === '..' || !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $domain)) {
            throw new \InvalidArgumentException("Invalid domain name: {$domain}");
        }
    }

    private const SSL_NONE = 0;
    private const SSL_SSL = 1;
    private const SSL_TLS = 2;

    /**
     * Map string SSL type to engine numeric value.
     */
    public static function sslToInt(string $ssl): int
    {
        return match (\strtolower($ssl)) {
            'ssl' => self::SSL_SSL,
            'tls', 'starttls' => self::SSL_TLS,
            default => self::SSL_NONE,
        };
    }

    /**
     * Map engine numeric SSL value to human-readable string.
     */
    public static function sslToString(int $ssl): string
    {
        return match ($ssl) {
            self::SSL_SSL => 'SSL',
            self::SSL_TLS => 'STARTTLS',
            default => 'None',
        };
    }

    /**
     * Get the appdata_souvera_mail path.
     */
    public function getDataPath(): string
    {
        return \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/') . '/appdata_souvera_mail';
    }

    /**
     * Get path to domains directory.
     */
    private function getDomainsPath(): string
    {
        return $this->getDataPath() . '/_data_/_default_/domains';
    }

    /**
     * Write a domain config JSON file.
     *
     * @param array<string, mixed> $config
     */
    public function writeDomainConfig(string $domain, array $config): void
    {
        $this->validateDomain($domain);
        $domainsPath = $this->getDomainsPath();
        if (!\is_dir($domainsPath)) {
            \mkdir($domainsPath, 0755, true);
        }

        $file = $domainsPath . '/' . $domain . '.json';
        $json = \json_encode($config, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode domain config as JSON: ' . \json_last_error_msg());
        }
        if (\file_put_contents($file, $json) === false) {
            throw new \RuntimeException('Failed to write domain config to ' . $file);
        }
    }

    /**
     * Read a domain config JSON file.
     *
     * @return array<string, mixed>|null
     */
    public function readDomainConfig(string $domain): ?array
    {
        $this->validateDomain($domain);
        $file = $this->getDomainsPath() . '/' . $domain . '.json';
        if (!\file_exists($file)) {
            return null;
        }

        $content = \file_get_contents($file);
        if ($content === false) {
            return null;
        }
        $data = \json_decode($content, true);
        return \is_array($data) ? $data : null;
    }

    /**
     * Delete a domain config file.
     */
    public function deleteDomainConfig(string $domain): void
    {
        $this->validateDomain($domain);
        $file = $this->getDomainsPath() . '/' . $domain . '.json';
        if (!\file_exists($file)) {
            throw new \RuntimeException("Domain config not found: {$domain}");
        }
        if (!\unlink($file)) {
            throw new \RuntimeException("Failed to delete domain config: {$domain}");
        }
    }

    /**
     * List configured domains.
     *
     * @return list<string>
     */
    public function listDomains(): array
    {
        $domainsPath = $this->getDomainsPath();
        if (!\is_dir($domainsPath)) {
            return [];
        }

        $domains = [];
        foreach (\glob($domainsPath . '/*.json') ?: [] as $file) {
            $name = \basename($file, '.json');
            if ($name !== 'disabled') {
                $domains[] = $name;
            }
        }
        return $domains;
    }

    /**
     * Engine SSL config object template.
     *
     * Pass `$allowSelfSigned = true` ONLY when the IMAP/SMTP/Sieve host is
     * an internal IP or an alias whose certificate CN does not match — e.g.
     * the operator routes IMAP through the cluster-internal Stalwart IP
     * (`10.20.0.153`) instead of a public hostname behind a load balancer
     * that doesn't TCP-forward port 993. In that scenario the TLS
     * handshake succeeds but `verify_peer_name` would reject the cert,
     * which Snappymail surfaces as the unhelpful `SocketReadException`.
     * Setting this true relaxes verify_peer + verify_peer_name +
     * allow_self_signed in one shot.
     *
     * @return array<string, bool|int|string>
     */
    private function sslConfig(bool $allowSelfSigned = false): array
    {
        $defaults = [
            'verify_peer' => !$allowSelfSigned,
            'verify_peer_name' => !$allowSelfSigned,
            'allow_self_signed' => $allowSelfSigned,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'security_level' => 1,
        ];

        try {
            if ($this->engineHelper !== null) {
                $this->engineHelper->loadApp();
            }

            if (\class_exists('\\Smail\\Mail\\Net\\SSLContext')) {
                $context = new \Smail\Mail\Net\SSLContext();
                return [
                    'verify_peer' => $allowSelfSigned ? false : $context->verify_peer,
                    'verify_peer_name' => $allowSelfSigned ? false : $context->verify_peer_name,
                    'allow_self_signed' => $allowSelfSigned || $context->allow_self_signed,
                    'SNI_enabled' => $context->SNI_enabled,
                    'disable_compression' => $context->disable_compression,
                    'security_level' => $context->security_level,
                ] + \array_filter([
                    'cafile' => $context->cafile,
                    'capath' => $context->capath,
                    'local_cert' => $context->local_cert,
                ], static fn (string $value): bool => $value !== '');
            }
        } catch (\Throwable $e) {
            // Use built-in defaults when the engine is not bootstrapped.
        }

        return $defaults;
    }

    /**
     * Build a complete engine domain config from setup parameters.
     * Uses the full engine format with all required keys.
     * Authentication is always OAUTHBEARER/XOAUTH2 (SSO-only).
     *
     * The three `*AllowSelfSigned` flags relax TLS verification for the
     * matching protocol. They are required when the operator routes
     * IMAP/SMTP/Sieve through the cluster-internal Stalwart IP
     * (`10.20.0.153`) because the load balancer in front of the
     * public hostname doesn't TCP-forward IMAP/SMTP/Sieve ports.
     * See PRD Step 27 for the live-debug trace that established this.
     *
     * @return array<string, mixed>
     */
    public function buildDomainConfig(
        string $imapHost,
        int $imapPort,
        string $imapSsl,
        string $smtpHost,
        int $smtpPort,
        string $smtpSsl,
        string $sieveHost,
        int $sievePort,
        string $sieveSsl,
        bool $sieve,
        bool $imapAllowSelfSigned = false,
        bool $smtpAllowSelfSigned = false,
        bool $sieveAllowSelfSigned = false,
    ): array {
        $imapType = self::sslToInt($imapSsl);
        $smtpType = self::sslToInt($smtpSsl);
        $sieveType = self::sslToInt($sieveSsl);

        $oauthSasl = ['OAUTHBEARER', 'XOAUTH2'];

        return [
            'IMAP' => [
                'host' => $imapHost,
                'port' => $imapPort,
                'type' => $imapType,
                'timeout' => 300,
                'lowerLogin' => true,
                'sasl' => $oauthSasl,
                'ssl' => $this->sslConfig($imapAllowSelfSigned),
                'use_expunge_all_on_delete' => false,
                'fast_simple_search' => true,
                'force_select' => false,
                'message_all_headers' => false,
                'message_list_limit' => 10000,
                'search_filter' => '',
                'spam_headers' => 'rspamd,spamassassin,bogofilter',
                'virus_headers' => 'rspamd,clamav',
                'disabled_capabilities' => [],
            ],
            'SMTP' => [
                'host' => $smtpHost,
                'port' => $smtpPort,
                'type' => $smtpType,
                'timeout' => 60,
                'lowerLogin' => true,
                'sasl' => $oauthSasl,
                'ssl' => $this->sslConfig($smtpAllowSelfSigned),
                'useAuth' => true,
            ],
            'Sieve' => [
                'host' => $sieveHost,
                'port' => $sievePort,
                'type' => $sieveType,
                'timeout' => 10,
                'lowerLogin' => true,
                'sasl' => $oauthSasl,
                'ssl' => $this->sslConfig($sieveAllowSelfSigned),
                'enabled' => $sieve,
                'authLiteral' => true,
            ],
            'whiteList' => '',
        ];
    }
}
