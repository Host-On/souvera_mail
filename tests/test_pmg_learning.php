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
echo "\n{$passes} passed, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
