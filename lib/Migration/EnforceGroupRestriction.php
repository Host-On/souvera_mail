<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Migration;

use OCA\SouveraMail\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Ensures Souvera Mail is restricted to the `souvera-users` group.
 *
 * Runs on every `occ app:enable souvera_mail` and every `occ upgrade`.
 * Idempotent — re-running does not change anything once the desired state
 * is in place. Always converges towards the same state:
 *
 *   1. The Nextcloud group `souvera-users` exists.
 *   2. The `souvera_mail` app is enabled only for members of that group.
 *
 * Product policy is explicit: only members of `souvera-users` may see or
 * open the app. Manual `occ app:enable souvera_mail --groups …` deviations
 * are reset on the next upgrade — admins who need a different group set
 * should change {@see Application::RESTRICTED_GROUP_ID} and rebuild the
 * app instead of editing app-config out-of-band.
 */
class EnforceGroupRestriction implements IRepairStep
{
    public function __construct(
        private IAppManager $appManager,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'Restrict Souvera Mail to the souvera-users group';
    }

    public function run(IOutput $output): void
    {
        $gid = Application::RESTRICTED_GROUP_ID;
        $group = $this->ensureGroup($output, $gid);

        $output->info('Binding ' . Application::APP_ID . ' to group ' . $gid);
        try {
            $this->appManager->enableAppForGroups(Application::APP_ID, [$group]);
        } catch (\Throwable $e) {
            $output->warning(
                'Failed to bind ' . Application::APP_ID . ' to ' . $gid
                . ': ' . $e->getMessage()
                . ' — run `occ app:enable ' . Application::APP_ID . ' --groups ' . $gid . '` manually'
            );
            $this->logger->warning(
                'Souvera Mail group-restriction binding failed: ' . $e->getMessage(),
                ['app' => Application::APP_ID, 'exception' => $e]
            );
            return;
        }
        $output->info('App is now restricted to ' . $gid);
    }

    private function ensureGroup(IOutput $output, string $gid): IGroup
    {
        if ($this->groupManager->groupExists($gid)) {
            $existing = $this->groupManager->get($gid);
            if ($existing !== null) {
                $output->info('Group ' . $gid . ' already exists');
                return $existing;
            }
        }

        $output->info('Creating group ' . $gid);
        $group = $this->groupManager->createGroup($gid);
        if ($group === null) {
            throw new \RuntimeException(
                'Nextcloud refused to create the group "' . $gid . '" (read-only LDAP backend? '
                . 'Group manager misconfigured?). Souvera Mail must be group-restricted to comply '
                . 'with product policy — please create the group manually via `occ group:add ' . $gid . '` '
                . 'and re-run `occ app:enable ' . Application::APP_ID . '`.'
            );
        }
        return $group;
    }
}
