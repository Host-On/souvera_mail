<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use Psr\Log\LoggerInterface;

/**
 * Minimal SMTP client for sending via external accounts.
 *
 * Password-based AUTH LOGIN / PLAIN, implicit TLS (465) or STARTTLS (587),
 * optional plaintext (25). No external library dependency — raw sockets
 * only, so it works in any Nextcloud runtime.
 *
 * Security invariants:
 *  - STARTTLS configuration fails CLOSED when the server does not
 *    advertise the capability (no plaintext auth downgrade).
 *  - All header values are CR/LF-stripped (no header injection).
 */
class ExternalSmtpService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array            $account     External account (with password).
     * @param string           $fromEmail   Envelope + From header address.
     * @param string           $fromName    Optional display name.
     * @param list<string>     $to
     * @param list<string>     $cc
     * @param list<string>     $bcc
     * @param list<array{name: string, type: string, data: string}> $attachments base64 data
     *
     * @throws \RuntimeException with a human-readable message on failure.
     */
    public function send(
        array $account,
        string $fromEmail,
        string $fromName,
        array $to,
        array $cc,
        array $bcc,
        string $subject,
        string $bodyHtml,
        string $bodyPlain,
        array $attachments = [],
    ): void {
        $smtpHost = \trim((string) ($account['smtp_host'] ?? ''));
        $smtpPort = (int) ($account['smtp_port'] ?? 465);
        $smtpSsl = (string) ($account['smtp_ssl'] ?? 'ssl');
        $username = \trim((string) ($account['username'] ?? $fromEmail));
        $password = (string) ($account['password'] ?? '');

        if ($smtpHost === '' || $password === '') {
            throw new \RuntimeException('SMTP not configured for this account');
        }
        $rcpts = \array_merge($to, $cc, $bcc);
        if ($rcpts === []) {
            throw new \RuntimeException('No recipients');
        }

        $fp = $this->connect($smtpHost, $smtpPort, $smtpSsl);
        try {
            $this->expect($fp, [220], 'greeting');
            $ehlo = $this->ehlo($fp);

            if ($smtpSsl === 'starttls') {
                if (!\in_array('STARTTLS', $ehlo, true)) {
                    throw new \RuntimeException('Server does not support STARTTLS — refusing to send without encryption');
                }
                $this->command($fp, 'STARTTLS');
                $this->expect($fp, [220], 'STARTTLS');
                if (!@\stream_socket_enable_crypto($fp, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('TLS negotiation failed');
                }
                $ehlo = $this->ehlo($fp);
            }

            $this->auth($fp, $ehlo, $username, $password);

            $this->command($fp, 'MAIL FROM:<' . $this->sanitizeAddr($fromEmail) . '>');
            $this->expect($fp, [250], 'MAIL FROM');
            foreach ($rcpts as $rcpt) {
                $this->command($fp, 'RCPT TO:<' . $this->sanitizeAddr($rcpt) . '>');
                $this->expect($fp, [250, 251], 'RCPT TO');
            }

            $this->command($fp, 'DATA');
            $this->expect($fp, [354], 'DATA');
            \fwrite($fp, $this->buildMessage($fromEmail, $fromName, $to, $cc, $subject, $bodyHtml, $bodyPlain, $attachments));
            $this->expect($fp, [250], 'message body');
            $this->command($fp, 'QUIT');
        } finally {
            @\fclose($fp);
        }
    }

    private function sanitizeAddr(string $addr): string
    {
        return \str_replace(["\r", "\n", '<', '>'], '', \trim($addr));
    }

    /** @return resource */
    private function connect(string $host, int $port, string $ssl)
    {
        $errno = 0;
        $errstr = '';
        $remote = ($ssl === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = @\stream_socket_client($remote, $errno, $errstr, 15, \STREAM_CLIENT_CONNECT);
        if ($fp === false) {
            throw new \RuntimeException('Cannot connect to SMTP server: ' . $errstr);
        }
        \stream_set_timeout($fp, 20);
        return $fp;
    }

    /**
     * Send EHLO and return the advertised capability TOKENS (each
     * space-separated word of every 250 line, upper-cased).
     *
     * @param resource $fp
     */
    private function ehlo($fp): array
    {
        $this->command($fp, 'EHLO souvera-mail.local');
        $tokens = [];
        while (true) {
            $line = $this->readLine($fp);
            if ($line === null) break;
            $code = (int) \substr($line, 0, 3);
            if ($code === 250) {
                if (\strlen($line) > 4) {
                    foreach (\preg_split('/\s+/', \trim(\substr($line, 4))) as $token) {
                        $token = \strtoupper(\trim($token));
                        if ($token !== '') $tokens[] = $token;
                    }
                }
                if (\strlen($line) < 4 || $line[3] !== '-') break;
            } else {
                break;
            }
        }
        return $tokens;
    }

    /** @param resource $fp */
    private function auth($fp, array $ehlo, string $user, string $pass): void
    {
        $caps = \array_map(fn($t) => \strtoupper($t), $ehlo);
        $authMethods = \in_array('AUTH', $caps, true) ? $caps : $caps;
        if (\in_array('AUTH', $caps, true)) {
            // Prefer CRAM-MD5? Not implemented — LOGIN/PLAIN are sufficient
            // for the TLS-protected connections this client allows.
            if (\in_array('LOGIN', $authMethods, true)) {
                $this->command($fp, 'AUTH LOGIN');
                $this->expect($fp, [334], 'AUTH LOGIN');
                $this->command($fp, \base64_encode($user));
                $this->expect($fp, [334], 'AUTH username');
                $this->command($fp, \base64_encode($pass));
                $this->expect($fp, [235], 'AUTH password');
                return;
            }
            if (\in_array('PLAIN', $authMethods, true)) {
                $this->command($fp, 'AUTH PLAIN ' . \base64_encode("\0" . $user . "\0" . $pass));
                $this->expect($fp, [235], 'AUTH PLAIN');
                return;
            }
            throw new \RuntimeException('SMTP server offers no supported AUTH method (LOGIN/PLAIN)');
        }
        // Server without an AUTH advertisement — try LOGIN anyway (some
        // legacy servers accept it without announcing).
        $this->command($fp, 'AUTH LOGIN');
        $this->expect($fp, [334], 'AUTH LOGIN (fallback)');
        $this->command($fp, \base64_encode($user));
        $this->expect($fp, [334], 'AUTH username (fallback)');
        $this->command($fp, \base64_encode($pass));
        $this->expect($fp, [235], 'AUTH password (fallback)');
    }

    /** @param resource $fp */
    private function command($fp, string $cmd): void
    {
        \fwrite($fp, $cmd . "\r\n");
    }

    /** @param resource $fp */
    private function readLine($fp): ?string
    {
        $line = \fgets($fp, 1024);
        return $line === false ? null : \rtrim($line, "\r\n");
    }

    /** @param resource $fp */
    private function expect($fp, array $codes, string $stage): void
    {
        $last = '';
        while (true) {
            $line = $this->readLine($fp);
            if ($line === null) {
                throw new \RuntimeException('SMTP server closed connection (' . $stage . ')');
            }
            $last = $line;
            $code = (int) \substr($line, 0, 3);
            if (\in_array($code, $codes, true)) return;
            if (\strlen($line) < 4 || $line[3] !== '-') break;
        }
        throw new \RuntimeException('SMTP error during ' . $stage . ': ' . $last);
    }

    /** @param list<array{name: string, type: string, data: string}> $attachments */
    private function buildMessage(
        string $fromEmail,
        string $fromName,
        array $to,
        array $cc,
        string $subject,
        string $bodyHtml,
        string $bodyPlain,
        array $attachments,
    ): string {
        $fromEmail = $this->sanitizeHeader($fromEmail);
        $fromName = $this->sanitizeHeader($fromName);
        $subject = $this->sanitizeHeader($subject);
        $to = \array_map(fn($a) => $this->sanitizeHeader((string) $a), $to);
        $cc = \array_map(fn($a) => $this->sanitizeHeader((string) $a), $cc);

        // From: encode only the display name (RFC 2047), keep the address
        // part as a plain mailbox expression.
        $fromHeader = $fromName !== ''
            ? $this->encodeHeaderText($fromName) . ' <' . $fromEmail . '>'
            : $fromEmail;

        $lines = [
            'From: ' . $fromHeader,
            'To: ' . \implode(', ', $to),
        ];
        if ($cc !== []) {
            $lines[] = 'Cc: ' . \implode(', ', $cc);
        }
        $lines[] = 'Subject: ' . $this->encodeHeaderText($subject);
        $lines[] = 'Date: ' . \date(\DateTimeInterface::RFC2822);
        $lines[] = 'Message-ID: <' . \bin2hex(\random_bytes(12)) . '@souvera-mail>';
        $lines[] = 'MIME-Version: 1.0';

        $hasAttachments = $attachments !== [];
        $validAttachments = [];
        if ($hasAttachments) {
            foreach ($attachments as $att) {
                $name = $this->sanitizeHeader((string) ($att['name'] ?? 'attachment'));
                $type = $this->sanitizeHeader((string) ($att['type'] ?? 'application/octet-stream'));
                $decoded = \base64_decode((string) ($att['data'] ?? ''), true);
                if ($decoded === false || $decoded === '') continue;
                $validAttachments[] = ['name' => $name, 'type' => $type, 'data' => \base64_encode($decoded)];
            }
        }
        $hasAttachments = $validAttachments !== [];

        $mixedBoundary = null;
        if ($hasAttachments) {
            $mixedBoundary = '----=_mixed_' . \bin2hex(\random_bytes(12));
            $lines[] = 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"';
            $lines[] = '';
            $lines[] = '--' . $mixedBoundary;
        }

        $boundary = '----=_souvera_' . \bin2hex(\random_bytes(12));
        $bodyPlain = $bodyPlain !== '' ? $bodyPlain : \htmlspecialchars_decode(\strip_tags($bodyHtml));
        $lines[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $lines[] = '';
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = \chunk_split(\base64_encode($bodyPlain), 76, "\r\n");
        $lines[] = '--' . $boundary;
        $lines[] = 'Content-Type: text/html; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: base64';
        $lines[] = '';
        $lines[] = \chunk_split(\base64_encode($bodyHtml), 76, "\r\n");
        $lines[] = '--' . $boundary . '--';

        foreach ($validAttachments as $att) {
            $name = $this->escapeMimeParam($att['name']);
            $type = $att['type'];
            $lines[] = '--' . $mixedBoundary;
            $lines[] = 'Content-Type: ' . $type . '; name="' . $name . '"';
            $lines[] = 'Content-Disposition: attachment; filename="' . $name . '"';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = '';
            $lines[] = \chunk_split($att['data'], 76, "\r\n");
        }
        if ($hasAttachments) {
            $lines[] = '--' . $mixedBoundary . '--';
        }

        $lines[] = '.';
        $lines[] = '';

        return \implode("\r\n", $lines);
    }

    /** Strip CR/LF so values can never break out of a header line. */
    private function sanitizeHeader(string $value): string
    {
        return \str_replace(["\r", "\n", "\0"], '', \trim($value));
    }

    /** Escape a value for a quoted MIME parameter (filename=...). */
    private function escapeMimeParam(string $value): string
    {
        return \str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /** RFC 2047 encode a header value when it contains non-ASCII. */
    private function encodeHeaderText(string $value): string
    {
        $value = $this->sanitizeHeader($value);
        if (\mb_check_encoding($value, 'ASCII')) {
            return $value;
        }
        return '=?UTF-8?B?' . \base64_encode($value) . '?=';
    }
}
