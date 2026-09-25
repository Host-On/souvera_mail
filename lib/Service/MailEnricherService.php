<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use Psr\Log\LoggerInterface;

/**
 * Zentrale Anreicherung einer Mail für Push-Benachrichtigungen:
 * Betreff, Absender und Text-Vorschau per JMAP `Email/get` als User.
 *
 * Dedupe: war zuvor 1:1 doppelt implementiert (MailPushPoller::
 * fetchEmailDetails und StalwartWebhookController::fetchEmailEnrichment).
 *
 * Fehler sind bewusst nicht fatal — der Push geht dann ohne Anreicherung
 * raus (z. B. wenn OIDC auf der Instanz gerade nicht verfügbar ist).
 *
 * @return array{subject: string, from: string, preview: string}
 */
class MailEnricherService
{
    /** Länge der Body-Vorschau (Zeichen) in der Push-Benachrichtigung. */
    private const PREVIEW_MAX_LEN = 80;

    public function __construct(
        private readonly StalwartAdminService $stalwart,
        private readonly StalwartUserContext $userContext,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetchDetails(string $userId, string $emailId): array
    {
        $empty = ['subject' => '', 'from' => '', 'preview' => ''];
        if ($emailId === '') {
            return $empty;
        }
        try {
            $bearer = $this->userContext->resolveBearer($userId);
            $accountId = $this->userContext->resolveAccountId($userId);
            $response = $this->stalwart->jmapCall(
                $bearer,
                [
                    ['Email/get', [
                        'accountId' => $accountId,
                        'ids' => [$emailId],
                        'properties' => ['subject', 'from', 'bodyValues'],
                        'bodyProperties' => ['textBody', 'htmlBody', 'preview'],
                        'fetchTextBodyValues' => true,
                        'fetchHTMLBodyValues' => true,
                        'maxBodyValueBytes' => 2048,
                    ], 'g0'],
                ],
                ['urn:ietf:params:jmap:mail'],
            );
            $get = $this->stalwart->extractMethodResponse($response, 'Email/get');
            $list = $get['list'] ?? [];
            $first = \is_array($list) && isset($list[0]) && \is_array($list[0]) ? $list[0] : [];
            $subject = (string) ($first['subject'] ?? '');
            $from = '';
            $fromArr = $first['from'] ?? null;
            if (\is_array($fromArr) && isset($fromArr[0]) && \is_array($fromArr[0])) {
                $from = (string) ($fromArr[0]['name'] ?? $fromArr[0]['email'] ?? '');
            }
            $preview = $this->buildPreview($first);
            return ['subject' => $subject, 'from' => $from, 'preview' => $preview];
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Souvera Mail: mail enrichment failed for "' . $userId . '": ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            return $empty;
        }
    }

    /**
     * Baut die Body-Vorschau (die ersten ~80 Zeichen) für die
     * Push-Benachrichtigung. Reihenfolge der Quellen:
     *   1. `textBody` (Text-Teil, direkt verwendbar)
     *   2. `htmlBody` (HTML-Teil, wird zu Text reduziert)
     *   3. `preview` (serverseitig generierte Plaintext-Vorschau)
     *   4. `bodyValues` (Fallback über die Roh-Teilwerte)
     *
     * Liefert '' wenn kein verwertbarer Body vorhanden ist (z. B. nur
     * Binär-/Attachment-Inhalt oder kaputtes Encoding) — der Aufrufer
     * verschickt den Push dann ohne Vorschau-Zeile.
     *
     * @param array<string, mixed> $email Email-Objekt aus Email/get
     */
    public function buildPreview(array $email): string
    {
        $text = (string) ($email['textBody'] ?? '');
        if ($text === '') {
            $html = (string) ($email['htmlBody'] ?? '');
            if ($html !== '') {
                $text = $this->htmlToText($html);
            }
        }
        if ($text === '') {
            $text = (string) ($email['preview'] ?? '');
        }
        if ($text === '') {
            $text = $this->extractPreview($email);
        }
        if ($text === '') {
            return '';
        }

        // Kaputtes Encoding → Fallback ohne Vorschau, damit ein invalider
        // Bytestrom nicht weiterverarbeitet (und am NC-Validator) scheitert.
        if (!\mb_check_encoding($text, 'UTF-8')) {
            return '';
        }

        $text = $this->normalizeWhitespace($text);
        if ($text === '') {
            return '';
        }

        return $this->truncate($text, self::PREVIEW_MAX_LEN);
    }

    /**
     * Reduziert einen HTML-Body auf reinen Text: Tags entfernen, dann
     * Entities auflösen (analog zum Webmail-Compose-Pfad in
     * {@see ExternalSmtpService}).
     */
    private function htmlToText(string $html): string
    {
        $stripped = @\strip_tags($html);
        if (!\is_string($stripped)) {
            return '';
        }
        $decoded = @\html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return \is_string($decoded) ? $decoded : $stripped;
    }

    /**
     * Fasst aufeinanderfolgende Whitespace-Zeichen (inkl. Non-breaking
     * Spaces, die aus &nbsp; entstehen) zu einem einzelnen Leerzeichen
     * zusammen und trimmt das Ergebnis.
     */
    private function normalizeWhitespace(string $text): string
    {
        $collapsed = \preg_replace('/[\s\p{Z}]+/u', ' ', $text);
        return \is_string($collapsed) ? \trim($collapsed) : '';
    }

    /**
     * Kürzt auf `$maxLen` Zeichen und hängt bei Abschneidung "..." an.
     * Eingabe muss valides UTF-8 sein.
     */
    private function truncate(string $text, int $maxLen): string
    {
        if (\mb_strlen($text, 'UTF-8') <= $maxLen) {
            return $text;
        }
        return \mb_substr($text, 0, $maxLen, 'UTF-8') . '...';
    }

    /**
     * Extrahiert eine Text-Vorschau aus den bodyValues einer Email/get-Antwort.
     * Stalwart liefert bodyValues nur, wenn "bodyValues" in properties steht.
     * Roh-Zusammenführung der Teilwerte — Normalisierung und Kürzung übernimmt
     * {@see buildPreview}.
     *
     * @param array<string, mixed> $email Email-Objekt aus Email/get
     */
    public function extractPreview(array $email): string
    {
        $bodyValues = $email['bodyValues'] ?? null;
        if (!\is_array($bodyValues)) {
            return '';
        }
        $parts = [];
        foreach ($bodyValues as $part) {
            if (\is_array($part) && isset($part['value']) && \is_string($part['value'])) {
                $parts[] = $part['value'];
            }
        }
        return \implode(' ', $parts);
    }
}
