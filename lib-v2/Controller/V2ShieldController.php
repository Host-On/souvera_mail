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
 * Shield spam/quarantine integration.
 *
 * Displays junk-mailbox content + offers report-spam / not-spam actions.
 * The actual spam filtering is delegated to Shield (souvera_shield NC app)
 * which manages Stalwart's Sieve rules.
 */
class V2ShieldController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private V2JmapProxy $jmap,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/shield/quarantine?limit=50
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function quarantine(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $limit = \min(100, \max(1, (int) ($this->request->getParam('limit') ?? 50)));
        $queryResult = $this->jmap->singleCall('Email/query', [
            'accountId' => $accountId,
            'filter' => ['inMailboxOtherThan' => ['']],
            'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
            'position' => 0,
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
            'properties' => ['id', 'subject', 'from', 'receivedAt', 'preview', 'mailboxIds', 'keywords'],
        ]);

        $emails = [];
        foreach ($getResult['data']['list'] ?? [] as $email) {
            $emails[] = [
                'id' => $email['id'] ?? '',
                'subject' => $email['subject'] ?? '',
                'fromAddress' => $email['from'][0]['email'] ?? '',
                'fromName' => $email['from'][0]['name'] ?? '',
                'receivedAt' => $email['receivedAt'] ?? '',
                'preview' => $email['preview'] ?? '',
                'isRead' => isset(($email['keywords'] ?? [])['$seen']),
            ];
        }

        return new JSONResponse(['emails' => $emails, 'total' => $queryResult['data']['total'] ?? \count($ids)]);
    }

    /**
     * POST /apps/souvera_mail/api/v2/shield/report
     * { emailId, action: "spam" | "notspam" }
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function report(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $body = \json_decode(\file_get_contents('php://input'), true);
        $emailId = $body['emailId'] ?? '';
        $action = $body['action'] ?? '';

        if ($emailId === '' || !\in_array($action, ['spam', 'notspam'], true)) {
            return new JSONResponse(['error' => 'Invalid parameters'], 400);
        }

        // Move to Junk or Inbox based on action.
        $targetRole = $action === 'spam' ? 'junk' : 'inbox';

        $mailboxesResult = $this->jmap->singleCall('Mailbox/get', ['accountId' => $accountId]);
        if (isset($mailboxesResult['error'])) {
            return new JSONResponse($mailboxesResult, 500);
        }

        $mailboxId = '';
        foreach ($mailboxesResult['data']['list'] ?? [] as $mb) {
            if (($mb['role'] ?? '') === $targetRole) {
                $mailboxId = $mb['id'];
                break;
            }
        }

        if ($mailboxId === '') {
            return new JSONResponse(['error' => "No {$targetRole} mailbox found"], 500);
        }

        $result = $this->jmap->call([
            ['Email/set', [
                'accountId' => $accountId,
                'update' => [$emailId => ['mailboxIds' => [$mailboxId => true]]],
            ]],
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        return new JSONResponse(['success' => true]);
    }
}
