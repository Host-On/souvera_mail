<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

/**
 * Local fallback presets for the most common German and international
 * mail providers.
 *
 * Snappymail's engine already tries three online autoconfig sources
 * (autoconfig.{domain}, Microsoft autodiscover, autoconfig.thunderbird.net)
 * before giving up — but we've seen users' networks block those URLs
 * during on-boarding (especially first-run in restricted enterprise
 * environments). This preset table gives us a curated last-line
 * fallback for the top providers.
 *
 * The list is CURATED and small on purpose — it's only there when
 * everything else fails. We do NOT try to be a full IspDB replica.
 *
 * Data cross-references (as of 2026-02):
 *  - web.de / GMX:   https://hilfe.web.de/pop-imap/imap-serverdaten.html
 *  - t-online.de:    https://www.telekom.de/hilfe/email/telekom-mail
 *  - gmail.com:      Requires 2FA + App Password since May 2022.
 *  - outlook.com:    Basic-Auth disabled Sept 2024; needs App Password.
 *  - freenet.de:     https://email.freenet.de/dienste/emailhilfe
 *  - posteo.de:      https://posteo.de/hilfe/einstellungen-und-zugriff
 *  - mailbox.org:    https://kb.mailbox.org/en/private/e-mail-article
 */
final class ExternalAccountsProviderPresets
{
    /**
     * Get the preset for a given email address (matched by domain,
     * lowercased). Returns null when no preset is known.
     *
     * @return array{
     *     display: string,
     *     imap:    array{host: string, port: int, ssl: string},
     *     smtp:    array{host: string, port: int, ssl: string},
     *     pop3?:   array{host: string, port: int, ssl: string},
     *     warning?: string,
     *     help_url?: string,
     *     pre_flight?: string
     * }|null
     */
    public static function forEmail(string $email): ?array
    {
        $at = \strrpos($email, '@');
        if ($at === false) { return null; }
        $domain = \strtolower(\substr($email, $at + 1));
        return self::forDomain($domain);
    }

    /** @return array<string,mixed>|null */
    public static function forDomain(string $domain): ?array
    {
        $domain = \strtolower(\trim($domain));
        $all = self::all();
        // Direct hit.
        if (isset($all[$domain])) {
            return $all[$domain];
        }
        // Aliased entries (outlook.com == hotmail.com == live.com …).
        foreach ($all as $canonical => $preset) {
            if (isset($preset['aliases']) && \in_array($domain, $preset['aliases'], true)) {
                return $preset;
            }
        }
        return null;
    }

