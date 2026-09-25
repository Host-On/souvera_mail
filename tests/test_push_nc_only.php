<?php
/**
 * Guard: Push-Pipeline — NC-Notification-only (Hard Cut v1.3.0).
 *
 * Push delivery moved fully to the Nextcloud notification pipeline
 * (notifications app → E2E-encrypted push proxy push.souvera.eu →
 * FCM/APNs). The direct FCM/APNs path and the device-token registry
 * are REMOVED. This guard freezes that state:
 *
 *   - no FcmClient / ApnsClient / DeviceToken* classes anywhere in lib/
 *   - webhook controller: nc-only pushToUser (no push_mode switch)
 *   - poller: group-based user sweep + oc_preferences state (no tokens)
 *   - routes: no /devices deviceToken endpoints
 *   - migration dropping the old registry exists
 *   - MailPushNotifier: notification pipeline, no vendor clients
 *
 * Run: php tests/test_push_nc_only.php
 */
declare(strict_types=1);

$failures = [];
$passes = [];
function assertTrue(bool $c, string $m, array &$p, array &$f): void {
    if ($c) { $p[] = $m; echo "PASS: $m\n"; }
    else    { $f[] = $m; echo "FAIL: $m\n"; }
}

$lib = __DIR__ . '/../lib';

// 1) Entfernte Klassen sind wirklich weg
foreach ([
    'lib/Service/FcmClient.php',
    'lib/Service/ApnsClient.php',
    'lib/Service/DeviceTokenService.php',
    'lib/Controller/DeviceTokenController.php',
    'lib/Db/DeviceToken.php',
    'lib/Db/DeviceTokenMapper.php',
    'lib/Command/Push/Test.php',
] as $rel) {
    assertTrue(!is_file(__DIR__ . '/../' . $rel), "gelöscht: $rel", $passes, $failures);
}

// 2) Keine Referenzen mehr im laufenden Code (Doku-Kommentare in alten
//    Migrationen sind bewusst erlaubt — sie beschreiben Historie)
$refs = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
    $src = file_get_contents($file->getPathname()) ?: '';
    if (preg_match('/FcmClient|ApnsClient|DeviceToken(Mapper)?\b/', $src)) {
        $refs[] = str_replace(dirname(__DIR__) . '/', '', $file->getPathname());
    }
}
assertTrue($refs === [], 'keine FCM/APNs/DeviceToken-Referenzen in lib/ (gefunden: ' . implode(', ', $refs) . ')', $passes, $failures);

// 3) Webhook: nc-only
$wh = file_get_contents($lib . '/Controller/StalwartWebhookController.php') ?: '';
assertTrue(str_contains($wh, 'pushToUser'), 'Webhook: pushToUser vorhanden', $passes, $failures);
assertTrue(!str_contains($wh, 'PUSH_MODE'), 'Webhook: kein push_mode-Schalter mehr', $passes, $failures);
assertTrue(str_contains($wh, 'notifier->notify('), 'Webhook: Versand über MailPushNotifier', $passes, $failures);

// 4) Poller: Admin-Pfad + Gruppen-Sweep + Preferences-State
$poller = file_get_contents($lib . '/Cron/MailPushPoller.php') ?: '';
assertTrue(str_contains($poller, 'RESTRICTED_GROUP_ID'), 'Poller: User-Quelle = souvera-users-Gruppe', $passes, $failures);
assertTrue(str_contains($poller, 'getUserValue') && str_contains($poller, 'setUserValue'), 'Poller: State in oc_preferences', $passes, $failures);
assertTrue(str_contains($poller, 'notifier->notify('), 'Poller: Versand über MailPushNotifier', $passes, $failures);
assertTrue(!str_contains($poller, 'DeviceToken'), 'Poller: keine Token-Registry mehr', $passes, $failures);
assertTrue(str_contains($poller, 'jmapCallAsAdmin') && str_contains($poller, 'lookupAccountIdByEmail'), 'Poller: Anreicherung/Snapshot über Admin-Pfad (kein User-Bearer)', $passes, $failures);
assertTrue(!str_contains($poller, 'resolveBearer'), 'Poller: kein OIDC-Bearer mehr (fragiler Webhook/Cron-Kontext)', $passes, $failures);

