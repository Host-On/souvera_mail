<?php

declare(strict_types=1);

namespace OCA\Smail\Controller;

use OCA\Smail\Service\AppPasswordService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * REST-style endpoints for the current user's Stalwart App Passwords.
 *
 * All operations are scoped to the currently authenticated NC user — the
 * controller never receives a user ID from the client, so there is no
 * privilege-escalation surface. The actual Stalwart principal is resolved
 * server-side via souvera_central's StalwartService.
 *
 * Endpoints:
 *  - GET    /app-passwords         → list (no secrets)
 *  - POST   /app-passwords         → create (returns plaintext secret ONCE)
 *  - DELETE /app-passwords/{id}    → revoke
 */
class AppPasswordController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private AppPasswordService $appPasswords,
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
        if (!$this->appPasswords->isAvailable()) {
            return $this->error(
                'App passwords are unavailable: souvera_central + Stalwart API URL + H2CK/oidc must be configured.',
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
        try {
            $items = $this->appPasswords->listForUser($this->userId);
            return new DataResponse(['status' => 'ok', 'items' => $items]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'App password list failed: ' . $e->getMessage(),
                ['app' => 'smail', 'exception' => $e]
            );
            return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }
    }

    #[NoAdminRequired]
    public function create(string $description = ''): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        if (\trim($description) === '') {
            return $this->error('description must not be empty', Http::STATUS_BAD_REQUEST);
        }
        if (!$this->appPasswords->isAvailable()) {
            return $this->error(
                'App passwords are unavailable: souvera_central + Stalwart API URL + H2CK/oidc must be configured.',
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
        try {
            $created = $this->appPasswords->createForUser($this->userId, $description);
            // The `secret` is plaintext and must be displayed exactly once.
            return new DataResponse(['status' => 'ok', 'created' => $created], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'App password create failed: ' . $e->getMessage(),
                ['app' => 'smail', 'exception' => $e]
            );
            return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }
    }

    #[NoAdminRequired]
    public function destroy(string $id = ''): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        if (\trim($id) === '') {
            return $this->error('id must not be empty', Http::STATUS_BAD_REQUEST);
        }
        if (!$this->appPasswords->isAvailable()) {
            return $this->error(
                'App passwords are unavailable: souvera_central + Stalwart API URL + H2CK/oidc must be configured.',
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
        try {
            $this->appPasswords->revokeForUser($this->userId, $id);
            return new DataResponse(['status' => 'ok', 'revoked' => $id]);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'App password revoke failed: ' . $e->getMessage(),
                ['app' => 'smail', 'exception' => $e]
            );
            return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }
    }

    private function error(string $message, int $status): DataResponse
    {
        return new DataResponse(['status' => 'error', 'message' => $message], $status);
    }
}
