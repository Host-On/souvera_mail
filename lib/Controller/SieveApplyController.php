<?php
declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\SieveApplyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Endpoints for the "Filter nachträglich anwenden" button that the
 * operator asked for after the Sieve save bug (`CantSaveFilters[351]`)
 * was fixed.
 *
 *   GET  /apps/souvera_mail/sieve/apply/folders
 *        → list of foldable target mailboxes for the dropdown
 *
 *   POST /apps/souvera_mail/sieve/apply
 *        body: { folderId: string, limit?: int, includeRedirect?: bool }
 *        → runs the active Sieve script against the target folder
 *          and returns counters { scanned, moved, redirected, discarded,
 *          flagged, errors[] }
 *
 * Both routes are `NoAdminRequired` because Sieve filters are a per-user
 * feature — an admin doesn't apply another user's filters (they don't
 * have that user's OIDC bearer anyway).
 */
class SieveApplyController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private SieveApplyService $service,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function folders(): DataResponse
    {
        if ($this->userId === null) {
            return new DataResponse(
                ['status' => 'error', 'message' => 'unauthenticated'],
                Http::STATUS_UNAUTHORIZED
            );
        }
        try {
            $data = $this->service->listFolders($this->userId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail sieve-apply folders lookup failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return new DataResponse(
                ['status' => 'error', 'message' => $e->getMessage()],
                Http::STATUS_BAD_GATEWAY
            );
        }
        return new DataResponse($data);
    }

    #[NoAdminRequired]
    public function apply(
        ?string $folderId = null,
        ?int $limit = null,
        ?bool $includeRedirect = null
    ): DataResponse {
        if ($this->userId === null) {
            return new DataResponse(
                ['status' => 'error', 'message' => 'unauthenticated'],
                Http::STATUS_UNAUTHORIZED
            );
        }
        $folderId = (string) ($folderId ?? 'INBOX');
        if ($folderId === '') { $folderId = 'INBOX'; }
        // v0.14.42: default limit bumped 2000 → 5000. The whole apply
        // runs server-side (JMAP calls from PHP), so raising the limit
        // only costs Stalwart bandwidth — client just waits for the
        // final counter response. Operator confirmed 5000 is safe.
        $limit = $limit === null ? 5000 : (int) $limit;
        $includeRedirect = $includeRedirect ?? true;

        try {
            $result = $this->service->apply($this->userId, $folderId, $limit, $includeRedirect);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Souvera Mail sieve-apply failed for ' . $this->userId . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return new DataResponse(
                ['status' => 'error', 'message' => $e->getMessage()],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        // Log a summary line for audit + operator visibility. This is
        // the one action that can cause visible side-effects (moves,
        // redirects) so an audit trail is worth the overhead.
        if (($result['status'] ?? '') === 'ok') {
            $this->logger->info(\sprintf(
                'Souvera Mail: sieve-apply for user=%s folder=%s scanned=%d moved=%d redirected=%d discarded=%d flagged=%d',
                $this->userId,
                $folderId,
                $result['scanned'] ?? 0,
                $result['moved'] ?? 0,
                $result['redirected'] ?? 0,
                $result['discarded'] ?? 0,
                $result['flagged'] ?? 0
            ), ['app' => 'souvera_mail']);
        }

        return new DataResponse($result);
    }
}
