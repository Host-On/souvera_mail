# Verbesserungs-Backlog — souvera_mail

Ergebnis des Gemini-Gesamtreviews (2026-09-12) — umgesetzte Wellen:

- **Welle 1 (v1.2.60)**: CSRF-Hardening v2-API, VacationSync-Rebuild-Guard,
  Cron-Job-Locks, Mailbox-Rollen-Cache, FOUC-Quick-Wins (parallele
  Shared-Mailbox-Loads, Gravatar-Preload, Dark-Mode-Iframe, Skeleton).
- **Welle 2 (v1.2.61)**: Sieve-Body-Tests, verschachtelte Ordner-Auflösung,
  UTF-8/EAI-Matching, CSS-Extraktion aus dem JS-Bundle (FOUC-Root-Fix),
  `docs/sieve-limitations.md`.
- **Welle 3 (v1.2.62)**: `MailEnricherService` (Dedup Poller/Webhook),
  dieses Backlog-Dokument.

Folgende Punkte sind **bewusst zurückgestellt** — jeder mit Grund:

## 1. Loopback-HTTP zu souvera_shield durch DI ersetzen (BLOCKER-Kandidat)

`V2SpamController` (list/view/release/delete/blacklist/identities) und
`PmgController::fetchShieldRawMail` rufen `souvera_shield` per HTTP-Loopback
auf der eigenen Instanz. Risiko: PHP-FPM-Worker-Exhaustion unter Last.
**Gemildert** durch Session-Close + 30s-Timeout am PMG-Pfad; die restlichen
Pfade brauchen das Muster noch.

**Richtige Lösung**: Cross-App-DI (`\OCP\Server::get(\OCA\SouveraShield\…)`).
Aufwand: mittel-hoch — erfordert Absprache mit dem Shield-Repo (stabile
interne Service-API, App-Abhängigkeits-Deklaration in info.xml, Feature-
Detection falls Shield deaktiviert). Eigenes Release-Paar (mail+shield)
nach Konsolidierung.

## 2. Externe IMAP/SMTP-Konten: oc_appconfig → eigene Tabelle

`ExternalAccountService` speichert `ext_account.<uid>.<hash>` in
`oc_appconfig` (wird instance-weit gecacht → Degradierung bei vielen
Nutzern). Lösung: Migration `oc_souvera_mail_ext_accounts` + Datenübernahme.
Aufwand: mittel (Migration + Mapper + Service-Umbau). Eigenes Release.

## 3. ext-imap ersetzen (PHP 8.4-Kompatibilität)

`ExternalImapService` nutzt die native `ext-imap`-Erweiterung (ab PHP 8.4
entfernt). Lösung: reine PHP-Bibliothek (z. B. webklex/php-imap). Aufwand:
mittel-hoch (Protokollverhalten verifizieren, Credentials-Handling,
Testabdeckung). Eigenes Release.

## 4. PHPUnit-Testinfrastruktur für das Backend

Die meisten `tests/*.php` sind statische String-Assertions und teils
veraltet (harte Versions-/Pfad-Assertions aus alten Releases — die
Vollsuite zeigt ~50 vorbestehende Fehlschläge, u. a. falsche Mountpunkte
`/projects/souvera_mail`, `/app`). Lösung: PHPUnit wie im Shield-Repo,
echte Verhaltenstests für MiniInterpreter, SieveApplyService, Push-Pipeline;
alte Release-Assertions ausmustern. Aufwand: hoch, langfristig.

## 5. MailPushPoller: JMAP-Batching

Pro Nutzer 2–4 sequenzielle JMAP-Calls pro Tick. Lösung: Mailbox/query +
Email/query in einem Request-Envelope (RFC 8620 §3.7 — Result-References
können hier NICHT genutzt werden, da filter.inMailbox verschachtelt ist;
stattdessen beide Calls in einem Envelope). Durch Webhook als Primärpfad
Priorität mittel.

## 6. MigrationCleanup Phase 2 (Purging) — OPERATOR-ENTSCHEIDUNG

Das Löschen abgeschlossener Migrations-Jobs ist im Code bewusst vertagt
(Audit-Trails für Regulatorik). Aktivierung erst nach Entscheidung über
die Aufbewahrungsfrist — bewusst NICHT im Rahmen dieses Reviews geändert.

## 7. lib/Sieve/Types.php: PSR-4-Split — NICHT durchführen

Die Fünf-Klassen-eine-Datei-Struktur mit Shim-Dateien ist ABSICHT
(v0.14.44: Toleranz gegen stale Composer-Classmaps auf Bestands-
Deployments). Ein Split erfordert eine koordinierte Release-Strategie
(z. B. nach 2 stabilen Releases mit aktuellem Classmap-Rebuild).
