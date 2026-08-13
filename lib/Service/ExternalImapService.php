<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use Psr\Log\LoggerInterface;

/**
 * Read-only IMAP access for external accounts (v2 frontend).
 *
 * External accounts are plain IMAP/SMTP mailboxes attached by the user
 * (see ExternalAccountService). This service lists folders and fetches
 * messages via PHP's imap_* functions so they can be shown in the app's
 * navigation without migrating them into the Stalwart mailbox.
 *
 * All methods are defensive: wrong credentials, unavailable servers or
 * a missing PHP imap extension produce empty results, never exceptions.
 */
class ExternalImapService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /** @return array<int,array>|null */
    private function open(array $account, string $mailbox = 'INBOX'): mixed
    {
        if (!\function_exists('imap_open')) {
            return null;
        }
        $ssl = $account['imap_ssl'] ?? 'ssl';
        $sslPart = match ($ssl) {
            'starttls' => '/tls',
            'none' => '/notls/novalidate-cert',
            default => '/ssl',
        };
        $ref = '{' . $account['imap_host'] . ':' . $account['imap_port'] . '/imap' . $sslPart . '}' . $mailbox;
        try {
            $conn = @\imap_open($ref, $account['username'], $account['password'], 0, 1, [
                'DISABLE_AUTHENTICATOR' => 'GSSAPI',
            ]);
            return $conn === false ? null : $conn;
        } catch (\Throwable $e) {
            $this->logger->warning('ExternalImapService: open failed: ' . $e->getMessage(), ['app' => 'souvera_mail']);
            return null;
        }
    }

    /**
     * Folder tree with unread counts.
     *
     * @return array{ok: bool, folders?: list<array{path: string, name: string, delimiter: string, unread: int, total: int}>, error?: string}
     */
    public function folders(array $account): array
    {
        $conn = $this->open($account);
        if ($conn === null) {
            return ['ok' => false, 'error' => 'Cannot connect to IMAP server'];
        }
        try {
            $host = $account['imap_host'];
            $list = @\imap_getmailboxes($conn, '{' . $host . '}', '*');
            if (!\is_array($list) || $list === []) {
                // Some servers only answer to LIST "" "*" via imap_list.
                $raw = @\imap_list($conn, '{' . $host . '}', '*');
                $list = \is_array($raw) ? \array_map(fn($m) => (object) ['name' => $m], $raw) : [];
            }
            $folders = [];
            foreach ($list as $mb) {
                $full = (string) $mb->name;
                // name is like {host}INBOX.Sent — strip the server part.
                $delim = (string) ($mb->delimiter ?? '.');
                $strip = '{' . $host . '}';
                $path = \str_starts_with($full, $strip) ? \substr($full, \strlen($strip)) : $full;
                if ($path === '') continue;
                $unread = 0;
                $total = 0;
                $status = @\imap_status($conn, $full, \SA_UNSEEN | \SA_MESSAGES);
                if ($status !== false) {
                    $unread = (int) ($status->unseen ?? 0);
                    $total = (int) ($status->messages ?? 0);
                }
                $folders[] = [
                    'path' => $path,
                    'name' => \strrpos($path, $delim) !== false ? \substr($path, \strrpos($path, $delim) + \strlen($delim)) : $path,
                    'delimiter' => $delim,
                    'unread' => $unread,
                    'total' => $total,
                ];
            }
            return ['ok' => true, 'folders' => $folders];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            @\imap_close($conn);
        }
    }

    /**
     * Message headers of a folder, newest first.
     *
     * @return array{ok: bool, messages?: list<array>, total?: int, unread?: int, error?: string}
     */
    public function messages(array $account, string $folder, int $offset = 0, int $limit = 50): array
    {
        $offset = \max(0, $offset);
        $limit = \max(1, \min(200, $limit));
        $conn = $this->open($account, $folder);
        if ($conn === null) {
            return ['ok' => false, 'error' => 'Cannot connect to IMAP server'];
        }
        try {
            $total = (int) \imap_num_msg($conn);
            if ($total === 0) {
                return ['ok' => true, 'messages' => [], 'total' => 0, 'unread' => 0];
            }
            // Sequence numbers 1 = oldest; newest first = reverse order.
            $start = \max(1, $total - $offset - $limit + 1);
            $end = $total - $offset;
            if ($end < 1) {
                return ['ok' => true, 'messages' => [], 'total' => $total, 'unread' => 0];
            }
            if ($start > $end) $start = $end;
            $seqs = \implode(',', \range($start, $end));
            $overview = @\imap_fetch_overview($conn, $seqs, 0);
            if (!\is_array($overview)) $overview = [];
            // Newest first: reverse the fetched (ascending) list.
            $overview = \array_reverse($overview);
            $uidMap = [];
            $rows = [];
            foreach ($overview as $o) {
                $uid = (int) ($o->uid ?? 0);
                $uidMap[$uid] = true;
                $rows[] = [
                    'uid' => $uid,
                    'subject' => $this->decodeHeader((string) ($o->subject ?? '')),
                    'from' => $this->decodeHeader((string) ($o->from ?? '')),
                    'date' => \strtotime((string) ($o->date ?? '')) ?: 0,
                    'seen' => (bool) ($o->seen ?? false),
                    'flagged' => (bool) ($o->flagged ?? false),
                ];
            }
            $status = @\imap_status($conn, '{' . $account['imap_host'] . '}' . $folder, \SA_UNSEEN | \SA_MESSAGES);
            return [
                'ok' => true,
                'messages' => $rows,
                'total' => $total,
                'unread' => $status !== false ? (int) ($status->unseen ?? 0) : 0,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            @\imap_close($conn);
        }
    }

    /**
     * Full message for display. Prefers the HTML part, falls back to the
     * plain-text part wrapped in <pre>.
     *
     * @return array{ok: bool, message?: array, error?: string}
     */
    public function message(array $account, string $folder, int $uid): array
    {
        $conn = $this->open($account, $folder);
        if ($conn === null) {
            return ['ok' => false, 'error' => 'Cannot connect to IMAP server'];
        }
        try {
            $headers = \imap_fetchheader($conn, $uid, \FT_UID);
            $structure = \imap_fetchstructure($conn, $uid, \FT_UID);
            $parsed = $headers !== false ? \imap_rfc822_parse_headers($headers) : null;

            $html = '';
            $plain = '';
            $parts = $this->flattenParts($structure);
            foreach ($parts as $part) {
                $content = @\imap_fetchbody($conn, $uid, $part['section'], \FT_UID | \FT_PEEK);
                if ($content === false || $content === '') continue;
                if ($part['encoding'] === 3) {
                    $content = \base64_decode($content);
                } elseif ($part['encoding'] === 4) {
                    $content = \quoted_printable_decode($content);
                }
                $content = $this->toUtf8($content, $part['charset']);
                if ($part['subtype'] === 'HTML') {
                    $html = $html !== '' ? $html : $content;
                } elseif ($part['subtype'] === 'PLAIN' && $plain === '') {
                    $plain = $content;
                }
                if ($html !== '' && $plain !== '') break;
            }
            if ($html === '' && $plain !== '') {
                $html = '<pre>' . \htmlspecialchars($plain, \ENT_QUOTES, 'UTF-8') . '</pre>';
            }

            return [
                'ok' => true,
                'message' => [
                    'uid' => $uid,
                    'subject' => $this->decodeHeader($parsed->subject ?? ''),
                    'fromName' => $this->decodeHeader($parsed->from[0]->personal ?? ''),
                    'fromAddress' => \trim((string) ($parsed->from[0]->mailbox ?? '')) . '@' . \trim((string) ($parsed->from[0]->host ?? '')),
                    'date' => \strtotime((string) ($parsed->date ?? '')) ?: 0,
                    'html' => $html,
                ],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            @\imap_close($conn);
        }
    }

    /**
     * Flatten the MIME part tree into a list of {section, subtype, encoding,
     * charset}. Multipart containers (including nested ALTERNATIVE inside
     * MIXED) are recursed so their leaves get proper "1.1"/"1.2" sections.
     *
     * @return list<array{section: string, subtype: string, encoding: int, charset: string}>
     */
    private function flattenParts(object $structure, string $prefix = ''): array
    {
        $out = [];
        $sections = [1 => $structure];
        if (!empty($structure->parts)) {
            $sections = $structure->parts;
        }
        $i = 1;
        foreach ($sections as $part) {
            $section = $prefix === '' ? (string) $i : $prefix . '.' . $i;
            $subtype = \strtoupper((string) ($part->subtype ?? ''));
            if (!empty($part->parts)) {
                $out = \array_merge($out, $this->flattenParts($part, $section));
            } else {
                $charset = '';
                if (!empty($part->parameters)) {
                    foreach ($part->parameters as $param) {
                        if (\strtoupper((string) ($param->attribute ?? '')) === 'CHARSET') {
                            $charset = (string) ($param->value ?? '');
                            break;
                        }
                    }
                }
                $out[] = [
                    'section' => $section,
                    'subtype' => $subtype,
                    'encoding' => (int) ($part->encoding ?? 0),
                    'charset' => $charset,
                ];
            }
            $i++;
        }
        return $out;
    }

    /** Decode RFC 2047 encoded words in header values (MIME-B etc.). */
    private function decodeHeader(string $value): string
    {
        $value = \trim($value);
        if ($value === '' || !\function_exists('imap_mime_header_decode')) {
            return $value;
        }
        $parts = @\imap_mime_header_decode($value);
        if (!\is_array($parts)) {
            return $value;
        }
        $out = '';
        foreach ($parts as $part) {
            $text = (string) ($part->text ?? '');
            $charset = (string) ($part->charset ?? 'default');
            if ($charset !== '' && \strcasecmp($charset, 'default') !== 0 && \strcasecmp($charset, 'UTF-8') !== 0) {
                $out .= $this->toUtf8($text, $charset);
            } else {
                $out .= $text;
            }
        }
        return $out;
    }

    /** Convert a part body to UTF-8 when a charset is declared. */
    private function toUtf8(string $content, string $charset): string
    {
        $charset = \trim($charset);
        if ($charset === '' || \strcasecmp($charset, 'UTF-8') === 0) {
            return $content;
        }
        if (\function_exists('mb_convert_encoding')) {
            $converted = @\mb_convert_encoding($content, 'UTF-8', $charset);
            return $converted === false ? $content : $converted;
        }
        return $content;
    }
}
