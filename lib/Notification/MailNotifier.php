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

		// Die vollen Inhalte (Betreff, Absender, Vorschau) kommen aus den
		// Subject-Parametern — setParsedSubject/setParsedMessage sind UNBEGRENZT
		// und genau diese geparsten Werte trägt der Push zum Gerät (der RAW-
		// Subject ist vom Ersteller auf 64 BYTE gekürzt, um die NC-Validierung
		// zu bestehen). Ohne Parameter (z. B. DB-geladene alte Notifications)
		// fällt der Notifier auf den RAW-Subject zurück.
		$params = $notification->getSubjectParameters();

		$parsedSubject = \trim((string) ($params['fullSubject'] ?? ''));
		if ($parsedSubject === '') {
			$parsedSubject = \trim((string) $notification->getSubject());
		}
		if ($parsedSubject === '') {
			$parsedSubject = $l->t('Neue E-Mail');
		}
		$notification->setParsedSubject($parsedSubject);

		$from = \trim((string) ($params['from'] ?? ''));
		$preview = \trim((string) ($params['preview'] ?? ''));
		$message = $from !== '' && $preview !== '' ? $from . "\n" . $preview : $from . $preview;
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
