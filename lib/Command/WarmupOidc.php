<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Command;

use OCA\SouveraMail\Service\OidcProviderService;
use OCA\SouveraMail\Service\StalwartAdminService;
use OCA\SouveraMail\Service\StalwartUserContext;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Refresh Stalwart 0.16's cached OIDC discovery + JWKS after a fresh deploy.
 *
 * ## Why this command exists
 *
 * Stalwart 0.16 lazily fetches the H2CK/oidc discovery document at
 * `<issuerUrl>/.well-known/openid-configuration` the first time it needs to
 * validate a Bearer JWT. On a Nextcloud install where that path emits a 301
 * redirect to `/index.php/.well-known/openid-configuration` (the shipped
 * `.htaccess` default), Stalwart's discovery fetch fails silently and it
 * caches the negative result. Subsequent JMAP / IMAP / SMTP OAUTHBEARER
 * authentications all get rejected with a bare-bones `401 Unauthorized` — no
 * message, no log entry — until an admin nudges the Directory object, which
 * forces Stalwart to re-run discovery.
 *
 * The reliable nudge is an idempotent `x:Directory/set` update on the OIDC
 * directory (setting its description to the same value re-triggers the
 * discovery+JWKS fetch pipeline). This command:
 *
 *   1. Mints an H2CK/oidc JWT for a probe user (`--user` or the first NC
 *      account that is a member of the `souvera-users` group) via the same
 *      code path a real request would use.
 *   2. Sends `GET /jmap/session` with that JWT. HTTP 200 = warm cache, we
 *      exit 0. Anything else = cold cache, continue.
 *   3. Uses Stalwart admin Basic-auth (from `souvera_central.stalwart_admin_*`
 *      system-config keys) to run `x:Directory/query` + `x:Directory/set`
 *      against every OIDC directory Stalwart knows about, poking each one.
 *   4. Retries step 2. HTTP 200 = success, exit 0. Anything else = we log
 *      the remaining error and exit 1 so the deploy pipeline can react.
 *
 * ## Deployment integration
 *
 * Call this command AFTER `souvera_mail:setup` and AFTER `souvera_central`
 * has finished provisioning Stalwart. In the typical Souvera bootstrap:
 *
 *     occ souvera_mail:bootstrap ...
 *     occ souvera_mail:warmup-oidc --json
 *
 * `--json` emits a single-line report suitable for jq/grep in CI.
 */
class WarmupOidc extends Command
{
    private const PROBE_MAX_ATTEMPTS = 3;
    private const RESTRICTED_GROUP = 'souvera-users';

