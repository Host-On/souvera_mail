<?php
declare(strict_types=1);

/**
 * v0.14.46 — Regression suite for the REAL root cause of the operator's
 * recurring log error (2026-02-19 … 2026-06):
 *
 *   > ERROR index  Parameter limit must be between 1 and 500
 *   > ERROR PHP    The backtick (`) operator is deprecated, use
 *   >              shell_exec() instead at …/gpg/base.php#489
 *
 * ROOT CAUSE (finally)
 * --------------------
 * The "limit must be between 1 and 500" error does NOT come from
 * Stalwart's JMAP API (that theory drove 0.14.44/0.14.45). It is thrown
 * by NEXTCLOUD's own IAddressBook search validator: Snappymail's
 * `plugins/nextcloud/NextcloudAddressBook.php` called
 * `$cm->search(…, ['limit' => 10000])` — NC only allows 1..500.
 * That's why the error co-occurred with the GPG backtick warning:
 * both fire during normal Snappymail usage (compose/contacts), not
 * during sieve-apply.
 *
 * FIX
 * ---
 * 1. NextcloudAddressBook: paginated `searchAll()` helper — pages of
 *    500 via `offset`, capped at 10 000 total. All three former
 *    `limit => 10000` call sites use it; GetSuggestions clamps $iLimit.
 * 2. gpg/base.php + gpg/pgp.php: backtick operator replaced with
 *    \shell_exec() (PHP 8.5 deprecation).
 *
 * WHAT THIS SUITE COVERS
 * ----------------------
 *  A. Lint: all three touched files parse.
 *  B. No backtick execution operator remains anywhere in the gpg dir.
 *  C. NextcloudAddressBook passes no literal limit > 500 to $cm->search.
 *  D. searchAll() exists, paginates with offset, uses NC_SEARCH_PAGE=500.
 *  E. Functional sim: searchAll collects multi-page results, never
 *     requests limit > 500, stops at the 10 000 cap.
 */

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

$root = \dirname(__DIR__);
$abPath = $root . '/app/smail/v/current/app/plugins/nextcloud/NextcloudAddressBook.php';
$gpgBase = $root . '/app/smail/v/current/app/libraries/Smail/Engine/gpg/base.php';
$gpgPgp = $root . '/app/smail/v/current/app/libraries/Smail/Engine/gpg/pgp.php';

echo "A. Lint changed files\n";
foreach ([$abPath, $gpgBase, $gpgPgp] as $f) {
    $out = (string) \shell_exec('php -l ' . \escapeshellarg($f) . ' 2>&1');
    check('php -l ' . \basename($f), \str_contains($out, 'No syntax errors'), \trim($out));
}

echo "B. No backtick execution operator in gpg dir\n";
$gpgDir = \dirname($gpgBase);
$offenders = [];
foreach (\glob($gpgDir . '/*.php') as $f) {
    $src = (string) \file_get_contents($f);
    // Strip comments/strings-agnostic heuristic: look for `cmd` patterns
    if (\preg_match('/`[^`\n]*\$[^`\n]*`|\(string\)\s*`/', $src)) {
        $offenders[] = \basename($f);
    }
}
check('gpg/*.php free of backtick exec', $offenders === [], \implode(', ', $offenders));
check('base.php uses shell_exec for which', \str_contains((string) \file_get_contents($gpgBase), "shell_exec('which '"));
check('pgp.php uses shell_exec for --list-config', \str_contains((string) \file_get_contents($gpgPgp), '--with-colons --list-config'))
;

echo "C. No IManager search limit > 500\n";
$abSrc = (string) \file_get_contents($abPath);
$bad = [];
if (\preg_match_all("/'limit'\s*=>\s*(\d+)/", $abSrc, $m)) {
    foreach ($m[1] as $n) {
        if ((int) $n > 500) { $bad[] = $n; }
    }
}
check('no literal limit > 500 in NextcloudAddressBook', $bad === [], \implode(', ', $bad));
check('no legacy limit=>10000 call', !\str_contains($abSrc, "'limit' => 10000"));

