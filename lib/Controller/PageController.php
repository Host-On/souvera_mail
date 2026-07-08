<?php

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\DomainConfigService;
use OCA\SouveraMail\Util\EngineHelper;
use OCA\SouveraMail\ContentSecurityPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroupManager;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private INavigationManager $navigationManager,
        private DomainConfigService $domainService,
        private IGroupManager $groupManager,
        private EngineHelper $engineHelper,
        private IURLGenerator $urlGenerator,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /** @return TemplateResponse|void */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $target = '')
    {
        // No domain configured → show setup hint instead of useless login form
        if (empty($this->domainService->listDomains())) {
            $isAdmin = $this->userId && $this->groupManager->isAdmin($this->userId);
            return new TemplateResponse('souvera_mail', 'not_configured', [
                'isAdmin' => $isAdmin,
            ]);
        }

        $queryString = $this->request->server['QUERY_STRING'] ?? '';
        if ($queryString !== '') {
            $this->engineHelper->loadApp();
            $this->engineHelper->startApp(true);

            return;
        }

        $this->navigationManager->setActiveEntry('souvera_mail');

        \OCP\Util::addStyle('souvera_mail', 'embed');
        // Souvera Mail v0.14.11: welcome-wizard for the IMAP-import
        // flow, now built on Vue 3 + @nextcloud/vue v9 following the
        // Souvera Design System (shared with Central / Shield). The
        // bundle carries its own CSS via style-loader, so a separate
        // addStyle call is no longer needed. The wizard's own boot
        // logic decides via GET /migration/welcome-state whether to
        // actually render anything — a missing provider.tools token
        // in Central keeps every visible element hidden.
        \OCP\Util::addScript('souvera_mail', 'souvera_mail-migration-wizard');

        $this->engineHelper->startApp();

        // SSO-only: if the auto-login could not establish a mail session
        // (expired/invalid OIDC token), show a clear error instead of the
        // engine's useless login form (LoginProcess rejects passwords anyway).
        if (!$this->engineHelper->hasAuthenticatedAccount()) {
            return new TemplateResponse('souvera_mail', 'auth_error', [
                'isOidcLogin' => $this->engineHelper->isOIDCLogin(),
                'reloadUrl' => $this->urlGenerator->linkToRoute('souvera_mail.page.index'),
            ]);
        }

        $oConfig = \Smail\Engine\Api::Config();
        $oActions = \Smail\Engine\Api::Actions();
        $oHttp = \Smail\Mail\Base\Http::SingletonInstance();
        $oServiceActions = new \Smail\Engine\ServiceActions($oHttp, $oActions);
        $sLanguage = $oActions->GetLanguage(false);

        $csp = new ContentSecurityPolicy();
        $sNonce = $csp->getEngineNonce();

        $params = [
            'Admin' => 0,
            'LoadingDescriptionEsc' => \htmlspecialchars(
                $oConfig->Get('webmail', 'loading_description', 'Souvera Mail'),
                ENT_QUOTES | ENT_IGNORE,
                'UTF-8'
            ),
            'BaseTemplates' => \Smail\Engine\Utils::ClearHtmlOutput(
                $oServiceActions->compileTemplates()
            ),
            'BaseAppBootScript' => \file_get_contents(
                APP_VERSION_ROOT_PATH . 'static/js/boot.js'
            ),
            'BaseAppBootScriptNonce' => $sNonce,
            'BaseLanguage' => $oActions->compileLanguage($sLanguage, false),
            'BaseAppBootCss' => \file_get_contents(APP_VERSION_ROOT_PATH . 'static/css/boot.css'),
            'BaseAppThemeCss' => \preg_replace(
                '/\\s*([:;{},]+)\\s*/s',
                '$1',
                $oActions->compileCss($oActions->GetTheme(false), false)
            ),
        ];

        \OCP\Util::addHeader('link', [
            'type' => 'text/css',
            'rel' => 'stylesheet',
            'href' => \Smail\Engine\Utils::WebStaticPath('css/app.css'),
        ], '');

        $response = new TemplateResponse('souvera_mail', 'index_embed', $params);

        $response->setContentSecurityPolicy($csp);

        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function appGet(): void
    {
        $this->engineHelper->startApp(true);
    }

    // NoCSRFRequired: the engine's internal AJAX does not carry Nextcloud CSRF
    // tokens; it uses its own CSRF protection within the engine session.
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function appPost(): void
    {
        $this->engineHelper->startApp(true);
    }

    // NoCSRFRequired: the engine's internal AJAX does not carry Nextcloud CSRF
    // tokens; it uses its own CSRF protection within the engine session.
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function indexPost(): void
    {
        $this->engineHelper->startApp(true);
    }
}
