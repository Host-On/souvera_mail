<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

/**
 * Thrown by {@see MailboxAccessGuard::assertMailboxOwnership} when
 * the Souvera-Mail login would open a mailbox the Nextcloud user
 * does not own on the Stalwart side. Extending a plain RuntimeException
 * so the caller (EngineHelper) can catch a narrow type instead of a
 * generic Throwable that would swallow programmer errors.
 */
class MailboxAccessDenied extends \RuntimeException
{
}
