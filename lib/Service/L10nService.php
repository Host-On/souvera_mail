<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Resolves the v2 UI translation catalog for a Nextcloud language code.
 *
 * Nextcloud ships several German variants as distinct language codes
 * ("de" and "de_DE" each have their own l10n/<code>.json), so the lookup
 * tries the full locale first, then the short form, then the legacy
 * bundled catalog. Shared by the inline template injection and the
 * runtime API endpoint — one source of truth for the client.
 */
class L10nService
{
    public function __construct(
        private IAppManager $appManager,
        private IUserSession $userSession,
        private IConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The language for the v2 UI. The user's PERSONAL language setting
     * (IUser::getLanguage — e.g. "de" for "Deutsch (Persönlich: Du)")
     * is authoritative. IL10N::getLanguageCode() is NOT used here: the
     * DI container may have cached an IL10N instance created with a
     * different language earlier in the request, which would silently
     * resolve to English despite the user's personal setting.
     */
    public function resolveLanguage(): string
    {
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $lang = \trim((string) $user->getLanguage());
            if ($lang !== '') {
                return $lang;
            }
        }
        $default = \trim((string) $this->config->getSystemValueString('default_language', ''));
        return $default !== '' ? $default : 'en';
    }

    /**
     * @return array<string, string> translations map (empty when none found)
     */
    public function getCatalog(string $lang): array
    {
        $lang = \trim($lang);
        $appPath = $this->appManager->getAppPath('souvera_mail');
        if ($appPath === null) {
            return [];
        }

        $langShort = \substr($lang, 0, 2);
        foreach (\array_unique([$lang, $langShort]) as $candidate) {
            if ($candidate === '') continue;
            $catalog = $this->readCatalogFile($appPath . '/l10n/' . $candidate . '.json');
            if ($catalog !== null) {
                return $catalog;
            }
        }

        // Legacy fallback: js/l10n-<lang>.json (older bundled catalog).
        $legacy = $this->readCatalogFile($appPath . '/js/l10n-' . $langShort . '.json');
        if ($legacy !== null) {
            return $legacy;
        }

        if ($langShort !== 'en') {
            $this->logger->warning(
                'Souvera Mail: no translation catalog found for language "' . $lang
                . '" (looked in l10n/' . $lang . '.json, l10n/' . $langShort
                . '.json, js/l10n-' . $langShort . '.json)',
                ['app' => 'souvera_mail']
            );
        }
        return [];
    }

    /**
     * @return array<string, string>|null null when the file is missing or invalid
     */
    private function readCatalogFile(string $path): ?array
    {
        if (!\is_file($path)) {
            return null;
        }
        $raw = \file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $parsed = \json_decode($raw, true);
        if (!\is_array($parsed) || !isset($parsed['translations'])
            || !\is_array($parsed['translations']) || \count($parsed['translations']) === 0) {
            return null;
        }
        /** @var array<string, string> */
        return $parsed['translations'];
    }
}
