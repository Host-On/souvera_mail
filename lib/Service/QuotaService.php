<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Reads the current user's Stalwart mailbox disk-quota usage via a single
 * `x:Account/get` JMAP call.
 *
 * Stalwart 0.16 stores per-account quotas as a `quotas` map keyed by the
 * `StorageQuota` enum — the disk-quota slot is `MaxDiskQuota` (registry
 * crate `schema/enums.rs:StorageQuota::MaxDiskQuota`, ordinal 18). Actual
 * usage is exposed as the server-set `usedDiskQuota` property (registry
 * `schema/properties.rs:UsedDiskQuota`, id 395).
 *
 * Result is cached per-user for 60 seconds so the engine UI can render the
 * pill on every mailbox switch without hammering Stalwart.
 */
class QuotaService
{
    private const CACHE_TTL_SECONDS = 60;
    private const QUOTA_KEY_MAX_DISK = 'MaxDiskQuota';

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
                /** @var array{used: int, total: int, percentage: int, unlimited: bool, formatted: array{used: string, total: string}} */
                return $decoded;
            }
        }

        $accountId = $this->userContext->resolveAccountId($userId);
        $bearer = $this->userContext->resolveBearer($userId);

        $response = $this->stalwart->jmapCall($bearer, [
            [
                'x:Account/get',
                [
                    'accountId' => $accountId,
                    'ids' => [$accountId],
                    'properties' => ['usedDiskQuota', 'quotas'],
                ],
                'c0',
            ],
        ]);

        $body = $this->stalwart->extractMethodResponse($response, 'x:Account/get');
        $list = $body['list'] ?? [];
        if (!\is_array($list) || $list === []) {
            throw new \RuntimeException('Stalwart returned no account object for the current user');
        }
        $acc = $list[0] ?? null;
        if (!\is_array($acc)) {
            throw new \RuntimeException('Stalwart account payload is not an object');
        }

        $used = (int) ($acc['usedDiskQuota'] ?? 0);
        $quotas = $acc['quotas'] ?? [];
        // Stalwart's VecMap<StorageQuota,u64> serializes as a plain object
        // with the enum-variant string as the key (verified against
        // registry/src/schema/enums_impl.rs StorageQuota::as_str).
        $totalRaw = 0;
        if (\is_array($quotas) && isset($quotas[self::QUOTA_KEY_MAX_DISK])) {
            $totalRaw = (int) $quotas[self::QUOTA_KEY_MAX_DISK];
        }

        $unlimited = $totalRaw <= 0;
        $percentage = ($unlimited || $used <= 0) ? 0 : (int) \min(100, \floor(($used / $totalRaw) * 100));

        $result = [
            'used' => $used,
            'total' => $totalRaw,
            'percentage' => $percentage,
            'unlimited' => $unlimited,
            'formatted' => [
                'used' => $this->humanBytes($used),
                'total' => $unlimited ? '∞' : $this->humanBytes($totalRaw),
            ],
        ];

        $this->cache->set($cacheKey, (string) \json_encode($result), self::CACHE_TTL_SECONDS);
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