echo "D. searchAll pagination helper present\n";
check('NC_SEARCH_PAGE = 500 constant', (bool) \preg_match('/NC_SEARCH_PAGE\s*=\s*500/', $abSrc));
check('NC_SEARCH_CAP = 10000 constant', (bool) \preg_match('/NC_SEARCH_CAP\s*=\s*10000/', $abSrc));
check('searchAll() defined', \str_contains($abSrc, 'private function searchAll('));
check("searchAll paginates via 'offset'", (bool) \preg_match("/'offset'\s*=>\s*\\\$offset/", $abSrc));
check('GetSuggestions clamps iLimit', (bool) \preg_match('/min\(self::NC_SEARCH_PAGE,\s*\$iLimit\)/', $abSrc));

echo "E. Functional simulation of searchAll\n";
$sim = <<<'SIM'
<?php
namespace Smail\Engine\Providers\AddressBook {
    interface AddressBookInterface {}
}
namespace Smail\Engine\Providers\AddressBook\Classes {
    class Contact { public $id; public $IdContactStr; public $ReadOnly; public $AddressBookName; public function setVCard($v) {} public $vCard; }
}
namespace Smail\Mail\Log {
    trait Inherit { protected function logException($e): void {} protected function logWrite($s): void {} }
}
namespace Sabre\VObject\Component {
    class VCard {}
}
namespace OCP\Contacts {
    interface IManager {}
}
namespace {
    class FakeManager implements \OCP\Contacts\IManager {
        public array $calls = [];
        public int $total;
        public function __construct(int $total) { $this->total = $total; }
        public function search($pattern, $props, $options = []) {
            $limit = $options['limit'] ?? -1;
            $offset = $options['offset'] ?? 0;
            if ($limit < 1 || $limit > 500) {
                throw new \InvalidArgumentException('Parameter limit must be between 1 and 500');
            }
            $this->calls[] = [$limit, $offset];
            $n = max(0, min($limit, $this->total - $offset));
            return $n ? array_fill(0, $n, ['UID' => 'u', 'FN' => 'f']) : [];
        }
        public function isEnabled(): bool { return true; }
        public function getUserAddressBooks(): array { return []; }
    }

    require getenv('AB_PATH');

    $reflect = new ReflectionClass(NextcloudAddressBook::class);
    $searchAll = $reflect->getMethod('searchAll');
    $searchAll->setAccessible(true);
    $cmProp = $reflect->getProperty('cm');
    $cmProp->setAccessible(true);

    // Case 1: 1200 contacts — expect 3 calls (500/500/200), 1200 collected
    $ab = $reflect->newInstanceWithoutConstructor();
    $mgr = new FakeManager(1200);
    $cmProp->setValue($ab, $mgr);
    $res = $searchAll->invoke($ab, '', ['FN']);
    echo 'case1_count=' . count($res) . "\n";
    echo 'case1_calls=' . count($mgr->calls) . "\n";
    $maxLimit = max(array_map(fn($c) => $c[0], $mgr->calls));
    echo 'case1_maxlimit=' . $maxLimit . "\n";

    // Case 2: 20000 contacts — expect cap at 10000
    $ab2 = $reflect->newInstanceWithoutConstructor();
    $mgr2 = new FakeManager(20000);
    $cmProp->setValue($ab2, $mgr2);
    $res2 = $searchAll->invoke($ab2, '', ['FN']);
    echo 'case2_count=' . count($res2) . "\n";

    // Case 3: empty book — no crash, zero results
    $ab3 = $reflect->newInstanceWithoutConstructor();
    $cmProp->setValue($ab3, new FakeManager(0));
    echo 'case3_count=' . count($searchAll->invoke($ab3, '', ['FN'])) . "\n";
}
SIM;
\file_put_contents('/tmp/nc_ab_searchall_sim.php', $sim);
\putenv('AB_PATH=' . $abPath);
$out = (string) \shell_exec('AB_PATH=' . \escapeshellarg($abPath) . ' php /tmp/nc_ab_searchall_sim.php 2>&1');
check('sim: 1200 contacts fully collected', \str_contains($out, 'case1_count=1200'), $out);
check('sim: 3 paginated calls for 1200', \str_contains($out, 'case1_calls=3'));
check('sim: no call exceeds limit 500', \str_contains($out, 'case1_maxlimit=500'));
check('sim: 20000 contacts capped at 10000', \str_contains($out, 'case2_count=10000'));
check('sim: empty book returns 0', \str_contains($out, 'case3_count=0'));

echo "\n== test_nc_contacts_limit_and_backtick: {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
