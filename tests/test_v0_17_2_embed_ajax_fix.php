<?php
/**
 * v0.17.2 — Regression pin for the /embed AJAX 404 fix.
 *
 * Operator report to v0.17.1:
 *   „/embed liefert weiterhin: 'An error occurred. Please refresh
 *    the page and try again. Error: Network response error: 404'"
 *
 * Root cause: SnappyMail's boot.js Zeile 4 built the AJAX URL as
 * `doc.location.pathname.replace(/\/+$/,'') + '/?/' + path`. On the
 * standalone route this evaluates to `/apps/souvera_mail/embed/?/...`.
 * Symfony's route matcher is trailing-slash-strict for NAMED routes
 * (unlike PathInfo-style plain URL matching) and does NOT accept
 * `/embed/` for a route registered as `/embed` — hence 404, hence
 * "Please refresh the page".
 *
 * Fix: strip a trailing `/embed` segment in both places SnappyMail
 * builds URLs from the current pathname:
 *   - `static/js/boot.js` (initial AppData fetch)
 *   - `static/js/app.js`  (BASE constant used by every subsequent request)
 *
 * Downstream effect: every SnappyMail HTTP request now lands at
 * `/apps/souvera_mail/?/...` — the SAME URL space as the normal
 * in-Nextcloud view — no separate POST route registration needed
 * (though v0.17.1's `page#embedPost` still ships as a belt-and-braces
 * safety-net for anyone who insists on POSTing straight to /embed).
 */
declare(strict_types=1);

$passes = [];
$failures = [];
$a = static function (bool $ok, string $label) use (&$passes, &$failures): void {
    if ($ok) { echo "PASS: {$label}\n"; $passes[] = $label; }
    else     { echo "FAIL: {$label}\n"; $failures[] = $label; }
};

// -------------------------------------------------------------------
// 1. boot.js patch — the initial AppData fetch must strip /embed
//    before appending '/?/' + path.
// -------------------------------------------------------------------
$boot = (string) \file_get_contents('/app/app/smail/v/current/static/js/boot.js');
$a(\str_contains($boot, "replace(/\\/embed\\/?\$/,'')"),
    'boot.js strips a trailing /embed (optional slash) before URL construction');
$a(\str_contains($boot, "replace(/\\/+\$/,'')"),
    'boot.js still strips trailing slashes AFTER the /embed strip (belt+braces)');

// -------------------------------------------------------------------
// 2. app.js BASE constant — every subsequent request uses this.
// -------------------------------------------------------------------
$app = (string) \file_get_contents('/app/app/smail/v/current/static/js/app.js');
// Count how many BASE = doc.location.pathname... occurrences there are;
// there should be EXACTLY one, and it must include the /embed strip.
$baseDefs = \preg_match_all(
    "#BASE\\s*=\\s*doc\\.location\\.pathname\\.replace\\(/\\\\/embed\\\\/\\?\\\$/,''\\)\\.replace\\(/\\\\/\\+\\\$/,''\\)#",
    $app
);
$a($baseDefs === 1,
    'app.js BASE constant strips /embed BEFORE stripping trailing slashes (exactly one occurrence)');

// Ensure the ORIGINAL un-patched pattern is gone.
$unpatched = \preg_match(
    "#BASE\\s*=\\s*doc\\.location\\.pathname\\.replace\\(/\\\\/\\+\\\$/,''\\)\\s*\\+\\s*'/'#",
    $app
);
$a(!$unpatched,
    'app.js has no un-patched BASE definition left (the vulnerable one is gone)');

// -------------------------------------------------------------------
// 3. Behavioural sim — evaluate the regex chain against every
//    plausible URL and check that the final request URL matches
//    the SAME `/` route that in-NC views use.
// -------------------------------------------------------------------
$stripEmbed = static function (string $pathname): string {
    // Mirror the JS: pathname.replace(/\/embed\/?$/,'').replace(/\/+$/,'') + '/'
    $s = (string) \preg_replace('#/embed/?$#', '', $pathname);
    $s = (string) \preg_replace('#/+$#', '', $s);
    return $s . '/';
};
$a($stripEmbed('/apps/souvera_mail/embed') === '/apps/souvera_mail/',
    'behavioural: /apps/souvera_mail/embed  →  /apps/souvera_mail/');
$a($stripEmbed('/apps/souvera_mail/embed/') === '/apps/souvera_mail/',
    'behavioural: /apps/souvera_mail/embed/ →  /apps/souvera_mail/');
$a($stripEmbed('/apps/souvera_mail/') === '/apps/souvera_mail/',
    'behavioural: /apps/souvera_mail/ (in-NC view) unchanged');
$a($stripEmbed('/apps/souvera_mail') === '/apps/souvera_mail/',
    'behavioural: /apps/souvera_mail (no slash) normalises with trailing slash');
$a($stripEmbed('/apps/other-app/embed') === '/apps/other-app/',
    'behavioural: the strip is generic (works for any app path that ends in /embed)');
$a($stripEmbed('/apps/souvera_mail/settings/embed') === '/apps/souvera_mail/settings/',
    'behavioural: only strips /embed at the END of the pathname (not a middle segment)');
// An unrelated path that contains "embed" as a non-terminal segment
// must NOT be modified.
$a($stripEmbed('/apps/souvera_mail/embed-plus-more') === '/apps/souvera_mail/embed-plus-more/',
    'behavioural: partial-word "embed-plus-more" is left alone');

// -------------------------------------------------------------------
// 4. v0.17.1 POST route stays put as a belt-and-braces safety net
//    (anyone POSTing directly to /embed still gets served).
// -------------------------------------------------------------------
$routes = (string) \file_get_contents('/app/appinfo/routes.php');
$a(\str_contains($routes, "'name' => 'page#embedPost'"),
    'v0.17.1 page#embedPost still registered (safety-net for direct POST /embed)');

// -------------------------------------------------------------------
// 5. Version bump
// -------------------------------------------------------------------
$info = (string) \file_get_contents('/app/appinfo/info.xml');
$pkg  = (string) \file_get_contents('/app/package.json');
$a((bool) \preg_match('#<version>0\.(?:1[7-9]|[2-9]\d)\.\d+</version>#', $info),
    'info.xml bumped to 0.17.2 or higher');
$a((bool) \preg_match('#"version"\s*:\s*"0\.(?:1[7-9]|[2-9]\d)\.\d+"#', $pkg),
    'package.json bumped to 0.17.2 or higher');

echo "\n========================================\n";
echo "PASSED: " . count($passes) . " / " . (count($passes) + count($failures)) . "\n";
if (!empty($failures)) {
    echo "FAILURES:\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL TESTS PASSED\n";
