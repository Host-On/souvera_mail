<?php

declare(strict_types=1);

namespace OCA\SouveraMail\DevOps;

use OCA\SouveraMail\DevOps\SelfUpdateTrait;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class SelfUpdateJob extends TimedJob
{
    use SelfUpdateTrait;

    private const DEFAULT_REPO = 'PhiGi87/souvera_mail';

    protected function getAppId(): string
    {
        return 'souvera_mail';
    }

    public function __construct()
    {
        // Check every 15 minutes — cheap API call, no-op if already latest
        $this->setInterval(900);
    }

    protected function run($argument): void
    {
        try {
            $result = $this->checkAndUpdate(self::DEFAULT_REPO);
            $logger = \OCP\Server::get(LoggerInterface::class);
            if (!empty($result['success'])) {
                $logger->info('souvera_mail self-update: ' . \json_encode($result));
            }
        } catch (\Throwable) {}
    }
}
