<?php
declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\VacationService;
use OCA\SouveraMail\Service\VacationSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Out-of-office / vacation ("Abwesenheitsnotiz") endpoints for the simple
 * form the operator asked for, injected into the webmail UI by
 * `app/plugins/nextcloud/js/vacation.js`.
 *
 *   GET  /apps/souvera_mail/vacation
 *        → { status, available, vacation: { enabled, subject, message, from, to } }
 *
 *   POST /apps/souvera_mail/vacation
 *        body: { enabled: bool, subject: string, message: string,
 *                from?: "YYYY-MM-DD", to?: "YYYY-MM-DD" }
 *        → { status: "ok" }
 *
 * `NoAdminRequired` — vacation is a per-user feature keyed off the caller's
 * own OIDC bearer; an admin never sets another user's auto-responder.
 */
class VacationController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private VacationService $service,
        private VacationSyncService $syncService,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function index(): DataResponse
    {
        if ($this->userId === null) {
            return $this->unauthenticated();
        }
        if (!$this->service->isAvailable()) {
            return new DataResponse(['status' => 'ok', 'available' => false]);
        }
        try {
            $vacation = $this->service->get($this->userId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail vacation get failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return new DataResponse(
                ['status' => 'error', 'message' => $e->getMessage()],
                Http::STATUS_BAD_GATEWAY
            );
        }
        return new DataResponse([
            'status' => 'ok',
            'available' => true,
            'vacation' => $vacation,
            'state' => $this->safeState(),
        ]);
    }

    /**
     * Builds the combined state without ever throwing — the settings UI uses
     * this via the long-established /vacation route as a fallback for
     * instances where the newer /vacation/state route is not registered.
     */
    private function safeState(): array
    {
        try {
            return $this->syncService->getState($this->userId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: vacation state (fallback) failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return ['debug' => ['stateError' => $e->getMessage()]];
        }
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function save(): DataResponse
    {
        if ($this->userId === null) {
            return $this->unauthenticated();
        }
        if (!$this->service->isAvailable()) {
            return new DataResponse(
                ['status' => 'error', 'message' => 'sieve-unavailable'],
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }

        $enabled = (bool) $this->request->getParam('enabled', false);
        $subject = (string) $this->request->getParam('subject', '');
        $message = (string) $this->request->getParam('message', '');
        $from = (string) $this->request->getParam('from', '');
        $to = (string) $this->request->getParam('to', '');

        try {
            $this->service->set($this->userId, $enabled, $subject, $message, $from, $to);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(
                ['status' => 'error', 'message' => $e->getMessage()],
                Http::STATUS_BAD_REQUEST
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail vacation save failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return new DataResponse(
                ['status' => 'error', 'message' => $e->getMessage()],
                Http::STATUS_BAD_GATEWAY
            );
        }
        return new DataResponse(['status' => 'ok']);
    }

    /**
     * Renders a standalone "Abwesenheitsnotiz" form that any logged-in user can
     * access without opening the full webmail. The form talks to this
     * controller's JSON endpoints via fetch().
     */
    #[NoAdminRequired]
    public function form(): TemplateResponse
    {
        return new TemplateResponse('souvera_mail', 'vacation-form', [
            'apiUrl' => \OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('souvera_mail.vacation.index'),
            'requestToken' => \OCP\Util::getRequestToken(),
        ]);
    }


    /**
     * GET /apps/souvera_mail/vacation/state
     *
     * Combined state for the settings UI: NC out-of-office data, the sync
     * preference and the current Sieve vacation block.
     */
    #[NoAdminRequired]
    public function state(): DataResponse
    {
        if ($this->userId === null) {
            return $this->unauthenticated();
        }
        try {
            return new DataResponse([
                'status' => 'ok',
                'state' => $this->syncService->getState($this->userId),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: vacation state failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return new DataResponse(
                [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'state' => ['debug' => ['stateError' => $e->getMessage()]],
                ],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * POST /apps/souvera_mail/vacation/sync
     *
     * Pulls the current NC out-of-office data into the Sieve responder now.
     * Called after the frontend wrote new out-of-office data to Nextcloud
     * via the dav OCS API, or when the user toggles the sync preference.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function sync(): DataResponse
    {
        if ($this->userId === null) {
            return $this->unauthenticated();
        }
        $result = $this->syncService->syncNow($this->userId);
        return new DataResponse(['status' => $result['ok'] ? 'ok' : 'error'] + $result);
    }

    private function unauthenticated(): DataResponse
    {
        return new DataResponse(
            ['status' => 'error', 'message' => 'unauthenticated'],
            Http::STATUS_UNAUTHORIZED
        );
    }
}
