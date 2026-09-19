<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\Notification\IManager;

/**
 * Versendet Mail-Eingangsmeldungen über das Nextcloud-Benachrichtigungssystem
 * (Notifications-App + push.souvera.eu Push-Proxy). Die Inhalte (Betreff,
 * Absender, Vorschau) werden vom Proxy mit dem Geräte-Schlüssel
 * end-to-end-verschlüsselt — Google/Apple sehen den Inhalt nicht.
 *
 * object_type für Mail-Benachrichtigungen (Deep-Link über object_id =
 * JMAP-Email-ID).
 */
class MailPushNotifier
{
    public const OBJECT_TYPE = 'souvera_mail';

    public function __construct(
        private IManager $notificationManager,
    ) {
    }

    /**
     * Erzeugt die NC-Benachrichtigung für eine neue Mail. `object_id`
     * trägt die JMAP-Email-ID (base32 der Stalwart-Doc-ID), über die die
     * Clients die Mail beim Antippen direkt öffnen.
     */
    public function notify(
        string $userId,
        string $emailId,
        string $subject,
        string $sender,
        string $preview,
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification
                ->setApp('souvera_mail')
                ->setUser($userId)
                ->setDateTime(new \DateTime())
                ->setObject(self::OBJECT_TYPE, $emailId)
                ->setSubject($subject !== '' ? $subject : 'Neue E-Mail');

            $lines = [];
            if ($sender !== '') {
                $lines[] = $sender;
            }
            if ($preview !== '') {
                $lines[] = $preview;
            }
            if ($lines !== []) {
                $notification->setMessage(\implode("\n", $lines));
            }

            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            // Push darf den Mail-Eingang selbst nie blockieren.
            \OCP\Server::get(\Psr\Log\LoggerInterface::class)->warning(
                'Souvera Mail: NC-Notification für neue Mail fehlgeschlagen: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
        }
    }
}
