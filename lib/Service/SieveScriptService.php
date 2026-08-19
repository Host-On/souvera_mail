<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * JMAP-backed implementation of Stalwart 0.16's `SieveScript` object family.
 *
 * Why we exist
 * ------------
 * Operator-reported (PRD step 23, 2026-07-01): Settings → Filters in the
 * webmail engine consistently surfaces engine-notification 352 (`CantGetFilters`)
 * against the operator's Stalwart 0.16 deploy. The engine's default
 * `SieveStorage` provider dials ManageSieve port 4190, which means
 * SASL-over-the-wire, a separate listener config inside Stalwart, and a
 * dedicated SSL/STARTTLS triple — three independent failure points that
 * the operator cannot inspect from the engine error.
 *
 * Stalwart 0.16 exposes the same scripts via JMAP under
 * `urn:ietf:params:jmap:sieve`. We talk to that endpoint with the SAME
 * H2CK/oidc-issued JWT bearer we already use for App Passwords and Quota.
 * Bypasses ManageSieve entirely; no extra listener, no extra TLS triple,
 * no extra SASL roundtrip. One transport ({@see StalwartAdminService})
 * for everything.
 *
 * Stalwart's wire format (verified against Stalwart 0.16 source +
 * `stalw.art/docs/sieve/jmap/`):
 *   - `SieveScript/get`     → `{ list: [{ id, name, blobId, isActive }] }`
 *   - `SieveScript/set`     → `create`/`update`/`destroy` like every JMAP
 *                              object; `onSuccessActivateScript` activates
 *                              a freshly-created/updated script atomically.
 *   - `SieveScript/validate`→ syntax check (no persist); returns null on
 *                              success or an `invalidSieve` error object.
 *
 * Script body uploads use the standard JMAP blob endpoint. Per JMAP
 * RFC 8620 §6.1 the `uploadUrl` template is **path-style** —
 * `<api>/jmap/upload/{accountId}/`, NOT a `?account=<id>` query string.
 * Stalwart returns `blobId` which we then reference from
 * `SieveScript/set.create`.
 * (v0.14.36 fix: earlier the docblock claimed `?account=` — that was a
 *  RainLoop-era copy-paste that never worked against real Stalwart. The
 *  a 2026-02 diagnosis caught the resulting 404 in
 *  production.)
 *
 * Script body downloads use `Blob/get` with `properties: ["data:asText"]`,
 * keeping everything on the same JMAP envelope.
 */
class SieveScriptService
{
    public const CAPABILITY_SIEVE = 'urn:ietf:params:jmap:sieve';
    private const HTTP_TIMEOUT_SECONDS = 10;

    /** Reserved script name for the combined active script (one active
     *  script per account is a Stalwart hard limit — see rebuildActiveScript). */
    public const MAIN_SCRIPT_NAME = 'souvera_filters';
    /** User-pref (JSON array of script names) for filters the user
     *  explicitly switched OFF. */
    private const PREF_DISABLED = 'pref_sieve_disabled';

    public function __construct(
        private StalwartAdminService $stalwart,
        private StalwartUserContext $userContext,
        private IClientService $clientService,
        private LoggerInterface $logger,
        private \OCP\IConfig $config,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->stalwart->isConfigured() && $this->userContext->isAvailable();
    }

