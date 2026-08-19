<?php
declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCA\SouveraMail\Sieve\MessageFacts;
use OCA\SouveraMail\Sieve\MiniInterpreter;
use Psr\Log\LoggerInterface;

/**
 * Apply the currently-active Sieve script against messages that are
 * already sitting in a mailbox — the missing feature the operator
 * asked for after `CantSaveFilters[351]` was fixed (2026-02-19).
 *
 * Why this class instead of "just ask Stalwart"
 * ---------------------------------------------
 * Sieve is an inbound-delivery filter. Once a message is stored in a
 * mailbox, Stalwart doesn't re-run Sieve against it — there's no
 * standard JMAP method for that. Our approach:
 *
 *   1. Fetch the active script body from Stalwart (via
 *      SieveScriptService::listScriptsWithBodies)
 *   2. Parse it locally with MiniInterpreter — a subset that covers
 *      what Snappymail's UI emits (see `MiniInterpreter` docblock)
 *   3. Fetch message facts via JMAP `Email/query` + `Email/get`
 *   4. Evaluate each message; collect the resulting actions
 *   5. Execute the actions server-side:
 *      • `fileinto` → JMAP `Email/set update mailboxIds`
 *      • `discard`  → move to Trash (safer than a real delete —
 *                     lets the user undo via Trash restore)
 *      • `addflag`  → JMAP `Email/set update keywords`
 *      • `redirect` → JMAP `EmailSubmission/set create`
 *        (only when the caller opts in — the operator confirmed
 *         `1b` in the scope question, so we do it by default)
 *
 * Bounded scope: we only look at the last `$limit` messages of the
 * target folder (default 2000, capped at 5000). Applying Sieve to
 * 50 000 old newsletter emails would take minutes and consume a lot
 * of Stalwart bandwidth. The user can iterate the button.
 *
 * Return shape: a counters array the controller relays to the JS
 * layer as JSON, so the toast can say "12 verschoben, 3 weitergeleitet".
 */
class SieveApplyService
{
    private const MAX_LIMIT = 5000;
    private const DEFAULT_LIMIT = 5000;

    /**
     * JMAP-per-call chunk size for Email/query.limit, Email/get.ids and
     * Email/set.update. Stalwart 0.16 reports `Parameter limit must be
     * between 1 and 500` when we breach its per-call cap.
     *
     * The message reads inclusive ("between 1 AND 500") but Stalwart's
     * Rust range check `1..500` is EXCLUSIVE on the upper bound —
     * limit=500 gets rejected too (operator report 2026-02-19: even
     * limit=500 fails). To eliminate any borderline behaviour across
     * Stalwart minor versions and future config changes, we pick a
     * conservative value well below every observed cap. 250 is
     * empirically safe: it works against Stalwart's default
     * `mail.parse.limits.query-limit = 500` AND against operator
     * setups that lower the cap (e.g. a shared-Stalwart tenant policy
     * of 300). Doubling the round-trip count is not a concern — the
     * whole apply-flow is server-side; the browser waits for one
     * final JSON.
     */
    private const JMAP_PAGE_LIMIT = 250;

    /** JMAP capability required by every Mailbox/* and Email/* method
     *  we issue (RFC 8621 §1). Our `StalwartAdminService::jmapCall`
     *  only puts `urn:ietf:params:jmap:core` + Stalwart's own capability
     *  in the top-level `using` array by default — we MUST add
     *  `urn:ietf:params:jmap:mail` here, otherwise Stalwart 0.16 rejects
     *  the call with `unknownMethod: Method X/… requires capability
     *  urn:ietf:params:jmap:mail which is not present in the "using"
     *  property.` (operator report 2026-02-19). */
    private const CAP_MAIL = 'urn:ietf:params:jmap:mail';
    private const CAP_SUBMISSION = 'urn:ietf:params:jmap:submission';

    /** JMAP standard-role names Stalwart returns for well-known folders. */
    private const ROLE_INBOX = 'inbox';
    private const ROLE_TRASH = 'trash';

