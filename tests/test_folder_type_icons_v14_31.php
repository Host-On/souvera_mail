<?php
/**
 * Regression pins for v0.14.31 — NC-style folder-type icons in
 * Snappymail's sidebar.
 *
 * Operator request 2026-02-19 (reference screenshot from sister
 * Souvera app with icon-prefixed nav rows): "so sollte das Sidebar
 * Menu aussehen, wie bei den anderen Apps halt".
 *
 * Contract:
 *   1. folder-names.js tags every rendered <li[data-imap-full-name]>
 *      with a `data-folder-type` attribute of the correct kind.
 *   2. folder-nav.css defines a `::before` icon for each type via
 *      mask-image on a currentColor background — no extra HTTP hop,
 *      icons pick up NC theming automatically.
 *   3. Selected rows raise icon opacity to full for readability.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function ok(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// -------------------- JS enricher --------------------
$js = (string) file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/js/folder-names.js');

$typeCases = [
    ['inbox',   "leaf === 'INBOX'"],
    ['sent',    "leaf === 'SENT'"],
    ['drafts',  "leaf === 'DRAFTS'"],
    ['spam',    "leaf === 'SPAM' || leaf === 'JUNK'"],
    ['trash',   "leaf === 'TRASH' || leaf === 'BIN' || leaf === 'DELETED'"],
    ['archive', "leaf === 'ARCHIVE'"],
    ['templates', "leaf === 'TEMPLATES'"],
    ['outbox',  "leaf === 'OUTBOX'"],
];
foreach ($typeCases as [$type, $needle]) {
    ok(str_contains($js, "ftype = '$type'"),
        "folder-names.js emits ftype='$type' for the correct leaf",
        $passes, $failures);
    ok(str_contains($js, $needle),
        "folder-names.js checks '$needle' for the $type type",
        $passes, $failures);
}

ok(str_contains($js, "ftype = 'namespace'")
    && str_contains($js, "/^shared( folders)?$/i.test(fn)"),
    "folder-names.js detects the namespace-header type",
    $passes, $failures);

ok((bool) preg_match(
    "#li\.setAttribute\(\s*'data-folder-type',\s*ftype\s*\)#",
    $js
), "folder-names.js writes data-folder-type attribute on the row <li>",
    $passes, $failures);

// -------------------- CSS icons --------------------
$css = (string) file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/css/folder-nav.css');

// Base icon slot
ok((bool) preg_match(
    '#\.b-folders li\[data-folder-type\] a::before[\s\S]{0,600}mask-image#',
    $css
), "CSS defines the icon `::before` slot with mask-image",
    $passes, $failures);

ok((bool) preg_match(
    '#\.b-folders li\[data-folder-type\] a\s*\{[\s\S]{0,400}display\s*:\s*flex#',
    $css
), "CSS makes rows flex so the icon + label stay aligned",
    $passes, $failures);

// One icon rule per type
foreach (['inbox', 'sent', 'drafts', 'spam', 'trash', 'archive', 'templates', 'outbox', 'namespace', 'user'] as $type) {
    ok(str_contains($css, "li[data-folder-type=\"$type\"] a::before"),
        "CSS defines an icon for data-folder-type=\"$type\"",
        $passes, $failures);
}

// Every icon rule ships both `mask-image` and `-webkit-mask-image`
$missingPrefix = [];
preg_match_all(
    '#li\[data-folder-type="([^"]+)"\] a::before\s*\{([^}]+)\}#s',
    $css,
    $blocks,
    PREG_SET_ORDER
);
foreach ($blocks as [, $type, $body]) {
    if (!str_contains($body, '-webkit-mask-image')) $missingPrefix[] = $type;
}
ok(empty($missingPrefix),
    "every icon rule includes -webkit-mask-image (Safari compat). Missing: "
    . implode(',', $missingPrefix),
    $passes, $failures);

// Selected rows raise opacity to 1
ok((bool) preg_match(
    '#a\.selected::before[\s\S]{0,200}opacity\s*:\s*1#',
    $css
), "CSS raises icon opacity on selected rows",
    $passes, $failures);

// -------------------- Version + CHANGELOG --------------------
$info = (string) file_get_contents('/app/appinfo/info.xml');
preg_match('#<version>([^<]+)</version>#', $info, $vm);
$ver = $vm[1] ?? '0';
ok(version_compare($ver, '0.14.31', '>='),
    "info.xml <version> >= 0.14.31 (got: '$ver')",
    $passes, $failures);

$cl = (string) file_get_contents('/app/CHANGELOG.md');
ok(str_contains($cl, '[0.14.31]'),
    "CHANGELOG has a [0.14.31] section",
    $passes, $failures);
ok((bool) preg_match('#0\.14\.31[\s\S]{0,1500}(?:folder-type|Icon|Sidebar|NC-style)#i', $cl),
    "0.14.31 section mentions the folder-type icons",
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
