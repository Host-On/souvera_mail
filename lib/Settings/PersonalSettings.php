<?php

declare(strict_types=1);

namespace OCA\Smail\Settings;

use OCA\Smail\Dashboard\UnreadMailWidget;
use OCA\Smail\Service\AppPasswordService;
use OCA\Smail\Util\EngineHelper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings
{
    public function __construct(
        private IURLGenerator $urlGenerator,
        private IConfig $config,
        private IUserSession $userSession,
        private EngineHelper $engineHelper,
        private AppPasswordService $appPasswordService,
    ) {
    }

    public function getForm()
    {
        $this->engineHelper->loadApp();
        $brandName = \Smail\Engine\Api::Config()->Get('webmail', 'title', 'Souvera Mail');

        $userId = $this->userSession->getUser()?->getUID() ?? '';
        $dashboardMode = $userId !== ''
            ? $this->config->getUserValue($userId, 'smail', UnreadMailWidget::USER_CONFIG_MODE, UnreadMailWidget::MODE_DEFAULT)
            : UnreadMailWidget::MODE_DEFAULT;
        if ($dashboardMode !== UnreadMailWidget::MODE_ALL) {
            $dashboardMode = UnreadMailWidget::MODE_UNREAD;
        }

        return new TemplateResponse('smail', 'personal_settings', [
            'brandName' => $brandName,
            'settingsUrl' => $this->urlGenerator->linkToRoute('smail.page.index') . '#/settings/accounts',
            'dashboardMode' => $dashboardMode,
            'dashboardModeUnread' => UnreadMailWidget::MODE_UNREAD,
            'dashboardModeAll' => UnreadMailWidget::MODE_ALL,
            'dashboardModeUrl' => $this->urlGenerator->linkToRoute('smail.preference.setDashboardMode'),
            'appPasswordsAvailable' => $this->appPasswordService->isAvailable(),
            'appPasswordsListUrl' => $this->urlGenerator->linkToRoute('smail.appPassword.index'),
            'appPasswordsCreateUrl' => $this->urlGenerator->linkToRoute('smail.appPassword.create'),
            // Route template for DELETE — JS substitutes `{id}` at runtime.
            'appPasswordsDestroyUrlTemplate' => $this->urlGenerator->linkToRoute(
                'smail.appPassword.destroy', ['id' => '__ID__']
            ),
        ], '');
    }

    public function getSection()
    {
        return 'smail';
    }

    public function getPriority()
    {
        return 50;
    }
}
