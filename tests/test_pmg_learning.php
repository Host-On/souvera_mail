<?php

declare(strict_types=1);

/**
 * Static contract tests for the PMG learning integration (PROXMOX_SPAM.md).
 * Run: php tests/test_pmg_learning.php
 */

$passes = 0;
$failures = 0;

function ok(bool $cond, string $label, int &$passes, int &$failures): void
{
    if ($cond) {
        $passes++;
        echo "  ok   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL {$label}\n";
    }
}

echo "== PMG learning integration ==\n";

$svc = (string) file_get_contents(__DIR__ . '/../lib/Service/PmgLearningService.php');
$rpt = (string) file_get_contents(__DIR__ . '/../lib/Service/PmgReportService.php');
$ctl = (string) file_get_contents(__DIR__ . '/../lib/Controller/PmgController.php');
$mig = (string) file_get_contents(__DIR__ . '/../lib/Migration/Version001910Date20260905120000.php');
$routes = (string) file_get_contents(__DIR__ . '/../appinfo/routes.php');
$proxy = (string) file_get_contents(__DIR__ . '/../lib/Service/V2JmapProxy.php');
$pmgClient = (string) @file_get_contents(__DIR__ . '/../src-v2/composables/usePmgClient.js');
$mailHome = (string) @file_get_contents(__DIR__ . '/../src-v2/views/MailHomeView.vue');
$spamList = (string) @file_get_contents(__DIR__ . '/../src-v2/views/SpamListView.vue');
$shieldView = (string) @file_get_contents(__DIR__ . '/../src-v2/views/ShieldView.vue');
$info = (string) file_get_contents(__DIR__ . '/../appinfo/info.xml');
// ---- PmgLearningService (transport) ----
ok(str_contains($svc, chr(39) . '/v1/' . chr(39)), 'learn URL pattern /v1/{mode}/{class}', $passes, $failures);
ok(str_contains($svc, "'X-API-Token'"), 'sends X-API-Token header', $passes, $failures);
ok(str_contains($svc, "'Content-Type' => 'message/rfc822'"), 'sends message/rfc822 content type', $passes, $failures);
ok(str_contains($svc, 'TIMEOUT_SECONDS = 90'), '90s timeout (5-node SSH fanout)', $passes, $failures);
ok(str_contains($svc, 'MIN_BYTES = 400'), 'min size 400 bytes enforced', $passes, $failures);
ok(str_contains($svc, 'MAX_BYTES = 10 * 1024 * 1024'), 'max size 10 MB enforced', $passes, $failures);
ok(str_contains($svc, '207'), 'partial failure (207) handled', $passes, $failures);
ok(str_contains($svc, 'error') && str_contains($svc, 'token missing'), 'unconfigured state surfaces as error', $passes, $failures);

// ---- Report service (revert-vs-ham logic) ----
ok(str_contains($rpt, "findLatestByHash"), 'report service looks up own reports', $passes, $failures);
ok(str_contains($rpt, "'forget'") && str_contains($rpt, "deleteByUserAndHash"), 'own spam report is reverted (forget + tracking delete)', $passes, $failures);
ok(str_contains($rpt, "learn('ham', 'learn'"), 'system-sorted mail is trained as ham (false positive)', $passes, $failures);
ok(str_contains($rpt, 'fetchRawMailBytes'), 'raw mail fetched via JMAP blob (original RFC 822)', $passes, $failures);

// ---- Migration ----
ok(str_contains($mig, 'souvera_mail_pmg_reports'), 'migration creates tracking table', $passes, $failures);
ok(str_contains($mig, 'message_id_hash'), 'tracking table has message_id_hash (PMG match key)', $passes, $failures);

// ---- Routes / controller ----
ok(str_contains($routes, "'pmg#report'"), 'routes register pmg#report', $passes, $failures);
ok(str_contains($routes, "'pmg#forget'"), 'routes register pmg#forget', $passes, $failures);
ok(str_contains($routes, "'pmg#status'"), 'routes register pmg#status', $passes, $failures);
ok(str_contains($ctl, 'requireUserId'), 'controller requires authenticated user', $passes, $failures);

// ---- v1.2.55 — Shield-Quarantäne-HAM + Frontend-Wiring ----
ok(str_contains($routes, "'pmg#reportShieldHam'"), 'routes register pmg#reportShieldHam', $passes, $failures);
ok(str_contains($routes, '/api/v2/pmg/report/ham-shield'), 'routes register /api/v2/pmg/report/ham-shield', $passes, $failures);
ok(str_contains($ctl, 'reportShieldHam'), 'controller exposes reportShieldHam', $passes, $failures);
ok(str_contains($ctl, 'httpClientService'), 'controller injects IClientService', $passes, $failures);
ok(str_contains($ctl, 'urlGenerator'), 'controller injects IURLGenerator', $passes, $failures);
ok(str_contains($ctl, 'forwardSessionCookies'), 'controller forwards session cookies to Shield', $passes, $failures);
ok(str_contains($ctl, 'getAbsoluteURL'), 'controller builds absolute Shield URL', $passes, $failures);
ok(str_contains($ctl, 'spam/raw'), 'controller fetches Shield internal spam/raw', $passes, $failures);
ok(str_contains($ctl, 'base64_decode'), 'controller decodes base64 Shield EML', $passes, $failures);
ok(str_contains($ctl, 'requesttoken'), 'controller forwards request token', $passes, $failures);
ok(str_contains($rpt, 'getCurrentAccountId'), 'report service resolves accountId from JMAP context', $passes, $failures);
ok(str_contains($rpt, 'reportRestoredFromJunk') && str_contains($rpt, 'reverted'), 'reportRestoredFromJunk delegates and flags reverted', $passes, $failures);
ok(str_contains($rpt, "findLatestByHash") && str_contains($rpt, "'forget'"), 'ham report distinguishes revert (findLatestByHash + forget)', $passes, $failures);
ok($pmgClient !== false && $pmgClient !== '', 'src-v2/usePmgClient.js exists', $passes, $failures);
ok(str_contains($pmgClient, '/api/v2/pmg/report/spam'), 'usePmgClient reports spam endpoint', $passes, $failures);
ok(str_contains($pmgClient, '/api/v2/pmg/report/ham'), 'usePmgClient reports ham endpoint', $passes, $failures);
ok(str_contains($pmgClient, '/api/v2/pmg/report/ham-shield'), 'usePmgClient reports shield-ham endpoint', $passes, $failures);
ok(str_contains($pmgClient, 'console.error'), 'usePmgClient is fire-and-forget (logs only)', $passes, $failures);
ok(str_contains($mailHome, 'usePmgClient') && str_contains($mailHome, 'reportSpam'), 'MailHomeView wires PMG reports', $passes, $failures);
ok(str_contains($mailHome, 'pmgReportForMove'), 'MailHomeView reports on move (junk in/out)', $passes, $failures);
ok(str_contains($spamList, 'reportShieldHam') && str_contains($spamList, 'reportHam'), 'SpamListView wires release→ham reports', $passes, $failures);
ok(str_contains($shieldView, 'reportSpam') && str_contains($shieldView, 'reportHam'), 'ShieldView wires report actions', $passes, $failures);
ok(str_contains($info, '<version>1.2.55</version>'), 'info.xml bumped to 1.2.55', $passes, $failures);
echo "\n{$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
