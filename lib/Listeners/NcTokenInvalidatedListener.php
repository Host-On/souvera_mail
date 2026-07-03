<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Listeners;

use OCA\SouveraMail\Service\AppPasswordService;
use OCP\Authentication\Events\TokenInvalidatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Mirrors a Nextcloud-side token invalidation to the Stalwart side.
 *
 * Even though we hide the stock "Create app password" form on
 * `/settings/user/security`, we DELIBERATELY keep the existing tokens
 * visible — users must always be able to revoke a compromised
 * credential from ANYWHERE they land. When they do (e.g. remote
 * "sign out on lost device"), NC dispatches TokenInvalidatedEvent.
 * We look the token id up in `oc_souvera_mail_apppwd` and, if it maps
 * to a combined credential, destroy the Stalwart side too — otherwise
 * mail authentication would keep working from the compromised device.
 *
 * @implements IEventListener<Event>
 */
class NcTokenInvalidatedListener implements IEventListener
{
    public function __construct(
        private AppPasswordService $appPasswords,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void
    {
        if (!($event instanceof TokenInvalidatedEvent)) {
            return;
        }

        $token = $event->getToken();
        $uid = $token->getUID();
        $tokenId = (int) $token->getId();

        try {
            $this->appPasswords->revokeByNcTokenId($uid, $tokenId);
        } catch (\Throwable $e) {
            // Never rethrow from event listeners — NC's token flow must
            // proceed even if Stalwart is temporarily unreachable.
            $this->logger->warning(
                'Souvera Mail: Stalwart mirror-revoke failed for NC token ' . $tokenId
                . ' of user ' . $uid . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
        }
    }
}
