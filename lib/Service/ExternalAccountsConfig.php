<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Consumer for external-mail-account settings owned by
 * `souvera_central`.
 *
 * We NEVER hold the state locally — every read is proxied to
 * `\OCA\SouveraCentral\Service\ExternalAccountsConfigService` via
 * `\OCP\Server::get()` so that the service resolves lazily even on
 * installs where `souvera_central` has not (yet) been enabled.
 *
 * Behaviour when Central is unreachable:
 *  - `isEnabled()` returns false → the feature is off.
 *  - Every getter returns its documented "safe default" (empty group
 *    list, cap = 3, consent required, guard on).
 *  - `snapshot()` reports the degraded state so operators see WHY the
 *    feature isn't on.
 *
 * The full read contract (methods, semantics, defaults) is documented
 * in {@see /app/docs/SHARED_EXTERNAL_ACCOUNTS.md}.
 */
final class ExternalAccountsConfig
{
    /** FQN kept as a string so this class stays loadable even without
     *  souvera_central. Update if Central's team renames the service. */
    public const CENTRAL_SERVICE_FQN =
        'OCA\\SouveraCentral\\Service\\ExternalAccountsConfigService';

    /** Safe defaults used when Central is unreachable. Mirrors the
     *  defaults documented in the shared contract v1.0. */
    private const DEFAULTS = [
        'enabled'            => false,
        'allowed_groups'     => [],
        'max_per_user'       => 3,
        'migration_handoff'  => false,   // conservative: off when Central missing
        'smtp_fail_guard'    => true,
        'consent_required'   => true,
    ];

    public function __construct(
        private LoggerInterface $logger,
        private IGroupManager $groupManager,
    ) {
    }

    /** @return object|null  The Central service instance, or null. */
    private function central(): ?object
    {
        if (!\class_exists(self::CENTRAL_SERVICE_FQN, true)) {
            return null;
        }
        try {
            $svc = Server::get(self::CENTRAL_SERVICE_FQN);
        } catch (\Throwable $e) {
            $this->logger->debug(
                'ExternalAccountsConfig: Central service not resolvable: ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            return null;
        }
        return \is_object($svc) ? $svc : null;
    }

    /**
     * Master switch. Returns false whenever Central is missing so the
     * feature stays invisible on standalone installs.
     */
    public function isEnabled(): bool
    {
        $c = $this->central();
        if ($c === null || !\method_exists($c, 'isEnabled')) {
            return (bool) self::DEFAULTS['enabled'];
        }
        try {
            return (bool) $c->isEnabled();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ExternalAccountsConfig::isEnabled failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            return (bool) self::DEFAULTS['enabled'];
        }
    }

    /** @return list<string> */
    public function getAllowedGroups(): array
    {
        $c = $this->central();
        if ($c === null || !\method_exists($c, 'getAllowedGroups')) {
            return self::DEFAULTS['allowed_groups'];
        }
        try {
            $groups = $c->getAllowedGroups();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ExternalAccountsConfig::getAllowedGroups failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            return self::DEFAULTS['allowed_groups'];
        }
        if (!\is_array($groups)) {
            return self::DEFAULTS['allowed_groups'];
        }
        // Sanitize: string-only, non-empty, dedupe.
        $out = [];
        foreach ($groups as $g) {
            if (\is_string($g) && $g !== '') {
                $out[$g] = true;
            }
        }
        return \array_keys($out);
    }

    public function getMaxAccountsPerUser(): int
    {
        $c = $this->central();
        if ($c === null || !\method_exists($c, 'getMaxAccountsPerUser')) {
            return (int) self::DEFAULTS['max_per_user'];
        }
        try {
            $max = (int) $c->getMaxAccountsPerUser();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ExternalAccountsConfig::getMaxAccountsPerUser failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            return (int) self::DEFAULTS['max_per_user'];
        }
        // Clamp to sane bounds (1…20) — Central is allowed to say 3;
        // we defensively refuse pathological values.
        return \max(1, \min(20, $max));
    }

    public function isMigrationHandoffEnabled(): bool
    {
        return $this->boolFromCentral('isMigrationHandoffEnabled',
            (bool) self::DEFAULTS['migration_handoff']);
    }

    public function isSmtpFailGuardEnabled(): bool
    {
        return $this->boolFromCentral('isSmtpFailGuardEnabled',
            (bool) self::DEFAULTS['smtp_fail_guard']);
    }

    public function isConsentRequired(): bool
    {
        return $this->boolFromCentral('isConsentRequired',
            (bool) self::DEFAULTS['consent_required']);
    }

    /**
     * Combined per-user check: master switch on AND (group list empty
     * OR user is member of at least one allowed group). Group
     * membership is resolved locally via IGroupManager so a stale
     * Central-side cache doesn't matter.
     */
    public function isAllowedForUser(string $uid): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        // If Central exposes the check we defer to it (single source of truth).
        $c = $this->central();
        if ($c !== null && \method_exists($c, 'isAllowedForUser')) {
            try {
                return (bool) $c->isAllowedForUser($uid);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'ExternalAccountsConfig::isAllowedForUser central failed: ' . $e->getMessage(),
                    ['app' => 'souvera_mail']
                );
                // Fall through to local resolution.
            }
        }

        $allowed = $this->getAllowedGroups();
        if (empty($allowed)) {
            return true;
        }
        $user = $this->groupManager->getUserGroupIds(
            $this->getUser($uid)
        );
        foreach ($allowed as $g) {
            if (\in_array($g, $user, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $c = $this->central();
        $centralPresent = $c !== null;
        $centralVersion = '';

        // Prefer Central's own snapshot if it's a first-class method
        // (versioned + future-proof against new keys).
        if ($centralPresent && \method_exists($c, 'snapshot')) {
            try {
                $snap = $c->snapshot();
                if (\is_array($snap)) {
                    $snap['_source'] = 'souvera_central';
                    return $snap;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'ExternalAccountsConfig::snapshot central failed: ' . $e->getMessage(),
                    ['app' => 'souvera_mail']
                );
            }
        }

        return [
            'enabled'            => $this->isEnabled(),
            'allowed_groups'     => $this->getAllowedGroups(),
            'max_per_user'       => $this->getMaxAccountsPerUser(),
            'migration_handoff'  => $this->isMigrationHandoffEnabled(),
            'smtp_fail_guard'    => $this->isSmtpFailGuardEnabled(),
            'consent_required'   => $this->isConsentRequired(),
            'central_present'    => $centralPresent,
            '_source'            => $centralPresent ? 'souvera_central' : 'defaults',
        ];
    }

    private function boolFromCentral(string $method, bool $default): bool
    {
        $c = $this->central();
        if ($c === null || !\method_exists($c, $method)) {
            return $default;
        }
        try {
            return (bool) $c->{$method}();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'ExternalAccountsConfig::' . $method . ' failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            return $default;
        }
    }

    private function getUser(string $uid): ?IUser
    {
        try {
            $userManager = Server::get(\OCP\IUserManager::class);
            return $userManager->get($uid);
        } catch (\Throwable) {
            return null;
        }
    }
}
