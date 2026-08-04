<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCA\SouveraMail\Db\MigrationJob;
use OCA\SouveraMail\Db\MigrationJobMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the "import my old mails" wizard end-to-end.
 *
 * Responsibilities
 * ----------------
 *   1. Pre-flight source credentials with provider.tools' test-connection
 *      / list-folders endpoints (fast UX, catches typos before the queue).
 *   2. Mint a Stalwart-only App Password labelled "Souvera Import <YYYY-MM-DD HH:mm>"
 *      via AppPasswordService — one row per migration, revoked at end.
 *   3. Compose the destination stanza (host from NC config, port 993, secure=true).
 *   4. Persist a MigrationJob row BEFORE the API call so a race /
 *      network flake can't leave a "phantom" job in provider.tools without
 *      a local audit trail.
 *   5. POST /imap/migrate; on success store the returned migrationId,
 *      on failure mark the row failed and revoke the app password.
 *   6. Provide a fast `getStatusForUser` (from cache) for the frontend
 *      polling loop, and a `refreshFromProvider` for the background job.
 *   7. Finalize: on transition to completed/failed, revoke the Stalwart
 *      app password and stamp finished_at.
 *
 * What we DO NOT do
 * -----------------
 *   - Store the source password anywhere — it flows straight through to
 *     provider.tools and is dropped from memory the moment the HTTP call
 *     returns.
 *   - Cancel jobs (provider.tools has no cancel endpoint — we document
 *     this in the wizard's "Migration starten"-confirm screen).
 */
class MigrationService
{
    /**
     * Rate limit — one active job per user. Anything above is a
     * conscious "restart-my-import" request the user can do only
     * AFTER the current one reaches a terminal state.
     */
    public const MAX_ACTIVE_PER_USER = 1;

    /**
     * NC-AppConfig keys used to derive the destination IMAP stanza.
     *
     * `imap_host` is optional — if empty, we fall back to the primary
     * `overwrite.cli.url` host and finally to the first trusted domain.
     * `imap_port` and `imap_secure` are hard defaults; operators can
     * override on unusual deployments via
     *   occ config:app:set souvera_mail stalwart_imap_host  --value ...
     *   occ config:app:set souvera_mail stalwart_imap_port  --value ...
     *   occ config:app:set souvera_mail stalwart_imap_secure --value 'true'
     */
    private const APPCONFIG_IMAP_HOST   = 'stalwart_imap_host';
    private const APPCONFIG_IMAP_PORT   = 'stalwart_imap_port';
    private const APPCONFIG_IMAP_SECURE = 'stalwart_imap_secure';

    private const DEFAULT_IMAP_PORT = 993;
    private const DEFAULT_IMAP_SECURE = true;

    /** Welcome-popup dismissal is per-user config, key `welcome_dismissed`. */
    public const USERCONFIG_WELCOME_DISMISSED = 'welcome_dismissed';

    public function __construct(
        private MigrationJobMapper $jobs,
        private ProviderToolsClient $providerTools,
        private AppPasswordService $appPasswords,
        private IConfig $config,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->providerTools->isAvailable()
            && $this->appPasswords->isAvailable();
    }

    /**
     * @return array{welcomeDismissed: bool, activeJob: ?array<string, mixed>, lastJob: ?array<string, mixed>, available: bool}
     */
    public function getWelcomeStateForUser(string $userId): array
    {
        $dismissed = $this->config->getUserValue(
            $userId,
            'souvera_mail',
            self::USERCONFIG_WELCOME_DISMISSED,
            '0'
        ) === '1';

        $active = null;
        $last = null;
        try {
            $active = $this->jobs->findActiveForUser($userId)->toApiArray();
        } catch (DoesNotExistException) {
        }
        if ($active === null) {
            try {
                $last = $this->jobs->findLatestForUser($userId)->toApiArray();
            } catch (DoesNotExistException) {
            }
        }

        return [
            'welcomeDismissed' => $dismissed,
            'activeJob' => $active,
            'lastJob' => $last,
            'available' => $this->isAvailable(),
        ];
    }

    public function dismissWelcome(string $userId): void
    {
        $this->config->setUserValue(
            $userId,
            'souvera_mail',
            self::USERCONFIG_WELCOME_DISMISSED,
            '1'
        );
    }

    /**
     * Reset the welcome state and cancel any active migration for the
     * given user — allows re-running the wizard after a completed run.
     */
    public function resetForUser(string $userId): void
    {
        $this->config->deleteUserValue($userId, 'souvera_mail', self::USERCONFIG_WELCOME_DISMISSED);
        try {
            $active = $this->jobs->findActiveForUser($userId);
            $active->setStatus(MigrationJob::STATUS_CANCELLED);
            $this->jobs->update($active);
        } catch (DoesNotExistException) {
            // No active job — nothing to cancel.
        }
    }

    /**
     * Pre-flight IMAP source credential check. Returns provider.tools'
     * raw success/message so the UI can display a green ✓ or red ✗.
     *
     * @return array{success: bool, message: string}
     */
    public function testSourceConnection(string $host, int $port, string $user, string $password, bool $secure): array
    {
        return $this->providerTools->testConnection([
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'password' => $password,
            'secure' => $secure,
        ]);
    }

    /**
     * @return array{
     *   success: bool,
     *   totalFolders: int,
     *   totalMessages: int,
     *   folders: list<array{path: string, messages: int}>,
     *   message?: string
     * }
     */
    public function listSourceFolders(string $host, int $port, string $user, string $password, bool $secure): array
    {
        return $this->providerTools->listFolders([
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'password' => $password,
            'secure' => $secure,
        ]);
    }

    /**
     * Start a new migration job for the given NC user.
     *
     * @param string        $sourceHost     Old provider IMAP host (e.g. imap.gmx.net)
     * @param int           $sourcePort     Old provider port (typ. 993 / 143)
     * @param string        $sourceUser     Old provider login (usually email)
     * @param string        $sourcePassword Old provider password — NEVER stored locally
     * @param bool          $sourceSecure   TLS-on-connect (993) vs STARTTLS (143)
     * @param list<string>  $folders        Source folder paths to migrate.
     *                                      Empty list is NOT accepted since
     *                                      provider.tools 2026-02 —
     *                                      MigrationController blocks that
     *                                      before we ever reach this method.
     *
     * @return array<string, mixed>  toApiArray() of the freshly created row
     *
     * @throws \RuntimeException           Rate limit hit / service unavailable
     * @throws ProviderToolsUnavailable    upstream error
     */
    public function startForUser(
        string $userId,
        string $sourceHost,
        int $sourcePort,
        string $sourceUser,
        string $sourcePassword,
        bool $sourceSecure,
        array $folders = [],
    ): array {
        $this->assertNoActiveJob($userId);
        if (!$this->isAvailable()) {
            throw new \RuntimeException(
                'Migration service is not fully configured — provider.tools token and Stalwart access both required.'
            );
        }

        // Mint destination temp app password. We do this BEFORE the
        // provider.tools POST so that on any subsequent failure we can
        // deterministically revoke it in the catch block below.
        $label = 'Souvera Import ' . \date('Y-m-d H:i', $this->time->getTime());
        $created = $this->appPasswords->createStalwartOnlyForMigration($userId, $label);

        $destination = [
            'host' => $this->resolveDestinationHost(),
            'port' => $this->resolveDestinationPort(),
            'user' => $created['username'],
            'password' => $created['secret'],
            'secure' => $this->resolveDestinationSecure(),
        ];
        $source = [
            'host' => $sourceHost,
            'port' => $sourcePort,
            'user' => $sourceUser,
            'password' => $sourcePassword,
            'secure' => $sourceSecure,
        ];

        // Persist a local pending row FIRST — if the API call now flakes
        // out we still have proof-of-attempt for the audit trail and
        // MigrationCleanup will pick up the orphan app-password.
        $now = $this->time->getTime();
        $job = new MigrationJob();
        $job->setUserId($userId);
        $job->setStatus(MigrationJob::STATUS_PENDING);
        $job->setSourceHost($sourceHost);
        $job->setSourceUser($sourceUser);
        $job->setStalwartAppId($created['id']);
        $job->setCreatedAt($now);
        $job->setUpdatedAt($now);
        $job = $this->jobs->insert($job);

        try {
            $started = $this->providerTools->startMigration($source, $destination, $folders);
        } catch (\Throwable $e) {
            // Roll back both the local row AND the temp Stalwart password.
            $job->setStatus(MigrationJob::STATUS_FAILED);
            $job->setErrorMessage('Start failed: ' . $e->getMessage());
            $job->setUpdatedAt($this->time->getTime());
            $job->setFinishedAt($this->time->getTime());
            try {
                $this->jobs->update($job);
            } catch (\Throwable $inner) {
                $this->logger->warning(
                    'Souvera Mail: migration job update after start failure also errored: '
                    . $inner->getMessage(),
                    ['app' => 'souvera_mail']
                );
            }
            $this->appPasswords->revokeStalwartOnlyForMigration(
                $userId,
                $created['id'],
                'migration-start-failed'
            );
            $this->logger->warning(
                'Souvera Mail: migration start failed for uid=' . $userId
                . ' host=' . $sourceHost . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            throw $e;
        }

        if (!$started['success'] || $started['migrationId'] === '') {
            $reason = $started['message'] !== '' ? $started['message'] : 'provider.tools rejected the migration without a reason';
            $job->setStatus(MigrationJob::STATUS_FAILED);
            $job->setErrorMessage('provider.tools rejected: ' . $reason);
            $job->setUpdatedAt($this->time->getTime());
            $job->setFinishedAt($this->time->getTime());
            $this->jobs->update($job);
            $this->appPasswords->revokeStalwartOnlyForMigration(
                $userId,
                $created['id'],
                'migration-start-rejected'
            );
            throw new ProviderToolsUnavailable('provider.tools rejected the migration: ' . $reason);
        }

        // Success — persist the migrationId and initial queue snapshot.
        $job->setProviderJobId($started['migrationId']);
        $job->setStatus(MigrationJob::STATUS_PENDING);
        $job->setProgressJson(\json_encode([
            'queue' => $started['queue'],
        ], JSON_UNESCAPED_SLASHES));
        $job->setUpdatedAt($this->time->getTime());
        $this->jobs->update($job);

        return $job->toApiArray();
    }

    /**
     * Cache-first status lookup. If we have no active job, returns null.
     * If the active job is stale (updated_at older than the fresh window),
     * this method still returns the cached copy — the poller catches it
     * up on the next tick. This keeps the frontend polling cheap.
     */
    public function getActiveJobForUser(string $userId): ?array
    {
        try {
            $job = $this->jobs->findActiveForUser($userId);
            return $job->toApiArray();
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Variant of getActiveJobForUser() that returns the MigrationJob
     * entity itself (or null) so the controller can pass it directly
     * to refreshFromProvider() for the on-demand /status poll path.
     */
    public function findActiveJobForUser(string $userId): ?MigrationJob
    {
        try {
            return $this->jobs->findActiveForUser($userId);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * @return ?array<string, mixed>  toApiArray() or null
     */
    public function getLatestJobForUser(string $userId): ?array
    {
        try {
            return $this->jobs->findLatestForUser($userId)->toApiArray();
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Called by the frontend on the "close after success"-screen. Marks
     * the terminal row as dismissed so the next welcome-state ping
     * returns "no active job" (welcome-screen if flag also set, or the
     * new-import screen if not).
     */
    public function dismissJobForUser(string $userId, int $jobId): void
    {
        try {
            $job = $this->jobs->find($jobId);
        } catch (DoesNotExistException) {
            return;
        }
        if ($job->getUserId() !== $userId) {
            return; // Silent no-op — user can only dismiss their own rows.
        }
        if (!\in_array($job->getStatus(), MigrationJob::TERMINAL_STATUSES, true)) {
            return; // Can only dismiss a terminal row.
        }
        $job->setStatus(MigrationJob::STATUS_DISMISSED);
        $job->setUpdatedAt($this->time->getTime());
        $this->jobs->update($job);
    }

    /**
     * v0.14.16 — user-initiated cancel while the job is still in the
     * provider.tools queue (STATUS_PENDING).
     *
     * provider.tools has no cancel endpoint (see ProviderToolsClient.php
     * §24-25). So we do the next best thing:
     *
     *   1. Revoke the temp Stalwart destination app-password NOW.
     *      Any later worker-pickup at provider.tools will fail at
     *      IMAP-AUTH — the job dies silently upstream.
     *   2. Flip the local row to STATUS_CANCELLED with a friendly
     *      errorMessage so the TerminalScreen can render it.
     *   3. Bump updated_at + finished_at so the poller stops pinging
     *      provider.tools for this row.
     *
     * We deliberately DO NOT allow cancel from STATUS_RUNNING: at that
     * point provider.tools is actively APPENDing mails and yanking the
     * app-password mid-transfer could corrupt a partially imported
     * folder. If we ever need that path, add a separate `forceCancel`
     * verb with a big red confirmation dialog.
     *
     * @throws \InvalidArgumentException  row does not belong to user
     *                                     OR is not in STATUS_PENDING
     * @throws DoesNotExistException      no such row
     */
    public function cancelJobForUser(string $userId, int $jobId): array
    {
        $job = $this->jobs->find($jobId);
        if ($job->getUserId() !== $userId) {
            throw new \InvalidArgumentException('job does not belong to user');
        }
        if ($job->getStatus() !== MigrationJob::STATUS_PENDING) {
            throw new \InvalidArgumentException(
                'Nur wartende Jobs (Warteschlange) können abgebrochen werden.'
            );
        }

        // Step 1: revoke the destination app password so any later
        // worker-pickup at provider.tools fails at IMAP-AUTH.  Failure
        // to revoke is logged but NOT fatal — the nightly
        // MigrationCleanup cron will pick up the orphan on the next
        // run, and the job's terminal status still needs to stick.
        $stalwartId = $job->getStalwartAppId();
        if ($stalwartId !== null && $stalwartId !== '') {
            try {
                $this->appPasswords->revokeStalwartOnlyForMigration(
                    $userId, $stalwartId, 'migration-cancelled'
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Souvera Mail: revoke on cancel failed for jobId=' . $job->getId()
                    . ' stalwartId=' . $stalwartId . ': ' . $e->getMessage(),
                    ['app' => 'souvera_mail']
                );
            }
            // v0.14.18 — NC-AppFramework's ->addType('stalwartAppId','string')
            // rejects setter(null) with an ArgumentCountError-like flake in
            // some PHP 8.3 patch levels. Guard the null-out in its own
            // try/catch: the app-password is revoked either way, and the
            // nightly cleanup cron will pick up the orphaned reference.
            try {
                $job->setStalwartAppId(null);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Souvera Mail: could not null out stalwart_app_id on cancel for jobId=' . $job->getId()
                    . ': ' . $e->getMessage(),
                    ['app' => 'souvera_mail']
                );
            }
        }

        // Step 2: flip local status to cancelled. Errors here MUST
        // propagate — without a status flip the poller keeps thinking
        // the job is pending, endless retry-loop.
        $now = $this->time->getTime();
        $job->setStatus(MigrationJob::STATUS_CANCELLED);
        $job->setErrorMessage('Vom Benutzer abgebrochen (war noch in der Warteschlange).');
        $job->setUpdatedAt($now);
        $job->setFinishedAt($now);
        $this->jobs->update($job);

        return $job->toApiArray();
    }

    /**
     * Poll provider.tools for the latest status of one job and persist
     * any state change. Called by both the MigrationPoller background
     * job (all active rows) and by the controller status endpoint when
     * the cached row is older than $forceRefreshAfterSeconds.
     *
     * @return array<string, mixed>  toApiArray() with the fresh values
     */
    public function refreshFromProvider(MigrationJob $job): array
    {
        $providerId = $job->getProviderJobId();
        if ($providerId === null || $providerId === '') {
            // Never got a migrationId — nothing to refresh. Should not
            // happen for rows in ACTIVE_STATUSES (startForUser only sets
            // pending after receiving the id), but be defensive.
            return $job->toApiArray();
        }
        try {
            $status = $this->providerTools->getStatus($providerId);
        } catch (\Throwable $e) {
            $this->logger->info(
                'Souvera Mail: refresh from provider.tools failed for jobId=' . $job->getId()
                . ' providerId=' . $providerId . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            // Best-effort: bump updated_at so the poller de-prioritises
            // this row for the next tick but don't flip status — a
            // transient upstream flake shouldn't kill the job.
            $job->setUpdatedAt($this->time->getTime());
            try {
                $this->jobs->update($job);
            } catch (\Throwable $inner) {
                $this->logger->warning(
                    'Souvera Mail: touch-updated_at after refresh failure also errored: '
                    . $inner->getMessage(),
                    ['app' => 'souvera_mail']
                );
            }
            return $job->toApiArray();
        }

        $upstream = $status['status'] ?? 'pending';
        $job->setProgressJson(\json_encode([
            'progress' => $status['progress'],
            'queue' => $status['queue'],
        ], JSON_UNESCAPED_SLASHES));
        $job->setUpdatedAt($this->time->getTime());

        if ($upstream === 'completed' || $upstream === 'failed') {
            $job->setStatus($upstream === 'completed'
                ? MigrationJob::STATUS_COMPLETED
                : MigrationJob::STATUS_FAILED);
            $job->setFinishedAt($this->time->getTime());
            if ($upstream === 'failed' && !empty($status['error'])) {
                $job->setErrorMessage((string) $status['error']);
            }
            // Revoke the temp Stalwart app password — the job is over,
            // no more IMAP APPENDs from provider.tools will happen.
            $stalwartId = $job->getStalwartAppId();
            if ($stalwartId !== null && $stalwartId !== '') {
                $this->appPasswords->revokeStalwartOnlyForMigration(
                    $job->getUserId(),
                    $stalwartId,
                    'migration-' . $upstream,
                );
                // Blank the id so the nightly cleanup doesn't try again.
                $job->setStalwartAppId(null);
            }
        } elseif ($upstream === 'running') {
            $job->setStatus(MigrationJob::STATUS_RUNNING);
        }
        // 'pending' stays pending — nothing to update.

        $this->jobs->update($job);
        return $job->toApiArray();
    }

    /**
     * @return list<MigrationJob>
     */
    public function findAllActive(int $limit = 50): array
    {
        return $this->jobs->findAllActive($limit);
    }

    /**
     * Older-than-N-days terminal rows whose app-password still hasn't
     * been reclaimed. Called by {@see \OCA\SouveraMail\Cron\MigrationCleanup}.
     *
     * @return list<MigrationJob>
     */
    public function findStaleTerminalJobs(int $olderThan, int $limit = 200): array
    {
        return $this->jobs->findStaleTerminalJobs($olderThan, $limit);
    }

    public function forceRevokeOrphan(MigrationJob $job): void
    {
        $stalwartId = $job->getStalwartAppId();
        if ($stalwartId === null || $stalwartId === '') {
            return;
        }
        $this->appPasswords->revokeStalwartOnlyForMigration(
            $job->getUserId(),
            $stalwartId,
            'migration-cleanup-orphan',
        );
        $job->setStalwartAppId(null);
        $job->setUpdatedAt($this->time->getTime());
        try {
            $this->jobs->update($job);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: cleanup update failed for jobId=' . $job->getId()
                . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
        }
    }

    private function assertNoActiveJob(string $userId): void
    {
        try {
            $active = $this->jobs->findActiveForUser($userId);
        } catch (DoesNotExistException) {
            return;
        }
        throw new \RuntimeException(
            'A migration is already active for this user (jobId=' . $active->getId()
            . ', status=' . $active->getStatus() . '). '
            . 'Wait for it to finish before starting a new one.'
        );
    }

    private function resolveDestinationHost(): string
    {
        $explicit = (string) $this->config->getAppValue(
            'souvera_mail',
            self::APPCONFIG_IMAP_HOST,
            ''
        );
        if ($explicit !== '') {
            return $explicit;
        }

        // Fall back to overwrite.cli.url host, then first trusted domain.
        $cliUrl = (string) $this->config->getSystemValue('overwrite.cli.url', '');
        if ($cliUrl !== '') {
            $host = \parse_url($cliUrl, PHP_URL_HOST);
            if (\is_string($host) && $host !== '') {
                return $host;
            }
        }
        $trusted = $this->config->getSystemValue('trusted_domains', []);
        if (\is_array($trusted) && isset($trusted[0]) && \is_string($trusted[0]) && $trusted[0] !== '') {
            return $trusted[0];
        }
        throw new \RuntimeException(
            'Cannot determine destination IMAP host. Set souvera_mail.stalwart_imap_host '
            . 'via `occ config:app:set` OR configure overwrite.cli.url in config.php.'
        );
    }

    private function resolveDestinationPort(): int
    {
        $explicit = (string) $this->config->getAppValue(
            'souvera_mail',
            self::APPCONFIG_IMAP_PORT,
            ''
        );
        if ($explicit !== '' && \ctype_digit($explicit)) {
            return (int) $explicit;
        }
        return self::DEFAULT_IMAP_PORT;
    }

    private function resolveDestinationSecure(): bool
    {
        $explicit = (string) $this->config->getAppValue(
            'souvera_mail',
            self::APPCONFIG_IMAP_SECURE,
            ''
        );
        if ($explicit === '') {
            return self::DEFAULT_IMAP_SECURE;
        }
        return \in_array(\strtolower($explicit), ['1', 'true', 'yes', 'on'], true);
    }
}
