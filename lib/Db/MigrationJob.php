<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Row in `oc_souvera_migrations` — a single IMAP import job initiated
 * by a Souvera Mail user against the provider.tools migration API.
 *
 * The job goes through:
 *   pending    — POSTed to provider.tools, awaiting queue slot
 *   running    — provider.tools has picked it up and is copying folders
 *   completed  — all messages transferred, cleanup done
 *   failed     — provider.tools reported failure OR our own pre-flight
 *                (test-connection) rejected the source creds
 *   dismissed  — user clicked "Verstanden, schließen" on the success/error
 *                splash; row stays for audit but is hidden from the UI
 *
 * Sensitive data (source password, destination temp app-password) is
 * NEVER stored — the source pw is forwarded straight to provider.tools
 * and forgotten; the destination pw is a short-lived Stalwart app
 * password whose id we keep so we can revoke it on completion.
 *
 * @method string  getUserId()
 * @method void    setUserId(string $userId)
 * @method ?string getProviderJobId()
 * @method void    setProviderJobId(?string $providerJobId)
 * @method string  getStatus()
 * @method void    setStatus(string $status)
 * @method string  getSourceHost()
 * @method void    setSourceHost(string $sourceHost)
 * @method string  getSourceUser()
 * @method void    setSourceUser(string $sourceUser)
 * @method ?string getStalwartAppId()
 * @method void    setStalwartAppId(?string $stalwartAppId)
 * @method ?string getProgressJson()
 * @method void    setProgressJson(?string $progressJson)
 * @method ?string getErrorMessage()
 * @method void    setErrorMessage(?string $errorMessage)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $updatedAt)
 * @method ?int    getFinishedAt()
 * @method void    setFinishedAt(?int $finishedAt)
 */
class MigrationJob extends Entity
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_RUNNING    = 'running';
    public const STATUS_COMPLETED  = 'completed';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_DISMISSED  = 'dismissed';

    /** Statuses that still consume queue slots + require polling. */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    /**
     * Statuses that are terminal — no more polling, ready for cleanup.
     *
     * `cancelled` is a *terminal* state: the operator asked us to stop
     * while the job was still in the provider.tools queue. Since
     * provider.tools has no cancel endpoint (see ProviderToolsClient.php
     * §24-25), we revoke the temp Stalwart app password locally. Any
     * later worker-pickup fails at IMAP-AUTH and the job silently dies
     * upstream — the local row already sits at `cancelled` and we
     * never surface the upstream flake.
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_CANCELLED,
        self::STATUS_DISMISSED,
    ];

    /** @var string */
    protected $userId = '';

    /** @var ?string */
    protected $providerJobId = null;

    /** @var string */
    protected $status = self::STATUS_PENDING;

    /** @var string */
    protected $sourceHost = '';

    /** @var string */
    protected $sourceUser = '';

    /** @var ?string */
    protected $stalwartAppId = null;

    /** @var ?string */
    protected $progressJson = null;

    /** @var ?string */
    protected $errorMessage = null;

    /** @var int */
    protected $createdAt = 0;

    /** @var int */
    protected $updatedAt = 0;

    /** @var ?int */
    protected $finishedAt = null;

    public function __construct()
    {
        $this->addType('userId', 'string');
        $this->addType('providerJobId', 'string');
        $this->addType('status', 'string');
        $this->addType('sourceHost', 'string');
        $this->addType('sourceUser', 'string');
        $this->addType('stalwartAppId', 'string');
        $this->addType('progressJson', 'string');
        $this->addType('errorMessage', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('finishedAt', 'integer');
    }

    /**
     * @return array{
     *   id: ?int,
     *   status: string,
     *   sourceHost: string,
     *   sourceUser: string,
     *   providerJobId: ?string,
     *   progress: array<string, mixed>,
     *   error: ?string,
     *   createdAt: int,
     *   updatedAt: int,
     *   finishedAt: ?int,
     *   isActive: bool,
     *   isTerminal: bool
     * }
     */
    public function toApiArray(): array
    {
        $progress = [];
        if ($this->progressJson !== null && $this->progressJson !== '') {
            $decoded = \json_decode($this->progressJson, true);
            if (\is_array($decoded)) {
                $progress = $decoded;
            }
        }
        return [
            'id' => $this->getId() === null ? null : (int) $this->getId(),
            'status' => $this->status,
            'sourceHost' => $this->sourceHost,
            'sourceUser' => $this->sourceUser,
            'providerJobId' => $this->providerJobId,
            'progress' => $progress,
            'error' => $this->errorMessage,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'finishedAt' => $this->finishedAt,
            'isActive' => \in_array($this->status, self::ACTIVE_STATUSES, true),
            'isTerminal' => \in_array($this->status, self::TERMINAL_STATUSES, true),
        ];
    }
}