// 4b) Enricher: Admin-JMAP (der User-Bearer-Pfad war der stille Tod der
//     Anreicherung — Pushes kamen nur als „Neue E-Mail" ohne Inhalt)
$enricher = file_get_contents($lib . '/Service/MailEnricherService.php') ?: '';
assertTrue(str_contains($enricher, 'jmapCallAsAdmin'), 'Enricher: Email/get über Admin-JMAP', $passes, $failures);
assertTrue(!str_contains($enricher, 'resolveBearer'), 'Enricher: kein User-Bearer mehr', $passes, $failures);
assertTrue(str_contains($enricher, "logger->warning"), 'Enricher: Fehlerversagen auf WARNING sichtbar (statt DEBUG)', $passes, $failures);

// 4c) Webhook: kein toter payloadFrom-Stufe-1-Pfad, Anomalie-Warnung vorhanden
$webhookSrc = file_get_contents($lib . '/Controller/StalwartWebhookController.php') ?: '';
assertTrue(!str_contains($webhookSrc, 'payloadFrom'), 'Webhook: toter payloadFrom-Pfad entfernt', $passes, $failures);
assertTrue(str_contains($webhookSrc, 'carried no data.documentId'), 'Webhook: Warnung bei fehlendem documentId', $passes, $failures);
assertTrue(str_contains($webhookSrc, 'notification assembled [len'), 'Webhook: Inhalts-Längen-Log je Push', $passes, $failures);

// 5) Notifier: Notification-Pipeline
$notifier = file_get_contents($lib . '/Service/MailPushNotifier.php') ?: '';
assertTrue(str_contains($notifier, 'notificationManager->notify('), 'Notifier: NC-NotificationManager', $passes, $failures);
assertTrue(!str_contains($notifier, 'PUSH_MODE'), 'Notifier: keine Push-Mode-Konstanten mehr', $passes, $failures);

// 5b) INotifier registriert (ohne ihn skipped die Push-Pipeline: prepare()
//     wirft IncompleteParsedNotificationException — Mail-Pushes kamen nie an)
assertTrue(is_file($lib . '/Notification/MailNotifier.php'), 'MailNotifier-Klasse vorhanden', $passes, $failures);
$app = file_get_contents($lib . '/AppInfo/Application.php') ?: '';
assertTrue(str_contains($app, 'registerNotifierService'), 'Application: registerNotifierService', $passes, $failures);
$mailNotifier = is_file($lib . '/Notification/MailNotifier.php') ? (file_get_contents($lib . '/Notification/MailNotifier.php') ?: '') : '';
assertTrue(str_contains($mailNotifier, 'implements INotifier'), 'MailNotifier implementiert INotifier', $passes, $failures);
assertTrue(str_contains($mailNotifier, 'setParsedSubject'), 'MailNotifier setzt ParsedSubject (Push-Pflicht)', $passes, $failures);

// 6) Routen: keine deviceToken-Endpunkte
$routes = file_get_contents(__DIR__ . '/../appinfo/routes.php') ?: '';
assertTrue(!str_contains($routes, 'deviceToken#'), 'Routes: keine deviceToken-Endpunkte', $passes, $failures);

// 7) Drop-Migration vorhanden
$drop = glob(__DIR__ . '/../lib/Migration/Version*Date*.php');
$found = false;
foreach ($drop as $m) {
    $src = file_get_contents($m) ?: '';
    if (str_contains($src, 'dropTable') && str_contains($src, 'souvera_mail_devicetoken')) { $found = true; }
}
assertTrue($found, 'Migration: Drop der alten Device-Registry vorhanden', $passes, $failures);

// 8) Syntax-Check der betroffenen Dateien
foreach ([
    $lib . '/Controller/StalwartWebhookController.php',
    $lib . '/Cron/MailPushPoller.php',
    $lib . '/Service/MailPushNotifier.php',
] as $file) {
    $out = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
    assertTrue(str_contains((string) $out, 'No syntax errors'), 'php -l: ' . basename($file), $passes, $failures);
}

// Ergebnis
if ($failures !== []) {
    echo "\nFAILURES (" . count($failures) . "):\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "\nALL TESTS PASSED\n";
exit(0);
