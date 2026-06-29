<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Dashboard\UnreadMailWidget;
use OCA\SouveraMail\Service\AppPasswordService;
use OCA\SouveraMail\Util\EngineHelper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * In-app settings page reachable at `/index.php/apps/souvera_mail/settings`.
 *
 * Replaces the Nextcloud Personal-Settings section that used to live at
 * `/settings/user/souvera_mail`. Rendered as a full-page TemplateResponse
 * with NC's own chrome (header + navigation), so the user perceives it as
 * "inside Souvera Mail" rather than as a foreign Personal-Settings tab.
 */
class SettingsController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private INavigationManager $navigationManager,
        private IURLGenerator $urlGenerator,
        private IConfig $config,
        private EngineHelper $engineHelper,
        private AppPasswordService $appPasswordService,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse
    {
        $this->navigationManager->setActiveEntry('souvera_mail');
        $this->engineHelper->loadApp();
        $brandName = \Smail\Engine\Api::Config()->Get('webmail', 'title', 'Souvera Mail');

        $userId = $this->userId ?? '';
        $dashboardMode = $userId !== ''
            ? $this->config->getUserValue(
                $userId,
                'souvera_mail',
                UnreadMailWidget::USER_CONFIG_MODE,
                UnreadMailWidget::MODE_DEFAULT,
            )
            : UnreadMailWidget::MODE_DEFAULT;
        if ($dashboardMode !== UnreadMailWidget::MODE_ALL) {
            $dashboardMode = UnreadMailWidget::MODE_UNREAD;
        }

        $params = [
            'brandName' => $brandName,
            'backUrl' => $this->urlGenerator->linkToRoute('souvera_mail.page.index'),
            'dashboardMode' => $dashboardMode,
            'dashboardModeUnread' => UnreadMailWidget::MODE_UNREAD,
            'dashboardModeAll' => UnreadMailWidget::MODE_ALL,
            'dashboardModeUrl' => $this->urlGenerator->linkToRoute('souvera_mail.preference.setDashboardMode'),
            'appPasswordsAvailable' => $this->appPasswordService->isAvailable(),
            'appPasswordsListUrl' => $this->urlGenerator->linkToRoute('souvera_mail.appPassword.index'),
            'appPasswordsCreateUrl' => $this->urlGenerator->linkToRoute('souvera_mail.appPassword.create'),
            // The DELETE URL is built as `<index-url>/__ID__` instead of
            // `linkToRoute(..., ['id' => '__ID__'])` because Symfony's URL
            // generator validates the `requirements` regex at generation
            // time, not just at routing time — `__ID__` does not match
            // `\d+` and crashes with InvalidParameterException. Building
            // the template by string concatenation skips the generator
            // check while keeping the server-side `\d+` constraint
            // (enforced when the JS substitutes a real numeric id).
            'appPasswordsDestroyUrlTemplate' =>
                $this->urlGenerator->linkToRoute('souvera_mail.appPassword.index') . '/__ID__',
            'connectedDevicesListUrl' => $this->urlGenerator->linkToRoute('souvera_mail.connectedDevices.index'),
            'connectedDevicesDestroyUrlTemplate' =>
                $this->urlGenerator->linkToRoute('souvera_mail.connectedDevices.index') . '/__ID__',
            'connectedDevicesSignOutOthersUrl' => $this->urlGenerator->linkToRoute(
                'souvera_mail.connectedDevices.signOutOthers'
            ),
        ];

        return new TemplateResponse('souvera_mail', 'settings', $params, 'user');
    }
}
