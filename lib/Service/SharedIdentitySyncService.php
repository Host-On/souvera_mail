<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use Psr\Log\LoggerInterface;

/**
 * Fetches the canonical list of identities the authenticated user is
 * allowed to send `From:` from — i.e. their own mailbox plus every
 * shared/role-mailbox where Stalwart has granted them `EmailSubmission`
 * rights — and exposes it to the Snappymail engine plugin so the
 * compose-window "From:" dropdown is always in sync with the server.
 *
 * Source-of-truth contract
 * ------------------------
 * The contents of `Identity/get` ON STALWART are authoritative. The
 * engine's local identity storage may carry user-created manual
 * identities alongside (e.g. an alias the user hand-added) — those
 * are left untouched by the sync. Stalwart-managed identities are
 * tagged with `Id = 'stalwart:<stalwartIdentityId>'` so the sync can
 * tell them apart on every subsequent run and reconcile additions /
 * removals / display-name changes idempotently.
 *
 * Throttling
 * ----------
 * The actual JMAP round-trip is throttled to once per
 * {@see THROTTLE_SECONDS} per user via the distributed cache. The
 * engine-plugin hook calls `syncIfStale()` on every engine boot;
 * 99% of those calls return the cached list within microseconds.
 *
 * Failure mode
 * ------------
 * Every error path (missing config, missing souvera_central, Stalwart
 * 5xx, malformed JMAP) is swallowed and logged at WARN level. The
 * caller MUST treat the return value as best-effort: an empty list
 * (`[]`) means "no Stalwart-managed identities right now", which the
 * engine plugin interprets as "remove all stale `stalwart:` entries
 * but keep manual ones" — except when {@see syncIfStale()} returns
 * `null` (cache hit), which means "skip reconciliation entirely".
 */
class SharedIdentitySyncService
{
    private const CACHE_PREFIX = 'souvera_mail.shared_identities.';
    private const CACHE_TIMESTAMP_PREFIX = 'souvera_mail.shared_identities.ts.';
    // Stalwart-side identities change rarely (admin adds someone to a
    // shared mailbox, or revokes them); 15 minutes is the sweet spot the
    // operator picked between "fresh enough that the user notices the
    // change within a coffee break" and "doesn't hammer Stalwart on
    // every engine boot of every user".
    public const THROTTLE_SECONDS = 900;
    // Prefix used in the engine's identity storage to mark records this
    // service owns. Manual identities never start with this prefix.
    public const STALWART_ID_PREFIX = 'stalwart:';
    // Human-readable suffix appended to the Label so the user sees
    // "Stalwart-verwaltet" in the Settings → Identities list and in
    // the compose-window "From:" dropdown.
    public const STALWART_LABEL_SUFFIX = ' [Stalwart]';
    // IMAP namespace prefix Stalwart's shared-mailbox folders live
    // under. Matches Stalwart's `server.namespace.shared` default; if a
    // deploy customises this (e.g. `Other Users/` or `#shared/`),
    // operators can override the full sent-folder path per principal
    // — but the out-of-the-box default works for every standard
    // Stalwart 0.16 deploy and is what 99% of operators need. We
    // prepend it to `<email>/Sent` for the engine identity's
    // `sentFolder` so the IMAP APPEND that the engine does after a
    // successful SMTP submission lands in the SHARED mailbox's Sent
    // folder — not in the user's own. (Operator-reported 2026-07-01.)
    public const SHARED_NAMESPACE_PREFIX = 'Shared Folders/';
    // Leaf folder name for "Sent" in Stalwart's default IMAP layout
    // for shared mailboxes. Stalwart auto-creates Inbox/Sent/Drafts/
    // Trash/Junk for every principal; the Sent leaf is the unprefixed
    // English name (Stalwart does NOT translate folder names per-user).
    public const SHARED_SENT_LEAF = 'Sent';

