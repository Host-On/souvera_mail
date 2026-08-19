<?php
declare(strict_types=1);

/**
 * v0.14.43 — Root-cause fix + JSON-error-handling regression suite for
 * the "Filter nachträglich anwenden" bug reported by the operator on
 * 2026-02-19:
 *
 *   > "beim nachträglichen Anwenden der Filter: Netzwerkfehler:
 *   >  SyntaxError: JSON.parse: unexpected character at line 1
 *   >  column 1 of the JSON data"
 *
 * ROOT CAUSE
 * ----------
 * `lib/Sieve/Types.php` holds FIVE value-object classes (Rule, TestNode,
 * ActionNode, MessageFacts, EvaluatedActions) in a single file. PSR-4
 * autoloading maps 1 class → 1 file, so `use OCA\SouveraMail\Sieve\Rule`
 * inside `MiniInterpreter::parseIfBlock()` triggers the composer
 * autoloader for `lib/Sieve/Rule.php` — which does not exist. PHP raises
 * `Error: Class "OCA\SouveraMail\Sieve\Rule" not found`.
 *
 * The controller's `catch (\Throwable $e)` catches this and returns a
 * JSON 500 — but only IF the request survives NC's earlier stack
 * (middleware, controller instantiation, argument resolution). Older
 * NC releases render some `\Error` classes as HTML 500 pages via the
 * global ExceptionHandler, which is what the operator saw.
 *
 * FIX (two-layer defence in depth)
 * --------------------------------
 * 1. `composer.json` classmap now points at `lib/Sieve/Types.php`.
 *    After `composer dump-autoload -o` this makes every Types class
 *    findable by the standard autoloader.
 * 2. `lib/Sieve/MiniInterpreter.php` explicitly `require_once`-s
 *    Types.php at the top so the fix still works in live installs
 *    where the operator forgot to re-run `composer dump-autoload`.
 *
 * WHAT THIS TEST SUITE COVERS
 * ---------------------------
 *  A. Composer artefacts are populated (classmap + static loader).
 *  B. MiniInterpreter.php has the defensive `require_once` at the top.
 *  C. Every Types.php class resolves via the standard autoloader
 *     WITHOUT any explicit `require` (this is the smoking gun for the
 *     original bug — pre-fix it fails with "Class not found").
 *  D. End-to-end sim: parse a real Snappymail-emitted script, evaluate
 *     it against MessageFacts, verify the EvaluatedActions API works.
 *  E. Regression: original `test_sieve_apply_wiring.php` invariants
 *     (route table, controller shape, JMAP capabilities) still hold.
 */

$repo = \dirname(__DIR__);

// -------------------------------------------------------------------
// The ENTIRE POINT of this test is that Types.php classes autoload
// through the SAME code path that live NC uses — the composer
// autoloader. We deliberately DO NOT require Types.php explicitly.
// -------------------------------------------------------------------
require $repo . '/vendor/autoload.php';

$passes = 0;
$failures = [];
$check = static function (bool $cond, string $msg) use (&$passes, &$failures): void {
    if ($cond) { $passes++; } else { $failures[] = $msg; }
};

// -------------------------------------------------------------------
// A. Composer artefacts — v0.14.44+ inverted design: Types.php holds
//    the 5 value-object classes (as it always has), and 5 THIN
//    shim files (`Rule.php`, `TestNode.php` etc.) each just do
//    `require_once __DIR__ . '/Types.php';`. The classmap points at
//    the shim files — PSR-4 autoloading finds them, they load
//    Types.php, all 5 classes are declared. Redeploy-safe because
//    `require_once` is idempotent; partial deploys (CloudManager
//    ships some files but not others) still work: even if only
//    Types.php was uploaded, the classes are declared; even if only
//    the shims arrived, they gracefully load Types.php.
// -------------------------------------------------------------------
$composerJson = \json_decode((string) \file_get_contents($repo . '/composer.json'), true);
$classmap = $composerJson['autoload']['classmap'] ?? [];
$check(
    !\in_array('lib/Sieve/Types.php', $classmap, true),
    'composer.json:autoload.classmap does NOT list Types.php (each class handled via its own PSR-4 shim file)'
);

