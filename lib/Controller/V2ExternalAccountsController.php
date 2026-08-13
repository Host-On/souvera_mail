<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\ExternalAccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * External IMAP/SMTP account management for v2 Vue-3 frontend.
 *
 * Handles CRUD for external accounts stored in encrypted app-config.
 * Connection testing uses PHP's native IMAP functions.
 */
class V2ExternalAccountsController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private ExternalAccountService $accountService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/external/accounts
     *
     * List all external accounts for the current user.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function list(): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $accounts = $this->accountService->listForUser($uid);
            return new JSONResponse(['accounts' => $accounts]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /apps/souvera_mail/api/v2/external/accounts
     *
     * Add a new external account.
     * Body: {email, imap_host, imap_port, imap_ssl, smtp_host, smtp_port,
     *        smtp_ssl, username, password, provider}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function create(): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        try {
            $account = $this->accountService->add($uid, $body);
            return new JSONResponse(['account' => $account], Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * DELETE /apps/souvera_mail/api/v2/external/accounts/{id}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function delete(string $id): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $this->accountService->delete($uid, $id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * POST /apps/souvera_mail/api/v2/external/accounts/{id}/test
     *
     * Test IMAP connection for an account.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function test(string $id): JSONResponse
    {
        $uid = $this->getUserId();
        if ($uid === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $account = $this->accountService->getWithPassword($uid, $id);
        if ($account === null) {
            return new JSONResponse(['error' => 'Account not found'], Http::STATUS_NOT_FOUND);
        }
        $result = $this->accountService->testImap($account);
        return new JSONResponse($result);
    }

    private function getUserId(): ?string
    {
        $user = $this->userSession->getUser();
        return $user !== null ? $user->getUID() : null;
    }
}
