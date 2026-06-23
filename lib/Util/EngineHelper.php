<?php

namespace OCA\Smail\Util;

use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

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
        private IEventDispatcher $eventDispatcher,
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
                    . \strtolower(\strtr(\substr($sClassName, 14), '\\', DIRECTORY_SEPARATOR))
                    . '.php';
                if (\is_file($file)) {
                    include_once $file;
                    return;
                }
                $parts = \explode('\\', \substr($sClassName, 14));
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

        $oConfig = \Smail\Engine\Api::Config();

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
                    // OIDC login failure — no credentials to clear
                    $this->logger->debug('Souvera Mail SSO login failed: ' . $e->getMessage());
                } catch (\Throwable $e) {
                    // Non-login errors — don't touch credentials
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
     * Call after startApp() — the result is cached by the engine, so this
     * reflects the outcome of the SSO auto-login attempt without side effects.
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
     * Returns the SSO uid stored in the NC session, or null if not set.
     */
    public function getSsoUid(): ?string
    {
        $uid = $this->session->get('smail-uid');
        return \is_string($uid) && $uid !== '' ? $uid : null;
    }

    /**
     * Returns the current Nextcloud session id, or null if unavailable.
     *
     * Stable per session (changes only on NC session regeneration), it is the
     * per-session secret the engine derives its connection/CSRF token from in
     * place of the former self-set x2mtoken cookie. getId() throws when no
     * session is active (e.g. CLI), so we guard and return null.
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
     * Returns the email for the current SSO user, identical to the value
     * FilterAppData seeds into AppData in the nextcloud engine plugin so the
     * NC-session reconstruction matches the live login path. Resolution order:
     *   1. custom smail email: IUserConfig smail/email (overrides everything)
     *   2. profile email: IUserConfig settings/email
     *   3. IUser::getEMailAddress() (NC account email)
     *   4. uid itself (last resort — guarantees a non-empty return)
     * Returns null when no SSO uid is present in the session.
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

    public function isOIDCLogin(): bool
    {
        if ($this->appConfig->getValueString('smail', 'autologin-oidc', '0') !== '0') {
            if ($this->appManager->isEnabledForUser('user_oidc')) {
                if ($this->session->get('is_oidc')) {
                    if ($this->session->get('oidc_access_token')) {
                        return true;
                    }
                    \Smail\Engine\Log::debug('Nextcloud', 'OIDC access_token missing');
                } else {
                    \Smail\Engine\Log::debug('Nextcloud', 'No OIDC login');
                }
            } else {
                \Smail\Engine\Log::debug('Nextcloud', 'OIDC login disabled');
            }
        }
        return false;
    }

    /**
     * Single source for the OIDC access token used for IMAP/SMTP OAUTHBEARER.
     * Order: token exchange (if an audience is configured) -> fresh login token
     * via user_oidc public event -> cached session value (last resort).
     *
     * Pass $audienceOverride / $scopesOverride (e.g. from the setup wizard
     * Test Login) to use the typed values instead of the stored ones; null
     * falls back to config.
     */
    public function getOidcAccessToken(?string $audienceOverride = null, ?string $scopesOverride = null): ?string
    {
        $audience = $audienceOverride
            ?? $this->appConfig->getValueString('smail', 'oidc-exchange-audience', '');
        if ($audience !== '') {
            $rawScopes = $scopesOverride
                ?? $this->appConfig->getValueString('smail', 'oidc-exchange-scopes', '');
            $scopes = \preg_split('/\s+/', \trim($rawScopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $exchanged = $this->dispatchTokenEvent(
                'OCA\\UserOIDC\\Event\\ExchangedTokenRequestedEvent',
                $audience,
                $scopes
            );
            if ($exchanged !== null) {
                return $exchanged;
            }
            $this->logger->warning(
                'OIDC token exchange for audience "' . $audience . '" yielded no token; '
                . 'falling back to the login token'
            );
        }

        $fresh = $this->dispatchTokenEvent('OCA\\UserOIDC\\Event\\ExternalTokenRequestedEvent', null);
        if ($fresh !== null) {
            return $fresh;
        }

        $sessionToken = $this->session->get('oidc_access_token');
        return \is_string($sessionToken) && $sessionToken !== '' ? $sessionToken : null;
    }

    /** @param list<string> $extraScopes */
    private function dispatchTokenEvent(string $eventClass, ?string $audienceArg, array $extraScopes = []): ?string
    {
        if (!\class_exists($eventClass)) {
            return null;
        }
        try {
            if ($audienceArg === null) {
                $event = new $eventClass();
            } elseif ($extraScopes === []) {
                $event = new $eventClass($audienceArg);
            } else {
                $event = new $eventClass($audienceArg, $extraScopes);
            }
            if (!$event instanceof Event) {
                return null;
            }
            $this->eventDispatcher->dispatchTyped($event);
            if (!\method_exists($event, 'getToken')) {
                return null;
            }
            $token = $event->getToken();
            if (!\is_object($token) || !\method_exists($token, 'getAccessToken')) {
                return null;
            }
            $access = $token->getAccessToken();
            if (!\is_string($access) || $access === '') {
                return null;
            }
            if (\method_exists($token, 'getExpiresInFromNow')) {
                // Visibility for the known "user_oidc reports expires_in=0" realm issue
                $this->logger->debug(
                    'OIDC token (' . $eventClass . ') expires in '
                    . (int)$token->getExpiresInFromNow() . 's'
                );
            }
            return $access;
        } catch (\Throwable $e) {
            $message = 'OIDC token event failed (' . $eventClass . '): ' . $e->getMessage();
            // user_oidc's GetExternalTokenFailedException / TokenExchangeFailedException
            // carry the IdP error response — surface it for diagnosis.
            if (\method_exists($e, 'getError') && \method_exists($e, 'getErrorDescription')) {
                $error = $e->getError();
                $description = $e->getErrorDescription();
                if (\is_string($error) || \is_string($description)) {
                    $message .= ' — IdP: ' . (\is_string($error) ? $error : '')
                        . ' (' . (\is_string($description) ? $description : '') . ')';
                }
            }
            $this->logger->warning($message);
            return null;
        }
    }

    /** @return array{string, string, string} */
    private function getLoginCredentials(): array
    {
        $sUID = $this->userSession->getUser()->getUID();
        if ($this->session->get('smail-uid') === $sUID && $this->isOIDCLogin()) {
            $sEmail = $this->userConfig->getValueString($sUID, 'settings', 'email');
            return [$sUID, $sEmail, "oidc_login|{$sUID}"];
        }
        return [$sUID, '', ''];
    }
}
