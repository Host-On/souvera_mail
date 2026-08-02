<?php

declare(strict_types=1);

namespace OCA\SouveraMail\V2\Controller;

use OCA\SouveraMail\Service\AppPasswordService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class V2SettingsController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private AppPasswordService $appPasswordService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/settings/quota
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function quota(): JSONResponse
    {
        try {
            $quota = $this->appPasswordService->getQuota();
            return new JSONResponse([
                'used' => $quota['used'] ?? 0,
                'total' => $quota['total'] ?? 0,
            ]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /apps/souvera_mail/api/v2/settings/app-passwords
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function appPasswords(): JSONResponse
    {
        try {
            $passwords = $this->appPasswordService->listAppPasswords();
            return new JSONResponse(['passwords' => $passwords]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /apps/souvera_mail/api/v2/settings/app-passwords
     * { name }
     */
    #[NoAdminRequired]
    public function createAppPassword(): JSONResponse
    {
        $body = \json_decode(\file_get_contents('php://input'), true);
        $name = \trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return new JSONResponse(['error' => 'Name required'], 400);
        }

        try {
            $password = $this->appPasswordService->createAppPassword($name);
            return new JSONResponse($password);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * DELETE /apps/souvera_mail/api/v2/settings/app-passwords/{id}
     */
    #[NoAdminRequired]
    public function deleteAppPassword(string $id): JSONResponse
    {
        try {
            $this->appPasswordService->deleteAppPassword((int) $id);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], 500);
        }
    }
}
