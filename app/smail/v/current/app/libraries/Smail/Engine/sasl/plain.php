<?php
/**
 * RFC 4616 — SASL PLAIN
 *
 * Single-shot password mechanism. Server capability: `AUTH=PLAIN`.
 *
 * Wire format:
 *   authzid \0 authcid \0 passwd
 *
 * Almost universally supported on IMAP / SMTP / ManageSieve. Required
 * for every external mailbox (web.de, GMX, self-hosted, …) because
 * these providers do not accept OAUTHBEARER / XOAUTH2 — the two
 * mechanisms the Souvera-Mail engine fork previously shipped
 * exclusively (Stalwart-only). Restored in v0.16.1.
 *
 * SECURITY: PLAIN transmits credentials in the clear inside the
 * base64 payload; the callers (ImapClient / SmtpClient / SieveClient)
 * refuse to use it over an unencrypted transport (STARTTLS is issued
 * first, and — for OIDC bearer tokens — the encrypted-transport
 * assertion in assertEncryptedForBearerAuth() applies additionally).
 */

namespace Smail\Engine\SASL;

class PLAIN extends \Smail\Engine\SASL
{
    public function authenticate(
        string $authcid,
        #[\SensitiveParameter]
        string $passphrase,
        ?string $authzid = null
    ) : string {
        // RFC 4616 §2:  message = [authzid] UTF8NUL authcid UTF8NUL passwd
        return $this->encode(($authzid ?? '') . "\x00{$authcid}\x00{$passphrase}");
    }

    public static function isSupported(string $param) : bool
    {
        return true;
    }
}
