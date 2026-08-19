<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Row in `oc_souvera_mail_devicetoken` — one registered FCM push target
 * (an Android device, currently) for a Nextcloud user.
 *
 * `lastPushState` caches an opaque JMAP marker (Email/query `queryState`
 * for the user's Inbox) so {@see \OCA\SouveraMail\Cron\MailPushPoller} can
 * detect "new mail since last check" without re-sending a push for a
 * mailbox state it already notified about via the Stalwart webhook.
 *
 * @method string  getUserId()
 * @method void    setUserId(string $userId)
 * @method string  getFcmToken()
 * @method void    setFcmToken(string $fcmToken)
 * @method string  getPlatform()
 * @method void    setPlatform(string $platform)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method int     getLastSeenAt()
 * @method void    setLastSeenAt(int $lastSeenAt)
 * @method ?string getLastPushState()
 * @method void    setLastPushState(?string $lastPushState)
 */
class DeviceToken extends Entity
{
    public const PLATFORM_ANDROID = 'android';

    /** @var string */
    protected $userId = '';

    /** @var string */
    protected $fcmToken = '';

    /** @var string */
    protected $platform = self::PLATFORM_ANDROID;

    /** @var int */
    protected $createdAt = 0;

    /** @var int */
    protected $lastSeenAt = 0;

    /** @var ?string */
    protected $lastPushState = null;

    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('fcmToken', 'string');
        $this->addType('platform', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('lastSeenAt', 'integer');
        $this->addType('lastPushState', 'string');
    }
}