    /**
     * List scripts for the user. Each entry includes the body so that the
     * engine's `Load()` contract is satisfied in a single round-trip (the
     * legacy `SieveStorage::Load` did N+1 GetScript calls — we collapse
     * those into one chained JMAP envelope using JMAP back-references).
     *
     * @return array{
     *     scripts: list<array{id: string, name: string, blobId: string, isActive: bool, body: string}>,
     *     accountId: string,
     *     bearer: string
     * }
     */
    public function listScriptsWithBodies(string $userId): array
    {
        $accountId = $this->userContext->resolveAccountId($userId);
        $bearer = $this->userContext->resolveBearer($userId);

        // 1. SieveScript/get → metadata (id, name, blobId, isActive)
        // 2. Blob/get with #blobIds back-reference → bodies as text
        $response = $this->stalwart->jmapCall(
            $bearer,
            [
                [
                    'SieveScript/get',
                    [
                        'accountId' => $accountId,
                        'properties' => ['id', 'name', 'blobId', 'isActive'],
                    ],
                    'c0',
                ],
                [
                    'Blob/get',
                    [
                        'accountId' => $accountId,
                        '#ids' => [
                            'resultOf' => 'c0',
                            'name' => 'SieveScript/get',
                            'path' => '/list/*/blobId',
                        ],
                        'properties' => ['data:asText'],
                    ],
                    'c1',
                ],
            ],
            [self::CAPABILITY_SIEVE, 'urn:ietf:params:jmap:blob']
        );

        $scriptsResp = $this->stalwart->extractMethodResponse($response, 'SieveScript/get');
        $rawList = \is_array($scriptsResp['list'] ?? null) ? $scriptsResp['list'] : [];

        // Blob/get is best-effort — when there are zero scripts, Stalwart
        // omits it. We tolerate a missing `Blob/get` entry by treating it
        // as an empty bodies map (engine will render empty body).
        $bodiesById = [];
        try {
            $blobsResp = $this->stalwart->extractMethodResponse($response, 'Blob/get');
            foreach ((array) ($blobsResp['list'] ?? []) as $entry) {
                if (\is_array($entry) && isset($entry['id'])) {
                    $bodiesById[(string) $entry['id']] = (string) ($entry['data:asText'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            // No bodies — proceed with empty map.
            $this->logger->debug(
                'Souvera Mail: SieveScriptService Blob/get returned no list (likely zero scripts): '
                . $e->getMessage(),
                ['app' => 'souvera_mail']
            );
        }

        $scripts = [];
        $disabled = $this->getDisabledFilters($userId);
        foreach ($rawList as $entry) {
            if (!\is_array($entry) || !isset($entry['id'])) {
                continue;
            }
            $blobId = (string) ($entry['blobId'] ?? '');
            $name = (string) ($entry['name'] ?? '');
            $scripts[] = [
                'id' => (string) $entry['id'],
                'name' => $name,
                'blobId' => $blobId,
                'isActive' => (bool) ($entry['isActive'] ?? false),
                // Individual scripts are only EXECUTED as part of the
                // combined main script — "enabled" is the UI-visible state.
                'enabled' => $name !== self::MAIN_SCRIPT_NAME && !\in_array($name, $disabled, true),
                'isMain' => $name === self::MAIN_SCRIPT_NAME,
                'body' => $bodiesById[$blobId] ?? '',
            ];
        }

        return [
            'scripts' => $scripts,
            'accountId' => $accountId,
            'bearer' => $bearer,
        ];
    }

    /** @return list<string> */
    public function getDisabledFilters(string $userId): array
    {
        $raw = (string) $this->config->getUserValue($userId, 'souvera_mail', self::PREF_DISABLED, '');
        $list = \json_decode($raw, true);
        if (!\is_array($list)) {
            return [];
        }
        return \array_values(\array_filter(\array_map('strval', $list), fn($n) => \trim($n) !== ''));
    }

    /** @param list<string> $names */
    public function setDisabledFilters(string $userId, array $names): void
    {
        $clean = \array_values(\array_unique(\array_filter(\array_map('strval', $names), fn($n) => \trim($n) !== '')));
        $this->config->setUserValue(
            $userId,
            'souvera_mail',
            self::PREF_DISABLED,
            \json_encode($clean, JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Rebuild and activate the COMBINED script from all enabled filter
     * scripts. Stalwart executes at most ONE active script per account,
     * so per-filter scripts are merged (in their stored order) into the
     * reserved main script which is then activated. Individual scripts
     * become inactive — their UI state is carried by the "enabled" flag
     * (user pref) instead of Stalwart's isActive.
     *
     * The managed VACATION block (VacationService) is carried over from
     * whichever script currently holds it, so a rebuild never drops the
     * out-of-office responder.
     *
     * @return array{success: bool, filters: int, active: bool}
     */
    public function rebuildActiveScript(string $userId): array
    {
        $scripts = $this->listScriptsWithBodies($userId)['scripts'];
        $disabled = $this->getDisabledFilters($userId);

        $blocks = [];
        $capabilities = [];
        $count = 0;
        $vacationRequire = '';
        $vacationBody = '';
        // Vacation lives in the MAIN script — the fallback to other
        // scripts runs EXACTLY ONCE: only while no main script exists
        // yet (first rebuild after the merge model was introduced).
        // Once the main script exists it is the single source of truth,
        // so a VacationService::set(false) that strips the responder
        // from the main script can never be reverted by stale legacy
        // content in old per-filter scripts.
        $hasMain = false;
        foreach ($scripts as $s) {
            if ($s['isMain']) $hasMain = true;
        }
        foreach ($scripts as $s) {
            if (!$s['isMain']) continue;
            $vacationRequire = \OCA\SouveraMail\Service\VacationService::extractManagedRequire((string) ($s['body'] ?? ''));
            $vacationBody = \OCA\SouveraMail\Service\VacationService::extractManagedBody((string) ($s['body'] ?? ''));
        }
        if (!$hasMain && $vacationRequire === '' && $vacationBody === '') {
            foreach ($scripts as $s) {
                if ($s['isMain']) continue;
                $vacationRequire = \OCA\SouveraMail\Service\VacationService::extractManagedRequire((string) ($s['body'] ?? ''));
                $vacationBody = \OCA\SouveraMail\Service\VacationService::extractManagedBody((string) ($s['body'] ?? ''));
                if ($vacationRequire !== '' || $vacationBody !== '') break;
            }
        }

        foreach ($scripts as $s) {
            if ($s['isMain']) continue;
            if (\in_array($s['name'], $disabled, true)) continue;
            $body = \trim((string) ($s['body']));
            if ($body === '') continue;
            // Never carry vacation parts through the FILTER section —
            // they are re-appended once at the end of the merged script.
            $vReq = \OCA\SouveraMail\Service\VacationService::extractManagedRequire($body);
            $vBody = \OCA\SouveraMail\Service\VacationService::extractManagedBody($body);
            if ($vReq !== '') {
                $body = \str_replace(\rtrim($vReq), '', $body);
            }
            if ($vBody !== '') {
                $body = \str_replace(\rtrim($vBody), '', $body);
            }
            $body = \trim($body);
            if ($body === '') continue;
            // A single `require` must head the combined script — collect
            // every capability and strip the per-script require lines
            // (a second require after executable rules is invalid Sieve).
            if (\preg_match_all('/^\s*require\s*\[([^\]]*)\]\s*;.*$/m', $body, $m) > 0) {
                foreach ($m[1] as $caps) {
                    foreach (\preg_split('/,\s*/', \trim($caps)) as $cap) {
                        $cap = \trim($cap, " \t\"'");
                        if ($cap !== '') $capabilities[$cap] = true;
                    }
                }
                $body = \preg_replace('/^\s*require\s*\[[^\]]*\]\s*;.*\n?/m', '', $body);
                $body = \trim($body);
                if ($body === '') continue;
            }
            $blocks[] = '# --- ' . $s['name'] . " ---\n" . $body;
            $count++;
        }

        // Composition (RFC 5228: ALL requires before any other command):
        //   vacation's marker require line first, then the capability
        //   union, then the filter blocks, then the vacation block.
        $merged = '';
        if ($vacationRequire !== '') {
            $merged .= $vacationRequire; // already ends with "\n"
        }
        if ($capabilities !== []) {
            $merged .= 'require ["' . \implode('", "', \array_keys($capabilities)) . "\"];\n";
        }
        if ($merged !== '') {
            $merged .= "\n";
        }
        $merged .= $blocks !== []
            ? \implode("\n\n", $blocks)
            : '# Souvera Mail — no active filters';
        if ($vacationBody !== '') {
            $merged .= "\n\n" . \rtrim($vacationBody);
        }

        $this->saveScript($userId, self::MAIN_SCRIPT_NAME, $merged);
        $this->activateScript($userId, self::MAIN_SCRIPT_NAME);
        return ['success' => true, 'filters' => $count, 'active' => $count > 0, 'skipped' => $skipped];
    }

    /**
     * Upload script content as a JMAP blob and return the new `blobId`.
     * Uses the path-style JMAP `uploadUrl` per RFC 8620 §6.1:
     * `POST <api>/jmap/upload/<accountId>/` with `Content-Type:
     * application/octet-stream` and the raw script bytes as the body.
     *
     * v0.14.36 (2026-02 diagnosis): earlier this method
     * built `?account=<id>` — Stalwart 0.16 does NOT expose that syntax
     * and returns 404 Not Found. The URL is now the RFC-conformant
     * path-style, and the accountId comes from the JMAP session
     * (`primaryAccounts`) rather than from the truncated
     * souvera_central lookup that was the root cause of the 404.
     */
    public function uploadBlob(string $accountId, string $bearer, string $body): string
    {
        $apiUrl = $this->stalwart->getApiUrl();
        if ($apiUrl === null) {
            throw new \RuntimeException('Stalwart API URL not configured (souvera_central.stalwart_api_url)');
        }
        // Path-style upload URL per JMAP RFC 8620 §6.1 — the `/` after
        // the accountId is REQUIRED by Stalwart (trailing-slash-strict
        // routing in the JMAP handler).
        $url = \rtrim($apiUrl, '/')
             . '/jmap/upload/'
             . \rawurlencode($accountId)
             . '/';

        try {
            $client = $this->clientService->newClient();
            $response = $client->post($url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $bearer,
                    'Content-Type' => 'application/octet-stream',
                    'Accept' => 'application/json',
                ],
                'body' => $body,
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
                'connect_timeout' => self::HTTP_TIMEOUT_SECONDS,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Stalwart JMAP blob upload failed: ' . $e->getMessage(), 0, $e);
        }

        $decoded = \json_decode((string) $response->getBody(), true);
        if (!\is_array($decoded) || !isset($decoded['blobId']) || !\is_string($decoded['blobId'])) {
            throw new \RuntimeException(
                'Stalwart JMAP blob upload returned an unexpected payload: '
                . \substr((string) $response->getBody(), 0, 200)
            );
        }
        return $decoded['blobId'];
    }

    /**
     * Create or update a script by name. The engine's `Save()` semantics are
     * "upsert" — if a script with $name exists, replace its blob; otherwise
     * create. Returns the script's stable `id`.
     */
    public function saveScript(string $userId, string $name, string $body): string
    {
        $name = \trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Sieve script name must not be empty');
        }

        $accountId = $this->userContext->resolveAccountId($userId);
        $bearer = $this->userContext->resolveBearer($userId);
        $blobId = $this->uploadBlob($accountId, $bearer, $body);

        // Resolve existing script-id by name first — JMAP `update` needs the
        // server-side id, not the user-facing name.
        $existing = $this->listScriptsWithBodies($userId)['scripts'];
        $existingId = null;
        foreach ($existing as $entry) {
            if ($entry['name'] === $name) {
                $existingId = $entry['id'];
                break;
            }
        }

        if ($existingId !== null) {
            $resp = $this->stalwart->jmapCall(
                $bearer,
                [
                    [
                        'SieveScript/set',
                        [
                            'accountId' => $accountId,
                            'update' => [
                                $existingId => [
                                    'blobId' => $blobId,
                                ],
                            ],
                        ],
                        'c0',
                    ],
                ],
                [self::CAPABILITY_SIEVE]
            );
            $setResp = $this->stalwart->extractMethodResponse($resp, 'SieveScript/set');
            if (isset($setResp['notUpdated'][$existingId])) {
                throw new \RuntimeException(
                    'Stalwart refused SieveScript/set update: '
                    . \json_encode($setResp['notUpdated'][$existingId], JSON_UNESCAPED_SLASHES)
                );
            }
            return $existingId;
        }

        $creationId = 'k1';
        $resp = $this->stalwart->jmapCall(
            $bearer,
            [
                [
                    'SieveScript/set',
                    [
                        'accountId' => $accountId,
                        'create' => [
                            $creationId => [
                                'name' => $name,
                                'blobId' => $blobId,
                            ],
                        ],
                    ],
                    'c0',
                ],
            ],
            [self::CAPABILITY_SIEVE]
        );
        $setResp = $this->stalwart->extractMethodResponse($resp, 'SieveScript/set');
        if (isset($setResp['notCreated'][$creationId])) {
            throw new \RuntimeException(
                'Stalwart refused SieveScript/set create: '
                . \json_encode($setResp['notCreated'][$creationId], JSON_UNESCAPED_SLASHES)
            );
        }
        $created = $setResp['created'][$creationId] ?? null;
        if (!\is_array($created) || !isset($created['id'])) {
            throw new \RuntimeException(
                'Stalwart did not return the new SieveScript id. Raw response: '
                . \json_encode($setResp, JSON_UNESCAPED_SLASHES)
            );
        }
        return (string) $created['id'];
    }

    /**
     * Activate a script by name (empty name → deactivate all). Stalwart
     * enforces at most one active script per account; assigning isActive=true
     * to one implicitly deactivates the rest.
     */
    public function activateScript(string $userId, string $name): void
    {
        $name = \trim($name);
        $accountId = $this->userContext->resolveAccountId($userId);
        $bearer = $this->userContext->resolveBearer($userId);

        $existing = $this->listScriptsWithBodies($userId)['scripts'];

        $updates = [];
        foreach ($existing as $entry) {
            $shouldActivate = ($name !== '' && $entry['name'] === $name);
            if ($entry['isActive'] !== $shouldActivate) {
                $updates[$entry['id']] = ['isActive' => $shouldActivate];
            }
        }
        if (\count($updates) === 0) {
            return; // Already in the desired state.
        }

        $resp = $this->stalwart->jmapCall(
            $bearer,
            [
                [
                    'SieveScript/set',
                    [
                        'accountId' => $accountId,
                        'update' => $updates,
                    ],
                    'c0',
                ],
            ],
            [self::CAPABILITY_SIEVE]
        );
        $setResp = $this->stalwart->extractMethodResponse($resp, 'SieveScript/set');
        if (!empty($setResp['notUpdated'])) {
            throw new \RuntimeException(
                'Stalwart refused SieveScript/set activate: '
                . \json_encode($setResp['notUpdated'], JSON_UNESCAPED_SLASHES)
            );
        }
    }

    public function deleteScript(string $userId, string $name): void
    {
        $name = \trim($name);
        if ($name === '') {
            return;
        }
        $accountId = $this->userContext->resolveAccountId($userId);
        $bearer = $this->userContext->resolveBearer($userId);

        $existing = $this->listScriptsWithBodies($userId)['scripts'];
        $id = null;
        foreach ($existing as $entry) {
            if ($entry['name'] === $name) {
                $id = $entry['id'];
                break;
            }
        }
        if ($id === null) {
            return; // Idempotent: deleting a missing script is a no-op.
        }

        $resp = $this->stalwart->jmapCall(
            $bearer,
            [
                [
                    'SieveScript/set',
                    [
                        'accountId' => $accountId,
                        'destroy' => [$id],
                    ],
                    'c0',
                ],
            ],
            [self::CAPABILITY_SIEVE]
        );
        $setResp = $this->stalwart->extractMethodResponse($resp, 'SieveScript/set');
        if (!\in_array($id, (array) ($setResp['destroyed'] ?? []), true)) {
            $err = $setResp['notDestroyed'][$id] ?? null;
            throw new \RuntimeException(
                'Stalwart refused SieveScript/set destroy: '
                . ($err !== null ? \json_encode($err, JSON_UNESCAPED_SLASHES) : 'id not in destroyed list')
            );
        }
    }
}
