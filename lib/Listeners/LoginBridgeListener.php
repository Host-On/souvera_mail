<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Listeners;

use OCA\SouveraMail\Service\LogService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ISession;
use OCP\User\Events\UserLoggedInEvent;

/**
 * Stamps the Nextcloud user id into the session under `souvera_mail-uid` on login,
 * so the engine helper can pick it up when issuing H2CK/oidc access tokens.
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
    public function __construct(
        private ISession $session,
        private LogService $logService,
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
    }
}
