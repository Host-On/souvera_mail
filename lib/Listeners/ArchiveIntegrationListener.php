<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Listeners;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\IURLGenerator;
use OCP\Util;

/**
 * Injiziert einen "Im Archiv suchen"-Button in die SnappyMail-Oberfläche.
 * Nur aktiv wenn das Archiv in souvera_central aktiviert ist.
 *
 * @see ARCHIVE_PLAN §2.3a
 */
class ArchiveIntegrationListener implements IEventListener
{
	public function __construct(
		private IURLGenerator $urlGenerator,
	) {}

	public function handle(Event $event): void
	{
		if (!($event instanceof BeforeTemplateRenderedEvent)) {
			return;
		}

		if ($event->getResponse()->getApp() !== 'souvera_mail') {
			return;
		}

		$enabled = \OCP\Server::get(\OCP\IConfig::class)
			->getAppValue('souvera_central', 'archive.enabled', '0') === '1';

		if (!$enabled) {
			return;
		}

		$archiveUrl = $this->urlGenerator->linkToRoute('souvera_archive.page.search');
		Util::addHeader('meta', [
			'name' => 'archive-url',
			'content' => $archiveUrl,
		]);
		Util::addScript('souvera_mail', 'archive-integration');
	}
}
