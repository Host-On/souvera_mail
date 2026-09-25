<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use Psr\Log\LoggerInterface;

/**
 * Zentrale Anreicherung einer Mail für Push-Benachrichtigungen:
 * Betreff, Absender und Text-Vorschau per JMAP `Email/get` als ADMIN.
 *
 * Bewusst KEIN User-Bearer: der primäre Aufrufer ist der Stalwart-Webhook
 * (kontextlos, keine Usersession) — eine On-demand-OIDC-Token-Generierung
 * pro User wäre der wunde Punkt, an dem die komplette Anreicherung still
 * gestorben ist (Push kam dann nur als „Neue E-Mail" ohne Inhalt). Der
 * Admin-JMAP-Pfad darf fremde Accounts lesen (gleiche Berechtigungsstufe
 * wie das Principal/get der Webhook-Auflösung) und ist kontextlos robust.
 *
 * Fehler sind bewusst nicht fatal — der Push geht dann ohne Anreicherung
 * raus; der Grund steht allerdings auf WARNING im Log (statt DEBUG), damit
 * ein stiller Anreicherungs-Tod sofort sichtbar ist.
 *
 * @return array{subject: string, from: string, preview: string}
 */
class MailEnricherService
{
    /** Länge der Body-Vorschau (Zeichen) in der Push-Benachrichtigung. */
    private const PREVIEW_MAX_LEN = 80;

    public function __construct(
        private readonly StalwartAdminService $stalwart,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $accountId JMAP accountId (base32 des numerischen
     *                          Stalwart-Account-Ids) des Empfängers
     * @param string $emailId   JMAP Email-Id (base32 der Stalwart-Doc-ID)
     * @return array{subject: string, from: string, preview: string}
     */
    public function fetchDetails(string $accountId, string $emailId): array
    {
        $empty = ['subject' => '', 'from' => '', 'preview' => ''];
        if ($emailId === '' || $accountId === '') {
            return $empty;
        }
        try {
            $response = $this->stalwart->jmapCallAsAdmin(
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
            $this->logger->warning(
                'Souvera Mail: mail enrichment failed (accountId=' . $accountId
                . ' emailId=' . $emailId . '): ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
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
     * Reduziert einen HTML-Body auf reinen Text (Original-Implementierung —
     * getestet; bewusst tolerant gegen strip_tags-Fehlschläge).
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

    private function normalizeWhitespace(string $text): string
    {
        $collapsed = \preg_replace('/[\s\p{Z}]+/u', ' ', $text);
        return \is_string($collapsed) ? \trim($collapsed) : '';
    }

    private function truncate(string $text, int $maxLen): string
    {
        if (\mb_strlen($text, 'UTF-8') <= $maxLen) {
            return $text;
        }
        return \mb_substr($text, 0, $maxLen, 'UTF-8') . '...';
    }

    /**
     * Fallback über die Roh-Teilwerte (bodyValues), wenn weder textBody
     * noch htmlBody noch preview geliefert wurden.
     *
     * @param array<string, mixed> $email
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
