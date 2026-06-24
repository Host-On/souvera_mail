<?php

namespace OCA\Smail\AppInfo;

/*
 * Load the bundled composer autoloader (engine + wrapper classmap) before any
 * Nextcloud service-container lookup so repair-steps that run during
 * `occ app:enable smail` can resolve `Smail\Engine\*` classes immediately.
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

use OCA\Smail\Dashboard\UnreadMailWidget;
use OCA\Smail\Listeners\ImpersonateListener;
use OCA\Smail\Listeners\LoginBridgeListener;
use OCA\Smail\Listeners\LogoutListener;
use OCA\Smail\Search\Provider;
use OCA\Smail\Util\NavigationTitle;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\User\Events\BeforeUserLoggedOutEvent;
use OCP\User\Events\UserLoggedInEvent;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'smail';

    /** @param array<string, mixed> $urlParams */
    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void
    {
        // NC 28+: Controllers use autowiring — no manual registerService needed.

        $context->registerSearchProvider(Provider::class);

        // Stamp smail-uid into the session on Nextcloud login. The OIDC access
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
    }

    public function boot(IBootContext $context): void
    {
        $serverContainer = $context->getServerContainer();

        $config = $serverContainer->get(IConfig::class);
        $dataDir = \rtrim(\trim($config->getSystemValue('datadirectory', '')), '\\/');
        if (!\is_dir($dataDir . '/appdata_smail')) {
            return;
        }

        $navigationManager = $serverContainer->get(INavigationManager::class);
        $navigationManager->add(function () use ($serverContainer) {
            $appConfig = $serverContainer->get(IAppConfig::class);
            $urlGenerator = $serverContainer->get(IURLGenerator::class);

            return [
                'id' => self::APP_ID,
                'name' => NavigationTitle::resolve($appConfig),
                'href' => $urlGenerator->linkToRoute('smail.page.index'),
                'icon' => $urlGenerator->imagePath(self::APP_ID, 'logo-white-64x64.png'),
                'order' => 4,
            ];
        });
    }
}
