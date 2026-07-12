<?php
declare(strict_types=1);

/**
 * MiniInterpreter behaviour under the exact Sieve dialect that
 * Snappymail's `sieve.js` emits. This is what the operator's
 * production script looks like — if the interpreter fails against
 * any of these shapes, `Apply filters` will silently under-apply
 * (safe, but not what the user wants).
 *
 * The autoload block mimics what the namespace bridge does at
 * runtime, so this test can run without composer.
 */

$repo = \dirname(__DIR__);
\spl_autoload_register(static function (string $class) use ($repo): void {
    $prefix = 'OCA\\SouveraMail\\';
    if (!\str_starts_with($class, $prefix)) {
        return;
    }
    $rel = \substr($class, \strlen($prefix));
    $path = $repo . '/lib/' . \str_replace('\\', '/', $rel) . '.php';
    if (\is_file($path)) { require $path; }
});
// Types.php declares multiple classes in one file — load it explicitly.
require $repo . '/lib/Sieve/Types.php';

use OCA\SouveraMail\Sieve\MiniInterpreter;
use OCA\SouveraMail\Sieve\MessageFacts;

$passes = 0;
$failures = [];
$check = static function (bool $cond, string $msg) use (&$passes, &$failures): void {
    if ($cond) { $passes++; } else { $failures[] = $msg; }
};

// -------------------------------------------------------------------
// Real-world script from the operator's screenshot ("Emergent —
// Weiterleiten nach s.grassegger@…"). Snappymail generates a flat
// `if allof (...) { fileinto; redirect; stop; }` pattern.
// -------------------------------------------------------------------
$script = <<<'SIEVE'
require ["fileinto","imap4flags","envelope","body"];

# Emergent (Weiterleiten nach s.grassegger@)
if allof (header :contains "From" "@emergent.com")
{
    fileinto "Emergent";
    redirect "s.grassegger@extern.de";
    stop;
}

# Newsletter cleanup
if anyof (header :contains "List-Unsubscribe" "unsubscribe",
          header :contains "Precedence" "bulk")
{
    fileinto "Newsletter";
}

# discard obvious spam
if header :contains "Subject" "PENIS ENLARGEMENT"
{
    discard;
    stop;
}
SIEVE;

$engine = (new MiniInterpreter())->parse($script);
$rules = $engine->getRules();
$check(\count($rules) === 3, 'parses 3 top-level rules (Emergent, Newsletter, Spam)');

// -------------------------------------------------------------------
// Emergent rule fires on matching From
// -------------------------------------------------------------------
$m = new MessageFacts(
    'M1',
    ['From' => 'CI Bot <ci@emergent.com>', 'Subject' => 'Build 1234'],
    'ci@emergent.com',
    ['philip@grassegger.souvera.work'],
    2048
);
$r = $engine->evaluate($m);
$check($r->fileintoTarget() === 'Emergent',
    'Emergent rule → fileinto "Emergent"');
$check($r->redirectTargets() === ['s.grassegger@extern.de'],
    'Emergent rule → redirect s.grassegger@extern.de');
// Because `stop;` fired, later rules must NOT contribute — verify by
// giving the message ALSO a newsletter-like header:
$m2 = new MessageFacts(
    'M2',
    ['From' => 'ci@emergent.com', 'List-Unsubscribe' => '<https://foo/unsubscribe>'],
    'ci@emergent.com',
    ['philip@…'],
    3072
);
$r2 = $engine->evaluate($m2);
$check($r2->fileintoTarget() === 'Emergent',
    'stop; short-circuits — Newsletter rule does NOT overwrite fileinto target');

// -------------------------------------------------------------------
// Newsletter rule fires on List-Unsubscribe, does NOT redirect
// -------------------------------------------------------------------
$m3 = new MessageFacts(
    'M3',
    ['From' => 'newsletter@heise.de',
     'List-Unsubscribe' => '<https://heise.de/unsubscribe?token=xyz>'],
    null, [], 5000
);
$r3 = $engine->evaluate($m3);
$check($r3->fileintoTarget() === 'Newsletter',
    'Newsletter rule fires on List-Unsubscribe');
$check($r3->redirectTargets() === [],
    'Newsletter rule does NOT redirect');

// -------------------------------------------------------------------
// Spam rule discards
// -------------------------------------------------------------------
$m4 = new MessageFacts(
    'M4',
    ['Subject' => 'PENIS ENLARGEMENT — click here!!!'],
    null, [], 512
);
$r4 = $engine->evaluate($m4);
$check($r4->shouldDiscard(), 'Spam rule → discard');

// -------------------------------------------------------------------
// Non-matching message returns empty actions (safe: leave it alone)
// -------------------------------------------------------------------
$m5 = new MessageFacts(
    'M5',
    ['From' => 'friend@example.com', 'Subject' => 'Coffee?'],
    null, [], 700
);
$r5 = $engine->evaluate($m5);
$check($r5->fileintoTarget() === null && $r5->redirectTargets() === []
    && !$r5->shouldDiscard(),
    'no rule matches → zero actions (message stays put)');

