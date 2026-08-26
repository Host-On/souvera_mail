<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command\Push;

use OCA\SouveraMail\Db\DeviceToken;
use OCA\SouveraMail\Db\DeviceTokenMapper;
use OCA\SouveraMail\Service\ApnsClient;
use OCA\SouveraMail\Service\FcmClient;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Operator diagnostic: send a real FCM test push to every device token
 * registered for a given Nextcloud user — used to verify the
 * service-account key, project id, and end-to-end delivery to the
 * Android app without waiting for real mail.
 */
class Test extends Command
{
    public function __construct(
        private DeviceTokenMapper $tokens,
        private FcmClient $fcm,
        private ApnsClient $apns,
        private IUserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:push:test')
            ->setDescription('Send a test FCM push to a user\'s registered devices')
            ->addArgument('uid', InputArgument::REQUIRED, 'Nextcloud user id')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Notification title', 'Souvera Mail')
            ->addOption('body', null, InputOption::VALUE_REQUIRED, 'Notification body', 'Dies ist ein Test-Push von souvera_mail:push:test.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uid = (string) $input->getArgument('uid');

        if ($this->userManager->get($uid) === null) {
            $output->writeln('<error>Unknown Nextcloud user id: ' . $uid . '</error>');
            return Command::FAILURE;
        }

        if (!$this->fcm->isConfigured() && !$this->apns->isConfigured()) {
            $output->writeln(
                '<error>Neither FCM nor APNs is configured on this instance — set '
                . FcmClient::SYSTEM_CONFIG_SERVICE_ACCOUNT . ' and/or '
                . ApnsClient::SYSTEM_CONFIG_CREDENTIALS . ' in config.php.</error>'
            );
            return Command::FAILURE;
        }

        $entities = $this->tokens->findAllForUser($uid);
        if ($entities === []) {
            $output->writeln('<comment>No registered device tokens for user "' . $uid . '".</comment>');
            return Command::FAILURE;
        }

        // Plattform-Routing wie im Produktivpfad: Android -> FCM, iOS -> APNs.
        $androidTokens = [];
        $iosTokens = [];
        foreach ($entities as $entity) {
            if ($entity->getPlatform() === DeviceToken::PLATFORM_IOS) {
                $iosTokens[] = $entity->getFcmToken();
            } else {
                $androidTokens[] = $entity->getFcmToken();
            }
        }

        $title = (string) $input->getOption('title');
        $body = (string) $input->getOption('body');

        if ($androidTokens !== [] && $this->fcm->isConfigured()) {
            $this->fcm->send($androidTokens, $title, $body, ['type' => 'test']);
            $output->writeln('<info>FCM: Sent test push to ' . \count($androidTokens) . ' device(s).</info>');
        }
        if ($iosTokens !== [] && $this->apns->isConfigured()) {
            $this->apns->send($iosTokens, $title, $body, ['type' => 'test']);
            $output->writeln('<info>APNs: Sent test push to ' . \count($iosTokens) . ' device(s).</info>');
        }
        if ($iosTokens !== [] && !$this->apns->isConfigured()) {
            $output->writeln('<error>APNs: ' . \count($iosTokens) . ' iOS device(s) registered, but APNs is not configured.</error>');
        }

        return Command::SUCCESS;
    }
}
