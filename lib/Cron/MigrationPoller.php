<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Cron;

use OCA\SouveraMail\Service\MigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Refreshes every ACTIVE migration job from provider.tools every 60s.
 *
 * Rationale: the frontend polls the LOCAL /status endpoint (fast, no
 * upstream rate-limit exposure to the browser). This cron does the
 * actual "phone home" call to provider.tools once per minute per job,
 * caching the result in `progress_json` for the next frontend poll.
 *
 * Why 60s?
 *   - provider.tools' Starter plan is 1000 calls/month → 1/min/job is
 *     ~ 44k/month per active job; still comfortably inside a Business
 *     plan (100k/mo) even with dozens of concurrent migrations.
 *   - IMAP migrations copy MBs per second at best — polling faster
 *     yields no UX benefit; slower feels sluggish.
 *
 * Scoping: only ACTIVE rows (pending/running) are touched. Terminal
 * rows never get polled again. Row count per tick is capped at 50 to
 * bound wall time even under load.
 */
class MigrationPoller extends TimedJob
{
    /** Poll interval in seconds. */
    private const INTERVAL_SECONDS = 60;

    /** Max jobs touched per tick — bounds wall time on busy instances. */
    private const BATCH_SIZE = 50;

    public function __construct(
        ITimeFactory $time,
        private MigrationService $migrations,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_SECONDS);
        // NC 33+: run as soon as the interval elapses, don't skip when
        // Nextcloud is under load. Migrations are user-facing.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void
    {
        $rows = $this->migrations->findAllActive(self::BATCH_SIZE);
        if ($rows === []) {
            return; // Fast path — no active jobs at all.
        }
        $refreshed = 0;
        foreach ($rows as $job) {
            try {
                $this->migrations->refreshFromProvider($job);
                $refreshed++;
            } catch (\Throwable $e) {
                // refreshFromProvider itself already logs at info level
                // for transient upstream flakes, so we log HERE only for
                // outright bugs (unhandled types, etc.).
                $this->logger->warning(
                    'Souvera Mail: migration poll bug on jobId=' . $job->getId()
                    . ': ' . $e->getMessage(),
                    ['app' => 'souvera_mail', 'exception' => $e]
                );
            }
        }
        if ($refreshed > 0) {
            $this->logger->debug(
                "Souvera Mail: MigrationPoller refreshed {$refreshed}/" . \count($rows) . ' active jobs',
                ['app' => 'souvera_mail']
            );
        }
    }
}
