<?php

declare(strict_types=1);

namespace OCA\SouveraMail\DevOps;

use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class SelfUpdateJob extends TimedJob
{
    use SelfUpdateTrait;

    public function __construct()
    {
        $this->setInterval(900);
    }

    protected function getAppId(): string
    {
        return 'souvera_mail';
    }

    protected function run($argument): void
    {
        try {
            $result = $this->checkAndUpdate();
            if (!empty($result['success'])) {
                \OCP\Server::get(LoggerInterface::class)->info(
                    'souvera_mail self-update: ' . json_encode($result)
                );
            } elseif (!empty($result['error'])) {
                \OCP\Server::get(LoggerInterface::class)->warning(
                    'souvera_mail self-update error: ' . json_encode($result)
                );
            }
        } catch (\Throwable $e) {
            \OCP\Server::get(LoggerInterface::class)->error(
                'souvera_mail self-update exception: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