    /** @return array<string, array<string,mixed>> */
    public static function all(): array
    {
        return [
            // -------- German consumer providers --------
            'web.de' => [
                'display' => 'WEB.DE',
                'imap' => ['host' => 'imap.web.de', 'port' => 993, 'ssl' => 'SSL'],
                'pop3' => ['host' => 'pop3.web.de', 'port' => 995, 'ssl' => 'SSL'],
                'smtp' => ['host' => 'smtp.web.de', 'port' => 587, 'ssl' => 'STARTTLS'],
                'pre_flight' => 'You must first enable POP3 / IMAP access in the WEB.DE web interface under "Einstellungen → POP3/IMAP-Abruf".',
                'help_url'   => 'https://hilfe.web.de/pop-imap/einschalten.html',
            ],
            'gmx.de' => [
                'display' => 'GMX',
                'aliases' => ['gmx.net', 'gmx.com', 'gmx.at', 'gmx.ch'],
                'imap' => ['host' => 'imap.gmx.net', 'port' => 993, 'ssl' => 'SSL'],
                'pop3' => ['host' => 'pop.gmx.net', 'port' => 995, 'ssl' => 'SSL'],
                'smtp' => ['host' => 'mail.gmx.net', 'port' => 587, 'ssl' => 'STARTTLS'],
                'pre_flight' => 'You must first enable POP3 / IMAP access in the GMX web interface under "Einstellungen → POP3/IMAP".',
                'help_url'   => 'https://hilfe.gmx.net/pop-imap/einschalten.html',
            ],
            't-online.de' => [
                'display' => 'Telekom Mail',
                'aliases' => ['magenta.de'],
                'imap' => ['host' => 'secureimap.t-online.de', 'port' => 993, 'ssl' => 'SSL'],
                'pop3' => ['host' => 'securepop.t-online.de', 'port' => 995, 'ssl' => 'SSL'],
                'smtp' => ['host' => 'securesmtp.t-online.de', 'port' => 587, 'ssl' => 'STARTTLS'],
                'pre_flight' => 'Use your Passwort für E-Mail-Programme (not your general Telekom login).',
                'help_url'   => 'https://www.telekom.de/hilfe/email/e-mail-passwort',
            ],
            'freenet.de' => [
                'display' => 'freenet Mail',
                'imap' => ['host' => 'mx.freenet.de', 'port' => 993, 'ssl' => 'SSL'],
                'pop3' => ['host' => 'mx.freenet.de', 'port' => 995, 'ssl' => 'SSL'],
                'smtp' => ['host' => 'mx.freenet.de', 'port' => 587, 'ssl' => 'STARTTLS'],
                'help_url' => 'https://email.freenet.de/dienste/emailhilfe',
            ],
            '1und1.de' => [
                'display' => '1&1',
                'aliases' => ['1and1.de', '1und1.com'],
                'imap' => ['host' => 'imap.1und1.de', 'port' => 993, 'ssl' => 'SSL'],
                'pop3' => ['host' => 'pop.1und1.de', 'port' => 995, 'ssl' => 'SSL'],
                'smtp' => ['host' => 'smtp.1und1.de', 'port' => 587, 'ssl' => 'STARTTLS'],
                'help_url' => 'https://hilfe-center.1und1.de/e-mail-c85990.html',
            ],
            'mail.de' => [
                'display' => 'mail.de',
                'imap' => ['host' => 'imap.mail.de', 'port' => 993, 'ssl' => 'SSL'],
                'pop3' => ['host' => 'pop3.mail.de', 'port' => 995, 'ssl' => 'SSL'],
                'smtp' => ['host' => 'smtp.mail.de', 'port' => 587, 'ssl' => 'STARTTLS'],
                'help_url' => 'https://mail.de/hilfe/pop3-imap-smtp',
            ],

            // -------- Privacy-focused providers --------
            'posteo.de' => [
                'display' => 'Posteo',
                'aliases' => ['posteo.net', 'posteo.eu'],
                'imap' => ['host' => 'posteo.de', 'port' => 993, 'ssl' => 'SSL'],
                'pop3' => ['host' => 'posteo.de', 'port' => 995, 'ssl' => 'SSL'],
                'smtp' => ['host' => 'posteo.de', 'port' => 587, 'ssl' => 'STARTTLS'],
                'help_url' => 'https://posteo.de/hilfe/pop-imap-smtp',
            ],
            'mailbox.org' => [
                'display' => 'mailbox.org',
                'imap' => ['host' => 'imap.mailbox.org', 'port' => 993, 'ssl' => 'SSL'],
                'pop3' => ['host' => 'pop3.mailbox.org', 'port' => 995, 'ssl' => 'SSL'],
                'smtp' => ['host' => 'smtp.mailbox.org', 'port' => 587, 'ssl' => 'STARTTLS'],
                'help_url' => 'https://kb.mailbox.org/en/private/e-mail-article',
            ],

            // -------- International (with special auth caveats!) --------
            'gmail.com' => [
                'display' => 'Google Mail',
                'aliases' => ['googlemail.com'],
                'imap' => ['host' => 'imap.gmail.com', 'port' => 993, 'ssl' => 'SSL'],
                'pop3' => ['host' => 'pop.gmail.com', 'port' => 995, 'ssl' => 'SSL'],
                'smtp' => ['host' => 'smtp.gmail.com', 'port' => 587, 'ssl' => 'STARTTLS'],
                'warning' => 'GMAIL_APP_PASSWORD',
                'help_url' => 'https://support.google.com/accounts/answer/185833',
            ],
            'outlook.com' => [
                'display' => 'Outlook.com',
                'aliases' => ['hotmail.com', 'live.com', 'msn.com', 'outlook.de', 'hotmail.de', 'live.de'],
                'imap' => ['host' => 'outlook.office365.com', 'port' => 993, 'ssl' => 'SSL'],
                'pop3' => ['host' => 'outlook.office365.com', 'port' => 995, 'ssl' => 'SSL'],
                'smtp' => ['host' => 'smtp-mail.outlook.com', 'port' => 587, 'ssl' => 'STARTTLS'],
                'warning' => 'OUTLOOK_MODERN_AUTH',
                'help_url' => 'https://support.microsoft.com/office/pop-imap-and-smtp-settings-8361e398-8af4-4e97-b147-6c6c4ac95353',
            ],
            'yahoo.com' => [
                'display' => 'Yahoo Mail',
                'aliases' => ['yahoo.de', 'ymail.com', 'rocketmail.com'],
                'imap' => ['host' => 'imap.mail.yahoo.com', 'port' => 993, 'ssl' => 'SSL'],
                'pop3' => ['host' => 'pop.mail.yahoo.com', 'port' => 995, 'ssl' => 'SSL'],
                'smtp' => ['host' => 'smtp.mail.yahoo.com', 'port' => 587, 'ssl' => 'STARTTLS'],
                'warning' => 'YAHOO_APP_PASSWORD',
                'help_url' => 'https://help.yahoo.com/kb/generate-manage-third-party-passwords-sln15241.html',
            ],
        ];
    }

    /**
     * Return a compact list of provider slugs → display names for
     * the front-end preset picker.
     *
     * @return array<string, string>
     */
    public static function directory(): array
    {
        $out = [];
        foreach (self::all() as $domain => $preset) {
            $out[$domain] = (string) $preset['display'];
        }
        return $out;
    }
}
