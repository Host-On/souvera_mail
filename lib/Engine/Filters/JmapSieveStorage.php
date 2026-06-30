<?php

/**
 * JMAP-backed Sieve storage provider that the Nextcloud plugin injects via
 * `main.fabrica('filters')`. Implements the engine's
 * {@see \Smail\Engine\Providers\Filters\FiltersInterface} so the engine's
 * existing Filters Actions trait (`DoFilters`, `DoFiltersScriptSave`,
 * `DoFiltersScriptActivate`, `DoFiltersScriptDelete`) lights up against it
 * with zero engine-side changes.
 *
 * Why bypass ManageSieve?
 * -----------------------
 * The default `Smail\Engine\Providers\Filters\SieveStorage` dials Stalwart
 * over port 4190 (ManageSieve) with SASL OAUTHBEARER. The operator's setup
 * (PRD step 23) consistently fails that dial-out with engine notification
 * 352 — without surfacing the underlying TLS / listener / SASL error. The
 * JMAP path uses the SAME H2CK/oidc JWT we already proved out for
 * AppPasswords / Quota / Identity sync; same transport, same authn, same
 * audit trail. No extra Stalwart listener config required.
 *
 * Identity bridging
 * -----------------
 * Stalwart's JMAP scripts are scoped to the AUTHENTICATED account. The user
 * id we hand to `SieveScriptService` comes from the NC session (set during
 * webmail login). The Account object passed in by the engine is only used
 * for logging — we never trust its email address as a credential because
 * Stalwart already enforces "script visibility = your own account".
 */

declare(strict_types=1);

namespace OCA\SouveraMail\Engine\Filters;

use OCA\SouveraMail\Service\SieveScriptService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Smail\Engine\Model\Account;
use Smail\Engine\Notifications;
use Smail\Engine\Providers\Filters\FiltersInterface;

final class JmapSieveStorage implements FiltersInterface
{
    public function __construct(
        private SieveScriptService $sieve,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve the Nextcloud user id we will act as. Stalwart binds scripts
     * to the authenticated principal, so we cannot fall back to `Account->Email()`
     * here — the engine's Account model often carries a shared-mailbox email
     * that is NOT the user's own account.
     */
    private function resolveUid(Account $account): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \RuntimeException(
                'JmapSieveStorage requires an authenticated Nextcloud session'
            );
        }
        return $user->getUID();
    }

    public function Load(Account $oAccount): array
    {
        try {
            $payload = $this->sieve->listScriptsWithBodies($this->resolveUid($oAccount));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: JmapSieveStorage::Load failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            // Bubble as the engine's CantGetFilters notification so the UI
            // surfaces a clean error toast instead of an unhandled PHP exception.
            throw new \Smail\Engine\Exceptions\ClientException(
                Notifications::CantGetFilters->value,
                $e
            );
        }

        $scripts = [];
        foreach ($payload['scripts'] as $entry) {
            $scripts[$entry['name']] = [
                '@Object' => 'Object/SieveScript',
                'name' => $entry['name'],
                'active' => $entry['isActive'],
                'body' => $entry['body'],
            ];
        }
        \ksort($scripts);

        // Engine convention: include an empty default script entry when
        // the user has none yet so the UI can render the empty editor.
        // We use the engine's own SIEVE_FILE_NAME constant so this stays
        // in lock-step with any upstream rename.
        $defaultName = \Smail\Engine\Providers\Filters\SieveStorage::SIEVE_FILE_NAME;
        if (!isset($scripts[$defaultName])) {
            $scripts[$defaultName] = [
                '@Object' => 'Object/SieveScript',
                'name' => $defaultName,
                'active' => false,
                'body' => '',
            ];
            \ksort($scripts);
        }

        return [
            // Capabilities advertised by Stalwart's Sieve interpreter. We
            // declare the standard RFC-5228 + common extensions the JMAP
            // sieve interpreter ships with — Stalwart implements these as
            // of 0.16 (see crates/sieve/src/compiler/grammar/instruction.rs).
            // The engine's filter UI uses this list to gate which actions
            // (fileinto / reject / vacation / imap4flags / …) the user can
            // pick from the dropdown without producing an unsupported script.
            'Capa' => [
                'body',
                'copy',
                'date',
                'envelope',
                'fileinto',
                'imap4flags',
                'mailbox',
                'reject',
                'regex',
                'relational',
                'subaddress',
                'vacation',
                'variables',
            ],
            'Scripts' => $scripts,
        ];
    }

    public function Save(Account $oAccount, string $sScriptName, string $sRaw): bool
    {
        try {
            $this->sieve->saveScript($this->resolveUid($oAccount), $sScriptName, $sRaw);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: JmapSieveStorage::Save failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            throw new \Smail\Engine\Exceptions\ClientException(
                Notifications::CantSaveFilters->value,
                $e
            );
        }
        return true;
    }

    public function Activate(Account $oAccount, string $sScriptName): bool
    {
        try {
            $this->sieve->activateScript($this->resolveUid($oAccount), $sScriptName);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: JmapSieveStorage::Activate failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            throw new \Smail\Engine\Exceptions\ClientException(
                Notifications::CantActivateFiltersScript->value,
                $e
            );
        }
        return true;
    }

    public function Delete(Account $oAccount, string $sScriptName): bool
    {
        try {
            $this->sieve->deleteScript($this->resolveUid($oAccount), $sScriptName);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: JmapSieveStorage::Delete failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            throw new \Smail\Engine\Exceptions\ClientException(
                Notifications::CantDeleteFiltersScript->value,
                $e
            );
        }
        return true;
    }
}
