<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\AppPasswordService;
use OCA\SouveraMail\Service\QuotaService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
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
        private IConfig $config,
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
            return new JSONResponse(['used' => $data['used'] ?? 0, 'total' => $data['total'] ?? 0, 'unlimited' => $data['unlimited'] ?? false]);
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

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function preferences(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $uid = $user->getUID();

        return new JSONResponse([
            'signatureHtml' => $this->getPref($uid, 'pref_signature_html', ''),
            'signatureEnabled' => $this->getPref($uid, 'pref_signature_enabled', '0') === '1',
            'messagesPerPage' => (int) $this->getPref($uid, 'pref_messages_per_page', '50'),
            'readingPane' => $this->getPref($uid, 'pref_reading_pane', '1') === '1',
            'remoteImages' => $this->getPref($uid, 'pref_remote_images', 'never'),
            'account' => [
                'email' => $user->getSystemEMailAddress() ?? $uid,
                'server' => '',
            ],
        ]);
    }

    #[NoAdminRequired]
    public function updatePreferences(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $uid = $user->getUID();
        $body = \json_decode(\file_get_contents('php://input'), true);

        $allowed = [
            'signatureHtml' => 'pref_signature_html',
            'signatureEnabled' => 'pref_signature_enabled',
            'messagesPerPage' => 'pref_messages_per_page',
            'readingPane' => 'pref_reading_pane',
            'remoteImages' => 'pref_remote_images',
        ];

        foreach ($allowed as $field => $key) {
            if (\array_key_exists($field, $body)) {
                $this->setPref($uid, $key, (string) $body[$field]);
            }
        }

        return new JSONResponse(['success' => true]);
    }

    private function getPref(string $uid, string $key, string $default): string
    {
        return $this->config->getUserValue($uid, 'souvera_mail', $key, $default);
    }

    private function setPref(string $uid, string $key, string $value): void
    {
        $this->config->setUserValue($uid, 'souvera_mail', $key, $value);
    }
}
