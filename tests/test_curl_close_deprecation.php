<?php
declare(strict_types=1);

/**
 * PHP 8.5 emits `E_DEPRECATED` on every `curl_close()` call
 * (see the operator-reported log entry from 2026-02-17:
 *  `Function curl_close() is deprecated since 8.5, as it has no effect
 *   since PHP 8.0 at .../StalwartService.php#1013`).
 *
 * The upstream Snappymail engine had exactly one `curl_close()` in
 * `app/smail/v/current/app/libraries/Smail/Engine/http/request/curl.php`
 * (`__doRequest()` finally-block). This test locks in the fix:
 *
 *   1. There must be ZERO literal `curl_close(` occurrences in any
 *      wrapper or engine PHP file we ship.
 *   2. The `finally` block in the CURL request class must still perform
 *      the required cleanup (headers array + body buffer + streamed
 *      bytes reset) — the deprecation fix must not accidentally leak
 *      per-request state into the next call on the same instance.
 *   3. The comment in the finally block must reference PHP 8.5 so a
 *      future maintainer knows why the call was removed.
 */

$repo = \dirname(__DIR__);
$curlPhp = $repo . '/app/smail/v/current/app/libraries/Smail/Engine/http/request/curl.php';

$failures = [];
$ok = 0;
$passed = [];
$fn = static function (bool $cond, string $msg) use (&$failures, &$ok, &$passed): void {
    if ($cond) {
        $ok++;
        $passed[] = $msg;
    } else {
        $failures[] = $msg;
    }
};

// ---------------------------------------------------------------------
// 1. The engine curl.php file exists and parses.
// ---------------------------------------------------------------------
$fn(\is_file($curlPhp), 'engine curl.php exists');

$src = \file_get_contents($curlPhp);
$fn(\is_string($src) && \strlen($src) > 0, 'engine curl.php is readable');

$fn(\str_contains($src, 'class CURL extends'),
    'engine curl.php still defines the CURL class');

// ---------------------------------------------------------------------
// 2. No literal `curl_close(` anywhere in the repo (except comments).
// ---------------------------------------------------------------------
$rii = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($repo, \FilesystemIterator::SKIP_DOTS)
);
$offenders = [];
foreach ($rii as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $path = $f->getPathname();
    // Skip vendor test fixtures / this test itself
    if (\str_contains($path, '/tests/test_curl_close_deprecation.php')) {
        continue;
    }
    // Skip upstream Snappymail vendor blobs we don't touch
    if (\str_contains($path, '/app/smail/v/current/app/libraries/MailSo/Vendor/')) {
        continue;
    }
    $body = \file_get_contents($path);
    if ($body === false) {
        continue;
    }
    // Strip single- and multi-line comments (rough) so a comment mention
    // like the one we ourselves left in curl.php doesn't count.
    $stripped = \preg_replace('#/\*.*?\*/#s', '', $body);
    $stripped = \preg_replace('#//[^\n]*#', '', $stripped);
    $stripped = \preg_replace('/#[^\n]*/', '', $stripped);
    if (\preg_match('/\bcurl_close\s*\(/', $stripped)) {
        $offenders[] = $path;
    }
}
$fn(
    $offenders === [],
    'no live curl_close() call anywhere in the repo — got: ' . \implode(', ', $offenders)
);

// ---------------------------------------------------------------------
// 3. The finally block still performs the required cleanup.
// ---------------------------------------------------------------------
$fn(
    (bool) \preg_match('/finally\s*\{[^}]*response_headers\s*=\s*array\(\)/s', $src),
    'finally block still resets response_headers to []'
);
$fn(
    (bool) \preg_match("/finally\s*\{[^}]*response_body\s*=\s*''/s", $src),
    'finally block still resets response_body to empty string'
);
$fn(
    (bool) \preg_match('/finally\s*\{[^}]*streamed_bytes\s*=\s*0/s', $src),
    'finally block still resets streamed_bytes counter to 0'
);

// ---------------------------------------------------------------------
// 4. The removal reason must be documented inline so nobody
//    "restores" the call in a future refactor.
// ---------------------------------------------------------------------
$fn(
    \str_contains($src, 'PHP 8.5') || \str_contains($src, '8.5'),
    'inline comment mentions PHP 8.5 (documents why the call was removed)'
);
$fn(
    \str_contains($src, 'no-op') || \str_contains($src, 'deprecated'),
    'inline comment mentions no-op or deprecated'
);

// ---------------------------------------------------------------------
// 5. `php -l` on the modified file
// ---------------------------------------------------------------------
$out = [];
$rc = 0;
\exec('php -l ' . \escapeshellarg($curlPhp) . ' 2>&1', $out, $rc);
$fn(
    $rc === 0,
    'php -l clean on engine curl.php: ' . \implode(' | ', $out)
);

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
$total = $ok + \count($failures);
echo "Passed: {$ok}/{$total}\n";
if ($failures !== []) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "ALL TESTS PASSED\n";
