<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * CRUD for registered FCM device tokens.
 *
 * @template-extends QBMapper<DeviceToken>
 */
class DeviceTokenMapper extends QBMapper
{
    public const TABLE = 'souvera_mail_devicetoken';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, self::TABLE, DeviceToken::class);
    }

    /**
     * @return list<DeviceToken>
     */
    public function findAllForUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByToken(string $fcmToken): DeviceToken
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('fcm_token', $qb->createNamedParameter($fcmToken)))
            ->setMaxResults(1);
        return $this->findEntity($qb);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByIdForUser(string $userId, int $id): DeviceToken
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->setMaxResults(1);
        return $this->findEntity($qb);
    }

    /**
     * Paginated sweep over every registered token, ordered by id — used by
     * {@see \OCA\SouveraMail\Cron\MailPushPoller} to page through the whole
     * table in bounded batches.
     *
     * @return list<DeviceToken>
     */
    public function findAllTokens(int $batchSize, int $offset): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->orderBy('id', 'ASC')
            ->setMaxResults($batchSize)
            ->setFirstResult($offset);
        return $this->findEntities($qb);
    }

    /**
     * Insert-or-refresh by token: if the token already exists (re-registration
     * from the same device, or the device switched Nextcloud accounts), the
     * row is reassigned to the current user and `last_seen_at` is bumped.
     * Otherwise a fresh row is inserted.
     */
    public function upsertByToken(string $userId, string $fcmToken, string $platform, int $now): DeviceToken
    {
        try {
            $entity = $this->findByToken($fcmToken);
            $entity->setUserId($userId);
            $entity->setPlatform($platform);
            $entity->setLastSeenAt($now);
            return $this->update($entity);
        } catch (DoesNotExistException) {
            $entity = new DeviceToken();
            $entity->setUserId($userId);
            $entity->setFcmToken($fcmToken);
            $entity->setPlatform($platform);
            $entity->setCreatedAt($now);
            $entity->setLastSeenAt($now);
            try {
                return $this->insert($entity);
            } catch (\OCP\DB\Exception $e) {
                // Lost a race with a concurrent registration of the SAME
                // brand-new token (e.g. app cold-start + background
                // refresh firing almost simultaneously) — the other
                // request's insert already won on `sm_devtok_token_uniq`.
                // Fall back to updating that row instead of surfacing a
                // spurious failure for what is really an idempotent call.
                $existing = $this->findByToken($fcmToken);
                $existing->setUserId($userId);
                $existing->setPlatform($platform);
                $existing->setLastSeenAt($now);
                return $this->update($existing);
            }
        }
    }

    /**
     * Best-effort cleanup called by {@see \OCA\SouveraMail\Service\FcmClient}
     * when Google reports a token as unregistered/invalid. Silently no-ops
     * if the token is already gone.
     */
    public function deleteByToken(string $fcmToken): void
    {
        try {
            $this->delete($this->findByToken($fcmToken));
        } catch (DoesNotExistException) {
            // Already gone — nothing to clean up.
        }
    }
}
