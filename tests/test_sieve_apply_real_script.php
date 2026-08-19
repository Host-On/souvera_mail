<?php
declare(strict_types=1);

/**
 * v0.14.48 — Regression suite for "meine Filter werden völlig ignoriert"
 * (report 2026-07-15, after the dispatcher limit fix).
 *
 * Root cause: Snappymail's filter UI emits the MIXED argument form
 *   `if header :contains ["From"] "emergent"`
 * (bracketed header-list + single quoted needle). MiniInterpreter only
 * had regexes for both-quoted and both-list, so the mixed form parsed
 * to TestNode('false') → 4836 scanned, 0 moved.
 *
 * Secondary: JMAP `headers` values are RAW (leading space, \r\n folding,
 * MIME encoded-words) → `:is` could never match either.
 *
 * The fixture below is the operator's REAL active script structure
 * (fetched live from Stalwart VM 287, account philip).
 */

$repo = \dirname(__DIR__);
require $repo . '/lib/Sieve/Types.php';
require $repo . '/lib/Sieve/MiniInterpreter.php';

use OCA\SouveraMail\Sieve\MessageFacts;
use OCA\SouveraMail\Sieve\MiniInterpreter;

$passes = 0;
$failures = [];
$check = static function (bool $cond, string $msg) use (&$passes, &$failures): void {
    if ($cond) { $passes++; } else { $failures[] = $msg; }
};

// -------------------------------------------------------------------
// 1. The operator's real script (Snappymail-generated, mixed form).
// -------------------------------------------------------------------
$script = <<<'SIEVE'
require ["imap4flags","fileinto"];

# This is Smail sieve script.
# Please don't change anything here.
# SMAIL:SIEVE

/*
BEGIN:FILTER:8843500079500004200710
BEGIN:HEADER
eyJJRCI6Ijg4NDM1MDAwNzk1MDAwMDQyMDA3MTAi
END:HEADER
*/

if header :contains ["From"] "emergent"
{
    addflag "\\Seen";
    fileinto "INBOX/Emergent";
}

/* END:FILTER */

if header :is ["From"] "support@planetromeo.com"
{
    addflag "\\Seen";
    fileinto "Deleted Items";
    stop;
}
SIEVE;

$engine = (new MiniInterpreter())->parse($script);
$rules = $engine->getRules();
$check(\count($rules) === 2, 'real script parses into 2 rules (got ' . \count($rules) . ')');
foreach ($rules as $i => $r) {
    $check($r->test->kind !== 'false',
        "rule #$i test must NOT degrade to 'false' (mixed [\"From\"] \"x\" form must parse)");
    $check($r->test->kind === 'header', "rule #$i test kind is 'header'");
}

$facts = static fn (array $headers): MessageFacts =>
    new MessageFacts('m1', $headers, null, [], 1000);

// Rule 1: :contains "emergent" against a normalised From value.
$r1 = $engine->evaluate($facts(['From' => 'Emergent <billing@emergent.sh>']));
$check($r1->fileintoTarget() === 'INBOX/Emergent', ':contains match → fileinto INBOX/Emergent');
$check($r1->addedFlags() === ['\\Seen'], ':contains match → addflag \\Seen');

// Rule 2: :is exact-match semantics (RFC 5228 — full header value).
$r2 = $engine->evaluate($facts(['From' => 'support@planetromeo.com']));
$check($r2->fileintoTarget() === 'Deleted Items', ':is exact match → fileinto Deleted Items');

$r3 = $engine->evaluate($facts(['From' => 'PLANETROMEO <support@planetromeo.com>']));
$check($r3->fileintoTarget() === null,
    ':is with display-name does NOT match (RFC header-test semantics, same as Stalwart delivery)');

// No match at all → empty.
$r4 = $engine->evaluate($facts(['From' => 'alice@example.org']));
$check($r4->isEmpty() && $r4->fileintoTarget() === null, 'unrelated sender matches nothing');

// -------------------------------------------------------------------
// 2. All four argument-form combinations parse and behave identically.
// -------------------------------------------------------------------
$forms = [
    'both-quoted' => 'if header :contains "From" "acme" { fileinto "A"; }',
    'both-list' => 'if header :contains ["From"] ["acme"] { fileinto "A"; }',
    'list-quoted' => 'if header :contains ["From"] "acme" { fileinto "A"; }',
    'quoted-list' => 'if header :contains "From" ["acme"] { fileinto "A"; }',
];
foreach ($forms as $label => $src) {
    $e = (new MiniInterpreter())->parse($src);
    $res = $e->evaluate($facts(['From' => 'billing@acme.com']));
    $check($res->fileintoTarget() === 'A', "$label form matches");
    $miss = $e->evaluate($facts(['From' => 'other@example.org']));
    $check($miss->fileintoTarget() === null, "$label form does not over-match");
}

// Multi-entry lists on both sides.
$e = (new MiniInterpreter())->parse(
    'if header :contains ["From","Reply-To"] ["foo","bar"] { fileinto "B"; }'
);
$check($e->evaluate($facts(['Reply-To' => 'x@bar.com']))->fileintoTarget() === 'B',
    'multi-list: second header × second needle matches');

// -------------------------------------------------------------------
// 3. Header normalisation (raw JMAP values → RFC 5228 comparison form).
// -------------------------------------------------------------------
$check(MessageFacts::normaliseHeaderValue(' support@planetromeo.com') === 'support@planetromeo.com',
    'leading space (raw JMAP header) is trimmed');
$check(MessageFacts::normaliseHeaderValue(" multipart/alternative;\r\n boundary=\"x\"")
        === 'multipart/alternative; boundary="x"',
    'CRLF+WSP folding is unfolded to a single space');
$check(MessageFacts::normaliseHeaderValue('=?UTF-8?Q?Gesch=C3=A4ftlich?=') === 'Geschäftlich',
    'MIME encoded-word is decoded to UTF-8');
$check(MessageFacts::normaliseHeaderValue('plain value') === 'plain value',
    'plain values pass through untouched');

// :is works end-to-end on a raw-style value once normalised.
$rawFrom = MessageFacts::normaliseHeaderValue(' support@planetromeo.com');
$r5 = $engine->evaluate($facts(['From' => $rawFrom]));
$check($r5->fileintoTarget() === 'Deleted Items',
    'normalised raw From value satisfies :is (the live 0-of-4836 case)');

// -------------------------------------------------------------------
// 4. Old regression guard: previous single/list forms from the existing
//    test corpus still parse (no behaviour change for them).
// -------------------------------------------------------------------
$e = (new MiniInterpreter())->parse(
    'if allof (header :contains "From" "@work.com", not header :contains "Subject" "OOO") { fileinto "W"; }'
);
$check($e->evaluate($facts(['From' => 'x@work.com', 'Subject' => 'Report']))->fileintoTarget() === 'W',
    'allof + not combination still works');
$check($e->evaluate($facts(['From' => 'x@work.com', 'Subject' => 'OOO today']))->fileintoTarget() === null,
    'not-branch still suppresses the match');

// -------------------------------------------------------------------
if ($failures) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    echo \count($failures) . " failed, $passes passed\n";
    exit(1);
}
echo "ALL TESTS PASSED ($passes assertions)\n";
