<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Listeners;

use OCA\SouveraMail\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Util;

/**
 * Rewrites Nextcloud's `/settings/user/security` page for members of the
 * `souvera-users` group so the built-in "Create new app password" flow is
 * hidden and replaced with a Souvera-branded notice + redirect button.
 *
 * Why this hijack exists
 * ----------------------
 * Since v0.14.0 every app password is a combined Mail + Nextcloud/DAV
 * credential (Stalwart secret paired with a permanent NC auth-token via
 * IProvider::generateToken). If a user creates a NC-only token through
 * the stock `/settings/user/security` form, that token would ONLY work
 * for DAV — not IMAP/SMTP/Sieve — which defeats the "one password for
 * everything" promise.
 *
 * We prevent the confusion by injecting a tiny CSS/JS bundle:
 *   1. Hides the built-in `<form class="new-token">` inside the
 *      auth-tokens section.
 *   2. Injects a prominent notice card with a "→ App-Passwort für Mail
 *      & Nextcloud erstellen" button pointing at Souvera Mail's own
 *      Security & Devices tab.
 *
 * NOTE: This is the pragmatic "option (a)" from the 2026-02-18 design
 * chat. A future release should route these through an HTTP middleware
 * so the form disappears even if Vue class names change. Tracked in the
 * PRD backlog.
 *
 * @implements IEventListener<Event>
 */
class SecurityPageHijackListener implements IEventListener
{
    public function __construct(
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof BeforeTemplateRenderedEvent)) {
            return;
        }

        // Guard: only inject when the current request is the personal
        // security page. `getResponse()->getRenderAs()` is not fine-grained
        // enough — we look at the request path instead.
        $script = $_SERVER['SCRIPT_URL'] ?? $_SERVER['REQUEST_URI'] ?? '';
        if (!\is_string($script) || \strpos($script, '/settings/user/security') === false) {
            return;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }
        if (!$this->groupManager->isInGroup($user->getUID(), Application::RESTRICTED_GROUP_ID)) {
            // Non-Souvera users see the vanilla NC page — DAV-only tokens
            // are perfectly valid for anyone outside the mail stack.
            return;
        }

        // The addStyle / addScript calls append the assets to the same
        // template that renders the settings page. NC bundles them into
        // the standard scripts array — no extra route needed.
        Util::addStyle(Application::APP_ID, 'security-page-hijack');
        Util::addScript(Application::APP_ID, 'security-page-hijack');
    }
}
