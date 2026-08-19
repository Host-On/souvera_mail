<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Bookkeeping for the "3 consecutive SMTP failures within 24h →
 * auto-deactivate that external account" guard.
 *
 * Design decision: we do NOT create a dedicated migration/DB table
 * for a soft-state counter that resets constantly. Instead we piggy-
 * back on the existing `oc_appconfig` table via the `IAppConfig`
 * bulk-key namespace `souvera_mail:ext_smtp_fail.{uid}.{email_hash}`
 * → JSON payload with `count`, `first_ts`, `last_ts`, `deactivated_at`.
 *
 * This keeps the schema footprint of Souvera Mail at zero new tables
 * for this feature (per architectural rule: prefer engine JSON files
 * or app_config over new DB tables when the row count is small).
 *
 * Governance:
 *  - Only accessible from within souvera_mail; never exposed via HTTP.
 *  - Counters older than 24h auto-reset on next read.
 *  - `deactivated_at` timestamps flag accounts for the OCC "revoke"
 *    command and the Vue "your account was disabled" toast.
 */
final class ExternalAccountsFailGuard
{
    private const KEY_PREFIX = 'ext_smtp_fail.';
    private const APP        = 'souvera_mail';
    private const WINDOW_S   = 86400;   // 24 h
    private const LIMIT      = 3;

    public function __construct(
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
        private ExternalAccountsConfig $config,
        private IDBConnection $db,
    ) {
    }

    /**
     * Record one send failure. Returns true if the failure crossed
     * the auto-deactivation threshold on this call (caller should then
     * do the actual disable).
     */
    public function recordFailure(string $uid, string $email): bool
    {
        if (!$this->config->isSmtpFailGuardEnabled()) {
            return false;
        }
        $key   = $this->keyFor($uid, $email);
        $state = $this->read($key);
        $now   = \time();

        // Reset the window if last failure is older than 24 h.
        if (($now - (int) ($state['last_ts'] ?? 0)) > self::WINDOW_S) {
            $state = ['count' => 0, 'first_ts' => $now, 'last_ts' => $now];
        }

        $state['count']  = (int) ($state['count'] ?? 0) + 1;
        $state['last_ts'] = $now;
        $state['first_ts'] = (int) ($state['first_ts'] ?? $now);

        $tripped = false;
        if ($state['count'] >= self::LIMIT && empty($state['deactivated_at'])) {
            $state['deactivated_at'] = $now;
            $tripped = true;
            $this->logger->warning(
                'ExternalAccountsFailGuard: auto-deactivated external account after '
                    . self::LIMIT . '× SMTP failure within 24h '
                    . '(uid=' . $uid . ', email_hash=' . $this->hash($email) . ')',
                ['app' => self::APP]
            );
        }

        $this->appConfig->setValueString(self::APP, $key, \json_encode($state));
        return $tripped;
    }

    /** Clear the counter after a successful send. */
    public function recordSuccess(string $uid, string $email): void
    {
        $key = $this->keyFor($uid, $email);
        if ($this->appConfig->hasKey(self::APP, $key)) {
            $this->appConfig->deleteKey(self::APP, $key);
        }
    }

    /**
     * Is this account currently in "auto-deactivated" state?
     *
     * @return array{deactivated: bool, count: int, first_ts: int, last_ts: int, deactivated_at: int}
     */
    public function status(string $uid, string $email): array
    {
        $state = $this->read($this->keyFor($uid, $email));
        $now = \time();
        // Auto-expire deactivation after 24 h so users can retry.
        if (!empty($state['deactivated_at'])
            && ($now - (int) $state['deactivated_at']) > self::WINDOW_S) {
            $this->recordSuccess($uid, $email);
            $state = [];
        }
        return [
            'deactivated'    => !empty($state['deactivated_at']),
            'count'          => (int) ($state['count'] ?? 0),
            'first_ts'       => (int) ($state['first_ts'] ?? 0),
            'last_ts'        => (int) ($state['last_ts'] ?? 0),
            'deactivated_at' => (int) ($state['deactivated_at'] ?? 0),
        ];
    }

    /**
     * List every user that currently has at least one deactivated
     * external account. Used by the OCC `external:list` command.
     *
     * @return list<array{uid: string, email_hash: string, count: int, deactivated_at: int}>
     */
    public function listDeactivated(): array
    {
        $out = [];
        // Directly query oc_appconfig to enumerate our keys — IAppConfig
        // lacks an efficient list-by-prefix method, and this is only
        // used by CLI diagnostics.
        $q = $this->db->getQueryBuilder();
        $q->select('configkey', 'configvalue')
            ->from('appconfig')
            ->where($q->expr()->eq('appid', $q->createNamedParameter(self::APP)))
            ->andWhere($q->expr()->like('configkey', $q->createNamedParameter(self::KEY_PREFIX . '%')));
        $res = $q->executeQuery();
        while ($row = $res->fetch()) {
            $decoded = \json_decode((string) $row['configvalue'], true);
            if (!\is_array($decoded) || empty($decoded['deactivated_at'])) {
                continue;
            }
            // Key layout: ext_smtp_fail.{uid}.{email_hash}
            $parts = \explode('.', (string) $row['configkey']);
            if (\count($parts) < 3) { continue; }
            $out[] = [
                'uid'            => $parts[1],
                'email_hash'     => $parts[2],
                'count'          => (int) ($decoded['count'] ?? 0),
                'deactivated_at' => (int) ($decoded['deactivated_at'] ?? 0),
            ];
        }
        $res->closeCursor();
        return $out;
    }

    /** Force-clear a single account's state (admin/user reset). */
    public function reset(string $uid, string $email): void
    {
        $this->recordSuccess($uid, $email);
    }

    private function keyFor(string $uid, string $email): string
    {
        return self::KEY_PREFIX . $uid . '.' . $this->hash($email);
    }

    private function hash(string $email): string
    {
        return \substr(\sha1(\strtolower(\trim($email))), 0, 12);
    }

    /** @return array<string,mixed> */
    private function read(string $key): array
    {
        $raw = $this->appConfig->getValueString(self::APP, $key, '');
        if ($raw === '') {
            return [];
        }
        $data = \json_decode($raw, true);
        return \is_array($data) ? $data : [];
    }
}
