<?php

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\DomainConfigService;
use OCA\SouveraMail\Service\L10nService;
use OCA\SouveraMail\Util\EngineHelper;
use OCA\SouveraMail\ContentSecurityPolicy as LocalCSP;
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
        private L10nService $l10nService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    /** @return TemplateResponse|void */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $target = '')
    {
        return $this->renderV2();
    }

    /**
     * v0.17.0 — Dedicated standalone entry point. Same code path as
     * index(), but ALWAYS renders without the Nextcloud shell (no
     * header, no app menu, no rounded containers). Used by mobile
     * WebView wrappers that want the mail UI full-screen.
     *
     * Route: GET /apps/souvera_mail/embed
     *
     * Auth: NoAdminRequired only — the Nextcloud session middleware
     * still runs; an unauthenticated visitor gets the usual /login
     * redirect (NOT a hard 401), so the WebView's cookie jar picks
     * up the OIDC session cleanly.
     *
     * @return TemplateResponse|void
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function embed()
    {
        return $this->renderV2();
    }

    /**
     * Shared implementation for `index()` (with `?embedded=1`
     * detection) and `embed()` (always standalone).
     *
     * @param bool $forceStandalone true if the caller was the
     *   dedicated /embed route; false leaves detection to the
     *   ?embedded= / ?standalone= query params.
     * @return TemplateResponse|void
     */
    private function renderMailApp(bool $forceStandalone)
    {
        // No domain configured → show setup hint instead of useless login form
        if (empty($this->domainService->listDomains())) {
            $isAdmin = $this->userId && $this->groupManager->isAdmin($this->userId);
            return new TemplateResponse('souvera_mail', 'not_configured', [
                'isAdmin' => $isAdmin,
            ]);
        }

        // v0.17.0 — Detect the standalone / embedded mode.
        //
        //   `?embedded=1`   ← WebView wrapper convention
        //   `?standalone=1` ← alias, some callers prefer that spelling
        //   /embed route    ← $forceStandalone === true
        //
        // The chosen mode drives two things:
        //   a) `\OCP\Util::addStyle('souvera_mail', 'standalone')`
        //      instead of 'embed' — the standalone stylesheet expects
        //      no `#content` wrapper and stretches the SnappyMail root
        //      to the full viewport.
        //   b) `TemplateResponse::renderAs('base')` — Nextcloud strips
        //      the header, app-menu, and #content shell; only the
        //      SnappyMail HTML + our loaded assets end up in <body>.
        //
        // Auth middleware runs unchanged (NoAdminRequired only) —
        // invalid sessions still get the standard /login redirect.
        $isStandalone = $forceStandalone
            || $this->request->getParam('embedded') === '1'
            || $this->request->getParam('standalone') === '1';

        // Snappymail's own AJAX handler kicks in when the query string
        // is non-empty (see `/?/Ajax/…` internal URLs). We must NOT
        // route a bare `?embedded=1` GET through it — that would
        // return an admin login screen from the engine. Strip our own
        // params from the check.
        $rawQuery = $this->request->server['QUERY_STRING'] ?? '';
        $residualQuery = \preg_replace(
            '/(?:^|&)(?:embedded|standalone)=[^&]*/',
            '',
            $rawQuery
        );
        $residualQuery = \ltrim((string) $residualQuery, '&');

        if ($residualQuery !== '') {
            $this->engineHelper->loadApp();
            $this->engineHelper->startApp(true);
            return;
        }

        $this->navigationManager->setActiveEntry('souvera_mail');

        if ($isStandalone) {
            \OCP\Util::addStyle('souvera_mail', 'standalone');
        } else {
            \OCP\Util::addStyle('souvera_mail', 'embed');
        }
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
            // v0.17.0 — surfaced to the template so it can add a
            // <body>-level class (via inline <script>) that our
            // standalone.css hangs off for full-viewport layout.
            'IsStandalone' => $isStandalone,
        ];

        \OCP\Util::addHeader('link', [
            'type' => 'text/css',
            'rel' => 'stylesheet',
            'href' => \Smail\Engine\Utils::WebStaticPath('css/app.css'),
        ], '');

        $response = new TemplateResponse('souvera_mail', 'index_embed', $params);

        $response->setContentSecurityPolicy($csp);

        if ($isStandalone) {
            // Strip the full Nextcloud UI — no header, no app menu,
            // no #content shell, no rounded containers. NC still
            // wraps our HTML in a minimal <html><head><body>… and
            // injects the CSS/JS registered via addStyle/addScript.
            $response->renderAs('base');
        }

        return $response;
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function appGet(): void
    {
        $this->engineHelper->startApp(true);
    }

    // v0.17.1 — POST-Handler für /embed. SnappyMail's client macht
    // relative AJAX-POSTs an die aktuelle Seiten-URL. Ohne diesen
    // Handler würde `POST /apps/souvera_mail/embed` einen 404 werfen,
    // weil `page#embed` (GET) die Methode nicht behandelt und die
    // Root-Route (`page#indexPost`) nur `POST /` bedient. Auf dem
    // WebView ist das der „Please refresh the page"-Fehler-Screen.
    //
    // Delegiert an dieselbe Engine-Aufrufkette wie `indexPost()` /
    // `appPost()` — der SnappyMail-Engine parsed den Query-String
    // selbst und dispatched an die richtige Aktion. Auth-Middleware
    // (NoAdminRequired) bleibt aktiv, damit die OIDC-Session wie
    // gewohnt greift; NoCSRFRequired, weil der Engine seine eigene
    // Session-basierte CSRF-Prüfung mitbringt und der NC-Token in
    // POSTs vom SnappyMail-Client nicht mitfährt.
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function embedPost(): void
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
        // When v2 is enabled, AJAX/POST requests from the old SnappyMail
        // engine must still be forwarded to it (the v2 client uses its own
        // API controllers at /api/v2/*).
        $this->engineHelper->startApp(true);
    }

    /** Renders the Vue-3 v2 client. */
    private function renderV2(): TemplateResponse
    {
        $this->navigationManager->setActiveEntry('souvera_mail');
        // Translations are injected INLINE into the template with the CSP
        // nonce (fast start) — and the v2 client additionally falls back to
        // the runtime endpoint /api/v2/l10n when the inline script was not
        // available (e.g. older NC versions without the $cspNonce template
        // variable would silently CSP-block it). See L10nService.
        // The language comes from the user's PERSONAL setting, not the
        // cached IL10N instance (which may resolve to the instance default).
        $translations = $this->l10nService->getCatalog($this->l10nService->resolveLanguage());
        \OCP\Util::addScript('souvera_mail', 'souvera_mail-v2');
        // The mail-migration assistant (provider.tools IMAP import, built for
        // SnappyMail) also runs on the v2 client. Its mount stays hidden
        // unless the welcome-state allows it or the open-migration event
        // forces it open (settings entry).
        \OCP\Util::addScript('souvera_mail', 'souvera_mail-migration-wizard');
        // The inline <script> in templates/v2.php uses the NC-provided
        // $cspNonce template variable (part of the default CSP header),
        // so no app-side ContentSecurityPolicy instance is needed here —
        // the engine-bound LocalCSP class must not be used on this route.
        return new TemplateResponse('souvera_mail', 'v2', [
            'translations' => $translations,
        ]);
    }
}