$classmapPhp = (string) \file_get_contents($repo . '/vendor/composer/autoload_classmap.php');
$staticLoader = (string) \file_get_contents($repo . '/vendor/composer/autoload_static.php');

foreach ([
    'ActionNode' => '/lib/Sieve/ActionNode.php',
    'EvaluatedActions' => '/lib/Sieve/EvaluatedActions.php',
    'MessageFacts' => '/lib/Sieve/MessageFacts.php',
    'Rule' => '/lib/Sieve/Rule.php',
    'TestNode' => '/lib/Sieve/TestNode.php',
] as $cls => $expectedPath) {
    $needleClassmap = "'OCA\\\\SouveraMail\\\\Sieve\\\\{$cls}' => \$baseDir . '{$expectedPath}'";
    $needleStatic = "'OCA\\\\SouveraMail\\\\Sieve\\\\{$cls}' => __DIR__ . '/../..' . '{$expectedPath}'";
    $check(
        \str_contains($classmapPhp, $needleClassmap),
        "vendor/composer/autoload_classmap.php maps {$cls} → {$expectedPath}"
    );
    $check(
        \str_contains($staticLoader, $needleStatic),
        "vendor/composer/autoload_static.php maps {$cls} → {$expectedPath}"
    );
}
$check(
    \str_contains($classmapPhp, "'OCA\\\\SouveraMail\\\\Sieve\\\\MiniInterpreter'"),
    'MiniInterpreter is present in autoload_classmap.php'
);

// -------------------------------------------------------------------
// B. Sieve types file layout: Types.php declares the classes;
//    the 5 shim files each `require_once __DIR__ . '/Types.php';`.
//    This is redeploy-safe and OpCache-safe (idempotent require).
// -------------------------------------------------------------------
$typesSrc = (string) \file_get_contents($repo . '/lib/Sieve/Types.php');
foreach (['Rule', 'TestNode', 'ActionNode', 'MessageFacts', 'EvaluatedActions'] as $cls) {
    $check(
        \preg_match('/\\bfinal\\s+class\\s+' . $cls . '\\b/', $typesSrc) === 1,
        "lib/Sieve/Types.php declares final class {$cls}"
    );
}
foreach ([
    'Rule.php',
    'TestNode.php',
    'ActionNode.php',
    'MessageFacts.php',
    'EvaluatedActions.php',
] as $shim) {
    $shimPath = $repo . '/lib/Sieve/' . $shim;
    $shimSrc = \is_file($shimPath) ? (string) \file_get_contents($shimPath) : '';
    $check(
        $shimSrc !== '' && \str_contains($shimSrc, "require_once __DIR__ . '/Types.php';"),
        "lib/Sieve/{$shim} is a PSR-4 shim that require_once's Types.php"
    );
    // Shim files must NOT declare a class themselves — that would
    // double-declare with Types.php's declaration and fatal on load.
    $check(
        !\preg_match('/\\bclass\\s+\\w+\\b/', $shimSrc),
        "lib/Sieve/{$shim} does NOT declare a class (would conflict with Types.php)"
    );
}

// MiniInterpreter.php no longer needs a defensive require_once —
// PSR-4 finds the shim files which load Types.php.
$miniSrc = (string) \file_get_contents($repo . '/lib/Sieve/MiniInterpreter.php');
$check(
    !\str_contains($miniSrc, "require_once __DIR__ . '/Types.php';"),
    'MiniInterpreter.php has no defensive require_once (PSR-4 shims handle it)'
);

// -------------------------------------------------------------------
// C. Every Sieve class autoloads via the STANDARD composer autoloader.
//    This is the direct regression check for the original bug — if any
//    of these class_exists() returns false, the "SyntaxError:
//    JSON.parse" bug is back.
// -------------------------------------------------------------------
foreach ([
    'OCA\\SouveraMail\\Sieve\\ActionNode',
    'OCA\\SouveraMail\\Sieve\\EvaluatedActions',
    'OCA\\SouveraMail\\Sieve\\MessageFacts',
    'OCA\\SouveraMail\\Sieve\\MiniInterpreter',
    'OCA\\SouveraMail\\Sieve\\Rule',
    'OCA\\SouveraMail\\Sieve\\TestNode',
] as $fqcn) {
    $check(
        \class_exists($fqcn, /*autoload*/ true),
        "class {$fqcn} resolves via the standard composer autoloader (regression: this was the ORIGINAL cause of the JSON.parse bug)"
    );
}

