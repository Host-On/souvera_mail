<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Cron;

use OCA\SouveraMail\AppInfo\Application;
use OCA\SouveraMail\Service\MailPushNotifier;
use OCA\SouveraMail\Service\StalwartAdminService;
use OCA\SouveraMail\Service\StalwartUserContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Low-frequency safety net for the event-driven Stalwart webhook
 * ({@see \OCA\SouveraMail\Controller\StalwartWebhookController}): if a
 * webhook delivery is ever lost (Stalwart misconfigured, network flap,
 * NC instance briefly down), this poller notices new mail within 5
 * minutes and raises the missed notification.
 *
 * For every member of the `souvera-users` group we fetch the JMAP
 * `Email/query` `queryState` for their Inbox (a cheap, opaque marker
 * that changes whenever the mailbox's result set changes — no message
 * bodies/subjects are ever read) and compare it against the user's
 * last known state (oc_preferences). A state change means new mail
 * arrived without a webhook → raise the notification through the
 * Nextcloud notification pipeline (notifications app → E2E-encrypted
 * push proxy push.souvera.eu → FCM/APNs → device).
 *
 * Pure safety net: the notifications themselves are content-complete
 * (subject/sender/preview) and end-to-end encrypted towards the device;
 * Google/Apple only ever see ciphertext.
 */
class MailPushPoller extends TimedJob
{
    private const INTERVAL_SECONDS = 300;

    /** Local cache namespace shared by the Souvera Mail cron jobs. */
    private const CACHE_NAME = 'souvera_mail_jobs';
    private const LOCK_KEY = 'push_poller_lock';
    private const LOCK_TTL = 600;

    /** oc_preferences app/key for the per-user JMAP query state. */
    private const STATE_APP = 'souvera_mail';
    private const STATE_KEY = 'push_last_state';

    public function __construct(
        ITimeFactory $time,
        private StalwartUserContext $userContext,
        private StalwartAdminService $stalwartAdmin,
        private \OCA\SouveraMail\Service\MailEnricherService $enricher,
        private \OCA\SouveraMail\Service\MailPushNotifier $notifier,
        private IConfig $config,
        private IGroupManager $groupManager,
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_SECONDS);
        // Safety-net job — fine to be skipped/delayed under load.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void
    {
        if (!$this->userContext->isAvailable()) {
            return; // Ohne OIDC-Setup keine JMAP-Abfragen möglich.
        }

        $cache = $this->cacheFactory->createLocal(self::CACHE_NAME);
        if ($cache->get(self::LOCK_KEY)) {
            // Vorheriger Lauf hängt noch (oder TTL läuft ohnehin über die ab).
            return;
        }
        $cache->set(self::LOCK_KEY, true, self::LOCK_TTL);
        try {
            $this->runLocked();
        } finally {
            $cache->remove(self::LOCK_KEY);
        }
    }

    private function runLocked(): void
    {
        $users = $this->sweepUsers();
        foreach ($users as $userId) {
            $snapshot = $this->resolveInboxSnapshot($userId);
            if ($snapshot === null) {
                continue;
            }
            $state = $snapshot['state'];
            $last = $this->config->getUserValue($userId, self::STATE_APP, self::STATE_KEY, '');
            if ($last === $state) {
                continue;
            }
            $isBaseline = $last === '';
            $this->config->setUserValue($userId, self::STATE_APP, self::STATE_KEY, $state);
            if ($isBaseline) {
                continue;
            }
            $details = $this->enricher->fetchDetails($userId, $snapshot['emailId']);
            $this->notifier->notify(
                $userId,
                $snapshot['emailId'],
                $details['subject'],
                $details['from'],
                $details['preview'],
            );
        }
    }

    /**
     * Users covered by the mail push: members of the `souvera-users`
     * group (the same restriction the mail app itself enforces).
     *
     * @return list<string> user ids
     */
    private function sweepUsers(): array
    {
        try {
            $group = $this->groupManager->get(Application::RESTRICTED_GROUP_ID);
        } catch (\Throwable $e) {
            $this->logger->warning('Souvera Mail: push poller could not load user group: ' . $e->getMessage(), ['app' => 'souvera_mail']);
            return [];
        }
        if ($group === null) {
            return [];
        }
        $uids = [];
        foreach ($group->getUsers() as $user) {
            $uids[] = $user->getUID();
        }
        \sort($uids);
        return $uids;
    }

    /**
     * Resolves the current Email/query `queryState` for a user's Inbox.
     * Returns null on any resolution failure — the poller simply skips
     * that user for this tick.
     */
    private function resolveInboxSnapshot(string $userId): ?array
    {
        try {
            $bearer = $this->userContext->resolveBearer($userId);
            $accountId = $this->userContext->resolveAccountId($userId);

            $mailboxResponse = $this->stalwartAdmin->jmapCall(
                $bearer,
                [
                    ['Mailbox/query', ['accountId' => $accountId, 'filter' => ['role' => 'inbox'], 'limit' => 1], 'm0'],
                ],
                ['urn:ietf:params:jmap:mail'],
            );
            $mailboxQuery = $this->stalwartAdmin->extractMethodResponse($mailboxResponse, 'Mailbox/query');
            $inboxId = $mailboxQuery['ids'][0] ?? null;
            if (!\is_string($inboxId) || $inboxId === '') {
                return null;
            }

            $emailResponse = $this->stalwartAdmin->jmapCall(
                $bearer,
                [
                    ['Email/query', [
                        'accountId' => $accountId,
                        'filter' => ['inMailbox' => $inboxId],
                        'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
                        'limit' => 1,
                    ], 'e0'],
                ],
                ['urn:ietf:params:jmap:mail'],
            );
            $emailQuery = $this->stalwartAdmin->extractMethodResponse($emailResponse, 'Email/query');
            $state = (string) ($emailQuery['queryState'] ?? '');
            if ($state === '') {
                return null;
            }
            $ids = $emailQuery['ids'] ?? [];
            $emailId = \is_array($ids) && isset($ids[0]) ? (string) $ids[0] : '';
            return ['state' => $state, 'emailId' => $emailId];
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Souvera Mail: MailPushPoller could not resolve inbox state for user "' . $userId . '": '
                . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            return null;
        }
    }
}
