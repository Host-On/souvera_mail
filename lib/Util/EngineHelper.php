<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Util;

use OCA\SouveraMail\Service\MailboxAccessDenied;
use OCA\SouveraMail\Service\MailboxAccessGuard;
use OCA\SouveraMail\Service\OidcProviderService;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Bridges Nextcloud's runtime (session, user, config) into the bundled
 * webmail engine. The OIDC access token used for IMAP/SMTP OAUTHBEARER is
 * obtained from H2CK/oidc via {@see OidcProviderService} — Souvera Mail does
 * not depend on `user_oidc` or any external IdP at runtime.
 */
class EngineHelper
{
    public function __construct(
        private IConfig $config,
        private IAppConfig $appConfig,
        private IUserConfig $userConfig,
        private ISession $session,
        private IUserSession $userSession,
        private IAppManager $appManager,
        private LoggerInterface $logger,
        private OidcProviderService $oidcProvider,
        private MailboxAccessGuard $mailboxGuard,
    ) {
    }

    public function loadApp(): void
    {
        if (\class_exists('Smail\\Engine\\Api')) {
            return;
        }

        // Smail namespace autoloader (case-sensitive PSR-4 style)
        \spl_autoload_register(function ($sClassName) {
            if (\str_starts_with($sClassName, 'Smail\\')) {
                $file = SMAIL_LIBRARIES_PATH . \strtr($sClassName, '\\', DIRECTORY_SEPARATOR) . '.php';
                if (\is_file($file)) {
                    include_once $file;
                }
            }
        });

        // Lowercase-filename autoloader for Smail\Engine
        \spl_autoload_register(function ($sClassName) {
            if (\str_starts_with($sClassName, 'Smail\\Engine\\')) {
                $file = SMAIL_LIBRARIES_PATH . 'Smail/Engine/'
                    . \strtolower(\strtr(\substr($sClassName, 13), '\\', DIRECTORY_SEPARATOR))
                    . '.php';
                if (\is_file($file)) {
                    include_once $file;
                    return;
                }
                $parts = \explode('\\', \substr($sClassName, 13));
                $fileName = \array_pop($parts);
                $dirPath = \implode(DIRECTORY_SEPARATOR, \array_map('strtolower', $parts));
                $file = SMAIL_LIBRARIES_PATH . 'Smail/Engine/'
                    . ($dirPath ? $dirPath . DIRECTORY_SEPARATOR : '')
                    . $fileName . '.php';
                if (\is_file($file)) {
                    include_once $file;
                }
            }
        });

        $_ENV['SMAIL_INCLUDE_AS_API'] = true;

        if (!\defined('APP_DATA_FOLDER_PATH')) {
            $dataDir = \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/');
            \define('APP_DATA_FOLDER_PATH', $dataDir . '/appdata_souvera_mail/');
        }

        $app_dir = \dirname(\dirname(__DIR__)) . '/app';
        $index = $app_dir . '/index.php';
        if (!\is_readable($index)) {
            $this->logger->warning('Souvera Mail: app/index.php not readable, skipping engine bootstrap');
            return;
        }
        require_once $index;

        // v0.15.0: sync the external-accounts feature flag from
        // souvera_central into the engine's runtime config. This is
        // the master-switch phase — the group-restriction check
        // (per-user) runs later in startApp() once we know the UID.
        //
        // Setting the config value here rather than at install time
        // means Central can toggle the feature without any Nextcloud
        // maintenance cycle: the change takes effect on the next
        // request.
        try {
            $externalCfg = \OCP\Server::get(\OCA\SouveraMail\Service\ExternalAccountsConfig::class);
            $oConfig = \Smail\Engine\Api::Config();
            $oConfig->Set('webmail', 'allow_additional_accounts', $externalCfg->isEnabled());
        } catch (\Throwable $e) {
            $this->logger->debug(
                'Souvera Mail: external-accounts flag sync skipped: ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
        }
    }

    public function startApp(bool $handle = false): void
    {
        $this->loadApp();

        if (false !== \stripos(\php_sapi_name(), 'cli')) {
            return;
        }

        try {
            $oActions = \Smail\Engine\Api::Actions();
            $aCredentials = $this->getLoginCredentials();
            $ncUid = $aCredentials[0];
            $expectedEmail = $aCredentials[1];

            // ────────────────────────────────────────────────
            // SECURITY GUARD (v0.14.5 / hardened in v0.14.6):
            // Runs on EVERY request that has an NC user + expected
            // email, regardless of whether Snappymail already has a
            // cached MainAccount. Previously the guard was gated
            // behind `$doLogin=true`, so once Snappymail's engine had
            // built a MainAccount (from NC session in
            // Actions/UserAuth::accountFromNcSession) it would be
            // served without any Stalwart-side ownership check —
            // leading to the SEG Marburg live incident where Jörg's
            // JWT was successfully OAUTHBEARER'd against Stalwart but
            // Stalwart's principal lookup returned hello@'s mailbox
            // (alias/auth-with-alias path). Now we ALWAYS ask Stalwart
            // "does this JWT map to the same mailbox we expect?" and
            // hard-deny on any mismatch.
            //
            // Guard is best-effort but fail-closed: throws
            // MailboxAccessDenied on Stalwart-unreachable, 401, 403,
            // ambiguous session body, or username mismatch.
            // ────────────────────────────────────────────────
            if ($ncUid !== '' && $expectedEmail !== '') {
                try {
                    $this->mailboxGuard->assertMailboxOwnership($ncUid);
                } catch (MailboxAccessDenied $e) {
                    $this->logger->critical(
                        'Souvera Mail: mailbox access denied — ' . $e->getMessage(),
                        ['app' => 'souvera_mail']
                    );
                    // Purge Snappymail's own auth cookies so a hard
                    // reload can't just replay the (still-valid) NC
                    // session into a stale MainAccount. Best-effort —
                    // we already have a deny path below regardless.
                    try {
                        $oActions->Logout(true);
                    } catch (\Throwable $ignored) {
                    }

                    if ($handle) {
                        \header_remove('Content-Security-Policy');
                        \header('Content-Type: text/plain; charset=utf-8', true, 403);
                        echo "Souvera Mail: " . $e->getMessage() . "\n";
                        exit;
                    }
                    return;
                }
            }

            $doLogin = !$oActions->getMainAccountFromToken(false);
            if ($doLogin && $aCredentials[1] && $aCredentials[2]) {
                try {
                    $oActions->LoginProcess(
                        $aCredentials[1],
                        new \Smail\Engine\SensitiveString($aCredentials[2])
                    );
                } catch (\Smail\Engine\Exceptions\ClientException $e) {
                    $this->logger->debug('Souvera Mail SSO login failed: ' . $e->getMessage());
                } catch (\Throwable $e) {
                    $this->logger->warning('Souvera Mail engine login error: ' . $e->getMessage());
                }
            }

            // v0.15.0: apply the group-restriction override for
            // external accounts. `loadApp()` already set the master
            // switch from Central; here we narrow it further to the
            // specific NC user. When Central says the feature is on
            // globally but the current user is NOT a member of any
            // allowed group, we downgrade the engine's capa to false
            // for THIS request only.
            try {
                $externalCfg = \OCP\Server::get(\OCA\SouveraMail\Service\ExternalAccountsConfig::class);
                if ($ncUid !== '' && $externalCfg->isEnabled()
                    && !$externalCfg->isAllowedForUser($ncUid)) {
                    \Smail\Engine\Api::Config()
                        ->Set('webmail', 'allow_additional_accounts', false);
                }
            } catch (\Throwable $e) {
                $this->logger->debug(
                    'Souvera Mail: external-accounts group check skipped: ' . $e->getMessage(),
                    ['app' => 'souvera_mail']
                );
            }

            if ($handle) {
                \header_remove('Content-Security-Policy');
                \Smail\Engine\Service::Handle();
                exit;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Souvera Mail engine bootstrap error: ' . $e->getMessage());
        }
    }

    /**
     * Whether the engine currently has an authenticated main account.
     * Call after startApp() — the result is cached by the engine.
     */
    public function hasAuthenticatedAccount(): bool
    {
        if (!\class_exists('Smail\\Engine\\Api')) {
            return false;
        }
        try {
            return \Smail\Engine\Api::Actions()->getMainAccountFromToken(false) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Returns the SSO uid stored in the NC session (set by LoginBridgeListener),
     * or null if no NC user is currently logged in.
     */
    public function getSsoUid(): ?string
    {
        $uid = $this->session->get('souvera_mail-uid');
        if (\is_string($uid) && $uid !== '') {
            return $uid;
        }
        // Fall back to the live user session — covers callers that run before
        // LoginBridgeListener has had a chance to set the session marker.
        $user = $this->userSession->getUser();
        return $user !== null ? $user->getUID() : null;
    }

    /**
     * Returns the current Nextcloud session id, or null if unavailable.
     *
     * Stable per session (changes only on NC session regeneration). The engine
     * derives its per-session connection/CSRF token secret from this. getId()
     * throws when no session is active (e.g. CLI), so we guard and return null.
     */
    public function getNcSessionId(): ?string
    {
        try {
            $id = $this->session->getId();
        } catch (\Throwable $e) {
            return null;
        }
        return $id !== '' ? $id : null;
    }

    /**
     * Returns the email for the current SSO user. Resolution order:
     *   1. Per-user override: IUserConfig souvera_mail/email
     *   2. NC profile email: IUserConfig settings/email
     *   3. IUser::getEMailAddress()
     *   4. uid itself (last-resort guarantee of a non-empty return)
     * Returns null when no NC user is logged in.
     */
    public function getSsoEmail(): ?string
    {
        $uid = $this->getSsoUid();
        if ($uid === null) {
            return null;
        }

        // Cascade — first non-empty wins. Diagnostics:
        // `occ souvera_mail:whoami <uid>` dumps this same cascade so an
        // operator can eyeball which source Snappymail actually consumed.
        $custom = $this->userConfig->getValueString($uid, 'souvera_mail', 'email', '');
        if ($custom !== '') {
            return $this->guardEmailAgainstUid($uid, $custom, 'userconfig[souvera_mail/email]');
        }

        $email = $this->userConfig->getValueString($uid, 'settings', 'email', '');
        if ($email !== '') {
            return $this->guardEmailAgainstUid($uid, $email, 'userconfig[settings/email]');
        }

        $user = $this->userSession->getUser();
        if ($user !== null && $user->getUID() === $uid) {
            $email = $user->getEMailAddress();
            if ($email !== '' && $email !== null) {
                return $this->guardEmailAgainstUid($uid, $email, 'IUser::getEMailAddress()');
            }
        }

        return $uid;
    }

    /**
     * Warn (loud) if the resolved email looks like it might belong to a
     * DIFFERENT user than the current uid — early warning for the
     * "joerg logs in and sees hello's mailbox" class of provisioning
     * bugs where an upstream tool (e.g. Souvera Central) writes the
     * wrong `settings/email` value.
     *
     * We are deliberately conservative: only log, never rewrite or
     * block. A false positive (e.g. shared alias like `info@`) is far
     * cheaper than a silent data leak.
     */
    private function guardEmailAgainstUid(string $uid, string $email, string $source): string
    {
        // Uid contains '@' AND the resolved email differs from the uid
        // → straight-up mismatch. `uid=joerg@x` but `email=hello@x`
        // fires here.
        if (\str_contains($uid, '@') && \strcasecmp($uid, $email) !== 0) {
            $this->logger->warning(
                'Souvera Mail: email/uid mismatch for uid="' . $uid . '" — Snappymail will use "' . $email
                . '" (from ' . $source . '). Verify Central provisioning; use '
                . '`occ souvera_mail:whoami ' . $uid . '` for a full trace.',
                ['app' => 'souvera_mail']
            );
        }

        // uid is a short identifier AND email localpart differs → still
        // suspicious. `uid=joerg` but `email=hello@x` fires here.
        if (\str_contains($uid, '@') === false
                && \str_contains($email, '@')
                && \strcasecmp(\explode('@', $email, 2)[0], $uid) !== 0) {
            $this->logger->info(
                'Souvera Mail: uid="' . $uid . '" resolves to email="' . $email
                . '" (from ' . $source . '). Localparts differ; verify this is intentional. '
                . 'Run `occ souvera_mail:whoami ' . $uid . '` if unexpected.',
                ['app' => 'souvera_mail']
            );
        }

        return $email;
    }

    /**
     * True when the souvera_mail OIDC autologin is wired up — i.e. the H2CK/oidc app
     * is available and a Nextcloud user is currently logged in. Browser-only
     * (CLI invocations return false: no live NC user).
     */
    public function isOIDCLogin(): bool
    {
        if (!$this->isOIDCEnabledServerSide()) {
            return false;
        }
        if ($this->userSession->getUser() === null) {
            return false;
        }
        return true;
    }

    /**
     * Session-free variant of {@see isOIDCLogin()} — true when the
     * server-side prerequisites for OIDC tokens are present, regardless
     * of whether an interactive NC user is currently logged in.
     *
     * Used by IMAP/SMTP/Sieve subrequest paths (engine plugin's
     * `beforeLogin` hook) where the connect is driven by an account
     * record (e.g. cached engine-token re-connect, cron, dashboard
     * widget background refresh, Sieve-from-CLI) and the NC session
     * may not be active even though the user is conceptually "logged
     * in" — `OidcProviderService::generateAccessToken($uid)` works
     * just fine without a session because it dispatches an in-process
     * PHP event scoped to (souvera_mail-client, $uid).
     */
    public function isOIDCEnabledServerSide(): bool
    {
        if ($this->appConfig->getValueString('souvera_mail', 'autologin-oidc', '0') !== '1') {
            return false;
        }
        if (!$this->oidcProvider->isProviderAvailable()) {
            \Smail\Engine\Log::debug('Nextcloud', 'H2CK/oidc provider not available');
            return false;
        }
        return true;
    }

    /**
     * Single source for the OIDC access token used for IMAP/SMTP OAUTHBEARER.
     * Dispatches H2CK/oidc's TokenGenerationRequestEvent for the current NC
     * user via {@see OidcProviderService}. Returns null when no NC user is
     * logged in or H2CK/oidc is unavailable.
     *
     * Pass `$audienceOverride` to log a deploy-time audience expectation; the
     * H2CK/oidc client itself controls the actual `aud` claim, so this
     * argument is informational only (kept for engine-plugin compatibility).
     */
    public function getOidcAccessToken(?string $audienceOverride = null, ?string $scopesOverride = null): ?string
    {
        // $audienceOverride / $scopesOverride are accepted for backward-compat
        // with engine plugins; H2CK issues tokens for the client we register.
        unset($audienceOverride, $scopesOverride);

        $uid = $this->getSsoUid();
        if ($uid === null) {
            return null;
        }
        return $this->oidcProvider->generateAccessToken($uid);
    }

    /**
     * Session-free variant of {@see getOidcAccessToken()}: generate an
     * OIDC access token for an explicit user id, without consulting the
     * NC session.
     *
     * Required for the engine plugin's `beforeLogin` hook on the IMAP /
     * SMTP / Sieve subrequest paths: the engine fires that hook on
     * every connect, including background refreshes where the NC
     * session is *not* the source of identity — the identity is the
     * MainAccount's stored sentinel `oidc_login|<uid>` (set by
     * `accountFromNcSession()` at first login and persisted in the
     * engine's encrypted account store). Without this method we used
     * to fall back to `getSsoUid()` which returned `null` outside the
     * session, the literal sentinel was then sent to Stalwart as a
     * password, and IMAP rejected the connect with `AUTHENTICATIONFAILED`.
     *
     * Behaviour contract:
     *  - Returns `null` (no exception) when `$uid` is empty, when the
     *    server-side prerequisites are not met (autologin-oidc disabled
     *    or H2CK/oidc missing), or when H2CK refuses to mint the token.
     *  - Caching is owned by {@see OidcProviderService} — re-calls
     *    within the JWT's `exp - 60s` window hit the distributed cache.
     */
    public function getOidcAccessTokenForUid(string $uid): ?string
    {
        if ($uid === '') {
            return null;
        }
        if (!$this->isOIDCEnabledServerSide()) {
            return null;
        }
        return $this->oidcProvider->generateAccessToken($uid);
    }

    /** @return array{string, string, string} */
    private function getLoginCredentials(): array
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return ['', '', ''];
        }
        $sUID = $user->getUID();
        if ($this->isOIDCLogin()) {
            $sEmail = $this->userConfig->getValueString($sUID, 'settings', 'email');
            return [$sUID, $sEmail, "oidc_login|{$sUID}"];
        }
        return [$sUID, '', ''];
    }
}