    public function __construct(
        private StalwartAdminService $stalwart,
        private SieveScriptService $sieveScripts,
        private StalwartUserContext $userContext,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch the list of foldable target mailboxes for the operator's
     * dropdown (name + id + role).  Called from the "Apply filters"
     * dialog to populate the folder picker.
     *
     * @return array{status: string, folders?: array<int, array{id:string,name:string,role:?string}>, message?:string}
     */
    public function listFolders(string $userId): array
    {
        try {
            $accountId = $this->userContext->resolveAccountId($userId);
            $bearer = $this->userContext->resolveBearer($userId);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        try {
            $response = $this->stalwart->jmapCall(
                $bearer,
                [[
                    'Mailbox/get',
                    ['accountId' => $accountId, 'properties' => ['id', 'name', 'role']],
                    'c0',
                ]],
                [self::CAP_MAIL]
            );
            $list = (array) ($this->stalwart->extractMethodResponse($response, 'Mailbox/get')['list'] ?? []);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Souvera Mail: Mailbox/get failed while listing folders for apply-filters: ' . $e->getMessage(),
                ['app' => 'souvera_mail', 'exception' => $e]
            );
            return ['status' => 'error', 'message' => 'Ordnerliste konnte nicht geladen werden: ' . $e->getMessage()];
        }

        $folders = [];
        foreach ($list as $mb) {
            if (!\is_array($mb) || !isset($mb['id'])) { continue; }
            $folders[] = [
                'id' => (string) $mb['id'],
                'name' => (string) ($mb['name'] ?? ''),
                'role' => isset($mb['role']) ? (string) $mb['role'] : null,
            ];
        }
        // Inbox first, then sorted by name.
        \usort($folders, static function (array $a, array $b): int {
            if ($a['role'] === self::ROLE_INBOX) { return -1; }
            if ($b['role'] === self::ROLE_INBOX) { return 1; }
            return \strcasecmp($a['name'], $b['name']);
        });
        return ['status' => 'ok', 'folders' => $folders];
    }

    /**
     * Run the active Sieve script against $folderId's messages.
     *
     * @param string $userId       NC user id (must be logged-in caller)
     * @param string $folderId     JMAP mailbox id, or 'INBOX' for role-lookup
     * @param int    $limit        Max number of messages to scan (last N by receivedAt)
     * @param bool   $includeRedirect  If false, `redirect` actions are counted but not executed
     *
     * @return array{
     *   status:string,
     *   scanned?:int,
     *   moved?:int,
     *   redirected?:int,
     *   redirects_skipped?:int,
     *   discarded?:int,
     *   flagged?:int,
     *   errors?:array<int,string>,
     *   message?:string
     * }
     */
    public function apply(
        string $userId,
        string $folderId,
        int $limit = self::DEFAULT_LIMIT,
        bool $includeRedirect = true
    ): array {
        $limit = \max(1, \min(self::MAX_LIMIT, $limit));

        // ---- 1. Get the active Sieve script body ----
        try {
            $scriptData = $this->sieveScripts->listScriptsWithBodies($userId);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Sieve-Skripte nicht ladbar: ' . $e->getMessage()];
        }
        $accountId = (string) $scriptData['accountId'];
        $bearer = (string) $scriptData['bearer'];

        $activeBody = '';
        $activeName = '';
        foreach ($scriptData['scripts'] as $s) {
            if (($s['isActive'] ?? false) === true) {
                $activeBody = (string) ($s['body'] ?? '');
                $activeName = (string) ($s['name'] ?? '');
                break;
            }
        }
        if ($activeBody === '') {
            return ['status' => 'error', 'message' => 'Kein aktives Sieve-Skript hinterlegt — bitte zuerst ein Skript aktivieren.'];
        }

        $engine = (new MiniInterpreter())->parse($activeBody);
        if ($engine->getRules() === []) {
            return ['status' => 'error', 'message' => 'Das aktive Skript enthält keine anwendbaren Regeln.'];
        }

        // ---- 2. Resolve folderId (accept 'INBOX' shorthand) ----
        try {
            $mailboxes = $this->fetchMailboxes($accountId, $bearer);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Ordnerliste nicht abrufbar: ' . $e->getMessage()];
        }
        $sourceMailboxId = $this->resolveMailboxId($folderId, $mailboxes);
        if ($sourceMailboxId === null) {
            return ['status' => 'error', 'message' => "Ordner '{$folderId}' wurde in der Mailbox nicht gefunden."];
        }

        // ---- 3. Fetch message ids in the source folder ----
        try {
            $ids = $this->queryMessageIds($accountId, $bearer, $sourceMailboxId, $limit);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Nachrichtenliste nicht abrufbar: ' . $e->getMessage()];
        }
        if ($ids === []) {
            return [
                'status' => 'ok',
                'scanned' => 0, 'moved' => 0, 'redirected' => 0,
                'discarded' => 0, 'flagged' => 0, 'errors' => [],
                'redirects_skipped' => 0,
                'message' => 'Der Ordner enthält keine Nachrichten.',
            ];
        }

        // ---- 4. Fetch facts for those messages ----
        try {
            $facts = $this->fetchMessageFacts($accountId, $bearer, $ids);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'Nachrichten-Details nicht abrufbar: ' . $e->getMessage()];
        }

        // ---- 5. Evaluate rules, collect actions ----
        $moves = [];        // messageId => targetMailboxId
        $keywords = [];     // messageId => [flag => true]
        $redirects = [];    // messageId => [recipient1, recipient2, …]
        $discards = [];     // messageId => true
        $scanned = 0;

        // Cache mailbox-name → mailbox-id lookup for fileinto targets.
        $trashId = $this->findMailboxByRole($mailboxes, self::ROLE_TRASH);
        foreach ($facts as $msg) {
            $scanned++;
            $result = $engine->evaluate($msg);
            if ($result->isEmpty() && !$result->shouldDiscard()
                && $result->fileintoTarget() === null && $result->redirectTargets() === []
                && $result->addedFlags() === []
            ) {
                continue;
            }
            if ($result->shouldDiscard() && $trashId !== null) {
                $discards[$msg->emailId] = true;
                $moves[$msg->emailId] = $trashId; // move-to-Trash semantic
            }
            $target = $result->fileintoTarget();
            if ($target !== null && !isset($discards[$msg->emailId])) {
                $mbId = $this->findMailboxByName($mailboxes, $target);
                if ($mbId !== null) {
                    $moves[$msg->emailId] = $mbId;
                }
            }
            foreach ($result->addedFlags() as $flag) {
                $keywords[$msg->emailId][$this->normaliseKeyword($flag)] = true;
            }
            $redir = $result->redirectTargets();
            if ($redir !== []) {
                $redirects[$msg->emailId] = $redir;
            }
        }

        // ---- 6. Execute JMAP mutations ----
        $errors = [];
        $moved = $this->executeMoves($accountId, $bearer, $moves, $sourceMailboxId, $errors);
        $flagged = $this->executeFlagAdds($accountId, $bearer, $keywords, $errors);

        $redirected = 0;
        $redirectsSkipped = 0;
        if ($includeRedirect && $redirects !== []) {
            $redirected = $this->executeRedirects($accountId, $bearer, $redirects, $errors);
        } else {
            foreach ($redirects as $rs) { $redirectsSkipped += \count($rs); }
        }

        return [
            'status' => 'ok',
            'script' => $activeName,
            'scanned' => $scanned,
            'moved' => $moved,
            'redirected' => $redirected,
            'redirects_skipped' => $redirectsSkipped,
            'discarded' => \count($discards),
            'flagged' => $flagged,
            'errors' => $errors,
        ];
    }

