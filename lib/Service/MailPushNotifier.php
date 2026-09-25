<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Versendet Mail-Eingangsmeldungen über das Nextcloud-Benachrichtigungssystem
 * (Notifications-App + push.souvera.eu Push-Proxy). Die Inhalte (Betreff,
 * Absender, Vorschau) werden vom Proxy mit dem Geräte-Schlüssel
 * end-to-end-verschlüsselt — Google/Apple sehen den Inhalt nicht.
 *
 * object_type für Mail-Benachrichtigungen (Deep-Link über object_id =
 * JMAP-Email-ID).
 *
 * Robuster gegen echte Mail-Inhalte: Betreff/Absender/Vorschau werden vor dem
 * Übergaben an den NotificationManager bereinigt (valides UTF-8, ohne
 * Steuerzeichen, auf NC-Limits gekürzt), weil der NotificationManager
 * andernfalls mit `Value provided for message/subject is not valid`
 * abbricht und der Push komplett ausfällt. Schlägt die Erstellung trotzdem
 * fehl, wird einmal mit einem generischen Fallback („Neue E-Mail") erneut
 * versucht, damit ein Push nie an einem Betreff scheitert.
 */
class MailPushNotifier
{
    public const OBJECT_TYPE = 'souvera_mail';

    /** NC-Validierungslimits: Subject max 64, Message max 4000 Zeichen. */
    private const SUBJECT_MAX_LEN = 64;
    private const MESSAGE_MAX_LEN = 4000;

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
        $safeSubject = $this->sanitize($subject, self::SUBJECT_MAX_LEN);
        $safeSender = $this->sanitize($sender, self::SUBJECT_MAX_LEN);
        $safePreview = $this->sanitize($preview, self::MESSAGE_MAX_LEN);

        try {
            $this->doNotify($userId, $emailId, $safeSubject, $safeSender, $safePreview);
            return;
        } catch (\Throwable $e) {
            $this->logger()->warning(
                'Souvera Mail: NC-Notification für neue Mail fehlgeschlagen: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
        }

        // Fallback: inhaltsfreie Benachrichtigung, damit ein Push nie an einem
        // (auch nach Bereinigung) invaliden Betreff/Absender scheitert.
        try {
            $this->doNotify($userId, $emailId, 'Neue E-Mail', '', '');
        } catch (\Throwable $e) {
            $this->logger()->error(
                'Souvera Mail: NC-Notification-Fallback ebenfalls fehlgeschlagen: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
        }
    }

    /**
     * Baut die Benachrichtigung zusammen und übergibt sie an den Manager.
     * `$subject`, `$sender` und `$preview` müssen bereits bereinigt sein.
     */
    private function doNotify(
        string $userId,
        string $emailId,
        string $subject,
        string $sender,
        string $preview,
    ): void {
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
            $message = \implode("\n", $lines);
            $notification->setMessage(\mb_substr($message, 0, self::MESSAGE_MAX_LEN, 'UTF-8'));
        }

        $this->notificationManager->notify($notification);
    }

    /**
     * Bereinigt einen Mail-Inhalt für die NC-Benachrichtigungsvalidierung:
     * garantiert valides UTF-8, entfernt Steuerzeichen (C0 + DEL) und kürzt
     * auf die übergebene Maximallänge. Leerer/nicht bereinigbarer Wert wird
     * zu '' (der Aufrufer setzt dann ggf. einen Fallback-Betreff).
     */
    private function sanitize(string $value, int $maxLen): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        // Valides UTF-8 erzwingen: ungültige Byte-Sequenzen werden ersetzt.
        if (!\mb_check_encoding($value, 'UTF-8')) {
            $converted = @\mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            $value = \is_string($converted) ? $converted : '';
        }
        if ($value === '') {
            return '';
        }

        // Steuerzeichen entfernen (C0-Bereich inkl. \x00-\x1F sowie DEL \x7F).
        $cleaned = \preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        $value = \is_string($cleaned) ? $cleaned : '';
        if ($value === '') {
            return '';
        }

        return \trim(\mb_substr($value, 0, $maxLen, 'UTF-8'));
    }

    private function logger(): LoggerInterface
    {
        return \OCP\Server::get(LoggerInterface::class);
    }
}
