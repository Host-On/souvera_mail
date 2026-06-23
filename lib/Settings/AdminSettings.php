<?php

declare(strict_types=1);

namespace OCA\Smail\Settings;

use OCA\Smail\Service\DomainConfigService;
use OCA\Smail\Service\LogService;
use OCA\Smail\Service\OidcProviderService;
use OCA\Smail\Util\EngineHelper;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Read-only admin settings page. Mirrors `occ smail:status` and renders the
 * same data through `templates/admin-local.php`. No write actions live here:
 * every interactive element in the rendered template is informational only,
 * and the configuration is changed exclusively through `occ` commands.
 */
class AdminSettings implements ISettings
{
    private const APP_ID = 'smail';

    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private OidcProviderService $oidcProvider,
        private DomainConfigService $domainService,
        private EngineHelper $engineHelper,
        private LogService $logService,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getForm()
    {
        return new TemplateResponse(self::APP_ID, 'admin-local', [
            'status' => $this->buildStatus(),
        ]);
    }

    public function getSection()
    {
        return self::APP_ID;
    }

    public function getPriority()
    {
        return 50;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatus(): array
    {
        $issues = [];
        $oidc = $this->oidcReport($issues);
        $domain = $this->domainReport($issues);
        $engine = $this->engineReport();

        return [
            'app' => [
                'version' => $this->appManager->getAppVersion(self::APP_ID),
            ],
            'oidc_provider' => $oidc,
            'domain' => $domain,
            'engine' => $engine,
            'debug_log' => [
                'enabled' => $this->logService->isEnabled(),
                'file' => $this->domainService->getDataPath() . '/smail.log',
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @param list<string> $issues
     * @return array<string, mixed>
     */
    private function oidcReport(array &$issues): array
    {
        $installed = $this->appManager->isInstalled(OidcProviderService::OIDC_APP_ID);
        $enabled = $this->appManager->isEnabledForUser(OidcProviderService::OIDC_APP_ID);
        $available = $this->oidcProvider->isProviderAvailable();
        $clientName = $this->oidcProvider->getClientIdentifier();
        $clientRegistered = $this->appConfig->getValueString(self::APP_ID, OidcProviderService::SMAIL_CLIENT_KEY, '') !== '';
        $defaultTokenType = $this->appConfig->getValueString('oidc', 'default_token_type', 'opaque');

        if (!$installed) {
            $issues[] = 'H2CK/oidc app is not installed (run: occ app:install oidc)';
        } elseif (!$enabled) {
            $issues[] = 'H2CK/oidc app is installed but disabled (run: occ app:enable oidc)';
        } elseif (!$available) {
            $issues[] = 'H2CK/oidc version mismatch — TokenGenerationRequestEvent unavailable (need 1.17+)';
        } elseif (!$clientRegistered) {
            $issues[] = 'Souvera Mail OIDC client is not registered (run: occ smail:oidc:register-client)';
        }
        if ($defaultTokenType !== 'jwt') {
            $issues[] = "H2CK/oidc default_token_type is '{$defaultTokenType}' — set to 'jwt' for RFC 9068";
        }

        return [
            'h2ck_oidc_installed' => $installed,
            'h2ck_oidc_enabled' => $enabled,
            'event_class_loadable' => $available,
            'client_name' => $clientName,
            'client_registered' => $clientRegistered,
            'default_token_type' => $defaultTokenType,
            'discovery_url' => $this->urlGenerator->getAbsoluteURL('/index.php/apps/oidc/openid-configuration'),
            'jwks_url' => $this->urlGenerator->getAbsoluteURL('/index.php/apps/oidc/jwks'),
        ];
    }

    /**
     * @param list<string> $issues
     * @return array<string, mixed>
     */
    private function domainReport(array &$issues): array
    {
        $domains = $this->domainService->listDomains();
        if ($domains === []) {
            $issues[] = 'No mail domain configured (run: occ smail:setup --imap-host … --domain …)';
            return ['configured' => []];
        }
        $entries = [];
        foreach ($domains as $name) {
            $cfg = $this->domainService->readDomainConfig($name);
            if (!$cfg) {
                $entries[$name] = ['error' => 'unreadable'];
                continue;
            }
            $imapType = $cfg['IMAP']['type'] ?? 0;
            $smtpType = $cfg['SMTP']['type'] ?? 0;
            $entries[$name] = [
                'imap' => [
                    'host' => $cfg['IMAP']['host'] ?? '',
                    'port' => $cfg['IMAP']['port'] ?? 0,
                    'ssl' => \is_int($imapType) ? DomainConfigService::sslToString($imapType) : 'custom',
                ],
                'smtp' => [
                    'host' => $cfg['SMTP']['host'] ?? '',
                    'port' => $cfg['SMTP']['port'] ?? 0,
                    'ssl' => \is_int($smtpType) ? DomainConfigService::sslToString($smtpType) : 'custom',
                ],
                'sieve_enabled' => (bool) ($cfg['Sieve']['enabled'] ?? false),
            ];
        }
        return [
            'configured' => $entries,
            'oidc_audience' => $this->appConfig->getValueString(self::APP_ID, 'oidc-exchange-audience', ''),
            'oidc_scopes' => $this->appConfig->getValueString(self::APP_ID, 'oidc-exchange-scopes', ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function engineReport(): array
    {
        try {
            $this->engineHelper->loadApp();
            $cfg = \Smail\Engine\Api::Config();
            return [
                'version' => \defined('APP_VERSION') ? APP_VERSION : null,
                'app_path' => $cfg->Get('webmail', 'app_path', ''),
                'webmail_title' => $cfg->Get('webmail', 'title', ''),
                'theme' => $cfg->Get('webmail', 'theme', ''),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
