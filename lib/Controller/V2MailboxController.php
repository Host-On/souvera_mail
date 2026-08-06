<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\V2JmapProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Mailbox and email API for the v2 Vue-3 frontend.
 *
 * All data comes from the Stalwart JMAP endpoint via {@see V2JmapProxy}.
 * No IMAP, no SnappyMail — pure JMAP.
 */
class V2MailboxController extends Controller
{
    /**
     * Standard system mailboxes every account should have. When one is
     * missing (e.g. provisioning created the account without a Sent
     * folder), it is created automatically so the UI always shows all
     * standard folders. Display names are localized client-side by role.
     */
    private const DEFAULT_ROLE_MAILBOXES = [
        'inbox' => 'Inbox',
        'drafts' => 'Drafts',
        'sent' => 'Sent',
        'junk' => 'Junk',
        'trash' => 'Trash',
    ];

    public function __construct(
        string $appName,
        IRequest $request,
        private V2JmapProxy $jmap,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/mailboxes
     * Optional query parameter: ?accountId=<sharedAccountId> for shared folders.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function list(): JSONResponse
    {
        $accountId = $this->request->getParam('accountId');
        if (empty($accountId)) {
            $accountId = $this->resolveAccountId();
        }
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $result = $this->jmap->singleCall('Mailbox/get', [
            'accountId' => $accountId,
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        $list = $result['data']['list'] ?? [];
        $mailboxes = [];
        $existingRoles = [];
        foreach ($list as $mb) {
            $role = $mb['role'] ?? null;
            if (\is_string($role) && $role !== '') {
                $existingRoles[$role] = true;
            }
            $mailboxes[] = [
                'id' => $mb['id'] ?? '',
                'name' => $mb['name'] ?? '?',
                'role' => $role,
                'total' => $mb['totalEmails'] ?? 0,
                'unread' => $mb['unreadEmails'] ?? 0,
                'parentId' => $mb['parentId'] ?? null,
                '_accountId' => $accountId,
            ];
        }

        // Self-healing: create missing standard mailboxes (e.g. "Sent").
        $create = [];
        foreach (self::DEFAULT_ROLE_MAILBOXES as $role => $name) {
            if (!isset($existingRoles[$role])) {
                $create['mb_' . $role] = ['name' => $name, 'role' => $role];
            }
        }
        if ($create !== []) {
            $set = $this->jmap->singleCall('Mailbox/set', [
                'accountId' => $accountId,
                'create' => $create,
            ]);
            if (!isset($set['error'])) {
                foreach ($set['data']['created'] ?? [] as $key => $created) {
                    $role = \str_starts_with((string) $key, 'mb_') ? \substr((string) $key, 3) : '';
                    $mailboxes[] = [
                        'id' => $created['id'] ?? '',
                        'name' => self::DEFAULT_ROLE_MAILBOXES[$role] ?? $created['id'] ?? '',
                        'role' => $role,
                        'total' => 0,
                        'unread' => 0,
                        'parentId' => null,
                        '_accountId' => $accountId,
                    ];
                }
            }
        }

        return new JSONResponse(['mailboxes' => $mailboxes]);
    }

    /**
     * GET /apps/souvera_mail/api/v2/emails?mailbox=INBOX&limit=20&offset=0
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function emails(): JSONResponse
    {
        $accountId = $this->request->getParam('accountId');
        if (empty($accountId)) {
            $accountId = $this->resolveAccountId();
        }
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $mailboxId = \trim((string) ($this->request->getParam('mailbox') ?? ''));
        $limit = \min(100, \max(1, (int) ($this->request->getParam('limit') ?? 50)));
        $offset = \max(0, (int) ($this->request->getParam('offset') ?? 0));
        $searchQuery = \trim((string) ($this->request->getParam('q') ?? ''));
        $filterType = \trim((string) ($this->request->getParam('filter') ?? 'all'));

        $filter = [];
        if ($mailboxId !== '') $filter['inMailbox'] = $mailboxId;
        if ($searchQuery !== '') $filter['text'] = $searchQuery;
        // JMAP Email/query does NOT have isUnread/isFlagged — unread status
        // is tracked via keywords/$seen and flagged via keywords/$flagged.
        if ($filterType === 'unread') $filter['notKeyword'] = '$seen';
        if ($filterType === 'flagged') $filter['hasKeyword'] = '$flagged';
        if ($filterType === 'attachments') $filter['hasAttachment'] = true;

        $queryResult = $this->jmap->singleCall('Email/query', [
            'accountId' => $accountId,
            'filter' => $filter !== [] ? $filter : new \stdClass(),
            'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
            'position' => $offset,
            'limit' => $limit,
        ]);

        if (isset($queryResult['error'])) {
            return new JSONResponse($queryResult, 500);
        }

        $ids = $queryResult['data']['ids'] ?? [];
        if (empty($ids)) {
            return new JSONResponse(['emails' => [], 'total' => 0]);
        }

        // JMAP Email/query may not include 'total'. If missing, estimate
        // from position + count. If we got exactly $limit results, assume
        // there are more (add 1 to enable the next-page button).
        $total = $queryResult['data']['total'] ?? null;
        if ($total === null) {
            $total = $offset + \count($ids);
            if (\count($ids) >= $limit) {
                $total++;
            }
        }

        $getResult = $this->jmap->singleCall('Email/get', [
            'accountId' => $accountId,
            'ids' => $ids,
            'properties' => ['id', 'subject', 'from', 'to', 'receivedAt', 'size', 'hasAttachment', 'keywords', 'threadId', 'preview'],
        ]);

        if (isset($getResult['error'])) {
            return new JSONResponse($getResult, 500);
        }

        $emails = [];
        foreach ($getResult['data']['list'] ?? [] as $email) {
            $fromList = $email['from'] ?? [];
            $fromAddr = $fromList[0]['email'] ?? '';
            $fromName = $this->decodeMimeHeader($fromList[0]['name'] ?? '');
            $keywords = $email['keywords'] ?? [];
            $emails[] = [
                'id' => $email['id'] ?? '',
                'subject' => $email['subject'] ?? '',
                'fromAddress' => $fromAddr,
                'fromName' => $fromName,
                'receivedAt' => $email['receivedAt'] ?? '',
                'size' => $email['size'] ?? 0,
                'hasAttachment' => $email['hasAttachment'] ?? false,
                'isRead' => isset($keywords['$seen']),
                'isFlagged' => isset($keywords['$flagged']),
                'preview' => $email['preview'] ?? '',
                'threadId' => $email['threadId'] ?? '',
            ];
        }

        return new JSONResponse(['emails' => $emails, 'total' => $total]);
    }

    /**
     * GET /apps/souvera_mail/api/v2/emails/{id}?accountId=...
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function detail(string $id): JSONResponse
    {
        $accountId = $this->resolveAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $result = $this->jmap->singleCall('Email/get', [
            'accountId' => $accountId,
            'ids' => [$id],
            'properties' => ['id', 'subject', 'from', 'to', 'cc', 'bcc', 'receivedAt',
                'size', 'hasAttachment', 'keywords', 'threadId', 'preview', 'messageId',
                'inReplyTo', 'references', 'textBody', 'htmlBody', 'attachments', 'bodyValues'],
            'bodyProperties' => ['partId', 'blobId', 'size', 'name', 'type', 'cid', 'disposition'],
            'fetchTextBodyValues' => true,
            'fetchHTMLBodyValues' => true,
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        $email = $result['data']['list'][0] ?? null;
        if ($email === null) {
            return new JSONResponse(['error' => 'Email not found'], 404);
        }

        $fromAddr = $email['from'][0]['email'] ?? '';
        $fromName = $this->decodeMimeHeader($email['from'][0]['name'] ?? '');
        $toAddrs = \implode(', ', \array_map(fn($a) => ($a['name'] ?? '') . ' <' . ($a['email'] ?? '') . '>', $email['to'] ?? []));
        $toList = \array_map(fn($a) => ['name' => $a['name'] ?? '', 'email' => $a['email'] ?? ''], $email['to'] ?? []);
        $ccList = \array_map(fn($a) => ['name' => $a['name'] ?? '', 'email' => $a['email'] ?? ''], $email['cc'] ?? []);
        $keywords = $email['keywords'] ?? [];
        $bodyValues = $email['bodyValues'] ?? [];
        $htmlPart = $email['htmlBody'][0] ?? null;
        $textPart = $email['textBody'][0] ?? null;

        $htmlBody = ($bodyValues[$htmlPart['partId'] ?? $htmlPart['blobId'] ?? ''] ?? [])['value'] ?? null;
        $plainBody = ($bodyValues[$textPart['partId'] ?? $textPart['blobId'] ?? ''] ?? [])['value'] ?? null;

        // Stalwart stores bodies as blobs — fall back to Blob/get when
        // bodyValues is empty (fetchTextBodyValues not honored).
        if ($htmlBody === null && $htmlPart !== null && ($bid = ($htmlPart['blobId'] ?? '')) !== '') {
            $bresult = $this->jmap->singleCall('Blob/get', [
                'accountId' => $accountId, 'ids' => [$bid],
            ]);
            $blist = $bresult['data']['list'] ?? [];
            $htmlBody = $this->extractBlobBody($blist[0] ?? []);
        }
        if ($plainBody === null && $textPart !== null && ($bid = ($textPart['blobId'] ?? '')) !== '') {
            $bresult = $this->jmap->singleCall('Blob/get', [
                'accountId' => $accountId, 'ids' => [$bid],
            ]);
            $blist = $bresult['data']['list'] ?? [];
            $plainBody = $this->extractBlobBody($blist[0] ?? []);
        }

        $attachments = [];
        foreach ($email['attachments'] ?? [] as $att) {
            $attachments[] = [
                'blobId' => $att['blobId'] ?? '',
                'name' => $att['name'] ?? 'attachment',
                'type' => $att['type'] ?? 'application/octet-stream',
                'size' => $att['size'] ?? 0,
                'partId' => $att['partId'] ?? '',
                'cid' => $att['cid'] ?? null,
                'disposition' => $att['disposition'] ?? 'attachment',
            ];
        }

        return new JSONResponse([
            'email' => [
                'id' => $email['id'] ?? '',
                'subject' => $email['subject'] ?? '',
                'fromAddress' => $fromAddr,
                'fromName' => $fromName,
                'toAddresses' => $toAddrs,
                'toList' => $toList,
                'ccList' => $ccList,
                'references' => $email['references'][0] ?? null,
                'receivedAt' => $email['receivedAt'] ?? '',
                'isRead' => isset($keywords['$seen']),
                'isFlagged' => isset($keywords['$flagged']),
                'attachments' => $attachments,
                'htmlBody' => $htmlBody,
                'plainBody' => $plainBody,
                'inReplyTo' => $email['inReplyTo'][0] ?? null,
                'messageId' => $email['messageId'][0] ?? null,
            ],
        ]);
    }

    /**
     * POST /apps/souvera_mail/api/v2/emails/{id}/read
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function markRead(string $id): JSONResponse
    {
        $accountId = $this->resolveAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        $isRead = (bool) ($body['isRead'] ?? true);
        $update = $isRead
            ? ['keywords/$seen' => true]
            : ['keywords/$seen' => null];

        $result = $this->jmap->call([
            ['Email/set', [
                'accountId' => $accountId,
                'update' => [$id => $update],
            ]],
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        return new JSONResponse(['success' => true]);
    }

    /**
     * POST /apps/souvera_mail/api/v2/mailboxes/{id}/empty
     *
     * Permanently delete ALL emails in the given mailbox.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function emptyMailbox(string $id): JSONResponse
    {
        $accountId = $this->request->getParam('accountId');
        if (empty($accountId)) {
            $accountId = $this->resolveAccountId();
        }
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        // Query all email IDs in the mailbox.
        $query = $this->jmap->singleCall('Email/query', [
            'accountId' => $accountId,
            'filter' => ['inMailbox' => $id],
            'limit' => 500,
        ]);

        $ids = $query['data']['ids'] ?? [];
        if ($ids === []) {
            return new JSONResponse(['success' => true, 'total' => 0]);
        }

        $result = $this->jmap->singleCall('Email/set', [
            'accountId' => $accountId,
            'destroy' => $ids,
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        return new JSONResponse([
            'success' => true,
            'total' => \count($ids),
            'destroyed' => \count($result['data']['destroyed'] ?? []),
        ]);
    }

    /**
     * POST /apps/souvera_mail/api/v2/mailboxes/{id}/mark-all-read
     *
     * Mark ALL unread emails in the given mailbox as read in a single
     * batch — no checkbox animation, no per-page limitation.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function markAllRead(string $id): JSONResponse
    {
        $accountId = $this->request->getParam('accountId');
        if (empty($accountId)) {
            $accountId = $this->resolveAccountId();
        }
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        // Query all unread email IDs in the mailbox.
        $query = $this->jmap->singleCall('Email/query', [
            'accountId' => $accountId,
            'filter' => ['inMailbox' => $id, 'notKeyword' => '$seen'],
            'limit' => 500,
        ]);

        $ids = $query['data']['ids'] ?? [];
        if ($ids === []) {
            return new JSONResponse(['success' => true, 'total' => 0]);
        }

        // Batch-update: mark all as seen.
        $update = [];
        foreach ($ids as $emailId) {
            $update[$emailId] = ['keywords/$seen' => true];
        }
        $result = $this->jmap->singleCall('Email/set', [
            'accountId' => $accountId,
            'update' => $update,
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        return new JSONResponse([
            'success' => true,
            'total' => \count($ids),
            'updated' => \count($result['data']['updated'] ?? []),
        ]);
    }

    /**
     * POST /apps/souvera_mail/api/v2/emails/{id}/flag
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function flagEmail(string $id): JSONResponse
    {
        $accountId = $this->resolveAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        $isFlagged = (bool) ($body['isFlagged'] ?? false);
        $update = $isFlagged
            ? ['keywords/$flagged' => true]
            : ['keywords/$flagged' => null];

        $result = $this->jmap->call([
            ['Email/set', [
                'accountId' => $accountId,
                'update' => [$id => $update],
            ]],
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        return new JSONResponse(['success' => true]);
    }

    /**
     * DELETE /apps/souvera_mail/api/v2/emails/{id}
     * Moves to Trash mailbox (soft-delete).
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function delete(string $id): JSONResponse
    {
        $accountId = $this->resolveAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        // Find the Trash mailbox ID.
        $mbResult = $this->jmap->singleCall('Mailbox/get', ['accountId' => $accountId]);
        $trashId = null;
        foreach ($mbResult['data']['list'] ?? [] as $mb) {
            if (($mb['role'] ?? '') === 'trash') {
                $trashId = $mb['id'];
                break;
            }
        }

        $update = $trashId !== null
            ? ['mailboxIds' => [$trashId => true]]
            : ['keywords/$add' => ['$deleted' => true]];

        $result = $this->jmap->call([
            ['Email/set', [
                'accountId' => $accountId,
                'update' => [$id => $update],
            ]],
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        return new JSONResponse(['success' => true]);
    }

    private function extractBlobBody(array $blob): ?string
    {
        // Stalwart 0.16 returns small/UTF-8 blobs as data:asText
        $text = $blob['data:asText'] ?? null;
        if (\is_string($text) && $text !== '') {
            return $text;
        }
        // Larger blobs come as base64
        $b64 = $blob['data:asBase64'] ?? null;
        if (\is_string($b64) && $b64 !== '') {
            $decoded = \base64_decode($b64, true);
            return ($decoded !== false) ? $decoded : null;
        }
        // Fallback: raw data key (may be plain text for tiny blobs)
        $raw = $blob['data'] ?? null;
        return \is_string($raw) && $raw !== '' ? $raw : null;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function downloadBlob(string $id, string $name): \OCP\AppFramework\Http\Response
    {
        $accountId = $this->resolveAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $result = $this->jmap->singleCall('Blob/get', [
            'accountId' => $accountId, 'ids' => [$id],
        ]);
        if (isset($result['error'])) return new JSONResponse($result, 500);
        $blob = $result['data']['list'][0] ?? null;
        if ($blob === null) return new JSONResponse(['error' => 'Blob not found'], 404);
        $data = \base64_decode($blob['data:asBase64'] ?? '', true) ?: '';
        return new \OCP\AppFramework\Http\DataDownloadResponse($data, $name, $blob['type'] ?? 'application/octet-stream');
    }

    /**
     * POST /apps/souvera_mail/api/v2/emails/{id}/move
     * { mailboxId }
     */
    #[NoAdminRequired]
    public function move(string $id): JSONResponse
    {
        $accountId = $this->resolveAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $body = \json_decode(\file_get_contents('php://input'), true);
        $targetId = \trim((string) ($body['mailboxId'] ?? ''));
        if ($targetId === '') {
            return new JSONResponse(['error' => 'mailboxId required'], 400);
        }
        $result = $this->jmap->call([
            ['Email/set', ['accountId' => $accountId, 'update' => [$id => ['mailboxIds' => [$targetId => true]]]]],
        ]);
        return isset($result['error'])
            ? new JSONResponse($result, 500)
             : new JSONResponse(['success' => true]);
    }

    private function resolveAccountId(): ?string
    {
        $accountId = $this->request->getParam('accountId');
        if (!empty($accountId)) return $accountId;
        return $this->jmap->getCurrentAccountId();
    }

    /**
     * Decodes MIME-encoded header values like =?UTF-8?B?...?= or =?UTF-8?Q?....?=.
     * Falls back to the raw input if decoding fails or the string isn't encoded.
     */
    private function decodeMimeHeader(string $raw): string
    {
        if ($raw === '' || !\str_contains($raw, '=?')) {
            return $raw;
        }
        $decoded = @\iconv_mime_decode($raw, 0, 'UTF-8');
        return ($decoded !== false) ? $decoded : $raw;
    }
}
