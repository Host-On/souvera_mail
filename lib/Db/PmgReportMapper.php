<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<PmgReport>
 */
class PmgReportMapper extends QBMapper
{
    public const TABLE = 'souvera_mail_pmg_reports';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, self::TABLE, PmgReport::class);
    }

    /**
     * The most recent report of a user for one mail (by message id hash).
     */
    public function findLatestByHash(string $userId, string $messageIdHash): ?PmgReport
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('message_id_hash', $qb->createNamedParameter($messageIdHash)))
            ->orderBy('reported_at', 'DESC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Replace (upsert) the user's report for one message: the latest action
     * wins (spam report → ham report replaces it, etc.).
     */
    public function upsert(PmgReport $report): PmgReport
    {
        $existing = $this->findLatestByHash($report->getUserId(), $report->getMessageIdHash());
        if ($existing !== null) {
            $report->setId($existing->getId());
            return $this->update($report);
        }
        return $this->insert($report);
    }

    public function deleteByUserAndHash(string $userId, string $messageIdHash): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('message_id_hash', $qb->createNamedParameter($messageIdHash)));
        $qb->executeStatement();
    }

    /**
     * The user's last reports, newest first.
     *
     * @return PmgReport[]
     */
    public function findByUser(string $userId, int $limit = 50): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('reported_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }
}
