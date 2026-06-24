<?php

declare(strict_types=1);

namespace OCA\Smail\Util;

use OCA\Smail\Service\OidcProviderService;
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
            \define('APP_DATA_FOLDER_PATH', $dataDir . '/appdata_smail/');
        }

        $app_dir = \dirname(\dirname(__DIR__)) . '/app';
        $index = $app_dir . '/index.php';
        if (!\is_readable($index)) {
            $this->logger->warning('Souvera Mail: app/index.php not readable, skipping engine bootstrap');
            return;
        }
        require_once $index;
    }

    public function startApp(bool $handle = false): void
    {
        $this->loadApp();

        if (false !== \stripos(\php_sapi_name(), 'cli')) {
            return;
        }

        try {
            $oActions = \Smail\Engine\Api::Actions();
            $doLogin = !$oActions->getMainAccountFromToken(false);
            $aCredentials = $this->getLoginCredentials();
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
        $uid = $this->session->get('smail-uid');
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
     *   1. Per-user override: IUserConfig smail/email
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

        $custom = $this->userConfig->getValueString($uid, 'smail', 'email', '');
        if ($custom !== '') {
            return $custom;
        }

        $email = $this->userConfig->getValueString($uid, 'settings', 'email', '');
        if ($email !== '') {
            return $email;
        }

        $user = $this->userSession->getUser();
        if ($user !== null && $user->getUID() === $uid) {
            $email = $user->getEMailAddress();
            if ($email !== '' && $email !== null) {
                return $email;
            }
        }

        return $uid;
    }

    /**
     * True when the smail OIDC autologin is wired up — i.e. the H2CK/oidc app
     * is available and a Nextcloud user is currently logged in. Browser-only
     * (CLI invocations return false: no live NC user).
     */
    public function isOIDCLogin(): bool
    {
        if ($this->appConfig->getValueString('smail', 'autologin-oidc', '0') !== '1') {
            return false;
        }
        if ($this->userSession->getUser() === null) {
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
