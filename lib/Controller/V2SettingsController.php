<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\AppPasswordService;
use OCA\SouveraMail\Service\QuotaService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class V2SettingsController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private AppPasswordService $appPasswordService,
        private QuotaService $quotaService,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function quota(): JSONResponse
    {
        try {
            $user = $this->userSession->getUser();
            if ($user === null) {
                return new JSONResponse(['used' => 0, 'total' => 0]);
            }
            if (!$this->quotaService->isAvailable()) {
                return new JSONResponse(['used' => 0, 'total' => 0]);
            }
            $data = $this->quotaService->getForUser($user->getUID());
            return new JSONResponse(['used' => $data['used'] ?? 0, 'total' => $data['total'] ?? 0]);
        } catch (\Throwable $e) {
            return new JSONResponse(['used' => 0, 'total' => 0, 'error' => $e->getMessage()]);
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function appPasswords(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        try {
            $list = $this->appPasswordService->listForUser($user->getUID());
            return new JSONResponse(['passwords' => $list]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    public function createAppPassword(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $body = \json_decode(\file_get_contents('php://input'), true);
        $name = \trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return new JSONResponse(['error' => 'Name required'], 400);
        }
        try {
            $result = $this->appPasswordService->createForUser($user->getUID(), $name);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[NoAdminRequired]
    public function deleteAppPassword(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        try {
            $this->appPasswordService->revokeForUser($user->getUID(), $id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
