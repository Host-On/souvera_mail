<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Cron;

use OCA\SouveraMail\Db\MigrationJob;
use OCA\SouveraMail\Service\MigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Nightly janitor for the migration table.
 *
 * Two responsibilities:
 *
 *   1. Belt-and-suspenders app-password revocation. `refreshFromProvider`
 *      already revokes the Stalwart app-password when a job goes to
 *      completed/failed — but if THAT revoke call itself flaked out
 *      (network hiccup, Stalwart momentarily down), the app-password
 *      would live forever. This cron catches such orphans by scanning
 *      terminal rows that STILL have a non-null stalwart_app_id and
 *      retries the revoke.
 *
 *   2. Purge terminal-and-dismissed rows older than 30 days. The audit
 *      history stays useful for admins for ~ a month; after that the
 *      table would grow without bound. `dismissed` rows are the
 *      user-visible tombstones, so those go first; `completed` /
 *      `failed` rows are preserved a bit longer since the user might
 *      not have closed the wizard yet.
 *
 * Runs once a day via NC's background-job scheduler.
 */
class MigrationCleanup extends TimedJob
{
    /** 24h between cleanup ticks. */
    private const INTERVAL_SECONDS = 86400;

    /** Terminal rows older than 30 days get deleted. */
    private const DELETE_AFTER_SECONDS = 30 * 86400;

    /** Max rows touched per tick — bounds wall time. */
    private const BATCH_SIZE = 200;

    public function __construct(
        ITimeFactory $time,
        private MigrationService $migrations,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_SECONDS);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void
    {
        $now = $this->time->getTime();

        // ── Phase 1: revoke orphan app-passwords on terminal rows. ────
        // We consider a row an "orphan" if it's terminal but still has
        // a stalwart_app_id set. Normal flow blanks that field the
        // instant revoke succeeds (see MigrationService::refreshFromProvider).
        // Look-back window covers 7 days so we don't hammer stale
        // Stalwarts about ancient rows on every tick.
        $lookback = $now - (7 * 86400);
        $orphans = $this->migrations->findStaleTerminalJobs($now, self::BATCH_SIZE);
        $revoked = 0;
        foreach ($orphans as $row) {
            if ($row->getStalwartAppId() === null || $row->getStalwartAppId() === '') {
                continue; // Already clean.
            }
            if ($row->getUpdatedAt() < $lookback) {
                // Older than 7d, don't bother retrying — the mailbox
                // is likely gone anyway.
                continue;
            }
            $this->migrations->forceRevokeOrphan($row);
            $revoked++;
        }

        // ── Phase 2: metrics. ────────────────────────────────────────
        // Row purging itself is deferred to a future release once we
        // have consensus on retention (SEG likes to keep audit trails
        // for regulator responses). For now we just log.
        if ($revoked > 0) {
            $this->logger->info(
                "Souvera Mail: MigrationCleanup revoked {$revoked} orphan app-password(s)",
                ['app' => 'souvera_mail']
            );
        }
    }
}
