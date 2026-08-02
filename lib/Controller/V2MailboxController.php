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
    public function __construct(
        string $appName,
        IRequest $request,
        private V2JmapProxy $jmap,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/mailboxes
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function list(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
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
        foreach ($list as $mb) {
            $mailboxes[] = [
                'id' => $mb['id'] ?? '',
                'name' => $mb['name'] ?? '?',
                'role' => $mb['role'] ?? null,
                'total' => $mb['totalEmails'] ?? 0,
                'unread' => $mb['unreadEmails'] ?? 0,
                'parentId' => $mb['parentId'] ?? null,
            ];
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
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $mailboxId = \trim((string) ($this->request->getParam('mailbox') ?? ''));
        $limit = \min(100, \max(1, (int) ($this->request->getParam('limit') ?? 50)));
        $offset = \max(0, (int) ($this->request->getParam('offset') ?? 0));

        $filter = $mailboxId !== '' ? ['inMailbox' => $mailboxId] : new \stdClass();
        $queryResult = $this->jmap->singleCall('Email/query', [
            'accountId' => $accountId,
            'filter' => $filter,
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
            $fromName = $fromList[0]['name'] ?? '';
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

        return new JSONResponse(['emails' => $emails, 'total' => $queryResult['data']['total'] ?? \count($ids)]);
    }

    /**
     * GET /apps/souvera_mail/api/v2/emails/{id}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function detail(string $id): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $result = $this->jmap->singleCall('Email/get', [
            'accountId' => $accountId,
            'ids' => [$id],
            'properties' => ['id', 'subject', 'from', 'to', 'cc', 'bcc', 'receivedAt',
                'size', 'hasAttachment', 'keywords', 'threadId', 'preview', 'messageId',
                'inReplyTo', 'textBody', 'htmlBody', 'attachments', 'bodyValues'],
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
        $fromName = $email['from'][0]['name'] ?? '';
        $toAddrs = \implode(', ', \array_map(fn($a) => ($a['name'] ?? '') . ' <' . ($a['email'] ?? '') . '>', $email['to'] ?? []));
        $keywords = $email['keywords'] ?? [];
        $bodyValues = $email['bodyValues'] ?? [];
        $htmlPart = $email['htmlBody'][0] ?? null;
        $textPart = $email['textBody'][0] ?? null;

        $htmlBody = $bodyValues[$htmlPart['partId'] ?? $htmlPart['blobId'] ?? ''] ?? null;
        $plainBody = $bodyValues[$textPart['partId'] ?? $textPart['blobId'] ?? ''] ?? null;

        // Stalwart stores bodies as blobs — fall back to Blob/get when
        // bodyValues is empty (fetchTextBodyValues not honored).
        if ($htmlBody === null && $htmlPart !== null && ($bid = ($htmlPart['blobId'] ?? '')) !== '') {
            $bresult = $this->jmap->singleCall('Blob/get', [
                'accountId' => $accountId, 'ids' => [$bid],
            ]);
            $blist = $bresult['data']['list'] ?? [];
            $bdata = $blist[0]['data:asBase64'] ?? ($blist[0]['data'] ?? null);
            if (\is_string($bdata) && $bdata !== '') {
                $htmlBody = \base64_decode($bdata, true) ?: $bdata;
            }
        }
        if ($plainBody === null && $textPart !== null && ($bid = ($textPart['blobId'] ?? '')) !== '') {
            $bresult = $this->jmap->singleCall('Blob/get', [
                'accountId' => $accountId, 'ids' => [$bid],
            ]);
            $blist = $bresult['data']['list'] ?? [];
            $bdata = $blist[0]['data:asBase64'] ?? ($blist[0]['data'] ?? null);
            if (\is_string($bdata) && $bdata !== '') {
                $plainBody = \base64_decode($bdata, true) ?: $bdata;
            }
        }

        $attachments = [];
        foreach ($email['attachments'] ?? [] as $att) {
            $attachments[] = [
                'blobId' => $att['blobId'] ?? '',
                'name' => $att['name'] ?? 'attachment',
                'type' => $att['type'] ?? 'application/octet-stream',
                'size' => $att['size'] ?? 0,
                'partId' => $att['partId'] ?? '',
            ];
        }

        return new JSONResponse([
            'email' => [
                'id' => $email['id'] ?? '',
                'subject' => $email['subject'] ?? '',
                'fromAddress' => $fromAddr,
                'fromName' => $fromName,
                'toAddresses' => $toAddrs,
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
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $isRead = $this->request->getParam('isRead', '1') === '1';
        $update = $isRead
            ? ['keywords/$add' => ['$seen' => true]]
            : ['keywords/$remove' => ['$seen']];

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
     * POST /apps/souvera_mail/api/v2/emails/{id}/flag
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function flagEmail(string $id): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        $isFlagged = (bool) ($body['isFlagged'] ?? false);
        $update = $isFlagged
            ? ['keywords/$add' => ['$flagged' => true]]
            : ['keywords/$remove' => ['$flagged']];

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
        $accountId = $this->jmap->getCurrentAccountId();
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

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function downloadBlob(string $id, string $name): \OCP\AppFramework\Http\Response
    {
        $accountId = $this->jmap->getCurrentAccountId();
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
        $response = new \OCP\AppFramework\Http\Response();
        $response->setHeaders([
            'Content-Type' => $blob['type'] ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . \addcslashes($name, '"') . '"',
            'Content-Length' => (string) (\strlen($data)),
        ]);
        $response->setStatus(200);
        $response->setBody($data);
        return $response;
    }

    /**
     * POST /apps/souvera_mail/api/v2/emails/{id}/move
     * { mailboxId }
     */
    #[NoAdminRequired]
    public function move(string $id): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
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
}