    // ------------------------------------------------------------------
    // JMAP helpers
    // ------------------------------------------------------------------

    /** @return array<int, array{id:string,name:string,role:?string}> */
    private function fetchMailboxes(string $accountId, string $bearer): array
    {
        $response = $this->stalwart->jmapCall(
            $bearer,
            [[
                'Mailbox/get',
                ['accountId' => $accountId, 'properties' => ['id', 'name', 'role']],
                'c0',
            ]],
            [self::CAP_MAIL]
        );
        $list = (array) ($this->stalwart->extractMethodResponse($response, 'Mailbox/get')['list'] ?? []);
        $out = [];
        foreach ($list as $mb) {
            if (!\is_array($mb) || !isset($mb['id'])) { continue; }
            $out[] = [
                'id' => (string) $mb['id'],
                'name' => (string) ($mb['name'] ?? ''),
                'role' => isset($mb['role']) ? (string) $mb['role'] : null,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, array{id:string,name:string,role:?string}> $mailboxes
     */
    private function resolveMailboxId(string $folderId, array $mailboxes): ?string
    {
        // JMAP mailbox ids are opaque tokens; if the caller passes such a
        // token we accept it as-is. If they pass "INBOX" (the shorthand
        // from the JMAP session or from Snappymail's UI), we resolve it
        // to the inbox role.
        foreach ($mailboxes as $mb) {
            if ($mb['id'] === $folderId) { return $folderId; }
        }
        if (\strtoupper($folderId) === 'INBOX') {
            return $this->findMailboxByRole($mailboxes, self::ROLE_INBOX);
        }
        // Last resort — case-insensitive name match.
        foreach ($mailboxes as $mb) {
            if (\strcasecmp($mb['name'], $folderId) === 0) { return $mb['id']; }
        }
        return null;
    }

    /**
     * @param array<int, array{id:string,name:string,role:?string}> $mailboxes
     */
    private function findMailboxByRole(array $mailboxes, string $role): ?string
    {
        foreach ($mailboxes as $mb) {
            if (($mb['role'] ?? null) === $role) { return $mb['id']; }
        }
        return null;
    }

    /**
     * Best-effort case-insensitive lookup — also strips a leading `INBOX/`
     * for shared/namespaced folders where Snappymail's sieve.js emits e.g.
     * `fileinto "INBOX/Newsletters";`.
     * @param array<int, array{id:string,name:string,role:?string}> $mailboxes
     */
    private function findMailboxByName(array $mailboxes, string $name): ?string
    {
        $candidates = [$name, \ltrim(\preg_replace('#^INBOX/#i', '', $name) ?? $name, '/')];
        foreach ($candidates as $cand) {
            if ($cand === '') { continue; }
            foreach ($mailboxes as $mb) {
                if (\strcasecmp($mb['name'], $cand) === 0) { return $mb['id']; }
            }
        }
        return null;
    }

    /**
     * Stalwart 0.16 caps `Email/query.limit` per JMAP call. The exact
     * cap depends on server config but our observed error was
     * "Parameter limit must be between 1 and 500". We chunk at
     * {@see self::JMAP_PAGE_LIMIT} = 250 to be safely below any
     * observed cap. To honour our `MAX_LIMIT = 5000` we paginate:
     * loop with `position` advancing by JMAP_PAGE_LIMIT until we've
     * collected `$limit` ids OR Stalwart returns fewer than a full
     * page (= end of mailbox).
     *
     * @return string[] JMAP Email ids, newest first, capped at $limit.
     */
    private function queryMessageIds(string $accountId, string $bearer, string $mailboxId, int $limit): array
    {
        $out = [];
        $position = 0;
        $pageLimit = self::JMAP_PAGE_LIMIT;
        // Diagnostic: emit a version breadcrumb so operators can
        // confirm the paginated code path is actually running (vs a
        // stale OpCache copy of the old single-call implementation).
        $this->logger->info(
            'Souvera Mail: sieve-apply queryMessageIds start '
            . "(pageLimit={$pageLimit}, requestedLimit={$limit}, mailboxId={$mailboxId})",
            ['app' => 'souvera_mail']
        );
        while (\count($out) < $limit) {
            $remaining = $limit - \count($out);
            $requestLimit = \min($pageLimit, $remaining);
            $response = $this->stalwart->jmapCall(
                $bearer,
                [[
                    'Email/query',
                    [
                        'accountId' => $accountId,
                        'filter' => ['inMailbox' => $mailboxId],
                        'sort' => [['property' => 'receivedAt', 'isAscending' => false]],
                        'position' => $position,
                        'limit' => $requestLimit,
                        'calculateTotal' => false,
                    ],
                    'c0',
                ]],
                [self::CAP_MAIL]
            );
            $body = $this->stalwart->extractMethodResponse($response, 'Email/query');
            $ids = (array) ($body['ids'] ?? []);
            $pageCount = 0;
            foreach ($ids as $id) {
                if (\is_string($id) && $id !== '') {
                    $out[] = $id;
                    $pageCount++;
                }
            }
            // Reached the end of the mailbox (fewer results than
            // requested) — stop paginating.
            if ($pageCount < $requestLimit) {
                break;
            }
            $position += $pageCount;
        }
        $this->logger->info(
            'Souvera Mail: sieve-apply queryMessageIds done '
            . '(collected=' . \count($out) . ')',
            ['app' => 'souvera_mail']
        );
        return $out;
    }

    /**
     * @param string[] $ids
     * @return MessageFacts[]
     */
    private function fetchMessageFacts(string $accountId, string $bearer, array $ids): array
    {
        // Stalwart 0.16 applies the same 500-item cap to Email/get.ids
        // as to Email/query.limit. Use JMAP_PAGE_LIMIT (250) to stay
        // well below every observed cap.
        $chunks = \array_chunk($ids, self::JMAP_PAGE_LIMIT);
        $facts = [];
        foreach ($chunks as $chunk) {
            $response = $this->stalwart->jmapCall(
                $bearer,
                [[
                    'Email/get',
                    [
                        'accountId' => $accountId,
                        'ids' => $chunk,
                        // `headers:all:asRaw` returns every header as a
                        // {name, value} tuple — we normalise it below.
                        'properties' => ['id', 'mailboxIds', 'size', 'headers', 'from', 'to', 'cc', 'subject'],
                    ],
                    'c0',
                ]],
                [self::CAP_MAIL]
            );
            $list = (array) ($this->stalwart->extractMethodResponse($response, 'Email/get')['list'] ?? []);
            foreach ($list as $entry) {
                if (!\is_array($entry) || !isset($entry['id'])) { continue; }
                $facts[] = $this->buildFacts($entry);
            }
        }
        return $facts;
    }

    /** @param array<string,mixed> $entry Email/get list entry */
    private function buildFacts(array $entry): MessageFacts
    {
        $headers = [];
        foreach ((array) ($entry['headers'] ?? []) as $h) {
            if (!\is_array($h) || !isset($h['name'])) { continue; }
            $name = (string) $h['name'];
            $value = MessageFacts::normaliseHeaderValue((string) ($h['value'] ?? ''));
            if (isset($headers[$name])) {
                if (\is_array($headers[$name])) {
                    $headers[$name][] = $value;
                } else {
                    $headers[$name] = [$headers[$name], $value];
                }
            } else {
                $headers[$name] = $value;
            }
        }
        // Fold `from`/`to` back into a synthetic `From:`/`To:` header so
        // rules like `address :contains "from" "…"` work even if the raw
        // headers list was pruned by the server.
        if (!isset($headers['From']) && isset($entry['from'][0]['email'])) {
            $headers['From'] = $entry['from'][0]['email'];
        }
        $envelopeFrom = $entry['from'][0]['email'] ?? null;
        $envelopeTo = [];
        foreach ((array) ($entry['to'] ?? []) as $t) {
            if (\is_array($t) && isset($t['email'])) { $envelopeTo[] = (string) $t['email']; }
        }
        return new MessageFacts(
            (string) $entry['id'],
            $headers,
            $envelopeFrom !== null ? (string) $envelopeFrom : null,
            $envelopeTo,
            (int) ($entry['size'] ?? 0)
        );
    }

    /**
     * @param array<string,string> $moves     messageId → destinationMailboxId
     * @param array<int,string>    &$errors
     */
    private function executeMoves(string $accountId, string $bearer, array $moves, string $sourceMailboxId, array &$errors): int
    {
        if ($moves === []) { return 0; }
        $update = [];
        foreach ($moves as $emailId => $destId) {
            // Set the target mailbox to true, remove the source mailbox.
            // (Sieve fileinto semantics = MOVE, not copy.)
            $update[$emailId] = [
                'mailboxIds/' . $destId => true,
                'mailboxIds/' . $sourceMailboxId => null,
            ];
        }
        // Stalwart 0.16 caps `Email/set.update` at the same
        // JMAP_PAGE_LIMIT (250) as query/get. Chunking prevents a
        // 5000-message apply-run from failing the whole batch with
        // "Parameter … must be between 1 and 500".
        $totalUpdated = 0;
        foreach (\array_chunk($update, self::JMAP_PAGE_LIMIT, /*preserve_keys*/ true) as $chunk) {
            try {
                $response = $this->stalwart->jmapCall(
                    $bearer,
                    [[
                        'Email/set',
                        ['accountId' => $accountId, 'update' => $chunk],
                        'c0',
                    ]],
                    [self::CAP_MAIL]
                );
                $body = $this->stalwart->extractMethodResponse($response, 'Email/set');
                $updated = (array) ($body['updated'] ?? []);
                $notUpdated = (array) ($body['notUpdated'] ?? []);
                foreach ($notUpdated as $id => $reason) {
                    $errors[] = "Email/set update rejected {$id}: " . \json_encode($reason);
                }
                $totalUpdated += \count($updated);
            } catch (\Throwable $e) {
                $errors[] = 'Email/set for moves failed: ' . $e->getMessage();
            }
        }
        return $totalUpdated;
    }

    /**
     * @param array<string, array<string, true>> $flagsByEmail
     * @param array<int,string> &$errors
     */
    private function executeFlagAdds(string $accountId, string $bearer, array $flagsByEmail, array &$errors): int
    {
        if ($flagsByEmail === []) { return 0; }
        $update = [];
        foreach ($flagsByEmail as $emailId => $flags) {
            $sub = [];
            foreach ($flags as $flag => $_true) {
                $sub['keywords/' . $flag] = true;
            }
            if ($sub !== []) { $update[$emailId] = $sub; }
        }
        // Same JMAP_PAGE_LIMIT (250) cap as Email/set.update above.
        $totalFlagged = 0;
        foreach (\array_chunk($update, self::JMAP_PAGE_LIMIT, /*preserve_keys*/ true) as $chunk) {
            try {
                $response = $this->stalwart->jmapCall(
                    $bearer,
                    [[
                        'Email/set',
                        ['accountId' => $accountId, 'update' => $chunk],
                        'c0',
                    ]],
                    [self::CAP_MAIL]
                );
                $body = $this->stalwart->extractMethodResponse($response, 'Email/set');
                $totalFlagged += \count((array) ($body['updated'] ?? []));
            } catch (\Throwable $e) {
                $errors[] = 'Email/set for flags failed: ' . $e->getMessage();
            }
        }
        return $totalFlagged;
    }

    /**
     * Re-inject each redirect target via JMAP `EmailSubmission/set create`.
     * Stalwart resolves the send identity from the OIDC bearer, so we
     * don't have to fetch Identity/get first.
     *
     * @param array<string, string[]> $redirects     messageId → [rcpt1, rcpt2]
     * @param array<int,string>       &$errors
     */
    private function executeRedirects(string $accountId, string $bearer, array $redirects, array &$errors): int
    {
        if ($redirects === []) { return 0; }

        // 1. Resolve the user's default identity (need identityId for JMAP EmailSubmission/set).
        $identityId = null;
        try {
            $iResp = $this->stalwart->jmapCall(
                $bearer,
                [['Identity/get', ['accountId' => $accountId], 'c0']],
                [self::CAP_SUBMISSION]
            );
            $iList = (array) ($this->stalwart->extractMethodResponse($iResp, 'Identity/get')['list'] ?? []);
            foreach ($iList as $iden) {
                if (\is_array($iden) && isset($iden['id'])) {
                    $identityId = (string) $iden['id'];
                    break; // first identity is the default in Stalwart
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'Identity/get failed (no redirect will be sent): ' . $e->getMessage();
            return 0;
        }
        if ($identityId === null) {
            $errors[] = 'Keine Absender-Identität in Stalwart hinterlegt — redirect nicht möglich';
            return 0;
        }

        // 2. Build one EmailSubmission/set create batch per redirect target.
        //    (One submission = one { emailId, envelope { mailFrom, rcptTo[] } }
        //    entry — Stalwart handles the actual SMTP transmission.)
        $create = [];
        $seq = 0;
        foreach ($redirects as $emailId => $rcpts) {
            foreach ($rcpts as $rcpt) {
                $create['r' . (++$seq)] = [
                    'emailId' => $emailId,
                    'identityId' => $identityId,
                    'envelope' => [
                        'mailFrom' => ['email' => $this->extractIdentityEmail($iList ?? [], $identityId)],
                        'rcptTo' => [['email' => $rcpt]],
                    ],
                ];
            }
        }
        if ($create === []) { return 0; }

        try {
            $response = $this->stalwart->jmapCall(
                $bearer,
                [[
                    'EmailSubmission/set',
                    ['accountId' => $accountId, 'create' => $create],
                    'c0',
                ]],
                [self::CAP_SUBMISSION, self::CAP_MAIL]
            );
            $body = $this->stalwart->extractMethodResponse($response, 'EmailSubmission/set');
            $created = (array) ($body['created'] ?? []);
            $notCreated = (array) ($body['notCreated'] ?? []);
            foreach ($notCreated as $key => $reason) {
                $errors[] = "EmailSubmission/set rejected {$key}: " . \json_encode($reason);
            }
            return \count($created);
        } catch (\Throwable $e) {
            $errors[] = 'EmailSubmission/set failed: ' . $e->getMessage();
            return 0;
        }
    }

    /** @param array<int, array<string,mixed>> $identities */
    private function extractIdentityEmail(array $identities, string $identityId): string
    {
        foreach ($identities as $iden) {
            if (\is_array($iden) && ($iden['id'] ?? null) === $identityId) {
                return (string) ($iden['email'] ?? '');
            }
        }
        return '';
    }

    /**
     * Sieve emits system flags like `\Seen`, `\Flagged`, `\Answered`.
     * JMAP `keywords` uses lower-case names WITHOUT the leading backslash
     * (`$seen`, `$flagged`, `$answered` — RFC 8621 §4.1.1). Anything else
     * becomes a user keyword (no backslash), lower-cased.
     */
    private function normaliseKeyword(string $sieveFlag): string
    {
        $sieveFlag = \ltrim($sieveFlag, '\\');
        return '$' . \strtolower($sieveFlag);
    }
}
