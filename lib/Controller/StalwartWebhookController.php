<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Db\DeviceTokenMapper;
use OCA\SouveraMail\Service\FcmClient;
use OCA\SouveraMail\Service\StalwartAdminService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Receives Stalwart's new-mail webhook and pushes an FCM notification to
 * every registered Android device of the resolved Nextcloud recipient(s).
 *
 * This is the event-driven PRIMARY path; {@see \OCA\SouveraMail\Cron\MailPushPoller}
 * is only a low-frequency fallback safety net.
 *
 * ==============================================================
 * EXACT CONTRACT — verified against the Stalwart source
 * ==============================================================
 *
 * URL:    POST https://<nc-host>/index.php/apps/souvera_mail/webhooks/stalwart
 * Auth:   Shared secret, EITHER of:
 *           Authorization: Bearer <secret>
 *           X-Souvera-Webhook-Secret: <secret>
 *         <secret> must match the system-config value
 *         `souvera_mail.stalwart_webhook_secret` (config.php).
 *
 * Body (application/json) — REAL shape, verified 2026-07-21 against
 * `stalwartlabs/stalwart` @ main, `crates/email/src/message/ingest.rs`
 * (the `trc::event!(MessageIngest(...), AccountId = account_id, ...)`
 * call emitted at the end of `email_ingest()`):
 *
 *   {
 *     "events": [
 *       {
 *         "id": "...", "createdAt": "...",
 *         "type": "message-ingest.ham",
 *         "data": {
 *           "accountId": 123, "documentId": 1, "mailboxId": [1],
 *           "blobId": "...", "changeId": 9, "messageId": "<...>",
 *           "size": 4821, "elapsed": 12
 *         }
 *       }
 *     ]
 *   }
 *
 * Crucially, there is NO recipient e-mail string anywhere in this payload
 * — only `data.accountId`, Stalwart's internal numeric account id. See
 * "accountId resolution" below for how that is turned into an NC user.
 *
 * Field-access notes (tolerant parser, centralized in the private
 * `extract*()` methods below so schema drift is a one-line change):
 *   - Top level: an `events` array is expected; a bare single event
 *     object (no wrapper) is also accepted, as is the OLDER assumed flat
 *     shape from before this payload was verified (`event`/`account`/
 *     `recipients`/... directly on the body) — see "Legacy fallback".
 *   - Per event, `type` (or legacy `event`/`eventType`) selects handling:
 *       - `message-ingest.ham`  → resolve `data.accountId` and push.
 *       - `message-ingest.spam` → ignored (200, debug log).
 *       - any other/unknown type → ignored (200, debug log), EXCEPT the
 *         legacy trigger types below, kept for backwards tolerance.
 *   - Legacy fallback (pre-verification assumption, kept only so an
 *     unexpected older/alternate Stalwart build doesn't silently break):
 *     `event`/`type`/`eventType` == "message.received"/"message.appended"/
 *     "message.new" triggers a push resolved via a recipient e-mail read
 *     from `account`/`recipient`/`email`/`recipients`/`to`/`rcptTo`/
 *     `rcpt_to` on the event object (string, list of strings, or list of
 *     `{"email"|"address": "..."}` objects).
 *
 * accountId resolution (numeric → Nextcloud user), centralized in
 * {@see self::resolveNcUserForStalwartAccountId()}:
 *   1. `data.accountId` is Stalwart's internal numeric (u32) account id —
 *      NOT the JMAP `accountId` STRING seen elsewhere in this app (e.g.
 *      {@see \OCA\SouveraMail\Service\StalwartUserContext::resolveAccountId()}).
 *      Verified: the JMAP accountId string is `Id::from(numeric_id).to_string()`
 *      (`crates/types/src/id.rs`) — a base32 encoding of that SAME numeric
 *      id using the alphabet `abcdefghijklmnopqrstuvwxyz792013`. So the two
 *      are the same account, just encoded differently.
 *   2. {@see StalwartAdminService::lookupPrincipalEmailByAccountId()} base32-
 *      encodes the numeric id and issues an ADMIN (Basic-auth) JMAP
 *      `Principal/get` call — the one JMAP method Stalwart does not scope
 *      to a caller-owned account (verified against
 *      `crates/jmap/src/api/request.rs` + `crates/jmap/src/principal/get.rs`)
 *      — to get the principal's e-mail/login. No per-user OIDC bearer is
 *      needed or obtainable at this point (we don't know the user yet).
 *   3. The resulting e-mail is resolved to an NC user via
 *      {@see IUserManager::getByEmail()}, same as the legacy path.
 *   No persistent NC-user↔accountId cache is kept: `Principal/get` is a
 *   single cheap admin call per ham event, and this app has no existing
 *   table that already stores this mapping (checked: DeviceToken,
 *   AppPasswordMapping, MigrationJob — none carry a Stalwart accountId).
 *   Revisit if webhook volume ever makes that round-trip a bottleneck.
 *
 * Response: always 200 OK as fast as possible (Stalwart should not retry
 * webhook delivery indefinitely). Non-2xx is reserved for auth failures
 * (401/503) — a malformed/unrecognized payload still returns 200 with
 * `{"status":"ignored", ...}` so a Stalwart-side schema drift never
 * causes webhook delivery to back off or disable itself.
 *
 * ==============================================================
 * Privacy
 * ==============================================================
 * The push notification body is a generic "Neue E-Mail" signal plus
 * `data: {type: "new_mail"}` — NEVER the subject or message body. The
 * Android app must open the app and sync via IMAP/JMAP to learn what
 * actually arrived.
 */
class StalwartWebhookController extends Controller
{
    public const SYSTEM_CONFIG_WEBHOOK_SECRET = 'souvera_mail.stalwart_webhook_secret';

    /** Optional monitoring endpoint for webhook health reports (latency/load
     *  monitoring). When set, every accepted webhook posts a compact report;
     *  otherwise the report degrades to a debug log entry. */
    public const SYSTEM_CONFIG_HEALTH_URL = 'souvera_mail.webhook_health_url';
    public const SYSTEM_CONFIG_HEALTH_TOKEN = 'souvera_mail.webhook_health_token';

    /** Real Stalwart event type that means "new mail, not spam, push it". */
    private const HAM_EVENT_TYPE = 'message-ingest.ham';

    /** Legacy/assumed event types, kept only as a backwards-tolerant
     *  fallback — see class docblock "Legacy fallback". */
    private const LEGACY_TRIGGER_EVENTS = ['message.received', 'message.appended', 'message.new'];

    /** JSON keys (in priority order) that may carry recipient email(s) on
     *  a LEGACY-shaped event. */
    private const RECIPIENT_KEYS = ['account', 'recipient', 'email', 'recipients', 'to', 'rcptTo', 'rcpt_to'];

    private const PUSH_TITLE = 'Neue E-Mail';
    private const PUSH_BODY = 'Du hast eine neue Nachricht erhalten.';

    /** @var array<int, string|null> per-request memo: numeric Stalwart
     *  accountId → resolved NC user id (or null = unresolved). */
    private array $accountIdUserCache = [];

    /** TTL for persistent accountId→user mappings in IAppConfig (seconds).
     *  Successful resolutions get the full TTL; unresolved (null) results get
     *  a shorter TTL so transient provisioning races self-heal quickly. */
    private const ACCOUNT_CACHE_TTL = 86400;
    private const ACCOUNT_CACHE_TTL_NULL = 300;
    private const ACCOUNT_CACHE_PREFIX = 'stalwart_account_';

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IAppConfig $appConfig,
        private IUserManager $userManager,
        private DeviceTokenMapper $tokens,
        private FcmClient $fcm,
        private StalwartAdminService $stalwartAdmin,
        private IClientService $httpClientService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * POST /apps/souvera_mail/webhooks/stalwart
     *
     * Unauthenticated (no NC user context) — protected by a shared secret
     * instead. See the class docblock for the full contract.
     */
    #[PublicPage]
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'souvera_mail_stalwart_webhook')]
    public function push(): DataResponse
    {
        $started = \microtime(true);
        $expectedSecret = \trim((string) $this->config->getSystemValue(self::SYSTEM_CONFIG_WEBHOOK_SECRET, ''));
        if ($expectedSecret === '') {
            return new DataResponse(
                ['status' => 'error', 'message' => 'Stalwart webhook is not configured on this instance'],
                Http::STATUS_SERVICE_UNAVAILABLE,
            );
        }

        $providedSecret = $this->extractProvidedSecret();
        if ($providedSecret === '' || !\hash_equals($expectedSecret, $providedSecret)) {
            $r = new DataResponse(['status' => 'error', 'message' => 'invalid or missing webhook secret'], Http::STATUS_UNAUTHORIZED);
            $r->throttle(['action' => 'souvera_mail_stalwart_webhook']);
            return $r;
        }

        $payload = $this->readPayload();
        $events = $this->extractEvents($payload);

        $notified = 0;
        foreach ($events as $event) {
            $notified += $this->processEvent($event);
        }

        $this->reportHealth(
            processedMs: (int) \round((\microtime(true) - $started) * 1000),
            eventsReceived: \count($events),
            notifiedUsers: $notified,
            events: $events,
        );

        return new DataResponse(['status' => 'ok', 'eventsReceived' => \count($events), 'notifiedUsers' => $notified]);
    }

    /**
     * Posts a compact health report (latency/load) to the optional monitoring
     * endpoint configured via {@see SYSTEM_CONFIG_HEALTH_URL}; without a
     * configured endpoint this degrades to a debug log entry. Failures are
     * logged but never break webhook processing.
     *
     * @param list<array<string, mixed>> $events
     */
    private function reportHealth(int $processedMs, int $eventsReceived, int $notifiedUsers, array $events): void
    {
        $url = \trim((string) $this->config->getSystemValue(self::SYSTEM_CONFIG_HEALTH_URL, ''));
        if ($url === '') {
            $this->logger->debug(
                'Souvera Mail: webhook health report skipped — ' . self::SYSTEM_CONFIG_HEALTH_URL . ' not configured',
                ['app' => 'souvera_mail']
            );
            return;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $token = \trim((string) $this->config->getSystemValue(self::SYSTEM_CONFIG_HEALTH_TOKEN, ''));
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $payload = [
            'source' => 'souvera_mail',
            'type' => 'webhook_health',
            'timestamp' => \time(),
            'processedMs' => $processedMs,
            'eventsReceived' => $eventsReceived,
            'notifiedUsers' => $notifiedUsers,
            'oldestEventAgeSeconds' => $this->oldestEventAgeSeconds($events),
        ];

        try {
            $client = $this->httpClientService->newClient();
            $client->post($url, [
                'json' => $payload,
                'headers' => $headers,
                'timeout' => 5,
                'connect_timeout' => 5,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: webhook health report failed: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
        }
    }

    /**
     * Best-effort age of the oldest event in seconds (null when no usable
     * timestamp is present on any event). Accepts seconds or milliseconds.
     *
     * @param list<array<string, mixed>> $events
     */
    private function oldestEventAgeSeconds(array $events): ?int
    {
        $now = \time();
        $oldest = null;
        foreach ($events as $event) {
            if (!\is_array($event)) {
                continue;
            }
            $ts = $event['receivedAt'] ?? $event['ts'] ?? $event['timestamp'] ?? null;
            if (!\is_numeric($ts)) {
                continue;
            }
            $seconds = (float) $ts;
            if ($seconds > 1_000_000_000_000) {
                $seconds /= 1000; // milliseconds
            }
            $age = $now - $seconds;
            if ($age >= 0 && ($oldest === null || $age > $oldest)) {
                $oldest = (int) $age;
            }
        }
        return $oldest;
    }

    /**
     * Handles a single event object and returns how many NC users were
     * pushed to (0 if the event was ignored or unresolvable). See the
     * class docblock ("EXACT CONTRACT") for the event shape.
     *
     * @param array<string, mixed> $event
     */
    private function processEvent(array $event): int
    {
        $type = \strtolower($this->extractEventType($event));

        if ($type === self::HAM_EVENT_TYPE) {
            $accountId = $this->extractAccountId($event);
            if ($accountId === null) {
                $this->logger->debug(
                    'Souvera Mail: Stalwart webhook ham event carried no numeric data.accountId',
                    ['app' => 'souvera_mail']
                );
                return 0;
            }
            $userId = $this->resolveNcUserForStalwartAccountId($accountId);
            if ($userId === null) {
                return 0;
            }
            return $this->pushToUser($userId) ? 1 : 0;
        }

        if (\in_array($type, self::LEGACY_TRIGGER_EVENTS, true)) {
            $notified = 0;
            foreach ($this->resolveNextcloudUserIdsByEmail($this->extractRecipientEmails($event)) as $userId) {
                $notified += $this->pushToUser($userId) ? 1 : 0;
            }
            return $notified;
        }

        $this->logger->debug(
            'Souvera Mail: Stalwart webhook ignored event type "' . $type . '"',
            ['app' => 'souvera_mail']
        );
        return 0;
    }

    /**
     * Sends the push to every device registered for one NC user.
     *
     * @return bool true if at least one device token existed and a push was sent
     */
    private function pushToUser(string $userId): bool
    {
        $fcmTokens = \array_map(
            static fn ($t) => $t->getFcmToken(),
            $this->tokens->findAllForUser($userId),
        );
        if ($fcmTokens === []) {
            return false;
        }
        $this->fcm->send($fcmTokens, self::PUSH_TITLE, self::PUSH_BODY, ['type' => 'new_mail']);
        return true;
    }

    private function extractProvidedSecret(): string
    {
        $auth = (string) $this->request->getHeader('Authorization');
        if (\str_starts_with($auth, 'Bearer ')) {
            return \substr($auth, 7);
        }
        $header = (string) $this->request->getHeader('X-Souvera-Webhook-Secret');
        return $header;
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $params = $this->request->getParams();
        if (\is_array($params) && $params !== []) {
            return $params;
        }
        $decoded = \json_decode((string) $this->request->getContent(), true);
        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Parses the top-level payload into a list of individual event
     * objects. Real shape is `{"events": [...]}` (see class docblock);
     * a bare single event object, or the pre-verification flat shape
     * (event fields directly on the body), are also accepted so a
     * future schema tweak on Stalwart's side degrades gracefully instead
     * of dropping every webhook.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function extractEvents(array $payload): array
    {
        if (isset($payload['events']) && \is_array($payload['events'])) {
            $events = [];
            foreach ($payload['events'] as $event) {
                if (\is_array($event)) {
                    $events[] = $event;
                }
            }
            return $events;
        }

        if (isset($payload['type']) || isset($payload['data']) || isset($payload['event'])) {
            return [$payload];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $event
     */
    private function extractEventType(array $event): string
    {
        foreach (['type', 'event', 'eventType'] as $key) {
            $value = $event[$key] ?? null;
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Reads the real payload's `data.accountId` (Stalwart's internal
     * numeric account id) — falling back to a top-level `accountId` for
     * tolerance. Accepts a JSON number or a numeric string.
     *
     * @param array<string, mixed> $event
     */
    private function extractAccountId(array $event): ?int
    {
        $data = $event['data'] ?? null;
        $raw = \is_array($data) ? ($data['accountId'] ?? null) : ($event['accountId'] ?? null);
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_string($raw) && \ctype_digit($raw)) {
            return (int) $raw;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $event
     * @return list<string>
     */
    private function extractRecipientEmails(array $event): array
    {
        $emails = [];
        foreach (self::RECIPIENT_KEYS as $key) {
            if (!\array_key_exists($key, $event)) {
                continue;
            }
            foreach ($this->normalizeEmailList($event[$key]) as $email) {
                $emails[\strtolower($email)] = $email;
            }
        }
        return \array_values($emails);
    }

    /**
     * @return list<string>
     */
    private function normalizeEmailList(mixed $value): array
    {
        if (\is_string($value)) {
            $value = \trim($value);
            return $value !== '' ? [$value] : [];
        }
        if (!\is_array($value)) {
            return [];
        }
        $emails = [];
        foreach ($value as $entry) {
            if (\is_string($entry) && \trim($entry) !== '') {
                $emails[] = \trim($entry);
            } elseif (\is_array($entry)) {
                $email = $entry['email'] ?? $entry['address'] ?? null;
                if (\is_string($email) && \trim($email) !== '') {
                    $emails[] = \trim($email);
                }
            }
        }
        return $emails;
    }

    /**
     * Resolves e-mail addresses to Nextcloud user ids via
     * {@see IUserManager::getByEmail()}. Unresolvable addresses are
     * skipped (logged at debug) rather than failing the whole webhook.
     * Only used by the LEGACY flat-shape fallback path — see class docblock.
     *
     * ASSUMPTION: this only finds users whose NC-profile e-mail
     * (`settings/email`, synced into `oc_accounts`) matches. A user who
     * set a Souvera-Mail-specific override (`IUserConfig souvera_mail/email`
     * — see {@see \OCA\SouveraMail\Util\EngineHelper::getSsoEmail()}) but
     * has a different NC-profile e-mail will NOT be resolved here.
     *
     * @param list<string> $emails
     * @return list<string>
     */
    private function resolveNextcloudUserIdsByEmail(array $emails): array
    {
        $userIds = [];
        foreach ($emails as $email) {
            $matches = $this->userManager->getByEmail($email);
            if ($matches === []) {
                $this->logger->debug(
                    'Souvera Mail: Stalwart webhook recipient not resolvable to an NC user: ' . $email,
                    ['app' => 'souvera_mail']
                );
                continue;
            }
            foreach ($matches as $user) {
                $userIds[$user->getUID()] = $user->getUID();
            }
        }
        return \array_values($userIds);
    }

    /**
     * Resolves Stalwart's internal numeric `accountId` to a Nextcloud user id.
     * Three-tier cache:
     *   1. In-process (per-request) — $this->accountIdUserCache
     *   2. Persistent (IAppConfig, 24h TTL) — avoids JMAP round-trip
     *   3. On miss: JMAP Principal/get + IUserManager.getByEmail
     */
    private function resolveNcUserForStalwartAccountId(int $accountId): ?string
    {
        // Tier 1: in-process memo (already checked by processEvent, but double-check)
        if (\array_key_exists($accountId, $this->accountIdUserCache)) {
            return $this->accountIdUserCache[$accountId];
        }

        // Tier 2: persistent cache
        $cached = $this->loadAccountCache($accountId);
        if ($cached !== null) {
            $this->accountIdUserCache[$accountId] = $cached;
            return $cached;
        }

        // Tier 3: live resolution
        $email = $this->stalwartAdmin->lookupPrincipalEmailByAccountId($accountId);
        if ($email === null) {
            // Short TTL for unresolved — the principal may not be provisioned yet.
            $this->saveAccountCache($accountId, null, self::ACCOUNT_CACHE_TTL_NULL);
            return $this->accountIdUserCache[$accountId] = null;
        }

        $matches = $this->userManager->getByEmail($email);
        $userId = ($matches === []) ? null : $matches[0]->getUID();

        // Full TTL for successful resolution; short TTL for unresolved (provisioning race).
        $ttl = ($userId !== null) ? self::ACCOUNT_CACHE_TTL : self::ACCOUNT_CACHE_TTL_NULL;
        $this->saveAccountCache($accountId, $userId, $ttl);
        return $this->accountIdUserCache[$accountId] = $userId;
    }

    /**
     * Reads a cached accountId→userId mapping. Returns the user id, or
     * `'__null__'` as a sentinel for "resolved to nothing", or null on miss.
     */
    private function loadAccountCache(int $accountId): ?string
    {
        $key = self::ACCOUNT_CACHE_PREFIX . $accountId;
        $raw = $this->appConfig->getValueString('souvera_mail', $key, '');
        if ($raw === '') {
            return null;
        }
        $entry = \json_decode($raw, true);
        if (!\is_array($entry) || !isset($entry['x'])) {
            $this->appConfig->setValueString('souvera_mail', $key, '');
            return null;
        }
        if (($entry['x'] ?? 0) < \time()) {
            $this->appConfig->setValueString('souvera_mail', $key, '');
            return null;
        }
        $uid = $entry['u'] ?? null;
        return $uid === '__null__' ? null : $uid;
    }

    /**
     * Stores a resolved (or null) accountId→userId mapping with current
     * time + TTL expiry.
     */
    private function saveAccountCache(int $accountId, ?string $userId, int $ttl = 0): void
    {
        $key = self::ACCOUNT_CACHE_PREFIX . $accountId;
        $this->appConfig->setValueString('souvera_mail', $key, \json_encode([
            'u' => $userId ?? '__null__',
            'x' => \time() + ($ttl > 0 ? $ttl : self::ACCOUNT_CACHE_TTL),
        ], JSON_UNESCAPED_SLASHES));
    }
}
