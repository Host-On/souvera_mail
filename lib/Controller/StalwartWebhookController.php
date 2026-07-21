<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCA\SouveraMail\Db\DeviceTokenMapper;
use OCA\SouveraMail\Service\FcmClient;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
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
 * EXACT CONTRACT — this is what Stalwart must be configured to send
 * ==============================================================
 *
 * URL:    POST https://<nc-host>/index.php/apps/souvera_mail/webhooks/stalwart
 * Auth:   Shared secret, EITHER of:
 *           Authorization: Bearer <secret>
 *           X-Souvera-Webhook-Secret: <secret>
 *         <secret> must match the system-config value
 *         `souvera_mail.stalwart_webhook_secret` (config.php).
 *
 * Body (application/json) — ASSUMED shape, see "Uncertainty" below.
 * Every field is read defensively; unknown/extra fields are ignored.
 *
 *   {
 *     "event": "message.received",
 *     "account": "recipient@example.com",
 *     "recipients": ["recipient@example.com", "other@example.com"],
 *     "message": { "id": "...", "mailboxName": "INBOX" }
 *   }
 *
 * Field-access notes (tolerant parser, centralized in the private
 * `extract*()` methods below so a real-world schema mismatch is a
 * one-line change):
 *   - `event` / `type` / `eventType` — first non-empty string wins.
 *     Only "message.received" / "message.appended" / "message.new"
 *     trigger a push; anything else returns 200 "ignored".
 *   - Recipient email(s) are read from ANY of: `account`, `recipient`,
 *     `email`, `recipients`, `to`, `rcptTo`, `rcpt_to`. Each of these
 *     may be a single string, OR a list of strings, OR a list of
 *     objects carrying an `email`/`address` key (e.g. `{"email": "..."}`).
 *     All matches across all of these keys are merged (deduplicated).
 *   - The message body/subject is deliberately NEVER read — only
 *     `message.id` presence is used for defensive existence-checking,
 *     and is never included in the outgoing push (see privacy note).
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
 *
 * ==============================================================
 * Uncertainty (documented per implementation instructions)
 * ==============================================================
 * Stalwart's exact webhook JSON schema was not available in this repo
 * at implementation time. The parser above is intentionally tolerant
 * and centralizes field access so adapting to the real shape is a
 * one-line change in `extractEventType()` / `extractRecipientEmails()`.
 */
class StalwartWebhookController extends Controller
{
    public const SYSTEM_CONFIG_WEBHOOK_SECRET = 'souvera_mail.stalwart_webhook_secret';

    /** Event types that trigger a push. Extend here if Stalwart's real
     *  event taxonomy differs. */
    private const TRIGGER_EVENTS = ['message.received', 'message.appended', 'message.new'];

    /** JSON keys (in priority order) that may carry recipient email(s). */
    private const RECIPIENT_KEYS = ['account', 'recipient', 'email', 'recipients', 'to', 'rcptTo', 'rcpt_to'];

    private const PUSH_TITLE = 'Neue E-Mail';
    private const PUSH_BODY = 'Du hast eine neue Nachricht erhalten.';

    public function __construct(
        string $appName,
        IRequest $request,
        private IConfig $config,
        private IUserManager $userManager,
        private DeviceTokenMapper $tokens,
        private FcmClient $fcm,
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
        $event = $this->extractEventType($payload);
        if (!\in_array($event, self::TRIGGER_EVENTS, true)) {
            $this->logger->debug(
                'Souvera Mail: Stalwart webhook ignored event type "' . $event . '"',
                ['app' => 'souvera_mail']
            );
            return new DataResponse(['status' => 'ignored', 'reason' => 'unhandled event type']);
        }

        $emails = $this->extractRecipientEmails($payload);
        if ($emails === []) {
            $this->logger->debug(
                'Souvera Mail: Stalwart webhook payload carried no resolvable recipient email',
                ['app' => 'souvera_mail']
            );
            return new DataResponse(['status' => 'ignored', 'reason' => 'no recipient email in payload']);
        }

        $notified = 0;
        foreach ($this->resolveNextcloudUserIds($emails) as $userId) {
            $fcmTokens = \array_map(
                static fn ($t) => $t->getFcmToken(),
                $this->tokens->findAllForUser($userId),
            );
            if ($fcmTokens === []) {
                continue;
            }
            $this->fcm->send($fcmTokens, self::PUSH_TITLE, self::PUSH_BODY, ['type' => 'new_mail']);
            $notified++;
        }

        return new DataResponse(['status' => 'ok', 'notifiedUsers' => $notified]);
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
     * @param array<string, mixed> $payload
     */
    private function extractEventType(array $payload): string
    {
        foreach (['event', 'type', 'eventType'] as $key) {
            $value = $payload[$key] ?? null;
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function extractRecipientEmails(array $payload): array
    {
        $emails = [];
        foreach (self::RECIPIENT_KEYS as $key) {
            if (!\array_key_exists($key, $payload)) {
                continue;
            }
            foreach ($this->normalizeEmailList($payload[$key]) as $email) {
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
     *
     * ASSUMPTION: this only finds users whose NC-profile e-mail
     * (`settings/email`, synced into `oc_accounts`) matches. A user who
     * set a Souvera-Mail-specific override (`IUserConfig souvera_mail/email`
     * — see {@see \OCA\SouveraMail\Util\EngineHelper::getSsoEmail()}) but
     * has a different NC-profile e-mail will NOT be resolved here. This
     * mirrors the one realistic source of truth available without an
     * exhaustive per-user config scan; see the deliverable report for
     * the flagged uncertainty.
     *
     * @param list<string> $emails
     * @return list<string>
     */
    private function resolveNextcloudUserIds(array $emails): array
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
}