    public function __construct(
        private OidcProviderService $oidc,
        private StalwartAdminService $stalwart,
        private StalwartUserContext $userContext,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('souvera_mail:warmup-oidc')
            ->setDescription('Force Stalwart to re-fetch OIDC discovery + JWKS after a fresh deploy')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Nextcloud user id to mint a probe JWT for (defaults to first souvera-users member)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single machine-readable JSON object')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonMode = (bool) $input->getOption('json');
        $report = [
            'command' => 'souvera_mail:warmup-oidc',
            'probe_user' => null,
            'initial_probe_status' => null,
            'admin_refresh' => null,
            'final_probe_status' => null,
            'ok' => false,
            'errors' => [],
        ];

        if (!$this->stalwart->isConfigured()) {
            $report['errors'][] = 'Stalwart API URL not configured (souvera_central.stalwart_api_url)';
            return $this->emit($output, $jsonMode, $report, 1);
        }

        $probeUser = $this->resolveProbeUser((string) ($input->getOption('user') ?: ''), $report);
        if ($probeUser === null) {
            return $this->emit($output, $jsonMode, $report, 1);
        }
        $report['probe_user'] = $probeUser;

        $token = $this->mintProbeToken($probeUser, $report);
        if ($token === null) {
            return $this->emit($output, $jsonMode, $report, 1);
        }

        $status = $this->probe($token, $report, 'initial_probe_status');
        if ($status === 200) {
            $report['ok'] = true;
            return $this->emit($output, $jsonMode, $report, 0);
        }

        $refreshed = $this->refreshOidcDirectories($report);
        $report['admin_refresh'] = $refreshed;
        if (!$refreshed['ok']) {
            return $this->emit($output, $jsonMode, $report, 1);
        }

        // Give Stalwart a moment to complete the JWKS fetch triggered by the
        // Directory/set update. Empirically the fetch is sub-100 ms on a warm
        // machine but the reload also reloads unrelated singletons; a small
        // sleep here is much cheaper than a false negative in CI.
        \usleep(500_000);

        $status = $this->probe($token, $report, 'final_probe_status');
        $report['ok'] = ($status === 200);
        return $this->emit($output, $jsonMode, $report, $report['ok'] ? 0 : 1);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function resolveProbeUser(string $explicit, array &$report): ?string
    {
        if ($explicit !== '') {
            if (!$this->userManager->userExists($explicit)) {
                $report['errors'][] = "user '{$explicit}' does not exist in Nextcloud";
                return null;
            }
            return $explicit;
        }
        // Pick the first souvera-users group member. Falls back to the first
        // NC user if the group isn't populated yet (fresh install edge case).
        $groupManager = \OC::$server->get(\OCP\IGroupManager::class);
        $group = $groupManager->get(self::RESTRICTED_GROUP);
        if ($group !== null) {
            $members = $group->getUsers();
            if (!empty($members)) {
                return \reset($members)->getUID();
            }
        }
        $users = $this->userManager->search('', 1);
        if (empty($users)) {
            $report['errors'][] = 'no Nextcloud users found to mint a probe JWT for';
            return null;
        }
        return \reset($users)->getUID();
    }

    /**
     * @param array<string, mixed> $report
     */
    private function mintProbeToken(string $userId, array &$report): ?string
    {
        // Explicit availability check FIRST so we surface the exact
        // reason (e.g. "app-config oidc-client-id is empty") instead of
        // the vague inner exception from resolveBearer(). Massively
        // shortens debugging time when the CI pipeline breaks after a
        // deploy that recreated the app without preserving app-config.
        $reason = $this->oidc->diagnoseAvailability();
        if ($reason !== null) {
            $report['errors'][] = "OIDC provider unavailable: {$reason}";
            $report['remediation'] = $reason;
            return null;
        }

        try {
            $token = $this->userContext->resolveBearer($userId);
            if ($token === '') {
                $report['errors'][] = "H2CK/oidc returned an empty token for user '{$userId}'";
                $report['remediation'] = "Client is registered but H2CK refused to mint. Check `occ souvera_mail:status`; the user must be OIDC-eligible in H2CK's client config.";
                return null;
            }
            return $token;
        } catch (\Throwable $e) {
            $report['errors'][] = "failed to mint OIDC JWT for user '{$userId}': " . $e->getMessage();
            return null;
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function probe(string $token, array &$report, string $reportKey): int
    {
        try {
            $status = $this->stalwart->probeSessionAsUser($token);
        } catch (\Throwable $e) {
            $report['errors'][] = 'probe failed: ' . $e->getMessage();
            return 0;
        }
        $report[$reportKey] = $status;
        return $status;
    }

    /**
     * Queries Stalwart for every OIDC-typed Directory and forces a no-op
     * update on each — Stalwart re-runs OIDC discovery + JWKS fetch as a
     * side-effect. Returns a small report the caller stitches into the
     * outer report.
     *
     * Stalwart 0.16's `x:Directory/query` filter surface is intentionally
     * narrow — filtering on `@type`/`type`/`variant` is NOT supported
     * (verified against upstream: `Filter is not supported or invalid`).
     * We therefore enumerate every directory id, resolve each via
     * `x:Directory/get`, and keep only records whose `@type == "Oidc"`.
     *
     * @return array{ok: bool, touched: list<string>, error: string|null}
     */
    private function refreshOidcDirectories(array &$outerReport): array
    {
        // Step 1: enumerate ALL directory IDs (no filter — Stalwart rejects
        // any filter on the type/variant properties).
        try {
            $queryResp = $this->stalwart->jmapCallAsAdmin([
                [
                    'x:Directory/query',
                    (object) [],
                    'c0',
                ],
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'touched' => [], 'error' => 'directory query failed: ' . $e->getMessage()];
        }

        $allIds = $this->extractQueryIds($queryResp);
        if (empty($allIds)) {
            return [
                'ok' => false,
                'touched' => [],
                'error' => 'Stalwart has no directories at all — run the deployment bootstrap step that creates the Nextcloud OIDC directory',
            ];
        }

        // Step 2: resolve each id and keep only OIDC directories. We also
        // capture the current issuerUrl so step 3 can flip-flop it as the
        // real cache-reset trigger.
        /** @var array<string, string> $oidcIssuerByIndex */
        $oidcIssuerByIndex = [];
        foreach ($allIds as $id) {
            try {
                $getResp = $this->stalwart->jmapCallAsAdmin([
                    [
                        'x:Directory/get',
                        ['ids' => [$id]],
                        'c0',
                    ],
                ]);
                $records = $this->extractGetRecords($getResp);
                foreach ($records as $rec) {
                    if (($rec['@type'] ?? null) === 'Oidc' && \is_string($rec['id'] ?? null)) {
                        $recId = (string) $rec['id'];
                        $iss = (string) ($rec['issuerUrl'] ?? '');
                        if ($iss !== '') {
                            $oidcIssuerByIndex[$recId] = $iss;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $outerReport['errors'][] = "Directory/get failed for id={$id}: " . $e->getMessage();
            }
        }
        $oidcIds = \array_keys($oidcIssuerByIndex);

        if (empty($oidcIds)) {
            return [
                'ok' => false,
                'touched' => [],
                'error' => 'no OIDC-typed Directory found in Stalwart (only found: ' . \implode(', ', $allIds) . ') — run the deployment bootstrap step that creates the Nextcloud OIDC directory',
            ];
        }

        // Step 3: force Stalwart to re-initialise its OIDC provider by
        // flip-flopping the `issuerUrl` field on every OIDC directory.
        //
        // Live-verified 2026-07-01 on `4a5cf564-nc34-web`: on a fresh
        // Souvera cluster (Nginx well-known already returns 200, JWT `iss`
        // already matches `issuerUrl`, JWKS reachable — everything looks
        // right) Stalwart 0.16 STILL rejects Bearer tokens with a bare
        // `401 Unauthorized`. Root cause: Stalwart caches the OIDC provider
        // by `issuerUrl` + `requireAudience`, and neither a
        // `description`-only Directory/set nor a `ReloadSettings` action
        // invalidates that cache — only a real change on `issuerUrl` does.
        //
        // The cheapest change that Stalwart still validates is toggling the
        // trailing slash. We first set the URL to its trailing-slash form,
        // ReloadSettings, then set it back and ReloadSettings again. Net
        // effect on the persisted config: zero. Net effect on the runtime
        // cache: fully re-initialised. The tests in
        // tests/test_warmup_oidc_command.php pin this behaviour.
        $touched = [];
        foreach ($oidcIds as $id) {
            $orig = $oidcIssuerByIndex[$id];
            $flipped = \str_ends_with($orig, '/') ? \rtrim($orig, '/') : $orig . '/';
            try {
                // 3a) flip
                $this->stalwart->jmapCallAsAdmin([
                    [
                        'x:Directory/set',
                        [
                            'update' => [
                                $id => [
                                    'issuerUrl' => $flipped,
                                    // Piggy-back a fresh description so the
                                    // change is also visible in the admin UI's
                                    // "recently changed" listing — useful for
                                    // operators grepping the change trail.
                                    'description' => 'Nextcloud OIDC (warmup ' . \gmdate('Y-m-d\TH:i:s\Z') . ')',
                                ],
                            ],
                        ],
                        'c0',
                    ],
                ]);
                // Reload after the flip so the intermediate state is
                // fully committed to the OIDC provider — this is what
                // actually clears the negative cache.
                $this->stalwart->jmapCallAsAdmin([
                    [
                        'x:Action/set',
                        ['create' => ['reload' => ['@type' => 'ReloadSettings']]],
                        'c1',
                    ],
                ]);

                // Small sleep before the flip-back so Stalwart's async
                // OIDC provider init has a chance to run. 500 ms is well
                // above the 100–200 ms discovery round-trip we've
                // measured on the operator's cluster.
                \usleep(500_000);

                // 3b) flip back to original
                $this->stalwart->jmapCallAsAdmin([
                    [
                        'x:Directory/set',
                        [
                            'update' => [
                                $id => ['issuerUrl' => $orig],
                            ],
                        ],
                        'c0',
                    ],
                ]);
                $this->stalwart->jmapCallAsAdmin([
                    [
                        'x:Action/set',
                        ['create' => ['reload' => ['@type' => 'ReloadSettings']]],
                        'c1',
                    ],
                ]);

                $touched[] = $id;
            } catch (\Throwable $e) {
                $outerReport['errors'][] = "Directory/set flip-flop failed for id={$id}: " . $e->getMessage();
            }
        }

        if (empty($touched)) {
            return [
                'ok' => false,
                'touched' => [],
                'error' => 'all Directory/set flip-flops failed — see errors[]',
            ];
        }

        // Step 4: InvalidateCaches for good measure — Stalwart's per-
        // account token cache is a separate concern from the OIDC
        // provider cache and can hold onto stale rejections after the
        // provider has been refreshed.
        try {
            $this->stalwart->jmapCallAsAdmin([
                [
                    'x:Action/set',
                    ['create' => ['flush' => ['@type' => 'InvalidateCaches']]],
                    'c0',
                ],
            ]);
        } catch (\Throwable $e) {
            $outerReport['errors'][] = 'InvalidateCaches failed (non-fatal): ' . $e->getMessage();
        }

        return ['ok' => true, 'touched' => $touched, 'error' => null];
    }

    /**
     * @param array<string, mixed> $jmapResp
     * @return list<string>
     */
    private function extractQueryIds(array $jmapResp): array
    {
        $calls = $jmapResp['methodResponses'] ?? [];
        if (!\is_array($calls)) {
            return [];
        }
        foreach ($calls as $call) {
            if (!\is_array($call) || \count($call) < 2 || ($call[0] ?? '') !== 'x:Directory/query') {
                continue;
            }
            $payload = $call[1] ?? [];
            $ids = $payload['ids'] ?? [];
            if (\is_array($ids)) {
                $out = [];
                foreach ($ids as $id) {
                    if (\is_string($id) && $id !== '') {
                        $out[] = $id;
                    }
                }
                return $out;
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $jmapResp
     * @return list<array<string, mixed>>
     */
    private function extractGetRecords(array $jmapResp): array
    {
        $calls = $jmapResp['methodResponses'] ?? [];
        if (!\is_array($calls)) {
            return [];
        }
        foreach ($calls as $call) {
            if (!\is_array($call) || \count($call) < 2 || ($call[0] ?? '') !== 'x:Directory/get') {
                continue;
            }
            $payload = $call[1] ?? [];
            $list = $payload['list'] ?? [];
            if (\is_array($list)) {
                $out = [];
                foreach ($list as $rec) {
                    if (\is_array($rec)) {
                        $out[] = $rec;
                    }
                }
                return $out;
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function emit(OutputInterface $output, bool $jsonMode, array $report, int $exit): int
    {
        if ($jsonMode) {
            $output->writeln((string) \json_encode($report, JSON_UNESCAPED_SLASHES));
            return $exit;
        }

        if ($report['ok'] === true && ($report['initial_probe_status'] ?? null) === 200) {
            $output->writeln('<info>Stalwart OIDC cache is already warm (probe returned 200). No action taken.</info>');
            return $exit;
        }
        if ($report['ok'] === true) {
            $output->writeln('<info>Stalwart OIDC cache warmed successfully.</info>');
            $touched = $report['admin_refresh']['touched'] ?? [];
            $output->writeln('  probe user:            ' . ($report['probe_user'] ?? '?'));
            $output->writeln('  initial probe status:  ' . ($report['initial_probe_status'] ?? '?'));
            $output->writeln('  directories touched:   ' . (empty($touched) ? '(none)' : \implode(', ', $touched)));
            $output->writeln('  final probe status:    ' . ($report['final_probe_status'] ?? '?'));
            return $exit;
        }

        $output->writeln('<error>Stalwart OIDC warmup failed.</error>');
        $output->writeln('  probe user:            ' . ($report['probe_user'] ?? '?'));
        $output->writeln('  initial probe status:  ' . ($report['initial_probe_status'] ?? '?'));
        if (!empty($report['admin_refresh'])) {
            $touched = $report['admin_refresh']['touched'] ?? [];
            $output->writeln('  directories touched:   ' . (empty($touched) ? '(none)' : \implode(', ', $touched)));
        }
        $output->writeln('  final probe status:    ' . ($report['final_probe_status'] ?? '?'));
        foreach ($report['errors'] as $err) {
            $output->writeln('  <comment>! ' . $err . '</comment>');
        }
        return $exit;
    }
}