    public function __construct(
        private StalwartUserContext $userContext,
        private StalwartAdminService $stalwart,
        private \OCP\ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * True iff the prerequisites for any kind of Stalwart-managed
     * identity discovery are met: souvera_central installed (so we
     * have a principal mapping), H2CK/oidc available (so we can
     * mint a user JWT), and a Stalwart API URL configured.
     */
    public function isAvailable(): bool
    {
        return $this->userContext->isAvailable() && $this->stalwart->isConfigured();
    }

    /**
     * Returns the current list of Stalwart-managed identities for the
     * given user, fetching fresh from Stalwart if the cached entry is
     * older than {@see THROTTLE_SECONDS}. Returns `null` if the cached
     * entry is still warm — caller can short-circuit reconciliation.
     *
     * @return list<array{stalwartId: string, email: string, name: string}>|null
     */
    public function syncIfStale(string $userId): ?array
    {
        if ($userId === '' || !$this->isAvailable()) {
            return null;
        }
        $cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
        $tsKey = self::CACHE_TIMESTAMP_PREFIX . $userId;
        $listKey = self::CACHE_PREFIX . $userId;

        $lastTs = (int) ($cache->get($tsKey) ?? 0);
        if ($lastTs > 0 && (\time() - $lastTs) < self::THROTTLE_SECONDS) {
            // Cache hit — engine plugin must skip reconciliation.
            return null;
        }

        $fresh = $this->fetchFromStalwart($userId);
        if ($fresh === null) {
            // Fetch failed — extend the throttle window so we don't
            // hammer a broken Stalwart, but keep the last known list
            // (or empty list) cached so the engine plugin can still
            // reconcile against *something*.
            $cache->set($tsKey, (string) \time(), self::THROTTLE_SECONDS);
            return [];
        }
        $cache->set($tsKey, (string) \time(), self::THROTTLE_SECONDS);
        $cache->set($listKey, \json_encode($fresh, JSON_UNESCAPED_SLASHES), self::THROTTLE_SECONDS * 4);
        return $fresh;
    }

    /**
     * Forces a fresh JMAP round-trip — used by the explicit
     * "Jetzt neu synchronisieren" button in the settings tab.
     *
     * @return list<array{stalwartId: string, email: string, name: string}>
     */
    public function forceSync(string $userId): array
    {
        if ($userId === '' || !$this->isAvailable()) {
            return [];
        }
        $cache = $this->cacheFactory->createDistributed(self::CACHE_PREFIX);
        $fresh = $this->fetchFromStalwart($userId) ?? [];
        $cache->set(self::CACHE_TIMESTAMP_PREFIX . $userId, (string) \time(), self::THROTTLE_SECONDS);
        $cache->set(self::CACHE_PREFIX . $userId, \json_encode($fresh, JSON_UNESCAPED_SLASHES), self::THROTTLE_SECONDS * 4);
        return $fresh;
    }

    /**
     * Calls JMAP `Identity/get` against Stalwart. Returns `null` on
     * any failure (caller logs); returns `[]` when Stalwart legitimately
     * reports no identities (rare but possible during account setup).
     *
     * @return list<array{stalwartId: string, email: string, name: string}>|null
     */
    private function fetchFromStalwart(string $userId): ?array
    {
        try {
            $accountId = $this->userContext->resolveAccountId($userId);
            $bearer = $this->userContext->resolveBearer($userId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: shared identity sync skipped, cannot resolve Stalwart account for '
                . $userId . ': ' . $e->getMessage()
            );
            return null;
        }

        try {
            $response = $this->stalwart->jmapCall($bearer, [
                [
                    'Identity/get',
                    [
                        'accountId' => $accountId,
                        // null = "return all identities the user can use to
                        // send From: from" per RFC 8621 §6.2.
                        'ids' => null,
                    ],
                    'c0',
                ],
            ], ['urn:ietf:params:jmap:submission']);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: JMAP Identity/get failed for ' . $userId . ': ' . $e->getMessage()
            );
            return null;
        }

        try {
            $body = $this->stalwart->extractMethodResponse($response, 'Identity/get');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: malformed Identity/get response for ' . $userId . ': ' . $e->getMessage()
            );
            return null;
        }

        $list = $body['list'] ?? [];
        if (!\is_array($list)) {
            return [];
        }

        $ownEmail = '';
        try {
            $ownEmail = \strtolower(\trim($this->userContext->resolveEmail($userId)));
        } catch (\Throwable) {
            // Already failed above; just continue without it — we won't
            // be able to flag the user's own identity but the rest works.
        }

