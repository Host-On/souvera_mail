<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Tracks which mails a user personally reported to the PMG spamfilter —
 * needed to distinguish "revert my own report" from "report a system-sorted
 * mail as ham" when a mail leaves the junk folder.
 *
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method string getAccountId()
 * @method void setAccountId(string $v)
 * @method string getEmailId()
 * @method void setEmailId(string $v)
 * @method string getMessageId()
 * @method void setMessageId(string $v)
 * @method string getMessageIdHash()
 * @method void setMessageIdHash(string $v)
 * @method string getClass()
 * @method void setClass(string $v)
 * @method string getReportedAt()
 * @method void setReportedAt(string $v)
 */
class PmgReport extends Entity
{
    public $userId;
    public $accountId;
    public $emailId;
    public $messageId;
    public $messageIdHash;
    public $class;
    public $reportedAt;
}
