<?php
declare(strict_types=1);

namespace OCA\SouveraMail\Sieve;

/**
 * Snapshot of the message facts we need to evaluate rules.
 *
 * `headers` is a shape `array<string, string|string[]>` where the key
 * is the header name (case preserved) and the value is either a single
 * decoded UTF-8 string OR an array of strings if the header appears
 * multiple times.
 *
 * `envelopeFrom` and `envelopeTo` come from the SMTP envelope (JMAP
 * `Email.envelope.mailFrom` / `rcptTo`). They're optional — if we
 * don't have them, `envelope :is "from" "..."` rules never match
 * (safe under-apply).
 *
 * `size` is the raw octets count of the RFC 5322 message (JMAP
 * `Email.size`).
 */
final class MessageFacts
{
    /**
     * @param string $emailId JMAP Email id (used later for Email/set etc.)
     * @param array<string, string|string[]> $headers
     * @param string|null $envelopeFrom
     * @param string[]    $envelopeTo
     * @param int         $size
     */
    public function __construct(
        public readonly string $emailId,
        public readonly array $headers,
        public readonly ?string $envelopeFrom,
        public readonly array $envelopeTo,
        public readonly int $size
    ) {}
}
