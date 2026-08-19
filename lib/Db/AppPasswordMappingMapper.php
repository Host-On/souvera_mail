<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * CRUD for the AppPassword combined-credential mapping.
 *
 * @template-extends QBMapper<AppPasswordMapping>
 */
class AppPasswordMappingMapper extends QBMapper
{
    public const TABLE = 'souvera_mail_apppwd';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, self::TABLE, AppPasswordMapping::class);
    }

    /**
     * @return list<AppPasswordMapping>
     */
    public function findAllForUser(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $this->findEntities($qb);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByStalwartId(string $userId, string $stalwartAppId): AppPasswordMapping
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('stalwart_app_id', $qb->createNamedParameter($stalwartAppId)));
        return $this->findEntity($qb);
    }

    /**
     * Delete all mappings for a user (used by unregister-user flows).
     */
    public function deleteAllForUser(string $userId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->executeStatement();
    }
}
