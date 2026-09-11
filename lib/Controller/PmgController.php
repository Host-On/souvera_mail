<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\PmgLearningService;
use OCA\SouveraMail\Service\PmgReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * OCS API: PMG spam/ham learning (report spam, report ham / revert).
 *
 * Called by the mail UI when the user reports a mail or moves mails
 * in/out of the junk folder.
 */
class PmgController extends OCSController
{
    public function __construct(
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly PmgReportService $reportService,
        private readonly PmgLearningService $learningService,
        private readonly IClientService $httpClientService,
        private readonly IURLGenerator $urlGenerator,
        private readonly ISession $session,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct('souvera_mail', $request);
    }

    /**
     * POST /api/v2/pmg/report/{class} — class: spam | ham
     * Body: accountId, emailId
     */
    #[NoAdminRequired]
    public function report(string $class, string $accountId, string $emailId): DataResponse
    {
        $userId = $this->requireUserId();

        if ($emailId === '') {
            throw new OCSBadRequestException('emailId is required');
        }

        $result = $this->reportService->report($userId, $accountId, $class, $emailId);

        if (isset($result['error'])) {
            throw new OCSBadRequestException($result['error']);
        }

        return new DataResponse($result);
    }

    /**
     * POST /api/v2/pmg/report/forget — revert the last report for a mail.
     * Body: accountId, emailId
     */
    #[NoAdminRequired]
    public function forget(string $accountId, string $emailId): DataResponse
    {
        $userId = $this->requireUserId();

        if ($emailId === '') {
            throw new OCSBadRequestException('emailId is required');
        }

        $result = $this->reportService->forgetLast($userId, $accountId, $emailId);

        if (isset($result['error'])) {
            throw new OCSBadRequestException($result['error']);
        }

        return new DataResponse($result);
    }

    /**
     * POST /api/v2/pmg/report/ham-shield — release a Shield quarantine mail
     * as ham. Body: {id} (Shield quarantine id).
     *
     * The original EML is fetched from Shield (not JMAP — the mail is not in
     * the user's JMAP store before release) and trained as ham directly.
     */
    #[NoAdminRequired]
    public function reportShieldHam(string $id): DataResponse
    {
        $this->requireUserId();

        if ($id === '') {
            throw new OCSBadRequestException('id is required');
        }

        // Loopback-Call braucht freigegebene Session-Lock.
        $this->session->close();

        $raw = $this->fetchShieldRawMail($id);
        if ($raw === null || $raw === '') {
            throw new OCSBadRequestException('Shield quarantine mail could not be fetched');
        }

        $result = $this->learningService->learn('ham', 'learn', $raw);

        if (isset($result['error'])) {
            throw new OCSBadRequestException($result['error']);
        }

        // Kein Tracking-Eintrag: die Mail liegt vor dem Release noch nicht im
        // JMAP-Store — der Vermerk dient nur der Junk-Restore-Unterscheidung
        // (Rücknahme vs. False-Positive), die hier nicht greifen kann.
        return new DataResponse($result);
    }

    /**
     * GET /api/v2/pmg/status — user's own reports + PMG health.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function status(): DataResponse
    {
        $userId = $this->requireUserId();

        $reports = array_map(
            static fn ($r) => $r->toArray(),
            $this->reportService->reportsForUser($userId)
        );

        return new DataResponse([
            'configured' => $this->learningService->isConfigured(),
            'base_url' => $this->learningService->getBaseUrl(),
            'reports' => $reports,
        ]);
    }

    private function requireUserId(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSNotFoundException('Authentication required');
        }

        return $user->getUID();
    }

    /**
     * Holt die Original-EML einer Shield-Quarantäne-Mail (base64 im JSON).
     *
     * @return string|null decoded RFC 822 bytes or null on failure
     */
    private function fetchShieldRawMail(string $id): ?string
    {
        try {
            $client = $this->httpClientService->newClient();
            $url = $this->urlGenerator->getAbsoluteURL('/apps/souvera_shield/api/internal/spam/raw?id=' . \urlencode($id));
            $response = $client->get($url, [
                'timeout' => 30,
                'headers' => \array_merge(
                    ['Accept' => 'application/json'],
                    $this->forwardSessionCookies(),
                ),
                'http_errors' => false,
            ]);

            $data = \json_decode((string) $response->getBody(), true);
            $raw = $data['raw'] ?? null;
            if (!\is_string($raw) || $raw === '') {
                $this->logger->warning('PMG: Shield raw mail response missing raw field', [
                    'app' => 'souvera_mail',
                    'status' => $response->getStatusCode(),
                ]);
                return null;
            }

            $decoded = \base64_decode($raw, true);
            return $decoded === false ? null : $decoded;
        } catch (\Throwable $e) {
            $this->logger->error('PMG: Shield raw mail fetch failed: ' . $e->getMessage(), [
                'app' => 'souvera_mail',
                'exception' => $e,
            ]);
            return null;
        }
    }

    private function forwardSessionCookies(): array
    {
        $headers = [];
        // Forward NC session cookie so Shield can resolve the user
        if (isset($_SERVER['HTTP_COOKIE'])) {
            $headers['Cookie'] = $_SERVER['HTTP_COOKIE'];
        }
        // Also forward the request token for CSRF-protected POST endpoints
        $reqToken = \OCP\Util::getRequestToken();
        if ($reqToken !== null && $reqToken !== '') {
            $headers['requesttoken'] = $reqToken;
        }
        return $headers;
    }
}
