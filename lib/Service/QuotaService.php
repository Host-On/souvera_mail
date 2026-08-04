<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Reads the current user's Stalwart mailbox disk-quota usage via the
 * standard JMAP `Quota/get` method (RFC 9425 "JMAP Quotas", capability
 * `urn:ietf:params:jmap:quota`, permission `jmapQuotaGet`).
 *
 * Stalwart implements `Quota/get` as a user-scoped method (unlike the
 * admin-only `x:Account/get` registry object), so a plain OIDC user bearer
 * is sufficient. Verified against upstream crates/jmap/src/quota/get.rs:
 * the quota object carries `resourceType` ("octets"), `used`
 * (`get_used_quota_account`) and `hardLimit` (`account.disk_quota()`);
 * Stalwart only returns a quota object when the account has a disk quota
 * configured (`account.disk_quota() > 0`), otherwise the list is empty —
 * which we map to "unlimited" (with `usageKnown = false`, because a missing
 * quota object carries no usage information at all).
 *
 * Result is cached per-user for 60 seconds so the engine UI can render the
 * pill on every mailbox switch without hammering Stalwart.
 */
class QuotaService
{
    private const CACHE_TTL_SECONDS = 60;
    private const CAPABILITY_QUOTA = 'urn:ietf:params:jmap:quota';
    private const CAPABILITY_MAIL = 'urn:ietf:params:jmap:mail';

    private ICache $cache;

    public function __construct(
        private StalwartAdminService $stalwart,
        private StalwartUserContext $userContext,
        ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
        $this->cache = $cacheFactory->createDistributed('souvera_mail/quota');
    }

    public function isAvailable(): bool
    {
        return $this->stalwart->isConfigured() && $this->userContext->isAvailable();
    }

    /**
     * @return array{
     *     used: int,
     *     total: int,
     *     percentage: int,
     *     unlimited: bool,
     *     usageKnown: bool,
     *     formatted: array{used: string, total: string}
     * }
     */
    public function getForUser(string $userId): array
    {
        $cacheKey = 'q.' . \sha1($userId);
        $cached = $this->cache->get($cacheKey);
        if (\is_string($cached)) {
            $decoded = \json_decode($cached, true);
            if (\is_array($decoded)) {
                /** @var array{used: int, total: int, percentage: int, unlimited: bool, usageKnown: bool, formatted: array{used: string, total: string}} */
                return $decoded;
            }
        }

        $accountId = $this->userContext->resolveAccountId($userId);
        $bearer = $this->userContext->resolveBearer($userId);

        $response = $this->stalwart->jmapCall($bearer, [
            [
                'Quota/get',
                [
                    'accountId' => $accountId,
                    'ids' => null,
                    'properties' => ['resourceType', 'used', 'hardLimit', 'softLimit', 'scope'],
                ],
                'c0',
            ],
        ], [self::CAPABILITY_QUOTA, self::CAPABILITY_MAIL]);

        $body = $this->stalwart->extractMethodResponse($response, 'Quota/get');
        $list = $body['list'] ?? null;
        if (!\is_array($list)) {
            throw new \RuntimeException('Stalwart returned an invalid Quota/get payload');
        }
        if ($list === []) {
            // Stalwart returns no quota object when the account has no disk
            // quota configured — treat that as unlimited. There is no usage
            // information in this case, so do not claim "0 B used".
            return $this->store($userId, 0, 0, true);
        }
        // RFC 9425 allows multiple quota objects in unspecified order —
        // pick the account-wide octets quota.
        $quota = null;
        foreach ($list as $candidate) {
            if (\is_array($candidate)
                && ($candidate['resourceType'] ?? '') === 'octets'
                && ($candidate['scope'] ?? '') === 'account') {
                $quota = $candidate;
                break;
            }
        }
        if ($quota === null) {
            throw new \RuntimeException('Stalwart returned no account-wide octets quota');
        }

        $used = (int) ($quota['used'] ?? 0);
        $totalRaw = (int) ($quota['hardLimit'] ?? $quota['softLimit'] ?? 0);
        $unlimited = $totalRaw <= 0;
        $percentage = ($unlimited || $used <= 0) ? 0 : (int) \min(100, \floor(($used / $totalRaw) * 100));

        return $this->store($userId, $used, $totalRaw, $unlimited, $percentage);
    }

    private function store(string $userId, int $used, int $total, bool $unlimited, ?int $percentage = null): array
    {
        if ($percentage === null) {
            $percentage = ($unlimited || $used <= 0) ? 0 : (int) \min(100, \floor(($used / $total) * 100));
        }
        $result = [
            'used' => $used,
            'total' => $total,
            'percentage' => $percentage,
            'unlimited' => $unlimited,
            'usageKnown' => !$unlimited,
            'formatted' => [
                'used' => $this->humanBytes($used),
                'total' => $unlimited ? '∞' : $this->humanBytes($total),
            ],
        ];

        $this->cache->set('q.' . \sha1($userId), (string) \json_encode($result), self::CACHE_TTL_SECONDS);
        return $result;
    }

    public function invalidate(string $userId): void
    {
        $this->cache->remove('q.' . \sha1($userId));
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = (int) \min(\count($units) - 1, \floor(\log($bytes, 1024)));
        $val = $bytes / (1024 ** $i);
        $precision = $val >= 100 || $i === 0 ? 0 : ($val >= 10 ? 1 : 2);
        return \number_format($val, $precision) . ' ' . $units[$i];
    }
}
