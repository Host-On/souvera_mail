<?php
/**
 * Tests for the Souvera Mail message-sort-fallback patch (0.13.28).
 *
 * Snappymail's `MailClient::GetUids()` falls back to `MessageSearch`
 * when the IMAP server's `SORT` capability isn't announced (or is
 * announced but fails silently — a known Stalwart 0.16 quirk on some
 * OAUTHBEARER post-auth code paths).
 *
 * `SEARCH ALL` returns UIDs ascending per RFC 3501 §7.2.5, which puts
 * OLDEST messages at the top of the list — the exact "Sortieren nach
 * Datum funktioniert gar nicht" bug the reported on their
 * live cluster.
 *
 * Our patch (in
 * `app/smail/v/current/app/libraries/Smail/Mail/Client/MailClient.php`)
 * reverses the UID array in the fallback branch so newest UID = first
 * — mirroring the effect of `SORT REVERSE DATE` for the 99% of folder
 * traffic where UID order correlates with date order. It's gated to
 * ONLY the fallback branch, ONLY when no search is active, ONLY when
 * the caller didn't pass an explicit sequence-set range.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$mailClientPath = '/app/app/smail/v/current/app/libraries/Smail/Mail/Client/MailClient.php';
$src = (string) file_get_contents($mailClientPath);

// -----------------------------------------------------------------
// 1. Patch is present and correctly gated
// -----------------------------------------------------------------
assertTrue(str_contains($src, "Souvera Mail patch"),
    "MailClient.php has the Souvera Mail sort-fallback patch marker",
    $passes, $failures);

// Structural: the patch lives INSIDE the `else` branch of `if ($bUseSort)`,
// right after MessageSearch and BEFORE the closing `}`. If someone
// accidentally moves it into the `if ($bUseSort)` branch we would
// double-reverse when server-side SORT already returned newest-first.
assertTrue((bool) preg_match(
    '#\$aResultUids\s*=\s*\$this->oImapClient->MessageSearch\('
    . '\$oSearchCriterias,\s*\$bReturnUid\s*\);\s*'
    . '(?://[^\n]*\n\s*){5,}'      // ≥5 comment lines documenting the fix
    . 'if\s*\(\\\\is_array\(\$aResultUids\)\s*&&\s*\\\\count\(\$aResultUids\)\s*>\s*1[\s\S]{0,200}'
    . '\$aResultUids\s*=\s*\\\\array_reverse\(\$aResultUids\)#s',
    $src
), "patch: MessageSearch → conditional array_reverse (inside else-branch)",
    $passes, $failures);

// Search-active guard — don't touch explicit search results
assertTrue((bool) preg_match(
    '#!\\\\strlen\(\$oParams->sSearch\)#',
    $src
), "patch skips the reverse when a search is active (!strlen(oParams->sSearch) guard)",
    $passes, $failures);

// Sequence-set guard — don't touch caller-provided ranges
assertTrue((bool) preg_match(
    '#!\$oParams->oSequenceSet#',
    $src
), "patch skips the reverse when caller passed an explicit sequence-set",
    $passes, $failures);

// Size guard — no-op on empty/single-element arrays
assertTrue((bool) preg_match(
    '#\\\\count\(\$aResultUids\)\s*>\s*1#',
    $src
), "patch only reverses when there is more than 1 UID (>1 guard)",
    $passes, $failures);

// CRITICAL bug-recurrence guard (0.13.28 → 0.13.29):
// v0.13.28 referenced the undefined variable `$sSearch` inside GetUids()
// which triggered "undefined variable" errors → Snappymail 500 → NC 404.
// GetUids() DOES have `$oParams->sSearch` but NOT a local `$sSearch`.
// Anti-regression: the patched fallback branch must reference the
// object-property form only.
$startFallback = strpos($src, 'Souvera Mail patch');
assertTrue($startFallback !== false, "patch marker still present", $passes, $failures);
$endFallback = strpos($src, 'array_reverse($aResultUids)', $startFallback);
$patchBlock = substr($src, $startFallback, $endFallback - $startFallback + 100);
assertTrue(!(bool) preg_match('#[^>a-zA-Z_]\$sSearch\b#', $patchBlock),
    "patch block does NOT reference the undefined local \$sSearch (regression from 0.13.28)",
    $passes, $failures);
assertTrue(str_contains($patchBlock, '$oParams->sSearch'),
    "patch block uses the correct \$oParams->sSearch (v0.13.29 fix)",
    $passes, $failures);

// The IF-branch (SORT worked) MUST NOT contain array_reverse
$sortBranch = substr($src, strpos($src, 'if ($bUseSort) {'));
$sortBranchEnd = strpos($sortBranch, '} else {');
$sortBranch = substr($sortBranch, 0, $sortBranchEnd);
assertTrue(!str_contains($sortBranch, 'array_reverse'),
    "the if(bUseSort) branch is UNCHANGED (no accidental double-reverse when SORT works)",
    $passes, $failures);

// -----------------------------------------------------------------
// 2. Behavioural sim — the patch produces the expected order
// -----------------------------------------------------------------
// Simulates what MessageSearch would return for a normal INBOX
// (UIDs ascending) and validates our reversal.
function simGetUidsFallback(array $aResultUids, string $sSearch = '', $oSequenceSet = null): array {
    if (\is_array($aResultUids) && \count($aResultUids) > 1
     && !\strlen($sSearch)
     && !$oSequenceSet
    ) {
        return \array_reverse($aResultUids);
    }
    return $aResultUids;
}

// 2a. Normal inbox — 10 UIDs ascending → 10 UIDs descending (newest first)
$in = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
$out = simGetUidsFallback($in);
assertTrue($out === [10, 9, 8, 7, 6, 5, 4, 3, 2, 1],
    "2a: normal inbox — ascending UIDs get reversed to descending (newest UID first)",
    $passes, $failures);

// 2b. Search-active — UNCHANGED (user typed a query, must respect IMAP order)
$out = simGetUidsFallback([1, 2, 3], 'from:boss@example.com');
assertTrue($out === [1, 2, 3],
    "2b: search-active → UIDs untouched (IMAP-side match ordering respected)",
    $passes, $failures);

// 2c. Sequence-set active — UNCHANGED (caller wanted a specific range)
$out = simGetUidsFallback([100, 101, 102], '', 'sequence-set-stub');
assertTrue($out === [100, 101, 102],
    "2c: caller-provided sequence set → UIDs untouched",
    $passes, $failures);

// 2d. Empty / single-element → UNCHANGED (nothing to reverse)
assertTrue(simGetUidsFallback([]) === [],
    "2d: empty list → no reverse", $passes, $failures);
assertTrue(simGetUidsFallback([42]) === [42],
    "2d: single-element list → no reverse", $passes, $failures);

// -----------------------------------------------------------------
// 3. Version bump + CHANGELOG regression
// -----------------------------------------------------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(version_compare($vm[1] ?? '0', '0.13.28', '>='),
    "info.xml <version> >= 0.13.28 (got: '" . ($vm[1] ?? '') . "')",
    $passes, $failures);

$cl = (string) file_get_contents('/app/CHANGELOG.md');
assertTrue(str_contains($cl, '[0.13.28]') || str_contains($cl, '[0.13.29]'),
    "CHANGELOG has a [0.13.28] or [0.13.29] section", $passes, $failures);
assertTrue(str_contains($cl, 'sort') || str_contains($cl, 'Sort'),
    "CHANGELOG mentions the sort fix",
    $passes, $failures);

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
