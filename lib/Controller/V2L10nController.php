<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\L10nService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * GET /apps/souvera_mail/api/v2/l10n
 *
 * Runtime translation catalog for the v2 client. The client falls back to
 * this endpoint when the inline template injection was not available
 * (e.g. older Nextcloud versions without the $cspNonce template variable,
 * which would silently CSP-block the inline script and leave the UI in
 * English). Response is keyed by the exact source strings the app uses.
 */
class V2L10nController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private L10nService $l10nService,
        private IL10N $l10n,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        try {
            $lang = $this->l10n->getLanguageCode();
        } catch (\Throwable) {
            $lang = 'en';
        }
        $translations = $this->l10nService->getCatalog($lang);
        return new JSONResponse([
            'language' => $lang,
            'translations' => $translations,
        ]);
    }
}
