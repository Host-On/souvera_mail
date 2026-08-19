<?php

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\DomainConfigService;
use OCA\SouveraMail\Service\L10nService;
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
     * GET /apps/souvera_mail/sound/{name}
     *
     * Serves notification sound files (new-mail.mp3, alert.mp3,
     * ping.mp3) stored in img/sounds/. Neither js/ nor img/ are
     * guaranteed to be served by every webserver config — a PHP
     * endpoint is the only path that works everywhere.
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function sound(string $name): \OCP\AppFramework\Http\Response
    {
        $appPath = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppPath('souvera_mail');
        if ($appPath === null) {
            return new \OCP\AppFramework\Http\DataResponse(['error' => 'Not found'], 404);
        }
        $safeName = \basename($name);
        $filePath = $appPath . '/img/sounds/' . $safeName;
        if (!\is_file($filePath)) {
            return new \OCP\AppFramework\Http\DataResponse(['error' => 'Not found'], 404);
        }
        $ext = \strtolower(\pathinfo($filePath, \PATHINFO_EXTENSION));
        $mime = $ext === 'ogg' ? 'audio/ogg' : 'audio/mpeg';
        return new \OCP\AppFramework\Http\DataDownloadResponse(
            (string) \file_get_contents($filePath),
            $safeName,
            $mime
        );
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
