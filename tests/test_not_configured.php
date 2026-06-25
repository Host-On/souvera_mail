<?php
/**
 * Standalone verification test for templates/not_configured.php
 * - Stubs OCP\Util::addStyle as a no-op
 * - Stubs IL10N via an anonymous class with t() pass-through
 * - Renders template for isAdmin=true and isAdmin=false
 * - Asserts presence/absence of required substrings
 */

declare(strict_types=1);

$failures = [];
$passes = [];

function assert_true(bool $cond, string $msg, array &$passes, array &$failures): void {
    if ($cond) {
        $passes[] = $msg;
        echo "PASS: $msg\n";
    } else {
        $failures[] = $msg;
        echo "FAIL: $msg\n";
    }
}

// Define p() helper that NC normally provides (escapes + echoes)
if (!function_exists('p')) {
    function p($value): void { echo htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('print_unescaped')) {
    function print_unescaped($value): void { echo (string)$value; }
}

// Stub OCP\Util class with a no-op addStyle
if (!class_exists('OCP\\Util')) {
    eval('namespace OCP; class Util { public static function addStyle($appId, $name) { /* no-op */ } }');
}

// Stub IL10N
$l = new class {
    public function t(string $text, $params = []): string { return $text; }
};

$template = '/app/templates/not_configured.php';

// ---- Test 1: isAdmin=true ----
$_ = ['isAdmin' => true];
ob_start();
include $template;
$adminOutput = ob_get_clean();

assert_true(
    str_contains($adminOutput, 'occ smail:bootstrap'),
    "[admin] output contains literal 'occ smail:bootstrap'",
    $passes, $failures
);
assert_true(
    !str_contains($adminOutput, 'admin/smail'),
    "[admin] output does NOT contain 'admin/smail'",
    $passes, $failures
);
assert_true(
    stripos($adminOutput, 'Setup Wizard') === false,
    "[admin] output does NOT contain 'Setup Wizard' (case-insensitive)",
    $passes, $failures
);
assert_true(
    stripos($adminOutput, 'setup-wizard') === false,
    "[admin] output does NOT contain 'setup-wizard'",
    $passes, $failures
);
assert_true(
    !preg_match('#<a\s[^>]*href=#i', $adminOutput),
    "[admin] output does NOT contain any clickable <a href=...> link",
    $passes, $failures
);
assert_true(
    str_contains($adminOutput, 'data-testid="smail-bootstrap-snippet"'),
    "[admin] output contains data-testid='smail-bootstrap-snippet'",
    $passes, $failures
);

// ---- Test 2: isAdmin=false ----
$_ = ['isAdmin' => false];
ob_start();
include $template;
$userOutput = ob_get_clean();

assert_true(
    str_contains($userOutput, 'occ smail:bootstrap'),
    "[non-admin] output contains 'occ smail:bootstrap'",
    $passes, $failures
);
assert_true(
    !str_contains($userOutput, 'admin/smail'),
    "[non-admin] output does NOT contain 'admin/smail'",
    $passes, $failures
);
assert_true(
    stripos($userOutput, 'Setup Wizard') === false,
    "[non-admin] output does NOT contain 'Setup Wizard'",
    $passes, $failures
);
assert_true(
    !preg_match('#<a\s[^>]*href=#i', $userOutput),
    "[non-admin] output does NOT contain any clickable <a href=...> link",
    $passes, $failures
);

// ---- Test 3: PHP syntax ----
$syntaxCheck = shell_exec("php -l " . escapeshellarg($template) . " 2>&1");
assert_true(
    str_contains((string)$syntaxCheck, 'No syntax errors'),
    "templates/not_configured.php has no PHP syntax errors",
    $passes, $failures
);

// ---- Test 4: info.xml version ----
$xmlContent = file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $xmlContent, $vm);
$version = $vm[1] ?? '';
assert_true(
    $version === '0.10.2',
    "appinfo/info.xml <version> equals 0.10.2 (got: '$version')",
    $passes, $failures
);

// ---- Test 5: CHANGELOG ----
$changelog = file_get_contents('/app/CHANGELOG.md');
assert_true(
    str_contains($changelog, '## [0.10.2]'),
    "CHANGELOG.md contains [0.10.2] section",
    $passes, $failures
);
assert_true(
    stripos($changelog, 'not_configured') !== false || stripos($changelog, 'setup wizard') !== false,
    "CHANGELOG.md [0.10.2] mentions the fix (not_configured / setup wizard)",
    $passes, $failures
);

// ---- Test 6: No remaining wizard refs in lib/ or templates/ ----
$grep1 = shell_exec("grep -rn '/settings/admin/smail' /app/lib /app/templates 2>/dev/null");
assert_true(
    empty(trim((string)$grep1)),
    "No file under /app/lib or /app/templates references '/settings/admin/smail'",
    $passes, $failures
);

$grep2 = shell_exec("grep -rniE 'setup[-_ ]wizard' /app/lib /app/templates 2>/dev/null");
assert_true(
    empty(trim((string)$grep2)),
    "No file under /app/lib or /app/templates references legacy setup wizard",
    $passes, $failures
);
if (!empty(trim((string)$grep2))) {
    echo "  (grep output):\n$grep2\n";
}

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
