<?php

declare(strict_types=1);

namespace OCA\SouveraMail\DevOps;

use OCA\SouveraMail\DevOps\SelfUpdateTrait;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class SelfUpdateJob extends TimedJob
{
    use SelfUpdateTrait;

    protected function getAppId(): string
    {
        return 'souvera_mail';
    }

    public function __construct()
    {
        // Run every 3 hours
        $this->setInterval(3 * 3600);
    }

    protected function run($argument): void
    {
        try {
            $result = $this->checkAndUpdate();
            $logger = \OCP\Server::get(LoggerInterface::class);
            if (!empty($result['success'])) {
                $logger->info('souvera_mail self-update: ' . \json_encode($result));
            }
        } catch (\Throwable $e) {
            // Never crash the cron — just log and retry next cycle
        }
    }
}
