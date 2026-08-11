<?php

declare(strict_types=1);

namespace OCA\SouveraMail\BackgroundJob;

use OCA\SouveraMail\Service\ExternalAccountService;
use OCA\SouveraMail\Service\ExternalImapFetchService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Periodically fetches new mail from all users' external IMAP accounts
 * and injects them into Stalwart.
 *
 * Runs every 5 minutes. Processes up to 50 messages per account per run.
 */
class ExternalImapFetchJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private ExternalAccountService $accountService,
        private ExternalImapFetchService $fetchService,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(300); // every 5 minutes
    }

    protected function run($argument): void
    {
        // Get all users who have external accounts
        $keys = \OCP\Server::get(\OCP\IAppConfig::class)->getKeys('souvera_mail');
        $prefix = 'ext_account.';
        $userIds = [];

        foreach ($keys as $key) {
            if (!\str_starts_with($key, $prefix)) continue;
            $parts = \explode('.', \substr($key, \strlen($prefix)), 2);
            if (isset($parts[0]) && !\in_array($parts[0], $userIds, true)) {
                $userIds[] = $parts[0];
            }
        }

        foreach ($userIds as $uid) {
            try {
                $accounts = $this->accountService->listForUser($uid);
                foreach ($accounts as $account) {
                    try {
                        $result = $this->fetchService->fetchForUser($uid, $account['id']);
                        if (($result['imported'] ?? 0) > 0) {
                            $this->logger->info('ExternalImapFetchJob: imported for ' . $uid, $result);
                        }
                    } catch (\Throwable $e) {
                        $this->logger->error('ExternalImapFetchJob: failed for ' . $uid, ['exception' => $e]);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('ExternalImapFetchJob: failed for user ' . $uid, ['exception' => $e]);
            }
        }
    }
}
