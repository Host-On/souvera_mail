<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Run on app enable and after upgrade (v2 client).
 *
 * The legacy SnappyMail engine is gone — there is no engine data tree,
 * no engine config and no plugin sync to maintain anymore. What remains
 * is clearing PHP caches so a freshly swapped app tree is picked up
 * immediately.
 */
class InstallStep implements IRepairStep
{
    public function getName()
    {
        return 'Setup Souvera Mail';
    }

    public function run(IOutput $output): void
    {
        $output->info('clearstatcache');
        \clearstatcache();
        \clearstatcache(true);
        $output->info('opcache_reset');
        if (\function_exists('opcache_reset')) {
            @\opcache_reset();
        }
    }
}
