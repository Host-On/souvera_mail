<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Resolves BIMI (Brand Indicators for Message Identification) logos
 * for email sender domains via DNS TXT record lookup.
 *
 * Cache TTL: 7 days for successful lookups, 1 day for failures.
 */
class BimiService
{
    private ICache $cache;
    private const CACHE_TTL_HIT = 7 * 24 * 3600;
    private const CACHE_TTL_MISS = 24 * 3600;
    private const BIMI_SELECTOR = 'default._bimi';

    public function __construct(
        ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
        $this->cache = $cacheFactory->createDistributed('souvera_mail_bimi');
    }

    /**
     * Resolve BIMI logo for an email address or domain.
     *
     * @return array{logoUrl: string|null, verified: bool, domain: string}
     */
    public function resolve(string $emailOrDomain): array
    {
        $domain = $this->extractDomain($emailOrDomain);
        if ($domain === '') {
            return ['logoUrl' => null, 'verified' => false, 'domain' => ''];
        }

        $cacheKey = 'bimi_' . \sha1($domain);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $data = \json_decode($cached, true);
            if (\is_array($data)) {
                // Sanitize legacy cache entries that may contain http:// URLs
                $logoUrl = $data['logoUrl'] ?? null;
                if ($logoUrl !== null && !$this->isAllowedLogoUrl($logoUrl)) {
                    $data['logoUrl'] = null;
                    $data['verified'] = false;
                }
                return $data + ['domain' => $domain];
            }
        }

        $result = $this->lookupBimi($domain);
        $ttl = ($result['logoUrl'] !== null) ? self::CACHE_TTL_HIT : self::CACHE_TTL_MISS;
        $this->cache->set($cacheKey, \json_encode($result), $ttl);

        return $result + ['domain' => $domain];
    }

    private function lookupBimi(string $domain): array
    {
        $hostname = self::BIMI_SELECTOR . '.' . $domain;

        try {
            $records = \dns_get_record($hostname, \DNS_TXT);
        } catch (\Throwable $e) {
            $this->logger->debug('BIMI DNS lookup failed for ' . $hostname . ': ' . $e->getMessage());
            return ['logoUrl' => null, 'verified' => false];
        }

        if ($records === false || $records === []) {
            return ['logoUrl' => null, 'verified' => false];
        }

        $bimiData = $this->parseBimiRecord($records);
        if ($bimiData === null) {
            return ['logoUrl' => null, 'verified' => false];
        }

        // Verify not a placeholder/common default
        if ($this->isDefaultLogo($bimiData['logo'])) {
            return ['logoUrl' => null, 'verified' => false];
        }

        return [
            'logoUrl' => $this->convertSvgToPng($bimiData['logo']),
            'verified' => \in_array('authority', $bimiData['tags'], true),
        ];
    }

    /**
     * @param array<int,array> $records
     * @return array{logo:string, tags:string[]}|null
     */
    private function parseBimiRecord(array $records): ?array
    {
        foreach ($records as $record) {
            $txt = $record['txt'] ?? '';
            if ($txt === '') continue;

            // BIMI TXT records: v=BIMI1; l=https://...; a=...;
            $parts = \array_map('trim', \explode(';', $txt));
            $data = [];

            foreach ($parts as $part) {
                if ($part === '' || $part === 'v=BIMI1') continue;
                $kv = \explode('=', $part, 2);
                if (\count($kv) === 2) {
                    $data[\trim($kv[0])] = \trim($kv[1]);
                }
            }

            if (isset($data['l']) && $this->isAllowedLogoUrl($data['l'])) {
                $tags = isset($data['a']) ? \explode(',', $data['a']) : [];
                return [
                    'logo' => $data['l'],
                    'tags' => \array_map('trim', $tags),
                ];
            }
        }

        return null;
    }

    private function isDefaultLogo(string $url): bool
    {
        $host = \parse_url($url, \PHP_URL_HOST) ?? '';
        return \in_array($host, ['default._bimi', 'bimi.entrust.net', 'bimi.digicert.com'], true);
    }

    private function isAllowedLogoUrl(string $url): bool
    {
        return \filter_var($url, \FILTER_VALIDATE_URL) !== false
            && \str_starts_with(\strtolower($url), 'https://');
    }

    private function convertSvgToPng(string $url): string
    {
        $ext = \strtolower(\pathinfo(\parse_url($url, \PHP_URL_PATH) ?? '', \PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            return $url . '#svg-viewbox';
        }
        return $url;
    }

    private function extractDomain(string $emailOrDomain): string
    {
        if (\filter_var($emailOrDomain, \FILTER_VALIDATE_EMAIL)) {
            $parts = \explode('@', $emailOrDomain);
            return \strtolower(\end($parts));
        }
        if (\str_contains($emailOrDomain, '.')) {
            return \strtolower(\trim($emailOrDomain));
        }
        return '';
    }
}
