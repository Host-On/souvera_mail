<?php

declare(strict_types=1);

namespace OCA\Smail\Controller;

use OCA\Smail\Service\QuotaService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Read-only endpoint that exposes the current user's Stalwart mailbox quota
 * usage to the embedded webmail engine's JS layer for live display.
 *
 * GET /index.php/apps/smail/quota → { status, used, total, percentage, ... }
 *
 * Same-origin from the engine iframe → cookie auth, no CSRF needed for GET.
 */
class QuotaController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private QuotaService $quotas,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(): DataResponse
    {
        if ($this->userId === null) {
            return new DataResponse(
                ['status' => 'error', 'message' => 'unauthenticated'],
                Http::STATUS_UNAUTHORIZED
            );
        }
        if (!$this->quotas->isAvailable()) {
            return new DataResponse(
                ['status' => 'error', 'message' => 'quota service unavailable'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
        try {
            $data = $this->quotas->getForUser($this->userId);
            return new DataResponse(\array_merge(['status' => 'ok'], $data));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail quota fetch failed: ' . $e->getMessage(),
                ['app' => 'smail', 'exception' => $e]
            );
            return new DataResponse(
                ['status' => 'error', 'message' => $e->getMessage()],
                Http::STATUS_BAD_GATEWAY
            );
        }
    }
}
