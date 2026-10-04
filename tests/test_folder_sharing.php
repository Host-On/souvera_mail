<?php

declare(strict_types=1);

/**
 * Guard-Test: Ordner-Freigabe (v1.7.0)
 * Prüft statisch: Routen, Rights-Mapping, Self-Share-Block,
 * 64-Byte-Notification-Subject, Frontend-Wiring, L10n.
 */

$fail = 0;
$ok = static function (bool $cond, string $msg) use (&$fail): void {
	if ($cond) {
		echo "  ok: $msg\n";
	} else {
		$fail++;
		echo "FAIL: $msg\n";
	}
};

$root = \dirname(__DIR__);

// --- Backend ---
$routes = (string) \file_get_contents($root . '/appinfo/routes.php');
foreach (['v2_share#list' => 'GET', 'v2_share#share' => 'POST', 'v2_share#revoke' => 'DELETE', 'v2_share#searchUsers' => 'GET'] as $name => $verb) {
	$ok(\str_contains($routes, "'name' => '{$name}'") && \str_contains($routes, "'verb' => '{$verb}'"),
		"Route {$name} ({$verb}) registriert");
}
$ctl = (string) \file_get_contents($root . '/lib/Controller/V2ShareController.php');
// Route-Name ↔ Controller-Methode: JEDER 'v2_share#X'-Eintrag braucht public function X()
foreach (['list', 'searchUsers', 'share', 'revoke'] as $method) {
	$ok(\preg_match('/public function ' . $method . '\\(/', $ctl) === 1,
		"Controller-Methode {$method}() existiert (Route-Match)");
}
$ok(\str_contains($ctl, "'mayReadItems' => true,\n\t\t'mayAddItems' => true,\n\t\t'mayRemoveItems' => true,\n\t\t'maySetSeen' => true,\n\t\t'maySetKeywords' => true,"),
	'Schreib-Rechte: lesen+verschieben+markieren (ohne maySubmit/mayDelete)');
$ok(!\str_contains($ctl, "'maySubmit'"), 'kein maySubmit in v1');
$ok(\str_contains($ctl, "Eigene Ordner können nicht mit sich selbst geteilt werden"), 'Self-Share-Block');
$ok(\str_contains($ctl, "\\mb_strcut('Ordner-Freigabe von '"), 'Notification-Subject byte-safe (64 B)');
$ok(\str_contains($ctl, "shareWith' => \\count(\$newShareWith) > 0 ? \$newShareWith : null"), 'shareWith leer → null (Entzug möglich)');
$ok(\str_contains($ctl, 'collectChildren'), 'Rekursives Teilen (Kinder sammeln)');
$ok(\str_contains($ctl, 'getByEmail'), 'Grants lösen Email → NC-Uid auf');
$ok(\str_contains($ctl, 'principalIdFromAccountKey'), 'AccountId-Key-Rückauflösung (base32)');

// --- Gemini-Review-Fixes (Runde 1) ---
	$ok(\str_contains($ctl, "'properties' => ['id', 'parentId', 'name', 'role', 'shareWith']"),
		'Mailbox/get fragt shareWith MIT ab (kein Grant-Verlust)');
	$ok(\str_contains($ctl, "'ids' => null") && !\str_contains($ctl, 'Mailbox/query'),
		'Mailbox/get aller Mailboxen statt /query (nur IDs)');
	$ok(\substr_count($ctl, "'Mailbox/set'") === 1 && \str_contains($ctl, "'update' => \$update"),
		'Ein gebatchter Mailbox/set für Ordner + Kinder');
	$ok(\str_contains($ctl, "getParam('includeChildren')"),
		'Revoke akzeptiert includeChildren (kein geheimes Rest-Recht)');
	$ok(\strpos($ctl, 'applyShare($user, $mailboxId, $granteeUid, $permission') < \strpos($ctl, 'notifyShare($grantee->getUID()'),
		'Notification erst NACH erfolgreicher Freigabe');
	$ok(\str_contains($ctl, '$payload = $response->getData();'),
		'JSONResponse.getData() korrekt gelesen (Array, nicht JSON-String)');

	// --- v1.7.1 Fixes (Live-Test) ---
	$ok(\str_contains($ctl, '$this->userManager->search($q, 20)') && !\str_contains($ctl, 'searchDisplayName'),
		'User-Suche matched uid+Name+E-Mail (search statt searchDisplayName)');
	$app171 = (string) \file_get_contents($root . '/src-v2/App.vue');
	$ok(\preg_match('/if \(!shared\) \{[\s\S]{0,400}Ordner freigeben/', $app171) === 1,
		'Share-Eintrag für ALLE eigenen Ordner (inkl. Systemordner)');
	$ok(\strpos($app171, "CTX_ICONS.share") < \strpos($app171, "if (!isSystem && !shared)"),
		'Share-Eintrag VOR dem isSystem-Block (Posteingang freigebbar)');

	// --- Frontend ---
$dlg = (string) \file_get_contents($root . '/src-v2/components/ShareDialog.vue');
foreach (['mailboxId', 'granteeUid', 'permission', 'includeChildren'] as $field) {
	$ok(\str_contains($dlg, $field), "ShareDialog sendet {$field}");
}
$ok(\str_contains($dlg, 'granteeAccountId'), 'ShareDialog-Revoke mit granteeAccountId-Fallback');

$app = (string) \file_get_contents($root . '/src-v2/App.vue');
$ok(\str_contains($app, "t('Ordner freigeben…')") && \str_contains($app, '<ShareDialog v-if="shareMailbox"'), 'Kontextmenü + Dialog verdrahtet');

// --- L10n ---
$de = (string) \file_get_contents($root . '/l10n/de.js');
foreach (['Ordner freigeben…', 'Unterordner einbeziehen', 'Entziehen'] as $str) {
	$ok(\str_contains($de, '"' . $str . '"'), "de.js: {$str}");
}

echo $fail === 0 ? "\nALL TESTS PASSED\n" : "\n{$fail} FAILURES\n";
exit($fail === 0 ? 0 : 1);
