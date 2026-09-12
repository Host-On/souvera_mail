<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Cron;

use OCA\SouveraMail\Service\VacationSyncService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Hourly background sync of the NC out-of-office data into the Sieve
 * auto-responder. Only users with the sync preference enabled are visited
 * (IConfig::getUsersForUserValue — no full user iteration).
 *
 * This job is what turns the responder OFF after the absence window ends,
 * even when the user never opens the mail app during their absence.
 */
class VacationSyncJob extends TimedJob
{
    /** Local cache namespace shared by the Souvera Mail cron jobs. */
    private const CACHE_NAME = 'souvera_mail_jobs';
    private const LOCK_KEY = 'vacation_sync_lock';
    private const LOCK_TTL = 900;

    public function __construct(
        ITimeFactory $time,
        private IConfig $config,
        private VacationSyncService $syncService,
        private ICacheFactory $cacheFactory,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
    }

    protected function run($argument): void
    {
        // Job-Lock: parallele Cron-Läufe verhindern. Läuft bereits ein
        // Sync (get() liefert einen Wert), kehren wir sofort zurück. Ist
        // der Cache nicht verfügbar, läuft der Job ohne Lock weiter.
        $cache = null;
        try {
            $cache = $this->cacheFactory->createLocal(self::CACHE_NAME);
            if ($cache->get(self::LOCK_KEY) !== null) {
                return;
            }
            $cache->set(self::LOCK_KEY, '1', self::LOCK_TTL);
        } catch (\Throwable $e) {
            \OCP\Server::get(LoggerInterface::class)->debug(
                'Souvera Mail: VacationSyncJob lock unavailable — running without lock: ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            $cache = null;
        }
        try {
            $this->runLocked();
        } finally {
            if ($cache !== null) {
                try {
                    $cache->remove(self::LOCK_KEY);
                } catch (\Throwable $e) {
                    // Lock läuft ohnehin über die TTL ab.
                }
            }
        }
    }

    private function runLocked(): void
    {
        if (!$this->syncService->isSupported()) {
            return;
        }
        $uids = $this->config->getUsersForUserValue('souvera_mail', 'pref_vacation_sync', '1');
        foreach ($uids as $uid) {
            try {
                $this->syncService->syncNow($uid);
            } catch (\Throwable $e) {
                \OCP\Server::get(LoggerInterface::class)->warning(
                    'VacationSyncJob failed for ' . $uid . ': ' . $e->getMessage(),
                    ['app' => 'souvera_mail']
                );
            }
        }
    }
}
