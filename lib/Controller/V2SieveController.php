<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\SieveScriptService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Sieve filter management API for the v2 Vue-3 frontend.
 *
 * Proxies to the existing SieveScriptService (JMAP SieveScript/get, /set,
 * Blob/upload). The SieveScriptService handles all OIDC token resolution
 * and JMAP transport internally.
 */
class V2SieveController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly SieveScriptService $sieve,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/sieve
     *
     * List all Sieve scripts for the current user with metadata and body.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function list(): JSONResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        if (!$this->sieve->isAvailable()) {
            return new JSONResponse(['scripts' => [], 'unavailable' => true]);
        }

        try {
            $result = $this->sieve->listScriptsWithBodies($userId);
            return new JSONResponse([
                'scripts' => $result['scripts'] ?? [],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Sieve list failed: ' . $e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /apps/souvera_mail/api/v2/sieve
     *
     * Create or update a Sieve script.
     * Body: {name: "...", body: "require ..."}  or {id: "...", name: "...", body: "..."}
     */
    #[NoAdminRequired]
    public function save(): JSONResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $payload = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $name = \trim((string) ($payload['name'] ?? ''));
        $body = \trim((string) ($payload['body'] ?? ''));

        if ($name === '' || $body === '') {
            return new JSONResponse(['error' => 'Name and body are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $blobId = $this->sieve->saveScript($userId, $name, $body);
            return new JSONResponse(['id' => $blobId, 'name' => $name]);
        } catch (\Throwable $e) {
            $this->logger->error('Sieve save failed: ' . $e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * PUT /apps/souvera_mail/api/v2/sieve/{name}/activate
     *
     * Activate (or deactivate) a Sieve script.
     * Body: {active: true}
     */
    #[NoAdminRequired]
    public function activate(string $name): JSONResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $payload = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $active = (bool) ($payload['active'] ?? true);

        try {
            if ($active) {
                $this->sieve->activateScript($userId, $name);
            }
            return new JSONResponse(['success' => true, 'active' => $active]);
        } catch (\Throwable $e) {
            $this->logger->error('Sieve activate failed: ' . $e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * DELETE /apps/souvera_mail/api/v2/sieve/{name}
     *
     * Delete a Sieve script.
     */
    #[NoAdminRequired]
    public function delete(string $name): JSONResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $this->sieve->deleteScript($userId, $name);
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('Sieve delete failed: ' . $e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * POST /apps/souvera_mail/api/v2/sieve/validate
     *
     * Validate Sieve script syntax.
     * Body: {body: "require ..."}
     * Returns {valid: true} or {valid: false, error: "..."}
     */
    #[NoAdminRequired]
    public function validate(): JSONResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $payload = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $body = \trim((string) ($payload['body'] ?? ''));

        if ($body === '') {
            return new JSONResponse(['valid' => false, 'error' => 'Empty script body']);
        }

        try {
            // Upload as temp blob, then validate
            $result = $this->sieve->listScriptsWithBodies($userId);
            $bearer = $result['bearer'] ?? '';
            $accountId = $result['accountId'] ?? '';

            if ($bearer === '' || $accountId === '') {
                return new JSONResponse(['valid' => false, 'error' => 'Cannot resolve Stalwart account']);
            }

            $blobId = $this->sieve->uploadBlob($accountId, $bearer, $body);

            // Validate via JMAP
            $stalwart = new \OCA\SouveraMail\Service\StalwartAdminService(
                \OCP\Server::get(\OCP\IConfig::class),
                \OCP\Server::get(\OCP\Http\Client\IClientService::class),
                \OCP\Server::get(\Psr\Log\LoggerInterface::class),
            );

            $validateResult = $stalwart->jmapCall($bearer, [
                ['SieveScript/validate', [
                    'accountId' => $accountId,
                    'blobId' => $blobId,
                ], 'v0'],
            ], ['urn:ietf:params:jmap:sieve']);

            $err = $validateResult['methodResponses'][0][1]['error'] ?? null;
            if ($err !== null) {
                return new JSONResponse(['valid' => false, 'error' => \json_encode($err)]);
            }

            return new JSONResponse(['valid' => true]);
        } catch (\Throwable $e) {
            return new JSONResponse(['valid' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * POST /apps/souvera_mail/api/v2/sieve/rebuild
     *
     * Rebuilds and activates the combined main script from all enabled
     * filters. Optional body {disabled: ["name", ...]} persists which
     * filters the user switched OFF before rebuilding.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function rebuild(): JSONResponse
    {
        $userId = $this->getUserId();
        if ($userId === null) {
            return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $payload = \json_decode(\file_get_contents('php://input'), true) ?? [];
        if (\is_array($payload['disabled'] ?? null)) {
            $this->sieve->setDisabledFilters($userId, $payload['disabled']);
        }

        try {
            $result = $this->sieve->rebuildActiveScript($userId);
            return new JSONResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('Sieve rebuild failed: ' . $e->getMessage(), ['exception' => $e]);
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    // ---------------------------------------------------------------

    private function getUserId(): ?string
    {
        $user = $this->userSession->getUser();
        return $user !== null ? $user->getUID() : null;
    }
}
