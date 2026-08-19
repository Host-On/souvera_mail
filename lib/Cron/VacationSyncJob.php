<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Cron;

use OCA\SouveraMail\Service\VacationSyncService;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
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
    public function __construct(
        ITimeFactory $time,
        private IConfig $config,
        private VacationSyncService $syncService,
    ) {
        parent::__construct($time);
        $this->setInterval(3600);
    }

    protected function run($argument): void
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
