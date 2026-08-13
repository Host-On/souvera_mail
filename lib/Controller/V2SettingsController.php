<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\AppPasswordService;
use OCA\SouveraMail\Service\QuotaService;
use OCA\SouveraMail\Service\SignatureStoreService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class V2SettingsController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private AppPasswordService $appPasswordService,
        private QuotaService $quotaService,
        private SignatureStoreService $signatureStore,
        private IConfig $config,
        private LoggerInterface $logger,
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
                return new JSONResponse(['used' => 0, 'total' => 0, 'unlimited' => false]);
            }
            if (!$this->quotaService->isAvailable()) {
                return new JSONResponse(['used' => 0, 'total' => 0, 'unlimited' => false]);
            }
            $data = $this->quotaService->getForUser($user->getUID());
            return new JSONResponse(['used' => $data['used'] ?? 0, 'total' => $data['total'] ?? 0, 'unlimited' => $data['unlimited'] ?? false]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail quota fetch failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
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
            'signatureHtml' => $this->signatureStore->read($uid),
            'signatureEnabled' => $this->getPref($uid, 'pref_signature_enabled', '0') === '1',
            'identitySignatures' => $this->readIdentitySignatures($uid),
            'replyPosition' => $this->getPref($uid, 'pref_reply_position', 'above'),
            'signaturePosition' => $this->getPref($uid, 'pref_signature_position', 'above'),
            'messagesPerPage' => (int) $this->getPref($uid, 'pref_messages_per_page', '50'),
            'readingPane' => $this->getPref($uid, 'pref_reading_pane', '1') === '1',
            'remoteImages' => $this->getPref($uid, 'pref_remote_images', 'never'),
            'verticalLayout' => $this->getPref($uid, 'pref_vertical_layout', '0') === '1',
            'autoRefresh' => (int) $this->getPref($uid, 'pref_auto_refresh', '60'),
            'notificationSound' => $this->getPref($uid, 'pref_notification_sound', 'none'),
            'defaultIdentityId' => $this->getPref($uid, 'pref_default_identity', ''),
            'navCollapsedGroups' => \json_decode($this->getPref($uid, 'pref_nav_collapsed_groups', '[]'), true) ?: [],
            'aliasDisplayNames' => \json_decode($this->getPref($uid, 'pref_alias_names', '{}'), true) ?: [],
            'account' => [
                'email' => $user->getSystemEMailAddress() ?? $uid,
                'server' => '',
                'version' => \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion('souvera_mail'),
            ],
        ]);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function updatePreferences(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Not authenticated'], 401);
        }
        $uid = $user->getUID();
        $body = \json_decode(\file_get_contents('php://input'), true);
        if (!\is_array($body)) {
            return new JSONResponse(['error' => 'invalid request body'], 400);
        }

        // Validate positions BEFORE persisting anything, so an invalid value
        // can never leave a partially saved preferences state behind.
        foreach (['replyPosition', 'signaturePosition'] as $positionField) {
            if (\array_key_exists($positionField, $body)
                && !\in_array((string) $body[$positionField], ['above', 'below'], true)) {
                return new JSONResponse(['error' => 'invalid position value'], 400);
            }
        }

        $allowed = [
            'signatureEnabled' => 'pref_signature_enabled',
            'replyPosition' => 'pref_reply_position',
            'signaturePosition' => 'pref_signature_position',
            'messagesPerPage' => 'pref_messages_per_page',
            'readingPane' => 'pref_reading_pane',
            'remoteImages' => 'pref_remote_images',
            'verticalLayout' => 'pref_vertical_layout',
            'autoRefresh' => 'pref_auto_refresh',
            'notificationSound' => 'pref_notification_sound',
            'defaultIdentityId' => 'pref_default_identity',
            'navCollapsedGroups' => 'pref_nav_collapsed_groups',
            'aliasDisplayNames' => 'pref_alias_names',
        ];

        // The signature HTML is stored as a FILE (64 KB DB limit would
        // break signatures with embedded base64 images) — see SignatureStoreService.
        if (\array_key_exists('signatureHtml', $body)) {
            try {
                $this->signatureStore->write($uid, (string) $body['signatureHtml']);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Souvera Mail: signature save failed: ' . $e->getMessage(),
                    ['app' => 'souvera_mail', 'exception' => $e]
                );
                return new JSONResponse(['error' => 'Failed to save signature'], 500);
            }
        }

        foreach ($allowed as $field => $key) {
            if (\array_key_exists($field, $body)) {
                $value = \is_array($body[$field])
                    ? \json_encode($body[$field], \JSON_UNESCAPED_SLASHES)
                    : (string) $body[$field];
                $this->setPref($uid, $key, $value);
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

    /**
     * Per-identity signature overrides: map identityId => {html, enabled}.
     * Sources: the enabled-flag map from the user preference and the
     * signature HTML files from SignatureStoreService.
     *
     * @return array<string, array{html: string, enabled: bool}>
     */
    private function readIdentitySignatures(string $uid): array
    {
        $raw = $this->getPref($uid, 'pref_identity_signatures', '');
        $map = \json_decode($raw, true);
        if (!\is_array($map)) {
            return [];
        }
        $out = [];
        foreach ($map as $id => $enabled) {
            $html = $this->signatureStore->readFor($uid, (string) $id);
            if ($html === '') {
                continue;
            }
            $out[(string) $id] = ['html' => $html, 'enabled' => (bool) $enabled];
        }
        return $out;
    }
}
