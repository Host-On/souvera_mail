<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\BimiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BimiController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private BimiService $bimi,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/bimi?email=user@domain.com
     *
     * Returns BIMI logo URL for the sender's domain.
     * Response is cached client-side for 7 days.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function resolve(): DataResponse
    {
        $emailOrDomain = \trim((string) ($this->request->getParam('email') ?? $this->request->getParam('domain') ?? ''));
        if ($emailOrDomain === '') {
            return new DataResponse(['error' => 'email or domain required'], 400);
        }

        try {
            $result = $this->bimi->resolve($emailOrDomain);
            $response = new DataResponse([
                'logoUrl' => $result['logoUrl'],
                'verified' => $result['verified'],
                'domain' => $result['domain'],
            ]);
            $response->cacheFor(7 * 24 * 3600);
            return $response;
        } catch (\Throwable $e) {
            $this->logger->error('BIMI resolve failed: ' . $e->getMessage());
            return new DataResponse(['error' => $e->getMessage()], 500);
        }
    }
}
