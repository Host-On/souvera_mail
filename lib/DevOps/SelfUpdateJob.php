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
        $this->setInterval(900);
    }

    protected function run($argument): void
    {
        try {
            $result = $this->checkAndUpdate();
            if (!empty($result['success'])) {
                \OCP\Server::get(LoggerInterface::class)->info('souvera_mail self-update: ' . \json_encode($result));
            }
        } catch (\Throwable $e) {
            // Silently retry next cycle
        }
    }
}
