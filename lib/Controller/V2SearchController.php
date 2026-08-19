<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\V2JmapProxy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class V2SearchController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private V2JmapProxy $jmap,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/search?q=term&limit=50
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(): JSONResponse
    {
        $accountId = $this->jmap->getCurrentAccountId();
        if ($accountId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }

        $query = \trim((string) ($this->request->getParam('q') ?? ''));
        $limit = \min(100, \max(1, (int) ($this->request->getParam('limit') ?? 50)));

        if ($query === '') {
            return new JSONResponse(['results' => [], 'total' => 0]);
        }

        $result = $this->jmap->singleCall('Email/query', [
            'accountId' => $accountId,
            'filter' => ['text' => $query],
            'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
            'position' => 0,
            'limit' => $limit,
        ]);

        if (isset($result['error'])) {
            return new JSONResponse($result, 500);
        }

        $ids = $result['data']['ids'] ?? [];
        if (empty($ids)) {
            return new JSONResponse(['results' => [], 'total' => 0]);
        }

        $getResult = $this->jmap->singleCall('Email/get', [
            'accountId' => $accountId,
            'ids' => $ids,
            'properties' => ['id', 'subject', 'from', 'to', 'receivedAt', 'size', 'hasAttachment', 'keywords', 'preview', 'mailboxIds'],
        ]);

        $emails = [];
        foreach ($getResult['data']['list'] ?? [] as $email) {
            $fromAddr = ($email['from'][0]['email'] ?? '');
            $fromName = ($email['from'][0]['name'] ?? '');
            $keywords = $email['keywords'] ?? [];
            $emails[] = [
                'id' => $email['id'] ?? '',
                'subject' => $email['subject'] ?? '',
                'fromAddress' => $fromAddr,
                'fromName' => $fromName,
                'receivedAt' => $email['receivedAt'] ?? '',
                'isRead' => isset($keywords['$seen']),
                'preview' => $email['preview'] ?? '',
                'mailboxIds' => $email['mailboxIds'] ?? [],
            ];
        }

        return new JSONResponse(['results' => $emails, 'total' => $result['data']['total'] ?? \count($ids)]);
    }
}