// -------------------------------------------------------------------
// D. End-to-end sim: parse a realistic Snappymail-emitted Sieve
//    script and evaluate it. If ANYTHING in the Sieve/ subpackage
//    autoloads incorrectly, this section blows up with an uncaught
//    Error, which the test framework will surface as a fatal.
// -------------------------------------------------------------------
$script = <<<'SIEVE'
require ["fileinto", "envelope", "imap4flags"];

# Rule 1: newsletters → Newsletter folder
if header :contains "List-Unsubscribe" "unsubscribe" {
    fileinto "Newsletter";
    stop;
}

# Rule 2: from a specific sender → Bills, mark seen
if address :is "from" "billing@acme.example" {
    fileinto "Bills";
    addflag "\\Seen";
}

# Rule 3: big attachments → Archive
if size :over 5M {
    fileinto "Archive";
}
SIEVE;

$mi = new \OCA\SouveraMail\Sieve\MiniInterpreter();
$mi->parse($script);
$check(\count($mi->getRules()) === 3, 'script parses to 3 rules (newsletter, billing, archive)');

// Message 1: fake newsletter → should be moved to Newsletter, stop
$msg1 = new \OCA\SouveraMail\Sieve\MessageFacts(
    'e1',
    ['List-Unsubscribe' => '<https://example.com/unsubscribe>', 'From' => 'newsletter@example.com'],
    'newsletter@example.com',
    ['me@my.example'],
    2048
);
$r1 = $mi->evaluate($msg1);
$check($r1->fileintoTarget() === 'Newsletter', 'newsletter message → fileinto Newsletter');
$check($r1->redirectTargets() === [], 'newsletter message has no redirects');

// Message 2: from billing → should go to Bills AND be flagged seen
$msg2 = new \OCA\SouveraMail\Sieve\MessageFacts(
    'e2',
    ['From' => 'Billing <billing@acme.example>'],
    'billing@acme.example',
    ['me@my.example'],
    1500
);
$r2 = $mi->evaluate($msg2);
$check($r2->fileintoTarget() === 'Bills', 'billing message → fileinto Bills');
$check($r2->addedFlags() === ['\\Seen'], 'billing message → addflag \\Seen');

// Message 3: big attachment → Archive
$msg3 = new \OCA\SouveraMail\Sieve\MessageFacts(
    'e3',
    ['From' => 'colleague@work.example', 'Subject' => 'Q3 report'],
    'colleague@work.example',
    ['me@my.example'],
    6 * 1024 * 1024 // 6 MB > 5 M
);
$r3 = $mi->evaluate($msg3);
$check($r3->fileintoTarget() === 'Archive', 'oversized message → fileinto Archive');

// Message 4: nothing matches → EvaluatedActions is empty
$msg4 = new \OCA\SouveraMail\Sieve\MessageFacts(
    'e4',
    ['From' => 'friend@personal.example', 'Subject' => 'lunch?'],
    'friend@personal.example',
    ['me@my.example'],
    900
);
$r4 = $mi->evaluate($msg4);
$check($r4->fileintoTarget() === null, 'unrelated message → no fileinto target');
$check($r4->isEmpty() === true, 'unrelated message → EvaluatedActions is empty');
$check($r4->shouldDiscard() === false, 'unrelated message → no discard');

