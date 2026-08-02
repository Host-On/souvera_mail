<?php

declare(strict_types=1);

namespace OCA\SouveraMail\V2\Controller;

use OCA\SouveraMail\V2\Service\V2JmapProxy;
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

        // Query emails in the mailbox
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

        // Fetch email details
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
}
