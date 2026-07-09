<?php

namespace OCA\SouveraMail\AppInfo;

/*
 * Load the bundled composer autoloader (engine + wrapper classmap) before any
 * Nextcloud service-container lookup so repair-steps that run during
 * `occ app:enable souvera_mail` can resolve `Smail\Engine\*` classes immediately.
 *
 * The classmap (built by `composer dump-autoload --optimize`) hard-codes the
 * lowercase-filename → CamelCase-class mapping that the upstream SnappyMail
 * library uses, so we do not rely on EngineHelper::loadApp() having been
 * invoked first. EngineHelper::loadApp() still registers a defensive fallback
 * autoloader for installs that ship without `vendor/` (e.g. checkout from
 * source rather than the released tarball).
 */
$vendorAutoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
if (\is_file($vendorAutoload)) {
    require_once $vendorAutoload;
}

use OCA\SouveraMail\Dashboard\UnreadMailWidget;
use OCA\SouveraMail\Listeners\ImpersonateListener;
use OCA\SouveraMail\Listeners\LoginBridgeListener;
use OCA\SouveraMail\Listeners\LogoutListener;
use OCA\SouveraMail\Listeners\NcTokenInvalidatedListener;
use OCA\SouveraMail\Listeners\SecurityPageHijackListener;
use OCA\SouveraMail\Search\Provider;
use OCA\SouveraMail\Util\NavigationTitle;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Authentication\Events\TokenInvalidatedEvent;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use OCP\User\Events\UserLoggedInEvent;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'souvera_mail';

    /**
     * Nextcloud group id that the app is restricted to.
     *
     * Every account that should be able to see the "Mail" navigation entry
     * and open `/apps/souvera_mail/…` must be a member of this group.
     * Enforced by:
     *  - {@see \OCA\SouveraMail\Migration\EnforceGroupRestriction} (install
     *    repair-step: ensures the group exists and binds the app to it on
     *    fresh installs).
     *  - Nextcloud's built-in app-permission layer, which rejects URL access
     *    for non-members once the binding is in place.
     *  - {@see Application::boot()} navigation closure, which additionally
     *    hides the menu entry via `IAppManager::isEnabledForUser()`.
     *
     * Admins who want a different group restriction can re-run
     * `occ app:enable souvera_mail --groups <other-group>` — the repair-step
     * respects an existing group restriction on post-update runs and only
     * enforces the default on the very first install.
     */
    public const RESTRICTED_GROUP_ID = 'souvera-users';

    /** @param array<string, mixed> $urlParams */
    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        // NC 28+: Controllers use autowiring — no manual registerService needed.

        $context->registerSearchProvider(Provider::class);

        // Stamp souvera_mail-uid into the session on Nextcloud login. The OIDC access
        // token itself is issued on-demand by OidcProviderService via the
        // H2CK/oidc TokenGenerationRequestEvent — no session-side bridging or
        // pre-warming required.
        $context->registerEventListener(
            UserLoggedInEvent::class,
            LoginBridgeListener::class
        );

        // Engine logout on Nextcloud logout
        $context->registerEventListener(
            BeforeUserLoggedOutEvent::class,
            LogoutListener::class
        );

        // Impersonate begin/end — engine logout. Use string class names to
        // avoid a hard dependency on the impersonate app.
        $context->registerEventListener(
            'OCA\\Impersonate\\Events\\BeginImpersonateEvent',
            ImpersonateListener::class
        );
        $context->registerEventListener(
            'OCA\\Impersonate\\Events\\EndImpersonateEvent',
            ImpersonateListener::class
        );

        $context->registerDashboardWidget(UnreadMailWidget::class);

        // v0.14.0 — combined Mail + Nextcloud/DAV app passwords.
        //
        // (a) Rewrite `/settings/user/security` for souvera-users: hide
        //     the native "Create app password" form and inject a notice
        //     pointing at Souvera Mail's own combined-flow.
        $context->registerEventListener(
            BeforeTemplateRenderedEvent::class,
            SecurityPageHijackListener::class
        );

        // (b) Mirror NC-side token invalidations to Stalwart — if a user
        //     revokes a combined token from `/settings/user/security`
        //     (still visible for existing tokens), the mail auth side must
        //     die too. Otherwise the credential lives on for IMAP/SMTP.
        $context->registerEventListener(
            TokenInvalidatedEvent::class,
            NcTokenInvalidatedListener::class
        );
    }

    public function boot(IBootContext $context): void
    {
        $serverContainer = $context->getServerContainer();

        $config = $serverContainer->get(IConfig::class);
        $dataDir = \rtrim(\trim($config->getSystemValue('datadirectory', '')), '\\/');
        if (!\is_dir($dataDir . '/appdata_souvera_mail')) {
            return;
        }

        $navigationManager = $serverContainer->get(INavigationManager::class);

        // ------------------------------------------------------------------
        // Navigation entry registration.
        //
        // NC34's NavigationManager::add() does `$id = $entry['id']` and then
        // `IAppManager::isEnabledForUser($id)` (strict `string $appId` since
        // 30+). If we return `[]` from the closure for the pre-auth case,
        // `$id` is null, the strict type-check throws TypeError, and the
        // crash trips layout.guest.php — taking down /login, public shares,
        // every guest-rendered page (operator report, 2026-06-30).
        //
        // The correct shape is therefore: **never register a closure unless
        // the resulting entry is going to be valid**. We branch HERE,
        // outside the closure:
        //   - pre-auth (no NC user)            -> do not register
        //   - user not in souvera-users group  -> do not register
        //   - otherwise                        -> register a closure that
        //     ALWAYS returns a complete entry with `id`, `name`, `href`,
        //     `icon`, `order`. Never `[]`.
        // ------------------------------------------------------------------
        $userSession = $serverContainer->get(IUserSession::class);
        $user = $userSession->getUser();
        if ($user === null) {
            // Pre-auth (login page, public shares, OIDC redirect leg, …) —
            // no user means no per-user navigation. Skipping the registration
            // entirely is the only crash-safe option in NC34: a stub closure
            // returning `[]` here still trips `add()`'s `$entry['id']` access.
            return;
        }
        $appManager = $serverContainer->get(IAppManager::class);
        if (!$appManager->isEnabledForUser(self::APP_ID, $user)) {
            // User exists but is outside the `souvera-users` group restriction.
            // Same crash-safety rule: don't register a closure at all.
            return;
        }

        $navigationManager->add(function () use ($serverContainer) {
            $appConfig = $serverContainer->get(IAppConfig::class);
            $urlGenerator = $serverContainer->get(IURLGenerator::class);

            return [
                'id' => self::APP_ID,
                'name' => NavigationTitle::resolve($appConfig),
                'href' => $urlGenerator->linkToRoute('souvera_mail.page.index'),
                'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),
                'order' => 4,
            ];
        });

        // v0.14.17: "Alte Mails importieren" was briefly registered as a
        // Nextcloud user-menu entry (`type => 'settings'`) in v0.14.12,
        // but the operator asked for it to live INSIDE Snappymail —
        // specifically next to the F1 help entry in the top-right user
        // dropdown (SystemDropDown.html). That entry is now injected by
        // the Snappymail plugin's `js/dropdown-menu.js`, so the NC-menu
        // entry has been removed here to avoid the double-entry.
    }
}
