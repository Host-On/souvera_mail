<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Repair;

use OCA\SouveraMail\DevOps\SelfUpdateTrait;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Pre-update repair step (registered under `<repair-steps><pre-update>`):
 * when the operator runs `occ app:update souvera_central`, pull the newest
 * GitHub release for ALL managed apps BEFORE the Nextcloud migration
 * machinery runs — so a plain `occ app:update souvera_central` keeps the
 * whole stack current without a manual `git pull`.
 *
 * Best-effort: any failure is logged and the regular update continues with
 * the locally installed version (the step never throws).
 */
class SelfUpdateNowStep implements IRepairStep {

    use SelfUpdateTrait;

    private string $appId = 'souvera_mail';

    public function getName(): string {
        return 'Souvera Mail: self-update all managed apps from GitHub';
    }

    protected function getAppId(): string {
        return $this->appId;
    }

    public function run(IOutput $output): void {
        $logger = \OCP\Server::get(LoggerInterface::class);
        $config = \OCP\Server::get(\OCP\IConfig::class);

        foreach (['souvera_central', 'souvera_mail', 'souvera_shield'] as $appId) {
            try {
                $this->appId = $appId;
                // The stable channel checks once per 24h inside the
                // maintenance window; an explicit `occ app:update` must
                // always check for a newer release.
                $config->setAppValue($appId, 'devops.last_check', '0');
                $result = $this->checkAndUpdate();
                $logger->info(
                    'souvera_central self-update (triggered by occ app:update): ' . json_encode($result),
                    ['app' => 'souvera_central']
                );
            } catch (\Throwable $e) {
                $logger->warning(
                    'souvera_central self-update step failed for ' . $appId . ': ' . $e->getMessage(),
                    ['app' => 'souvera_central', 'exception' => $e]
                );
            }
        }
    }

    /**
     * No subprocess during an in-flight `occ app:update`: the app is
     * already enabled, and spawning a second occ process would risk DB
     * contention on oc_appconfig. The atomic directory swap is enough.
     */
    private function enableApp(string $appId): array {
        return ['success' => true, 'occ_log' => 'skipped (in-flight occ app:update)', 'occ_exit' => 0];
    }
}
