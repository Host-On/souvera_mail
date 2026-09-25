<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Notification;

use OCA\SouveraMail\AppInfo\Application;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Rendert souvera_mail-Benachrichtigungen für den Push-/Bell-Kanal.
 *
 * Ohne diesen Notifier wirft NotificationManager::prepare() eine
 * IncompleteParsedNotificationException — und die Notifications-App
 * überspringt den Push (Push.php: „Error when preparing notification
 * for push") — genau deshalb erreichten Mail-Pushes nie die Geräte,
 * obwohl der Standard-NotificationManager benutzt wird.
 */
class MailNotifier implements INotifier {

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	#[\Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	#[\Override]
	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Souvera Mail');
	}

	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			// Nicht unsere Notification — der Manager fragt den nächsten Notifier.
			throw new \InvalidArgumentException('Notification gehört nicht zu souvera_mail');
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);

		// Betreff/Meldung wurden bei Erstellung gesetzt (subject = Mail-Betreff,
		// message = Absender + Vorschau) — als geparste Werte übernehmen, das
		// verlangt die Push-Pipeline. Keine Platzhalter im Spiel.
		$subject = (string) $notification->getSubject();
		$notification->setParsedSubject($subject !== '' ? $subject : $l->t('Neue E-Mail'));

		$message = (string) $notification->getMessage();
		if ($message !== '') {
			$notification->setParsedMessage($message);
		}

		$iconUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg')
		);
		$notification->setIcon($iconUrl);

		return $notification;
	}
}
