<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\DevOps\WebhookUpdateTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class WebhookController extends Controller
{
    use WebhookUpdateTrait;

    public function __construct(IRequest $request)
    {
        parent::__construct('souvera_mail', $request);
    }

    protected function getAppId(): string
    {
        return 'souvera_mail';
    }

    /**
     * @NoCSRFRequired
     * @PublicPage
     */
    public function update(): DataResponse
    {
        return $this->runUpdate();
    }
}