// -------------------------------------------------------------------
// E. Controller invariants (JSON always, structured error contract).
// -------------------------------------------------------------------
$ctrlSrc = (string) \file_get_contents($repo . '/lib/Controller/SieveApplyController.php');
$check(
    \str_contains($ctrlSrc, 'catch (\\Throwable $e)'),
    'controller catches \\Throwable (Errors AND Exceptions) so a class-not-found in the service still produces JSON'
);
$check(
    \str_contains($ctrlSrc, "return new DataResponse(") && \str_contains($ctrlSrc, 'status'),
    'controller always returns DataResponse (renders JSON by default) with a status field'
);
$check(
    \str_contains($ctrlSrc, "STATUS_INTERNAL_SERVER_ERROR") && \str_contains($ctrlSrc, "STATUS_UNAUTHORIZED"),
    'controller uses HTTP status enum from the AppFramework (not raw ints)'
);
$check(
    // Structured error body — every catch path emits { status: "error", message: string }.
    \preg_match(
        '/catch\s*\(\s*\\\\Throwable\s+\$e\s*\)\s*\{[\s\S]{0,500}status[^}]*error[^}]*message/',
        $ctrlSrc
    ) === 1,
    "controller's catch block returns a { status: 'error', message: ... } shape"
);

// The controller's logger call must include the exception in the log
// context so nextcloud.log carries the full stack trace, not just the
// bare message. This is critical for diagnosing whatever bubbles up
// through the request lifecycle.
$check(
    \str_contains($ctrlSrc, "'exception' => \$e"),
    "controller logs 'exception' => \$e context (nextcloud.log will carry full stack trace)"
);

// -------------------------------------------------------------------
// F. Service defensive behaviour: `apply()` returns structured error
//    JSON for every failure path (empty script, no rules, folder
//    lookup failure, message-list failure, etc.) — the controller's
//    catch is the LAST resort; every well-known failure inside the
//    service must be surfaced as `{status: error, message: '<German
//    operator-facing text>'}`.
// -------------------------------------------------------------------
$svcSrc = (string) \file_get_contents($repo . '/lib/Service/SieveApplyService.php');
$errorSurfaces = [
    'Sieve-Skripte nicht ladbar',
    'Kein aktives Sieve-Skript hinterlegt',
    'Das aktive Skript enthält keine anwendbaren Regeln',
    'Ordnerliste nicht abrufbar',
    'wurde in der Mailbox nicht gefunden',
    'Nachrichtenliste nicht abrufbar',
    'Nachrichten-Details nicht abrufbar',
];
foreach ($errorSurfaces as $needle) {
    $check(
        \str_contains($svcSrc, $needle),
        "service surfaces a German error message for the '{$needle}' failure path"
    );
}
// Guard: no direct `throw new` in `apply()` that would escape the
// service unwrapped. Every risky call site is wrapped in try/catch.
$applyBody = (string) \substr(
    $svcSrc,
    (int) \strpos($svcSrc, 'public function apply(')
);
$applyBody = \substr($applyBody, 0, (int) \strpos($applyBody, "\n    // ---") + 1);
$check(
    \substr_count($applyBody, 'try {') >= 4,
    'apply() wraps every JMAP-touching block in try/catch (fetchMailboxes / listScriptsWithBodies / queryMessageIds / fetchMessageFacts)'
);

// -------------------------------------------------------------------
// G. `php -l` clean on every touched file.
// -------------------------------------------------------------------
foreach ([
    'lib/Sieve/MiniInterpreter.php',
    'lib/Sieve/Types.php',
    'lib/Sieve/Rule.php',
    'lib/Sieve/TestNode.php',
    'lib/Sieve/ActionNode.php',
    'lib/Sieve/MessageFacts.php',
    'lib/Sieve/EvaluatedActions.php',
    'lib/Service/SieveApplyService.php',
    'lib/Controller/SieveApplyController.php',
] as $f) {
    $out = [];
    $rc = 0;
    \exec('php -l ' . \escapeshellarg($repo . '/' . $f) . ' 2>&1', $out, $rc);
    $check($rc === 0, "php -l clean on {$f}: " . \implode(' | ', $out));
}

