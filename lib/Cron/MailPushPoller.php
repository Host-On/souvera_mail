<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Cron;

use OCA\SouveraMail\Db\DeviceToken;
use OCA\SouveraMail\Db\DeviceTokenMapper;
use OCA\SouveraMail\Service\FcmClient;
use OCA\SouveraMail\Service\StalwartAdminService;
use OCA\SouveraMail\Service\StalwartUserContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Low-frequency safety net for the event-driven Stalwart webhook
 * ({@see \OCA\SouveraMail\Controller\StalwartWebhookController}): if a
 * webhook delivery is ever lost (Stalwart misconfigured, network flap,
 * NC instance briefly down), this poller notices new mail within 5
 * minutes and sends the missed push.
 *
 * For each user with at least one registered device token, we fetch the
 * JMAP `Email/query` `queryState` for their Inbox (a cheap, opaque
 * marker that changes whenever the mailbox's result set changes — no
 * message bodies/subjects are ever read) and compare it against the
 * `last_push_state` cached on each device-token row. A state change
 * triggers exactly one push per differing token; the very first
 * observation of a token establishes a baseline without pushing (so
 * enabling this job never fires a backlog of pushes for old mail).
 *
 * Reuses the EXISTING JMAP machinery ({@see StalwartAdminService},
 * {@see StalwartUserContext}) — no Stalwart-side change required.
 */
class MailPushPoller extends TimedJob
{
    /** Poll interval in seconds (5 minutes). */
    private const INTERVAL_SECONDS = 300;

    /** Page size when sweeping `oc_souvera_mail_devicetoken`. */
    private const BATCH_SIZE = 200;

    /** Hard cap on tokens touched per tick — bounds wall time. */
    private const MAX_TOKENS_PER_TICK = 2000;

    public function __construct(
        ITimeFactory $time,
        private DeviceTokenMapper $tokens,
        private StalwartUserContext $userContext,
        private StalwartAdminService $stalwartAdmin,
        private FcmClient $fcm,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(self::INTERVAL_SECONDS);
        // Safety-net job — fine to be skipped/delayed under load.
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function run($argument): void
    {
        if (!$this->userContext->isAvailable() || !$this->fcm->isConfigured()) {
            return; // Nothing useful to do without OIDC or FCM configured.
        }

        $byUser = $this->sweepTokensByUser();
        if ($byUser === []) {
            return;
        }

        foreach ($byUser as $userId => $userTokens) {
            $snapshot = $this->resolveInboxSnapshot($userId);
            if ($snapshot === null) {
                continue;
            }
            $state = $snapshot['state'];
            $details = null; // lazy: erst holen, wenn wirklich ein Push rausgeht
            foreach ($userTokens as $token) {
                if ($token->getLastPushState() === $state) {
                    continue;
                }
                $isBaseline = $token->getLastPushState() === null;
                $token->setLastPushState($state);
                try {
                    $this->tokens->update($token);
                } catch (\Throwable $e) {
                    $this->logger->warning(
                        'Souvera Mail: MailPushPoller failed to persist last_push_state for token id='
                        . $token->getId() . ': ' . $e->getMessage(),
                        ['app' => 'souvera_mail', 'exception' => $e]
                    );
                    continue;
                }
                if ($isBaseline) {
                    continue;
                }
                if ($details === null) {
                    $details = $this->fetchEmailDetails($userId, $snapshot['emailId']);
                }
                $data = [
                    'type' => 'new_mail',
                    'emailId' => $snapshot['emailId'],
                    'mailboxPath' => 'INBOX',
                    'subject' => $details['subject'],
                    'sender' => $details['from'],
                ];
                $body = $details['subject'] !== ''
                    ? $details['subject']
                    : 'Du hast eine neue Nachricht erhalten.';
                $this->fcm->send(
                    [$token->getFcmToken()],
                    'Neue E-Mail',
                    $body,
                    $data,
                );
            }
        }
    }

    /**
     * @return array<string, list<DeviceToken>>
     */
    private function sweepTokensByUser(): array
    {
        $byUser = [];
        $offset = 0;
        $seen = 0;
        while ($seen < self::MAX_TOKENS_PER_TICK) {
            $rows = $this->tokens->findAllTokens(self::BATCH_SIZE, $offset);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $byUser[$row->getUserId()][] = $row;
            }
            $seen += \count($rows);
            $offset += self::BATCH_SIZE;
            if (\count($rows) < self::BATCH_SIZE) {
                break;
            }
        }
        return $byUser;
    }

    /**
     * Resolves the current Email/query `queryState` for a user's Inbox.
     * Returns null on any resolution failure — the poller simply skips
     * that user for this tick.
     *
     * Two sequential JMAP round-trips rather than one request using a
     * JMAP result reference: result references (RFC 8620 §3.7) replace
     * an ENTIRE top-level method argument (e.g. `filter`), not a nested
     * property inside it (`filter.inMailbox`) — so the inbox id has to
     * be read back into PHP and spliced into a literal `filter` object
     * on the second call.
     */
    /**
     * Holt Betreff + Absender der neuesten Inbox-Mail für die Push-Ansicht.
     * Fehler sind hier unkritisch (der Push geht trotzdem raus, nur mit
     * generischem Text).
     *
     * @return array{subject: string, from: string}
     */
    private function fetchEmailDetails(string $userId, string $emailId): array
    {
        if ($emailId === '') {
            return ['subject' => '', 'from' => ''];
        }
        try {
            $bearer = $this->userContext->resolveBearer($userId);
            $accountId = $this->userContext->resolveAccountId($userId);
            $response = $this->stalwartAdmin->jmapCall(
                $bearer,
                [
                    ['Email/get', [
                        'accountId' => $accountId,
                        'ids' => [$emailId],
                        'properties' => ['subject', 'from'],
                    ], 'g0'],
                ],
                ['urn:ietf:params:jmap:mail'],
            );
            $get = $this->stalwartAdmin->extractMethodResponse($response, 'Email/get');
            $list = $get['list'] ?? [];
            $first = \is_array($list) && isset($list[0]) && \is_array($list[0]) ? $list[0] : [];
            $subject = (string) ($first['subject'] ?? '');
            $from = '';
            $fromArr = $first['from'] ?? null;
            if (\is_array($fromArr) && isset($fromArr[0]) && \is_array($fromArr[0])) {
                $from = (string) ($fromArr[0]['name'] ?? $fromArr[0]['email'] ?? '');
            }
            return ['subject' => $subject, 'from' => $from];
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Souvera Mail: MailPushPoller could not fetch email details for "'
                . $userId . '": ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            return ['subject' => '', 'from' => ''];
        }
    }

    /**
     * Ermittelt Inbox-queryState UND die JMAP-Id der neuesten Inbox-Mail
     * in EINEM Durchlauf (das Email/query mit limit=1 liefert beides).
     *
     * @return array{state: string, emailId: string}|null
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
