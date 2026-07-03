<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Row in `oc_souvera_mail_apppwd` — the missing link between a user's
 * Stalwart Application Password and the paired Nextcloud auth token that
 * shares the SAME plaintext secret.
 *
 * Why this table exists
 * ---------------------
 * Since v0.14.0 every app password is a *combined* Mail + Nextcloud/DAV
 * credential. Creation is a two-phase commit: Stalwart first (owns the
 * hash / permissions), then Nextcloud's IProvider::generateToken() with
 * the SAME plaintext. To revoke both sides — and to detect legacy
 * Mail-only passwords created before v0.14.0 — we persist an explicit
 * mapping row per creation.
 *
 * @method string getUserId()
 * @method void   setUserId(string $userId)
 * @method int    getNcTokenId()
 * @method void   setNcTokenId(int $ncTokenId)
 * @method string getStalwartAppId()
 * @method void   setStalwartAppId(string $stalwartAppId)
 * @method string getDescription()
 * @method void   setDescription(string $description)
 * @method int    getCreatedAt()
 * @method void   setCreatedAt(int $createdAt)
 */
class AppPasswordMapping extends Entity
{
    /** @var string */
    protected $userId = '';

    /** @var int */
    protected $ncTokenId = 0;

    /** @var string */
    protected $stalwartAppId = '';

    /** @var string */
    protected $description = '';

    /** @var int */
    protected $createdAt = 0;

    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('ncTokenId', 'integer');
        $this->addType('stalwartAppId', 'string');
        $this->addType('description', 'string');
        $this->addType('createdAt', 'integer');
    }
}
