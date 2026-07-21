<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCA\SouveraMail\Db\DeviceToken;
use OCA\SouveraMail\Db\DeviceTokenMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Business logic behind the `/devices` REST endpoints — registering and
 * unregistering the FCM tokens the Android client uses to receive push
 * notifications for new mail (see {@see FcmClient}).
 */
class DeviceTokenService
{
    /** @var list<string> */
    private const ALLOWED_PLATFORMS = ['android', 'ios'];

    public function __construct(
        private DeviceTokenMapper $tokens,
        private ITimeFactory $time,
    ) {
    }

    /**
     * @return array{id: int}
     */
    public function register(string $userId, string $fcmToken, string $platform): array
    {
        $fcmToken = \trim($fcmToken);
        if ($fcmToken === '') {
            throw new \InvalidArgumentException('fcmToken must not be empty');
        }
        if (\strlen($fcmToken) > 512) {
            throw new \InvalidArgumentException('fcmToken exceeds 512 characters');
        }

        $platform = \strtolower(\trim($platform)) ?: DeviceToken::PLATFORM_ANDROID;
        if (!\in_array($platform, self::ALLOWED_PLATFORMS, true)) {
            $platform = DeviceToken::PLATFORM_ANDROID;
        }

        $entity = $this->tokens->upsertByToken($userId, $fcmToken, $platform, $this->time->getTime());
        return ['id' => (int) $entity->getId()];
    }

    public function unregister(string $userId, int $id): void
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('id must be a positive integer');
        }
        try {
            $entity = $this->tokens->findByIdForUser($userId, $id);
        } catch (DoesNotExistException $e) {
            throw new \InvalidArgumentException('Unknown device token id: ' . $id, 0, $e);
        }
        $this->tokens->delete($entity);
    }

    /**
     * @return list<array{id: int, platform: string, createdAt: int, lastSeenAt: int}>
     */
    public function listForUser(string $userId): array
    {
        $items = [];
        foreach ($this->tokens->findAllForUser($userId) as $entity) {
            $items[] = [
                'id' => (int) $entity->getId(),
                'platform' => $entity->getPlatform(),
                'createdAt' => $entity->getCreatedAt(),
                'lastSeenAt' => $entity->getLastSeenAt(),
            ];
        }
        return $items;
    }
}