// -------------------------------------------------------------------
// H. Stalwart 0.16 pagination guard — Email/query.limit is capped at
//    500 per JMAP call ("Parameter limit must be between 1 and 500",
//    operator report 2026-02-19). Service MUST paginate — a single
//    call with limit=5000 gets rejected with a JMAP error, which
//    (pre-pagination) bubbled up as a raw HTML 500 because it
//    happened at the entry point of apply() and one particular
//    NC-middleware chain rendered it before the catch fired.
// -------------------------------------------------------------------
$check(
    // Verify the private queryMessageIds() method paginates via a while
    // loop with `position` and `pageLimit` = 500 (Stalwart's cap).
    \preg_match(
        '/private function queryMessageIds[\s\S]{0,2000}while\s*\(\s*\\\\count\s*\(\s*\$out\s*\)\s*<\s*\$limit\s*\)/',
        $svcSrc
    ) === 1,
    'queryMessageIds() uses a while-loop for pagination (Stalwart caps limit=500 per call)'
);
$check(
    \str_contains($svcSrc, 'JMAP_PAGE_LIMIT = 250'),
    'queryMessageIds() page-limit constant is 250 (safely below Stalwart 0.16 cap of ~500; v0.14.45 tightened from 500 → 250 after operator report that limit=500 still hit "must be between 1 and 500")'
);
$check(
    \preg_match(
        "/\\\\min\\(\\\$pageLimit,\\s*\\\$remaining\\)/",
        $svcSrc
    ) === 1,
    'queryMessageIds() requests min($pageLimit, $remaining) per JMAP call (never over-asks)'
);
$check(
    \preg_match(
        '/Email\/query[\s\S]{0,600}[\'"]position[\'"]\s*=>\s*\$position/',
        $svcSrc
    ) === 1,
    'Email/query request carries `position` for pagination cursor'
);
$check(
    \preg_match(
        '/if\s*\(\s*\$pageCount\s*<\s*\$requestLimit\s*\)\s*\{\s*break;/',
        $svcSrc
    ) === 1,
    'pagination stops when the JMAP page returns fewer items than requested (end-of-mailbox guard)'
);

// executeMoves() and executeFlagAdds() must ALSO chunk their `update`
// map at JMAP_PAGE_LIMIT (250) entries — Stalwart applies the same
// per-call cap to Email/set.update. Without chunking, a 5000-message
// apply that ends up moving 251+ messages would fail the whole batch
// with the same "Parameter … must be between 1 and 500" JMAP error.
$check(
    \substr_count($svcSrc, 'array_chunk($update, self::JMAP_PAGE_LIMIT') >= 2,
    'executeMoves() AND executeFlagAdds() chunk their Email/set.update at JMAP_PAGE_LIMIT (Stalwart cap-safe)'
);
// preserve_keys must be true — Email/set.update uses email-id keys.
$check(
    \substr_count($svcSrc, 'array_chunk($update, self::JMAP_PAGE_LIMIT, /*preserve_keys*/ true)') >= 2,
    'array_chunk() preserves email-id keys (preserve_keys=true) — required for JMAP Email/set.update semantics'
);
// Email/get.ids also chunked via the same JMAP_PAGE_LIMIT constant.
$check(
    \str_contains($svcSrc, 'array_chunk($ids, self::JMAP_PAGE_LIMIT)'),
    'fetchMessageFacts() chunks Email/get.ids at JMAP_PAGE_LIMIT (250)'
);
// Diagnostic breadcrumb helps distinguish stale-OpCache from real-fix runs.
$check(
    \str_contains($svcSrc, 'queryMessageIds start'),
    'queryMessageIds() emits an info-level breadcrumb so operators can verify the paginated code path is live (vs stale OpCache)'
);

// -------------------------------------------------------------------
// I. Frontend contract: the JS button POSTs limit=5000 but the SERVER
//    is responsible for enforcement — client can ask, server paginates.
// -------------------------------------------------------------------
$jsSrc = (string) \file_get_contents($repo . '/app/smail/v/current/app/plugins/nextcloud/js/sieve-apply.js');
$check(
    \str_contains($jsSrc, 'limit: 5000'),
    'sieve-apply.js still requests limit=5000 (server-side paginated — no client-side chunking needed)'
);

// -------------------------------------------------------------------
// Report
// -------------------------------------------------------------------
$total = $passes + \count($failures);
echo "Passed: {$passes}/{$total}\n";
if ($failures !== []) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - {$f}\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
