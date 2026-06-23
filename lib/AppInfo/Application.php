<?php

namespace OCA\Smail\AppInfo;

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