// -------------------------------------------------------------------
// Match-type variations: :is, :matches (glob), :regex
// -------------------------------------------------------------------
$script2 = <<<'SIEVE'
if header :is "Priority" "urgent" { addflag "\\Flagged"; }
if header :matches "Subject" "*[URGENT]*" { addflag "\\Flagged"; }
if header :regex "Message-ID" "^<[a-z]+@internal\\.foo>$" { fileinto "Internal"; }
SIEVE;
$e2 = (new MiniInterpreter())->parse($script2);
$check(\count($e2->getRules()) === 3, 'parses 3 rules with different match-types');

$hit = $e2->evaluate(new MessageFacts('X', ['Priority' => 'urgent'], null, [], 0));
$check($hit->addedFlags() === ['\\Flagged'], ':is header match sets addflag');

$hit = $e2->evaluate(new MessageFacts('X', ['Subject' => 'Re: [URGENT] Hi'], null, [], 0));
$check($hit->addedFlags() === ['\\Flagged'], ':matches glob (* wildcard) works');

$hit = $e2->evaluate(new MessageFacts('X', ['Message-ID' => '<test@internal.foo>'], null, [], 0));
$check($hit->fileintoTarget() === 'Internal', ':regex escapes work');

// -------------------------------------------------------------------
// envelope test — only matches when envelopeFrom is present
// -------------------------------------------------------------------
$script3 = 'if envelope :is "from" "list-bounce@foo.example" { discard; }';
$e3 = (new MiniInterpreter())->parse($script3);
$check($e3->evaluate(new MessageFacts('X', [], 'list-bounce@foo.example', [], 0))->shouldDiscard(),
    'envelope :is fires on SMTP envelopeFrom');
$check(!$e3->evaluate(new MessageFacts('X', ['From' => 'list-bounce@foo.example'], null, [], 0))->shouldDiscard(),
    'envelope :is does NOT fall back to header From (envelope is distinct)');

// -------------------------------------------------------------------
// size test — :over and :under, K/M suffixes
// -------------------------------------------------------------------
$script4 = 'if size :over 100K { fileinto "Big"; }';
$e4 = (new MiniInterpreter())->parse($script4);
$check($e4->evaluate(new MessageFacts('X', [], null, [], 200 * 1024))->fileintoTarget() === 'Big',
    'size :over 100K → 200K message matches');
$check($e4->evaluate(new MessageFacts('X', [], null, [], 50 * 1024))->fileintoTarget() === null,
    'size :over 100K → 50K message does NOT match');

// -------------------------------------------------------------------
// not / allof / anyof combinators
// -------------------------------------------------------------------
$script5 = <<<'SIEVE'
if allof (header :contains "From" "@work.com",
          not header :contains "Subject" "OOO")
{
    fileinto "Work";
}
SIEVE;
$e5 = (new MiniInterpreter())->parse($script5);
$check(
    $e5->evaluate(new MessageFacts('X',
        ['From' => 'boss@work.com', 'Subject' => 'Sprint plan'], null, [], 0
    ))->fileintoTarget() === 'Work',
    'allof + not: real work mail → Work'
);
$check(
    $e5->evaluate(new MessageFacts('X',
        ['From' => 'boss@work.com', 'Subject' => 'OOO — back next week'], null, [], 0
    ))->fileintoTarget() === null,
    'allof + not: OOO mail excluded'
);

// -------------------------------------------------------------------
// Address test — extracts email out of "Name <a@b>" form
// -------------------------------------------------------------------
$script6 = 'if address :contains "from" "foo@bar.com" { fileinto "Foo"; }';
$e6 = (new MiniInterpreter())->parse($script6);
$check(
    $e6->evaluate(new MessageFacts('X',
        ['From' => 'Fooby <foo@bar.com>'], null, [], 0
    ))->fileintoTarget() === 'Foo',
    'address test extracts email from display-name form'
);

// -------------------------------------------------------------------
// Comments (# and /* */) are stripped and don't derail parsing
// -------------------------------------------------------------------
$script7 = <<<'SIEVE'
# rule name  { discard; }
/* multi-line
   comment with braces { and } */
if header :contains "X-Foo" "bar" { fileinto "Foo"; }
SIEVE;
$e7 = (new MiniInterpreter())->parse($script7);
$check(\count($e7->getRules()) === 1, 'comment stripping ignores braces inside comments');

// -------------------------------------------------------------------
// Empty / whitespace-only script → zero rules, no crash
// -------------------------------------------------------------------
$check((new MiniInterpreter())->parse('')->getRules() === [],
    'empty script parses to zero rules without error');
$check((new MiniInterpreter())->parse("   \n\n  \n")->getRules() === [],
    'whitespace-only script parses to zero rules without error');

// -------------------------------------------------------------------
// `php -l` clean
// -------------------------------------------------------------------
foreach (['lib/Sieve/MiniInterpreter.php', 'lib/Sieve/Types.php'] as $f) {
    $out = [];
    $rc = 0;
    \exec('php -l ' . \escapeshellarg($repo . '/' . $f) . ' 2>&1', $out, $rc);
    $check($rc === 0, "php -l clean on {$f}: " . \implode(' | ', $out));
}

// -------------------------------------------------------------------
// Report
// -------------------------------------------------------------------
$total = $passes + \count($failures);
echo "Passed: {$passes}/{$total}\n";
if ($failures !== []) {
    echo "FAILURES:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "ALL TESTS PASSED\n";
