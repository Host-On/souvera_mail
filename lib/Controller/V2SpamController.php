<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\V2JmapProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Spam folder controller — merges JMAP junk + Shield/PMG quarantine.
 *
 * Invoked when the user opens the "Spam" folder in the sidebar.
 */
class V2SpamController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private V2JmapProxy $jmap,
        private IClientService $httpClientService,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/spam/list?limit=50&offset=0
     *
     * Returns both JMAP junk mailbox emails AND Shield/PMG quarantine items,
     * merged and sorted by date descending.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function list(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $limit = \min(100, \max(1, (int) ($this->request->getParam('limit') ?? 50)));
        $offset = \max(0, (int) ($this->request->getParam('offset') ?? 0));

        // 1. Fetch JMAP junk emails
        $junkEmails = $this->fetchJunkEmails($accountId);

        // 2. Fetch Shield/PMG quarantine items
        $shieldItems = $this->fetchShieldSpam();

        // 3. Merge and sort by date descending
        $merged = $this->mergeAndSort($junkEmails, $shieldItems);
        $total = \count($merged);

        // 4. Apply pagination
        $page = \array_slice($merged, $offset, $limit);

        return new JSONResponse(['items' => $page, 'total' => $total]);
    }

    /**
     * GET /apps/souvera_mail/api/v2/spam/view?id=<emailId>&source=jmap
     * GET /apps/souvera_mail/api/v2/spam/view?id=<shieldId>&source=shield
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function view(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $id = \trim((string) ($this->request->getParam('id') ?? ''));
        $source = \trim((string) ($this->request->getParam('source') ?? 'jmap'));

        if ($id === '') {
            return new JSONResponse(['error' => 'Missing id parameter'], Http::STATUS_BAD_REQUEST);
        }

        if ($source === 'shield') {
            return $this->viewShieldItem($id);
        }

        return $this->viewJmapEmail($accountId, $id);
    }

    /**
     * POST /apps/souvera_mail/api/v2/spam/release
     * {ids: ["shield:abc123", "jmap:xyz456"], source: "shield"|"jmap"}
     */
    #[NoAdminRequired]
    public function release(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $ids = (array) ($body['ids'] ?? []);
        $source = \trim((string) ($body['source'] ?? ''));

        if (empty($ids)) {
            return new JSONResponse(['error' => 'Missing ids parameter'], Http::STATUS_BAD_REQUEST);
        }

        if ($source === 'shield') {
            return $this->releaseShield($ids);
        }

        // JMAP release: move from junk → inbox
        return $this->releaseJmap($accountId, $ids);
    }

    /**
     * POST /apps/souvera_mail/api/v2/spam/delete
     * {ids: ["shield:abc123"], source: "shield"}
     */
    #[NoAdminRequired]
    public function delete(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $ids = (array) ($body['ids'] ?? []);
        $source = \trim((string) ($body['source'] ?? 'jmap'));

        if (empty($ids)) {
            return new JSONResponse(['error' => 'Missing ids parameter'], Http::STATUS_BAD_REQUEST);
        }

        if ($source === 'shield') {
            return $this->deleteShield($ids);
        }

        // JMAP delete: move to trash
        return $this->deleteJmap($accountId, $ids);
    }

    // -------------------------------------------------------------------
    // Fetch helpers
    // -------------------------------------------------------------------

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchJunkEmails(string $accountId): array
    {
        try {
            $mailboxesResult = $this->jmap->singleCall('Mailbox/get', ['accountId' => $accountId]);
            $junkId = null;
            foreach ($mailboxesResult['data']['list'] ?? [] as $mb) {
                if (($mb['role'] ?? '') === 'junk') {
                    $junkId = $mb['id'];
                    break;
                }
            }

            if ($junkId === null) {
                return [];
            }

            $queryResult = $this->jmap->singleCall('Email/query', [
                'accountId' => $accountId,
                'filter' => ['inMailbox' => $junkId],
                'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
                'limit' => 200,
            ]);

            $ids = $queryResult['data']['ids'] ?? [];
            if (empty($ids)) return [];

            $getResult = $this->jmap->singleCall('Email/get', [
                'accountId' => $accountId,
                'ids' => $ids,
                'properties' => ['id', 'subject', 'from', 'receivedAt', 'size', 'hasAttachment', 'keywords', 'preview', 'threadId'],
            ]);

            $emails = [];
            foreach ($getResult['data']['list'] ?? [] as $email) {
                $fromList = $email['from'] ?? [];
                $emails[] = [
                    'id' => $email['id'] ?? '',
                    'subject' => $email['subject'] ?? '',
                    'fromAddress' => $fromList[0]['email'] ?? '',
                    'fromName' => $this->decodeMimeHeader($fromList[0]['name'] ?? ''),
                    'receivedAt' => $email['receivedAt'] ?? '',
                    'size' => $email['size'] ?? 0,
                    'hasAttachment' => $email['hasAttachment'] ?? false,
                    'preview' => $email['preview'] ?? '',
                    'isRead' => isset(($email['keywords'] ?? [])['$seen']),
                    'isFlagged' => isset(($email['keywords'] ?? [])['$flagged']),
                    'threadId' => $email['threadId'] ?? '',
                    '_source' => 'jmap',
                ];
            }
            return $emails;
        } catch (\Throwable $e) {
            $this->logger->error('SpamController: JMAP junk fetch failed', ['exception' => $e]);
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchShieldSpam(): array
    {
        try {
            $client = $this->httpClientService->newClient();
            $url = $this->urlGenerator->getAbsoluteURL('/apps/souvera_shield/api/internal/spam/list');
            $response = $client->get($url, [
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $data = \json_decode($response->getBody(), true) ?? [];
            $items = $data['data'] ?? [];

            return \array_map(static function (array $item): array {
                $time = (int) ($item['time'] ?? 0);
                return [
                    'id' => $item['id'] ?? '',
                    'subject' => $item['subject'] ?? '',
                    'fromAddress' => $item['envelope_sender'] ?? $item['from'] ?? '',
                    'fromName' => $item['from'] ?? '',
                    'receivedAt' => $time > 0 ? \gmdate('Y-m-d\TH:i:s\Z', $time) : '',
                    'size' => (int) ($item['bytes'] ?? 0),
                    'spamLevel' => (float) ($item['spamlevel'] ?? 0),
                    'preview' => '',
                    'isRead' => false,
                    'isFlagged' => false,
                    'hasAttachment' => false,
                    '_source' => 'shield',
                ];
            }, $items);
        } catch (\Throwable $e) {
            $this->logger->error('SpamController: Shield fetch failed', ['exception' => $e]);
            return [];
        }
    }

    /**
     * @param array<int,array> $junk
     * @param array<int,array> $spam
     * @return array<int,array>
     */
    private function mergeAndSort(array $junk, array $spam): array
    {
        $merged = \array_merge($junk, $spam);
        \usort($merged, static function (array $a, array $b): int {
            $ta = $a['receivedAt'] ?? '';
            $tb = $b['receivedAt'] ?? '';
            // Items without dates go last
            if ($ta === '' && $tb === '') return 0;
            if ($ta === '') return 1;
            if ($tb === '') return -1;
            return \strcmp($tb, $ta); // descending
        });
        return $merged;
    }

    // -------------------------------------------------------------------
    // View helpers
    // -------------------------------------------------------------------

    private function viewJmapEmail(string $accountId, string $id): JSONResponse
    {
        try {
            $getResult = $this->jmap->singleCall('Email/get', [
                'accountId' => $accountId,
                'ids' => [$id],
                'properties' => ['id', 'subject', 'from', 'to', 'cc', 'bcc', 'receivedAt', 'size', 'hasAttachment', 'keywords', 'preview', 'threadId', 'htmlBody', 'textBody', 'bodyStructure', 'bodyValues', 'mailboxIds'],
            ]);

            $email = $getResult['data']['list'][0] ?? null;
            if ($email === null) {
                return new JSONResponse(['error' => 'Email not found'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(\array_merge(
                $this->formatJmapItem($email),
                [
                    'htmlBody' => $email['htmlBody'] ?? [],
                    'textBody' => $email['textBody'] ?? [],
                    'bodyStructure' => $email['bodyStructure'] ?? null,
                    'bodyValues' => $email['bodyValues'] ?? [],
                    'to' => $this->formatAddresses($email['to'] ?? []),
                    'cc' => $this->formatAddresses($email['cc'] ?? []),
                ]
            ));
        } catch (\Throwable $e) {
            $this->logger->error('SpamController: view jmap failed', ['exception' => $e]);
            return new JSONResponse(['error' => 'Failed to load email'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function viewShieldItem(string $id): JSONResponse
    {
        try {
            $client = $this->httpClientService->newClient();
            $url = $this->urlGenerator->getAbsoluteURL('/apps/souvera_shield/api/internal/spam/view?id=' . \urlencode($id));
            $response = $client->get($url, [
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json'],
            ]);
            $data = \json_decode($response->getBody(), true) ?? [];
            return new JSONResponse($data);
        } catch (\Throwable $e) {
            $this->logger->error('SpamController: view shield failed', ['exception' => $e]);
            return new JSONResponse(['error' => 'Failed to load spam item'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------
    // Action helpers
    // -------------------------------------------------------------------

    private function releaseShield(array $ids): JSONResponse
    {
        try {
            $client = $this->httpClientService->newClient();
            $url = $this->urlGenerator->getAbsoluteURL('/apps/souvera_shield/api/internal/spam/release');
            $response = $client->post($url, [
                'timeout' => 30,
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => \json_encode(['ids' => $ids]),
            ]);
            $result = \json_decode($response->getBody(), true) ?? [];
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('SpamController: release shield failed', ['exception' => $e]);
            return new JSONResponse(['error' => 'Failed to release'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function releaseJmap(string $accountId, array $ids): JSONResponse
    {
        try {
            $mailboxesResult = $this->jmap->singleCall('Mailbox/get', ['accountId' => $accountId]);
            $inboxId = null;
            foreach ($mailboxesResult['data']['list'] ?? [] as $mb) {
                if (($mb['role'] ?? '') === 'inbox') {
                    $inboxId = $mb['id'];
                    break;
                }
            }
            if ($inboxId === null) {
                return new JSONResponse(['error' => 'No inbox found'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $updates = [];
            foreach ($ids as $id) {
                $updates[(string)$id] = ['mailboxIds' => [$inboxId => true]];
            }

            $result = $this->jmap->singleCall('Email/set', [
                'accountId' => $accountId,
                'update' => $updates,
            ]);

            if (isset($result['error'])) {
                return new JSONResponse($result, Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            return new JSONResponse(['success' => \count($ids)]);
        } catch (\Throwable $e) {
            $this->logger->error('SpamController: release jmap failed', ['exception' => $e]);
            return new JSONResponse(['error' => 'Failed to release'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function deleteShield(array $ids): JSONResponse
    {
        try {
            $client = $this->httpClientService->newClient();
            $url = $this->urlGenerator->getAbsoluteURL('/apps/souvera_shield/api/internal/spam/delete');
            $response = $client->post($url, [
                'timeout' => 30,
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'body' => \json_encode(['ids' => $ids]),
            ]);
            $result = \json_decode($response->getBody(), true) ?? [];
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('SpamController: delete shield failed', ['exception' => $e]);
            return new JSONResponse(['error' => 'Failed to delete'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function deleteJmap(string $accountId, array $ids): JSONResponse
    {
        try {
            $mailboxesResult = $this->jmap->singleCall('Mailbox/get', ['accountId' => $accountId]);
            $trashId = null;
            foreach ($mailboxesResult['data']['list'] ?? [] as $mb) {
                if (($mb['role'] ?? '') === 'trash') {
                    $trashId = $mb['id'];
                    break;
                }
            }
            if ($trashId === null) {
                return new JSONResponse(['error' => 'No trash found'], Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            $updates = [];
            foreach ($ids as $id) {
                $updates[(string)$id] = ['mailboxIds' => [$trashId => true]];
            }

            $result = $this->jmap->singleCall('Email/set', [
                'accountId' => $accountId,
                'update' => $updates,
            ]);

            if (isset($result['error'])) {
                return new JSONResponse($result, Http::STATUS_INTERNAL_SERVER_ERROR);
            }

            return new JSONResponse(['success' => \count($ids)]);
        } catch (\Throwable $e) {
            $this->logger->error('SpamController: delete jmap failed', ['exception' => $e]);
            return new JSONResponse(['error' => 'Failed to delete'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // -------------------------------------------------------------------
    // Format helpers
    // -------------------------------------------------------------------

    private function formatJmapItem(array $email): array
    {
        $fromList = $email['from'] ?? [];
        $keywords = $email['keywords'] ?? [];
        return [
            'id' => $email['id'] ?? '',
            'subject' => $email['subject'] ?? '',
            'fromAddress' => $fromList[0]['email'] ?? '',
            'fromName' => $this->decodeMimeHeader($fromList[0]['name'] ?? ''),
            'receivedAt' => $email['receivedAt'] ?? '',
            'size' => $email['size'] ?? 0,
            'hasAttachment' => $email['hasAttachment'] ?? false,
            'preview' => $email['preview'] ?? '',
            'isRead' => isset($keywords['$seen']),
            'isFlagged' => isset($keywords['$flagged']),
            'threadId' => $email['threadId'] ?? '',
            '_source' => 'jmap',
        ];
    }

    /**
     * @param array<int,array{name?:string,email?:string}> $addresses
     * @return array<int,array{name:string,email:string}>
     */
    private function formatAddresses(array $addresses): array
    {
        return \array_map(function (array $a): array {
            return [
                'name' => $this->decodeMimeHeader($a['name'] ?? ''),
                'email' => $a['email'] ?? '',
            ];
        }, $addresses);
    }

    private function decodeMimeHeader(string $raw): string
    {
        if ($raw === '') return '';
        $parts = \imap_mime_header_decode($raw);
        if ($parts === false) return $raw;
        $decoded = '';
        foreach ($parts as $p) {
            $charset = $p->charset ?? 'UTF-8';
            $text = $p->text ?? '';
            if (\strcasecmp($charset, 'default') !== 0) {
                $converted = @\mb_convert_encoding($text, 'UTF-8', $charset);
                $decoded .= $converted !== false ? $converted : $text;
            } else {
                $decoded .= $text;
            }
        }
        return $decoded;
    }
}
