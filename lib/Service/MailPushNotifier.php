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
    /**
     * NC-Validierungslimits (v34 core, Notification::setSubject/setMessage):
     * RAW-Subject/-Message dürfen max. 64 BYTE haben (isset($s[64])-Trick —
     * BYTE, nicht Zeichen!). Die PARSED-Werte (setParsedSubject/
     * setParsedMessage, gesetzt vom MailNotifier) sind unbegrenzt und tragen
     * den vollen Inhalt zum Gerät.
     */
    private const SUBJECT_MAX_BYTES = 64;
    private const SENDER_MAX_BYTES = 64;
    private const PREVIEW_MAX_BYTES = 240;
    private const MESSAGE_LINE_MAX_BYTES = 64;

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
        $safeSubject = $this->sanitize($subject, self::SUBJECT_MAX_BYTES);
        $safeSender = $this->sanitize($sender, self::SENDER_MAX_BYTES);
        $safePreview = $this->sanitize($preview, self::PREVIEW_MAX_BYTES);

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
        // NC-Muster: RAW-Subject/RAW-Message sind auf 64 BYTE validiert — die
        // vollen Inhalte wandern in die Subject-Parameter (unbegrenzt) und der
        // MailNotifier setzt daraus die geparsten Werte für den Push.
        // Das RAW-Message-Feld trägt zusätzlich die „Von: …"-Zeile in die DB:
        // das Android holt die komplette Notification per OCS-Fetch vom Server
        // und zeigt deren Message als zweite Zeile (die Push-Payload selbst
        // enthält KEIN Message-Feld).
        $messageLine = \trim(
            ($sender !== '' ? 'Von: ' . $sender : '')
            . ($sender !== '' && $preview !== '' ? ' · ' : '')
            . $preview
        );
        $notification
            ->setApp('souvera_mail')
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject(self::OBJECT_TYPE, $emailId)
            ->setSubject(\mb_strcut($subject !== '' ? $subject : 'Neue E-Mail', 0, self::SUBJECT_MAX_BYTES), [
                'fullSubject' => $subject !== '' ? $subject : 'Neue E-Mail',
                'from' => $sender,
                'preview' => $preview,
            ]);
        if ($messageLine !== '') {
            $notification->setMessage(\mb_strcut($messageLine, 0, self::MESSAGE_LINE_MAX_BYTES));
        }

        $this->notificationManager->notify($notification);
    }

    /**
     * Bereinigt einen Mail-Inhalt für die NC-Benachrichtigungsvalidierung:
     * garantiert valides UTF-8, entfernt Steuerzeichen (C0 + DEL) und kürzt
     * auf die übergebene Maximallänge in BYTE (mb_strcut — NC validiert
     * Bytes, nicht Zeichen; mb_strcut schneidet trotzdem UTF-8-sauber).
     * Leerer/nicht bereinigbarer Wert wird zu '' (der Aufrufer setzt dann
     * ggf. einen Fallback-Betreff).
     */
    private function sanitize(string $value, int $maxBytes): string
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

        return \trim(\mb_strcut($value, 0, $maxBytes, 'UTF-8'));
    }

    private function logger(): LoggerInterface
    {
        return \OCP\Server::get(LoggerInterface::class);
    }
}
