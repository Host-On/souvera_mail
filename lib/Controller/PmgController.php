<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\PmgLearningService;
use OCA\SouveraMail\Service\PmgReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * OCS API: PMG spam/ham learning (report spam, report ham / revert).
 *
 * Called by the mail UI when the user reports a mail or moves mails
 * in/out of the junk folder.
 */
class PmgController extends OCSController
{
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly PmgReportService $reportService,
        private readonly PmgLearningService $learningService,
    ) {
        parent::__construct('souvera_mail', $request);
    }

    /**
     * POST /api/v2/pmg/report/{class} — class: spam | ham
     * Body: accountId, emailId
     */
    #[NoAdminRequired]
    public function report(string $class, string $accountId, string $emailId): DataResponse
    {
        $userId = $this->requireUserId();

        if ($accountId === '' || $emailId === '') {
            throw new OCSBadRequestException('accountId and emailId are required');
        }

        $result = $this->reportService->report($userId, $accountId, $class, $emailId);

        if (isset($result['error'])) {
            throw new OCSBadRequestException($result['error']);
        }

        return new DataResponse($result);
    }

    /**
     * POST /api/v2/pmg/report/forget — revert the last report for a mail.
     * Body: accountId, emailId
     */
    #[NoAdminRequired]
    public function forget(string $accountId, string $emailId): DataResponse
    {
        $userId = $this->requireUserId();

        if ($accountId === '' || $emailId === '') {
            throw new OCSBadRequestException('accountId and emailId are required');
        }

        $result = $this->reportService->forgetLast($userId, $accountId, $emailId);

        if (isset($result['error'])) {
            throw new OCSBadRequestException($result['error']);
        }

        return new DataResponse($result);
    }

    /**
     * GET /api/v2/pmg/status — user's own reports + PMG health.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function status(): DataResponse
    {
        $userId = $this->requireUserId();

        $reports = array_map(
            static fn ($r) => $r->toArray(),
            $this->reportService->reportsForUser($userId)
        );

        return new DataResponse([
            'configured' => $this->learningService->isConfigured(),
            'base_url' => $this->learningService->getBaseUrl(),
            'reports' => $reports,
        ]);
    }

    private function requireUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSNotFoundException('Authentication required');
        }

        return $user->getUID();
    }
}
