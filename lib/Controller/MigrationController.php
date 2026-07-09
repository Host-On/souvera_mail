<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Service\MigrationService;
use OCA\SouveraMail\Service\ProviderToolsUnavailable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * REST endpoints powering the "Alte Mails importieren" welcome-wizard.
 *
 * All routes are scoped to the currently authenticated NC user — the
 * user id is NEVER read from the client. Provider.tools credentials
 * (source-side host / port / user / password) come from the request
 * body, are forwarded straight to provider.tools, and NEVER touched
 * again after that call returns.
 *
 * Endpoints (all prefixed by `/apps/souvera_mail/`):
 *   GET  /migration/welcome-state         → whether to show welcome
 *   POST /migration/dismiss-welcome       → mark "Nicht mehr zeigen"
 *   POST /migration/test-connection       → pre-flight source-cred check
 *   POST /migration/list-folders          → source folder inventory
 *   POST /migration/start                 → begin migration job
 *   GET  /migration/status                → active job + cached progress
 *   POST /migration/dismiss/{jobId}       → hide a terminal job from UI
 */
class MigrationController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private MigrationService $migrations,
        private LoggerInterface $logger,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoAdminRequired]
    public function welcomeState(): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        return new DataResponse([
            'status' => 'ok',
            'state' => $this->migrations->getWelcomeStateForUser($this->userId),
        ]);
    }

    #[NoAdminRequired]
    public function dismissWelcome(): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        $this->migrations->dismissWelcome($this->userId);
        return new DataResponse(['status' => 'ok']);
    }

    #[NoAdminRequired]
    public function testConnection(
        string $host = '',
        int $port = 993,
        string $user = '',
        string $password = '',
        bool $secure = true,
    ): DataResponse {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        $errors = $this->validateSourceInput($host, $port, $user, $password);
        if ($errors !== []) {
            return $this->error(\implode('; ', $errors), Http::STATUS_BAD_REQUEST);
        }
        if (!$this->migrations->isAvailable()) {
            return $this->error(
                'Import-Dienst ist auf dieser Instanz nicht aktiviert. Bitte den Administrator kontaktieren.',
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
        try {
            $result = $this->migrations->testSourceConnection($host, $port, $user, $password, $secure);
            return new DataResponse(['status' => 'ok', 'result' => $result]);
        } catch (ProviderToolsUnavailable $e) {
            return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Migration test-connection failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }
    }

    #[NoAdminRequired]
    public function listFolders(
        string $host = '',
        int $port = 993,
        string $user = '',
        string $password = '',
        bool $secure = true,
    ): DataResponse {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        $errors = $this->validateSourceInput($host, $port, $user, $password);
        if ($errors !== []) {
            return $this->error(\implode('; ', $errors), Http::STATUS_BAD_REQUEST);
        }
        if (!$this->migrations->isAvailable()) {
            return $this->error(
                'Import-Dienst ist auf dieser Instanz nicht aktiviert.',
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
        try {
            $result = $this->migrations->listSourceFolders($host, $port, $user, $password, $secure);
            return new DataResponse(['status' => 'ok', 'result' => $result]);
        } catch (ProviderToolsUnavailable $e) {
            return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Migration list-folders failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }
    }

    #[NoAdminRequired]
    public function start(
        string $host = '',
        int $port = 993,
        string $user = '',
        string $password = '',
        bool $secure = true,
        array $folders = [],
    ): DataResponse {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        $errors = $this->validateSourceInput($host, $port, $user, $password);
        if ($errors !== []) {
            return $this->error(\implode('; ', $errors), Http::STATUS_BAD_REQUEST);
        }
        // v0.14.14 — provider.tools tightened its contract: `folders`
        // MUST be a non-empty array of source-mailbox paths. Reject
        // empty selection early with a friendly message rather than
        // letting the upstream 400 bubble up unhelpfully.
        $folderPaths = \array_values(\array_filter(
            \array_map('strval', $folders),
            static fn (string $p): bool => $p !== '',
        ));
        if ($folderPaths === []) {
            return $this->error(
                'Bitte wähle mindestens einen Ordner zum Importieren aus.',
                Http::STATUS_BAD_REQUEST
            );
        }
        if (!$this->migrations->isAvailable()) {
            return $this->error(
                'Import-Dienst ist auf dieser Instanz nicht aktiviert.',
                Http::STATUS_SERVICE_UNAVAILABLE
            );
        }
        try {
            $job = $this->migrations->startForUser(
                $this->userId, $host, $port, $user, $password, $secure, $folderPaths
            );
            return new DataResponse(['status' => 'ok', 'job' => $job], Http::STATUS_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), Http::STATUS_BAD_REQUEST);
        } catch (ProviderToolsUnavailable $e) {
            return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        } catch (\RuntimeException $e) {
            // Rate-limit-hit ("A migration is already active …") + other
            // config-space runtime errors → 409 Conflict is the closest
            // fit and lets the frontend surface a "resume-view" link.
            $isRateLimit = \str_contains($e->getMessage(), 'already active');
            return $this->error(
                $e->getMessage(),
                $isRateLimit ? Http::STATUS_CONFLICT : Http::STATUS_INTERNAL_SERVER_ERROR
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Migration start failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return $this->error($e->getMessage(), Http::STATUS_BAD_GATEWAY);
        }
    }

    #[NoAdminRequired]
    public function status(): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        // v0.14.15 — on-demand refresh from provider.tools when the
        // cached row is older than 10s.  Without this the frontend
        // would sit on "Warteschlange …" for up to 60s because the
        // MigrationPoller cron only ticks once a minute.  The service
        // already de-dupes concurrent refreshes and swallows upstream
        // flakes, so it's safe to call on every /status hit for a
        // pending row.
        try {
            $jobRow = $this->migrations->findActiveJobForUser($this->userId);
            if ($jobRow !== null) {
                $ageSec = \time() - (int) $jobRow->getUpdatedAt();
                if ($ageSec >= 10) {
                    $active = $this->migrations->refreshFromProvider($jobRow);
                } else {
                    $active = $jobRow->toApiArray();
                }
                return new DataResponse(['status' => 'ok', 'active' => $active, 'latest' => null]);
            }
        } catch (\Throwable $e) {
            $this->logger->info(
                'Souvera Mail: /status on-demand refresh errored uid=' . $this->userId
                . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
            // Fall through to the cached-array path below.
        }
        $active = $this->migrations->getActiveJobForUser($this->userId);
        if ($active !== null) {
            return new DataResponse(['status' => 'ok', 'active' => $active, 'latest' => null]);
        }
        $latest = $this->migrations->getLatestJobForUser($this->userId);
        return new DataResponse(['status' => 'ok', 'active' => null, 'latest' => $latest]);
    }

    #[NoAdminRequired]
    public function dismissJob(int $jobId = 0): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        if ($jobId <= 0) {
            return $this->error('jobId must be > 0', Http::STATUS_BAD_REQUEST);
        }
        $this->migrations->dismissJobForUser($this->userId, $jobId);
        return new DataResponse(['status' => 'ok']);
    }

    /**
     * v0.14.16 — Cancel a job that is still in the provider.tools
     * queue (STATUS_PENDING). Fails with HTTP 409 CONFLICT once the
     * upstream worker has picked up the job (STATUS_RUNNING), because
     * pulling the app-password mid-transfer could corrupt a partially
     * imported folder.
     */
    #[NoAdminRequired]
    public function cancelJob(int $jobId = 0): DataResponse
    {
        if ($this->userId === null) {
            return $this->error('unauthenticated', Http::STATUS_UNAUTHORIZED);
        }
        if ($jobId <= 0) {
            return $this->error('jobId must be > 0', Http::STATUS_BAD_REQUEST);
        }
        try {
            $job = $this->migrations->cancelJobForUser($this->userId, $jobId);
            return new DataResponse(['status' => 'ok', 'job' => $job]);
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {
            return $this->error('Auftrag nicht gefunden.', Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            // "does not belong to user" → 403; "not pending" → 409
            $status = \str_contains($e->getMessage(), 'does not belong')
                ? Http::STATUS_FORBIDDEN
                : Http::STATUS_CONFLICT;
            return $this->error($e->getMessage(), $status);
        } catch (\Throwable $e) {
            // Full trace to the log; short human-facing summary to the
            // client — the operator asked us to stop swallowing errors
            // silently after the "Interner Fehler beim Abbruch"-report
            // gave zero clue what went wrong. The class name + message
            // suffix is deliberate: PHP-level flakes (SQL truncation,
            // AppFramework type-mismatches, …) become traceable without
            // handing the user a stack trace.
            $this->logger->error(
                'Souvera Mail: cancel job failed uid=' . $this->userId
                . ' jobId=' . $jobId . ': ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            $short = (new \ReflectionClass($e))->getShortName();
            return $this->error(
                'Interner Fehler beim Abbruch (' . $short . '): ' . $e->getMessage(),
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * @return list<string>
     */
    private function validateSourceInput(string $host, int $port, string $user, string $password): array
    {
        $errors = [];
        if (\trim($host) === '') {
            $errors[] = 'host must not be empty';
        }
        if ($port < 1 || $port > 65535) {
            $errors[] = 'port must be 1..65535';
        }
        if (\trim($user) === '') {
            $errors[] = 'user must not be empty';
        }
        if ($password === '') {
            $errors[] = 'password must not be empty';
        }
        return $errors;
    }

    private function error(string $message, int $status): DataResponse
    {
        return new DataResponse(['status' => 'error', 'message' => $message], $status);
    }
}
