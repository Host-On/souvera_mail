<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * CRUD + query helpers for the IMAP-migration job table.
 *
 * @template-extends QBMapper<MigrationJob>
 */
class MigrationJobMapper extends QBMapper
{
    public const TABLE = 'souvera_migrations';

    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, self::TABLE, MigrationJob::class);
    }

    /**
     * Look up a single row by its primary key.
     *
     * Nextcloud's `QBMapper` intentionally dropped the id-lookup helper
     * that the older deprecated `Mapper` class exposed as `find($id)`,
     * so we re-add a minimal one here — dismissJobForUser() and
     * cancelJobForUser() in MigrationService rely on it. Ownership
     * scoping happens in the service layer (comparing
     * `$job->getUserId()` against the current uid), NOT here — the
     * mapper is dumb by design so admin-only cleanup paths can still
     * fetch any row.
     *
     * @throws DoesNotExistException  no row with that id
     */
    public function find(int $id): MigrationJob
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        return $this->findEntity($qb);
    }

    /**
     * Latest migration row for a user, regardless of status. Used to
     * decide whether the wizard opens on the welcome-screen (no history)
     * or jumps directly to the progress-screen (there's an active job).
     *
     * @throws DoesNotExistException
     */
    public function findLatestForUser(string $userId): MigrationJob
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(1);
        return $this->findEntity($qb);
    }

    /**
     * Currently-active migration for a user (pending or running). Used
     * to enforce the "max one concurrent migration per user" rate limit.
     *
     * @throws DoesNotExistException
     */
    public function findActiveForUser(string $userId): MigrationJob
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in(
                'status',
                $qb->createNamedParameter(
                    MigrationJob::ACTIVE_STATUSES,
                    IQueryBuilder::PARAM_STR_ARRAY
                )
            ))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults(1);
        return $this->findEntity($qb);
    }

    /**
     * All active jobs across all users, ordered by oldest updated_at
     * first. Used by MigrationPoller so we refresh stale rows first.
     *
     * @return list<MigrationJob>
     */
    public function findAllActive(int $limit = 50): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->in(
                'status',
                $qb->createNamedParameter(
                    MigrationJob::ACTIVE_STATUSES,
                    IQueryBuilder::PARAM_STR_ARRAY
                )
            ))
            ->orderBy('updated_at', 'ASC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }

    /**
     * Terminal jobs older than $olderThan seconds (unix ts). Used by
     * MigrationCleanup to purge history rows the user has already
     * dismissed OR that finished long ago.
     *
     * @return list<MigrationJob>
     */
    public function findStaleTerminalJobs(int $olderThan, int $limit = 200): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->in(
                'status',
                $qb->createNamedParameter(
                    MigrationJob::TERMINAL_STATUSES,
                    IQueryBuilder::PARAM_STR_ARRAY
                )
            ))
            ->andWhere($qb->expr()->lt(
                'updated_at',
                $qb->createNamedParameter($olderThan, IQueryBuilder::PARAM_INT)
            ))
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }
}
