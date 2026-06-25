<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Dashboard\UnreadMailWidget;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Persists per-user preferences for the Souvera Mail Nextcloud wrapper.
 *
 * The only preference we expose today is the dashboard widget mode
 * ({@see UnreadMailWidget::USER_CONFIG_MODE}: `unread` | `all`). Stored via
 * Nextcloud's standard {@see IConfig::setUserValue()} so operators can also
 * manage it via `occ user:setting <uid> souvera_mail dashboard-mode <value>`.
 */
class PreferenceController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function setDashboardMode(string $mode): DataResponse
    {
        if ($this->userId === null) {
            return new DataResponse(['status' => 'error', 'message' => 'no user'], Http::STATUS_UNAUTHORIZED);
        }

        $mode = $mode === UnreadMailWidget::MODE_ALL
            ? UnreadMailWidget::MODE_ALL
            : UnreadMailWidget::MODE_UNREAD;

        $this->config->setUserValue(
            $this->userId,
            'souvera_mail',
            UnreadMailWidget::USER_CONFIG_MODE,
            $mode,
        );

        return new DataResponse([
            'status' => 'ok',
            'mode' => $mode,
        ]);
    }
}
