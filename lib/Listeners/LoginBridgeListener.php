<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Listeners;

use OCA\SouveraMail\Dashboard\UnreadMailWidget;
use OCA\SouveraMail\Service\LogService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\ISession;
use OCP\User\Events\UserLoggedInEvent;

/**
 * Stamps the Nextcloud user id into the session under `souvera_mail-uid` on login,
 * so the engine helper can pick it up when issuing H2CK/oidc access tokens.
 *
 * Additionally auto-activates the "Souvera Mail · Inbox" dashboard widget on
 * the very first login of each user (tracked via the per-user marker
 * `souvera_mail/dashboard-widget-autoactivated`). The user can still remove
 * the widget afterwards — we ONLY seed it on first login, never re-add it
 * on subsequent logins. This matches operator intent: reduce friction for
 * new users without overriding user choice.
 *
 * The legacy `is_oidc` / `oidc_access_token` session markers (set by
 * user_oidc's TokenBridgeListener in older Souvera Mail versions) are gone:
 * H2CK/oidc issues fresh tokens on demand, so no per-session token caching
 * in the NC session is needed any more.
 *
 * @implements IEventListener<Event>
 */
class LoginBridgeListener implements IEventListener
{
    public const DASHBOARD_APP = 'dashboard';
    public const DASHBOARD_LAYOUT_KEY = 'layout';
    public const AUTOACTIVATE_MARKER_APP = 'souvera_mail';
    public const AUTOACTIVATE_MARKER_KEY = 'dashboard-widget-autoactivated';

    public function __construct(
        private ISession $session,
        private LogService $logService,
        private IConfig $config,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof UserLoggedInEvent)) {
            return;
        }

        $uid = $event->getUser()->getUID();
        $this->session->set('souvera_mail-uid', $uid);
        $this->logService->debug("Login bridge: souvera_mail-uid set to {$uid}");

        $this->autoActivateDashboardWidget($uid);
    }

    /**
     * On the very first login of a user, append the Souvera Mail unread-mail
     * widget id to their per-user Dashboard layout so it shows up on the
     * home page without them having to add it manually. The marker key
     * prevents us from re-adding the widget if the user has deliberately
     * removed it after the first seed.
     *
     * Best-effort: any exception is swallowed at debug level — a failed
     * seed is annoying but must never break the login flow.
     */
    private function autoActivateDashboardWidget(string $uid): void
    {
        try {
            $marker = $this->config->getUserValue(
                $uid,
                self::AUTOACTIVATE_MARKER_APP,
                self::AUTOACTIVATE_MARKER_KEY,
                ''
            );
            if ($marker === '1') {
                // Already seeded once — respect user's subsequent choice.
                return;
            }

            $widgetId = UnreadMailWidget::WIDGET_ID;

            $currentLayout = (string) $this->config->getUserValue(
                $uid,
                self::DASHBOARD_APP,
                self::DASHBOARD_LAYOUT_KEY,
                ''
            );

            if ($currentLayout === '') {
                // Empty layout means NC falls back to the dashboard-app-level
                // default. Explicitly seed our widget alongside the two most
                // common defaults so the user sees a working dashboard.
                $newLayout = 'recommendations,spreed,' . $widgetId;
            } else {
                $ids = array_map('trim', explode(',', $currentLayout));
                $ids = array_filter($ids, static fn (string $s): bool => $s !== '');
                if (in_array($widgetId, $ids, true)) {
                    // User already has the widget — nothing to do; still
                    // stamp the marker so we skip this branch next login.
                    $this->config->setUserValue(
                        $uid,
                        self::AUTOACTIVATE_MARKER_APP,
                        self::AUTOACTIVATE_MARKER_KEY,
                        '1'
                    );
                    return;
                }
                $ids[] = $widgetId;
                $newLayout = implode(',', $ids);
            }

            $this->config->setUserValue(
                $uid,
                self::DASHBOARD_APP,
                self::DASHBOARD_LAYOUT_KEY,
                $newLayout
            );
            $this->config->setUserValue(
                $uid,
                self::AUTOACTIVATE_MARKER_APP,
                self::AUTOACTIVATE_MARKER_KEY,
                '1'
            );
            $this->logService->debug("Login bridge: seeded dashboard widget for {$uid} (layout={$newLayout})");
        } catch (\Throwable $e) {
            $this->logService->debug('Login bridge: dashboard widget seed skipped: ' . $e->getMessage());
        }
    }
}
