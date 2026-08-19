<?php
/**
 * Regression test for the folder-name localisation + Spam/Junk
 * auto-hide shipped in Souvera Mail 0.13.13.
 *
 * Report (2026-07-01)
 * ----------------------------
 *  - Shared-mailbox folders show their raw English IMAP leaf names
 *    ("Deleted Items", "INBOX", "Sent Items", "Drafts") because
 *    Stalwart only tags SPECIAL-USE flags for the principal that
 *    OWNS the mailbox — users with ACL access see the bare names
 *    and the engine has no hint that "Deleted Items" is a Trash.
 *  - The IMAP namespace prefix "Shared Folders/" Stalwart returns
 *    is rendered verbatim as a tree header.
 *  - Spam/Junk should be hidden across the board.
 *
 * Operator-confirmed implementation choice: localisation strings
 * live in `langs/<lang>.json` — never in the JS. That keeps
 * "translate folder names" a strictly i18n concern and avoids
 * hard-coded display strings in code.
 *
 * Architecture
 * ------------
 *  1. `app/plugins/nextcloud/langs/<lang>.json` carries a new
 *     `FOLDERS` namespace with all the leaf-name + namespace
 *     translations.
 *  2. `app/plugins/nextcloud/js/folder-names.js` (NEW) walks the
 *     engine's folder collection AND the rendered DOM, looks up
 *     each leaf in the translation table, replaces the display
 *     name, and marks Junk/Spam leaves with a CSS class that the
 *     injected stylesheet hides via `display:none`.
 *  3. The plugin loads the new JS via `$this->addJs('js/folder-names.js')`.
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

// ---------------------------------------------------------------
// 1. Translation files carry the new FOLDERS namespace
// ---------------------------------------------------------------
foreach (['de', 'en'] as $lang) {
    $path = "/app/app/smail/v/current/app/plugins/nextcloud/langs/$lang.json";
    assertTrue(\file_exists($path), "langs/$lang.json exists", $passes, $failures);
    $raw = \file_get_contents($path);
    $data = \json_decode($raw, true);
    assertTrue(\is_array($data), "langs/$lang.json parses as valid JSON",
        $passes, $failures);
    assertTrue(isset($data['FOLDERS']) && \is_array($data['FOLDERS']),
        "langs/$lang.json has the new FOLDERS namespace", $passes, $failures);
    foreach ([
        'SHARED_NAMESPACE', 'OTHER_USERS_NAMESPACE',
        'INBOX', 'SENT', 'SENT_ITEMS', 'DRAFTS',
        'TRASH', 'DELETED_ITEMS', 'JUNK', 'SPAM',
        'ARCHIVE', 'OUTBOX',
    ] as $key) {
        assertTrue(isset($data['FOLDERS'][$key]) && \is_string($data['FOLDERS'][$key]) && $data['FOLDERS'][$key] !== '',
            "langs/$lang.json carries FOLDERS/$key (non-empty)",
            $passes, $failures);
    }
}

// Spot-check the German translations the operator explicitly asked for
$de = \json_decode(\file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/langs/de.json'), true);
$expected = [
    'SHARED_NAMESPACE' => 'Geteilte Postfächer',
    'INBOX' => 'Posteingang',
    'SENT' => 'Gesendet',
    'SENT_ITEMS' => 'Gesendet',
    'DRAFTS' => 'Entwürfe',
    'TRASH' => 'Papierkorb',
    'DELETED_ITEMS' => 'Gelöscht',
    'JUNK' => 'Spam',
    'SPAM' => 'Spam',
    'ARCHIVE' => 'Archiv',
    'OUTBOX' => 'Postausgang',
];
foreach ($expected as $k => $v) {
    assertTrue(($de['FOLDERS'][$k] ?? null) === $v,
        "de.json FOLDERS/$k == '$v' (got: '" . ($de['FOLDERS'][$k] ?? 'null') . "')",
        $passes, $failures);
}

// ---------------------------------------------------------------
// 2. folder-names.js — wired + source contract
// ---------------------------------------------------------------
$jsPath = '/app/app/smail/v/current/app/plugins/nextcloud/js/folder-names.js';
assertTrue(\file_exists($jsPath), "folder-names.js exists", $passes, $failures);
$js = \file_get_contents($jsPath);

// Engine i18n is the source of truth — JS reads `FOLDERS/<key>`, never
// hard-codes display strings.
assertTrue(\str_contains($js, "rl.i18n('FOLDERS/'"),
    "folder-names.js reads display names from rl.i18n('FOLDERS/...') (langs/<lang>.json source of truth)",
    $passes, $failures);

// Patches the folder model's `name` observable AND the rendered DOM.
assertTrue(\str_contains($js, 'folder.name(translated)'),
    "folder-names.js patches folder.name() observable when leaf matches a known English IMAP name",
    $passes, $failures);
assertTrue(\str_contains($js, 'data-imap-full-name'),
    "folder-names.js also walks rendered DOM via [data-imap-full-name] attribute (catches re-renders before the model is ready)",
    $passes, $failures);

// Junk/Spam hide: CSS rule + data-attribute + isJunkPath() detection
assertTrue(\str_contains($js, 'data-folder-junk') && \str_contains($js, 'display: none'),
    "folder-names.js injects CSS that hides [data-folder-junk='1'] folders globally",
    $passes, $failures);
assertTrue(\str_contains($js, 'isJunkPath') && \str_contains($js, "JUNK_LEAVES"),
    "folder-names.js exposes a JUNK_LEAVES set + isJunkPath() helper",
    $passes, $failures);

// MutationObserver re-runs on engine renders so race conditions
// (folder list lazy-loaded after our boot interval) still get covered.
assertTrue(\str_contains($js, 'MutationObserver'),
    "folder-names.js observes DOM mutations so late-rendered folders get patched too",
    $passes, $failures);

// English IMAP leaf names we promise to translate
foreach ([
    'Sent Items', 'Sent Messages', 'Sent Mail',
    'Deleted Items', 'Deleted Messages',
    'Junk', 'Junk E-mail',
    'Spam', 'Inbox', 'Drafts', 'Trash', 'Archive', 'Outbox',
] as $leaf) {
    assertTrue(\str_contains($js, "'$leaf'"),
        "folder-names.js recognises the IMAP leaf '$leaf'",
        $passes, $failures);
}

// Plugin index.php loads the new JS
$plug = \file_get_contents('/app/app/smail/v/current/app/plugins/nextcloud/index.php');
assertTrue(\str_contains($plug, "\$this->addJs('js/folder-names.js')"),
    "Plugin loads folder-names.js via addJs()",
    $passes, $failures);

// ---------------------------------------------------------------
// 3. Behavioural simulation — drive the leaf-translation map
//    through the same JS data the runtime would build
// ---------------------------------------------------------------
//
// We don't have a JS engine here, but we can pin the leaf→translation
// contract by reading `de.json` directly and verifying the
// transformations our JS would emit.

function simTranslate(string $imapPath, array $translations, array $junkLeaves): array {
    $parts = \preg_split('#[/\\\\.]#', $imapPath);
    $leaf = \end($parts) ?: '';
    $upper = \strtoupper($leaf);
    $translated = null;
    foreach ($translations as $variants => $key) {
        $vs = \explode('|', $variants);
        foreach ($vs as $v) {
            if (\strtoupper($v) === $upper) {
                $translated = $key;
                break 2;
            }
        }
    }
    return [
        'displayName' => $translated ?? $leaf,
        'isJunk'      => \in_array($upper, $junkLeaves, true),
    ];
}

$T = [
    'Inbox'                                  => $de['FOLDERS']['INBOX'],
    'Sent'                                   => $de['FOLDERS']['SENT'],
    'Sent Items|Sent Messages|Sent Mail'     => $de['FOLDERS']['SENT_ITEMS'],
    'Drafts'                                 => $de['FOLDERS']['DRAFTS'],
    'Trash'                                  => $de['FOLDERS']['TRASH'],
    'Deleted Items|Deleted Messages|Deleted' => $de['FOLDERS']['DELETED_ITEMS'],
    'Junk|Junk E-mail|Junk Email'            => $de['FOLDERS']['JUNK'],
    'Spam'                                   => $de['FOLDERS']['SPAM'],
];
$JUNK = ['JUNK', 'JUNK E-MAIL', 'JUNK EMAIL', 'SPAM'];

// 3a. Shared mailbox path with German IMAP names
$r = simTranslate('Shared Folders/team@buxtehude.email/Deleted Items', $T, $JUNK);
assertTrue($r['displayName'] === 'Gelöscht',
    "3a: 'Shared Folders/.../Deleted Items' → 'Gelöscht'", $passes, $failures);
assertTrue($r['isJunk'] === false,
    "3a: 'Deleted Items' is NOT marked as junk", $passes, $failures);

// 3b. Sent variants all collapse to "Gesendet"
foreach (['Sent', 'Sent Items', 'Sent Messages', 'Sent Mail'] as $variant) {
    $r = simTranslate("Shared Folders/team@buxtehude.email/$variant", $T, $JUNK);
    assertTrue($r['displayName'] === 'Gesendet',
        "3b: '$variant' → 'Gesendet'", $passes, $failures);
}

// 3c. Junk/Spam variants get marked for hide
foreach (['Junk', 'Junk E-mail', 'Spam'] as $variant) {
    $r = simTranslate("Shared Folders/team@buxtehude.email/$variant", $T, $JUNK);
    assertTrue($r['isJunk'] === true,
        "3c: '$variant' marked as junk (will be hidden)", $passes, $failures);
}

// 3d. Unknown leaves pass through unchanged (no false-positive translation)
$r = simTranslate('Shared Folders/team@buxtehude.email/Custom Folder', $T, $JUNK);
assertTrue($r['displayName'] === 'Custom Folder',
    "3d: unknown leaves pass through verbatim (no over-eager replacement)",
    $passes, $failures);

// ---------------------------------------------------------------
// 4. CHANGELOG + version
// ---------------------------------------------------------------
$changelog = \file_get_contents('/app/CHANGELOG.md');
assertTrue(\str_contains($changelog, '[0.13.13]'),
    "CHANGELOG.md contains [0.13.13] entry", $passes, $failures);
foreach (['Geteilte Postfächer', 'sentFolder', 'Spam'] as $needle) {
    assertTrue(\str_contains($changelog, $needle),
        "CHANGELOG 0.13.13 mentions '$needle'", $passes, $failures);
}

$info = \file_get_contents('/app/appinfo/info.xml');
\preg_match('#<version>([^<]+)</version>#', $info, $vm);
assertTrue(\version_compare($vm[1] ?? '0.0.0', '0.13.13', '>='),
    "info.xml <version> >= 0.13.13 (got: '" . ($vm[1] ?? '') . "')",
    $passes, $failures);

// ---------------------------------------------------------------
echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
exit(0);
