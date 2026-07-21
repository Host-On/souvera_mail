<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\DeviceTokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * REST-style endpoints the Souvera Android client uses to register/
 * unregister its FCM registration token so the server can send push
 * notifications for new mail (see {@see \OCA\SouveraMail\Service\FcmClient}).
 *
 * The client authenticates with the Nextcloud account app-password via
 * HTTP Basic — same as any other DAV/app-password endpoint — so normal
 * Nextcloud session/userId resolution applies here; there is no custom
 * auth handling.
 *
 *   GET    /devices        → list the current user's registered tokens (debug)
 *   POST   /devices        → register/refresh a token. Body: {fcmToken, platform}
 *   DELETE /devices/{id}   → unregister one token
 *
 * All operations are scoped to the currently authenticated NC user.
 */
class DeviceTokenController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private DeviceTokenService $devices,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        try {
            $items = $this->devices->listForUser($this->userId);
            return new DataResponse(['status' => 'ok', 'items' => $items]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: device token list failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return $this->error($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function register(string $fcmToken = '', string $platform = 'android'): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        try {
            $created = $this->devices->register($this->userId, $fcmToken, $platform);
            return new DataResponse(['status' => 'ok'] + $created, Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: device token register failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return $this->error($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function unregister(int $id = 0): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        try {
            $this->devices->unregister($this->userId, $id);
            return new DataResponse(['status' => 'ok', 'revoked' => $id]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: device token unregister failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return $this->error($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    private function error(string $message, int $status): DataResponse
    {
        return new DataResponse(['status' => 'error', 'message' => $message], $status);
    }
}