        $out = [];
        foreach ($list as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $sid = (string) ($entry['id'] ?? '');
            $email = \strtolower(\trim((string) ($entry['email'] ?? '')));
            $name = \trim((string) ($entry['name'] ?? ''));
            if ($sid === '' || $email === '') {
                // Stalwart should never emit these; defensive skip.
                continue;
            }
            // Stalwart-side `Identity/get` returns BOTH the user's own
            // primary identity AND every shared mailbox where they have
            // send-as. Distinguish so we can route the IMAP APPEND of
            // the sent copy correctly: shared mailboxes need a
            // `sentFolder` that points at the shared inbox's Sent
            // (`Shared Folders/<email>/Sent` under Stalwart's default
            // namespace); the user's own identity must keep
            // `sentFolder = ''` so the engine falls back to its
            // account-level Sent.
            $isShared = ($ownEmail !== '' && $email !== $ownEmail);
            $sentFolder = $isShared
                ? (self::SHARED_NAMESPACE_PREFIX . $email . '/' . self::SHARED_SENT_LEAF)
                : '';
            $out[] = [
                'stalwartId' => $sid,
                'email' => $email,
                // Fall back to the local-part of the address when
                // Stalwart hasn't set a description — better than an
                // empty `From: <team@example.com>` field.
                'name' => $name !== '' ? $name : \explode('@', $email)[0],
                'isShared' => $isShared,
                'sentFolder' => $sentFolder,
            ];
        }
        return $out;
    }

    /**
     * Reconciles the engine-side `identities` JSON blob against the
     * Stalwart-side list. Pure function — no engine API calls — so
     * the engine plugin can use it AND tests can drive it with stubs.
     *
     * Rules (all idempotent):
     *  1. Keep every identity whose `Id` does NOT start with
     *     {@see STALWART_ID_PREFIX} verbatim (= manual identities).
     *  2. For each `$stalwart` entry, ensure exactly one engine
     *     identity exists with `Id = stalwart:<stalwartId>`. Update
     *     its Email/Name/Label in place if the entry is already
     *     present; insert a fresh skeleton if not.
     *  3. Drop engine identities tagged `stalwart:<id>` whose `<id>`
     *     is no longer in `$stalwart` (e.g. the operator revoked
     *     send-as on a shared mailbox).
     *  4. Skip any Stalwart entry whose `email` already matches a
     *     manual identity — the user gets to keep their hand-tuned
     *     signature for their own primary mailbox.
     *
     * @param list<array<string, mixed>> $engineIdentities Current engine state (raw JSON-decoded array)
     * @param list<array{stalwartId: string, email: string, name: string, isShared?: bool, sentFolder?: string}> $stalwart
     * @return list<array<string, mixed>> New `identities` array, ready to JSON-encode + Put()
     */
    public function reconcile(array $engineIdentities, array $stalwart): array
    {
        $manual = [];
        $existingByStalwartId = [];
        foreach ($engineIdentities as $idn) {
            $id = (string) ($idn['Id'] ?? '');
            if (\str_starts_with($id, self::STALWART_ID_PREFIX)) {
                $existingByStalwartId[\substr($id, \strlen(self::STALWART_ID_PREFIX))] = $idn;
            } else {
                $manual[] = $idn;
            }
        }

        // Rule 4: skip Stalwart entries whose email collides with a manual identity.
        $manualEmails = [];
        foreach ($manual as $m) {
            $manualEmails[\strtolower(\trim((string) ($m['Email'] ?? '')))] = true;
        }

        $merged = $manual;
        foreach ($stalwart as $s) {
            if (isset($manualEmails[$s['email']])) {
                continue;
            }
            $sid = $s['stalwartId'];
            $sentFolder = (string) ($s['sentFolder'] ?? '');
            $existing = $existingByStalwartId[$sid] ?? null;
            if ($existing !== null) {
                $existing['Email'] = $s['email'];
                $existing['Name'] = $s['name'];
                $existing['Label'] = $s['name'] . self::STALWART_LABEL_SUFFIX;
                // Always re-assert the canonical sentFolder — Stalwart
                // is the authoritative source. If the operator revokes
                // send-as on a shared mailbox and re-grants on a
                // different one, the existing record's stale path
                // would otherwise outlive the change.
                $existing['sentFolder'] = $sentFolder;
                $merged[] = $existing;
            } else {
                $merged[] = $this->skeleton($sid, $s['email'], $s['name'], $sentFolder);
            }
        }
        return $merged;
    }

    /**
     * Default identity record for a freshly discovered Stalwart-managed
     * identity. Field shape mirrors the engine identity model.
     *
     * @return array<string, mixed>
     */
    private function skeleton(string $stalwartId, string $email, string $name, string $sentFolder = ''): array
    {
        return [
            'Id' => self::STALWART_ID_PREFIX . $stalwartId,
            'Label' => $name . self::STALWART_LABEL_SUFFIX,
            'Email' => $email,
            'Name' => $name,
            'ReplyTo' => '',
            'Bcc' => '',
            'Signature' => '',
            'SignatureInsertBefore' => false,
            // Routes the IMAP APPEND of the sent copy AFTER SMTP
            // submission. Empty = engine falls back to the account's
            // default Sent (= the user's own); non-empty = explicit
            // path the engine APPENDs to. For shared mailboxes we
            // point at `Shared Folders/<email>/Sent` (Stalwart default).
            'sentFolder' => $sentFolder,
            'pgpEncrypt' => false,
            'pgpSign' => false,
            'smimeKey' => '',
            'smimeCertificate' => '',
        ];
    }
}
