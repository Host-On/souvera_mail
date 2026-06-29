<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\ConnectedDevicesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * REST-style endpoints for the current user's active Nextcloud sessions.
 *
 *   GET    /connected-devices              → list
 *   DELETE /connected-devices/{id}         → revoke one (refuses the current one)
 *   POST   /connected-devices/sign-out-others → revoke every session except the current one
 *
 * All endpoints scoped to the session-authenticated user. Mutating endpoints
 * are CSRF-enforced by Nextcloud's default controller policy.
 */
class ConnectedDevicesController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private ConnectedDevicesService $devices,
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
                'Souvera Mail: connected devices list failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return $this->error($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function destroy(int $id = 0): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        if ($id <= 0) {
            return $this->error('invalid id', Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->devices->revoke($this->userId, $id);
            return new DataResponse(['status' => 'ok', 'revoked' => $id]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: connected device revoke failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return $this->error($e->getMessage(), Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    #[NoAdminRequired]
    public function signOutOthers(): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        try {
            $count = $this->devices->revokeAllOthers($this->userId);
            return new DataResponse(['status' => 'ok', 'revoked' => $count]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: sign-out-others failed: ' . $e->getMessage(),
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
