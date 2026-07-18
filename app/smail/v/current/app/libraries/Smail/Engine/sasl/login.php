<?php
/**
 * "SASL LOGIN" — Microsoft-invented, RFC-less but widely deployed.
 *
 * Two-step challenge/response:
 *   S: + <base64 "Username:">
 *   C: <base64 username>
 *   S: + <base64 "Password:">
 *   C: <base64 password>
 *   S: OK / NO
 *
 * Server capability: `AUTH=LOGIN`. Restored in v0.16.1 for legacy
 * providers whose IMAP / SMTP daemons still only advertise PLAIN
 * + LOGIN (e.g. some Dovecot / Postfix defaults, Uberspace, custom
 * self-hosted stacks like `philip@uelzen.email`).
 *
 * State: after `authenticate()` produces the username, `challenge()`
 * hands back the password ONCE, then returns null (indicating the
 * exchange is complete from the client's perspective — the server's
 * OK/NO reply is checked by the caller).
 */

namespace Smail\Engine\SASL;

class LOGIN extends \Smail\Engine\SASL
{
    private string $passphrase = '';
    private bool $passwordSent = false;

    public function authenticate(
        string $authcid,
        #[\SensitiveParameter]
        string $passphrase,
        ?string $authzid = null
    ) : string {
        // Stash the password so challenge() can hand it back on
        // the second turn. authcid is the initial response.
        $this->passphrase = $passphrase;
        $this->passwordSent = false;
        return $this->encode($authcid);
    }

    public function hasChallenge() : bool
    {
        return true;
    }

    public function challenge(string $challenge) : ?string
    {
        // Ignore the challenge content — spec says clients must not
        // parse it (Microsoft never RFC-standardised the prompt text
        // and some servers send an empty challenge).
        if ($this->passwordSent) {
            return null;
        }
        $this->passwordSent = true;
        return $this->encode($this->passphrase);
    }

    public static function isSupported(string $param) : bool
    {
        return true;
    }
}
