# Sieve — Funktionsweise, Korrektheit und bekannte Limitierungen

Stand: v1.2.61 (2026-09-12)

## Architektur

- **Speichern/Aktivieren**: über JMAP-Sieve (`urn:ietf:params:jmap:sieve`)
  gegen Stalwart — keine ManageSieve-Verbindung (Port 4190) nötig.
- **„Filter nachträglich anwenden"**: `SieveApplyService` parst das aktive
  Skript mit dem `MiniInterpreter` (`lib/Sieve/`), holt die Nachrichten des
  gewählten Ordners per JMAP und wendet Aktionen (fileinto/redirect/
  discard/addflag) per `Email/set` an.

## Korrektheit (seit v1.2.61)

- **Body-Tests** (`body :contains/:is/:matches/:regex`) werden geparst und
  ausgewertet. Der Plain-Text-Body wird beim Nachträglich-Anwenden NUR dann
  mitgeladen, wenn das aktive Skript tatsächlich body-Tests enthält
  (`MiniInterpreter::rulesUseBody()`), begrenzt auf 64 KB pro Body-Part.
- **Verschachtelte Zielordner** (Tiefe ≥ 2, z. B. `INBOX/Clients/Acme`)
  werden über die rekonstruierte `parentId`-Kette aufgelöst — der JMAP-
  Mailbox-`name` enthält nur den Blattnamen.
- **UTF-8/EAI**: Vergleiche sind `mb_strtolower`/`mb_stripos`-basiert;
  der Adress-Regex akzeptiert Umlaut-Domains (`test@österreich.at`).

## Bekannte Limitierungen

1. **`imap4flags` (addflag/removeflag/setflag) wird von Stalwart 0.16 in
   JMAP-Sieve-Scripts NICHT unterstützt.** `SieveScriptService::rebuildActiveScript`
   entfernt diese Anforderungen/Befehle beim Zusammenbau des Hauptskripts,
   damit Stalwart das Script nicht ablehnt. Konsequenz: Sieve-Regeln, die
   „Gelesen"/„Markiert"-Aktionen setzen, wirken bei der AUTOMATISCHEN
   Zustellung nicht. Der „nachträglich anwenden"-Pfad setzt Flags über
   `executeFlagAdds` (JMAP-Keywords) — dort funktioniert es.
2. **`envelope`-Tests** werden beim Nachträglich-Anwenden über die
   Mail-Header (`From`/`To`) emuliert, nicht über den echten SMTP-Envelope.
   Bei Mailern, deren Envelope stark vom Header-From abweicht (Newsletter),
   kann die Nachträglich-Anwenden-Bewertung von der echten Zustellungs-
   Filterung abweichen.
3. **Body-Tests greifen nur bei Zustellung bzw. Nachträglich-Anwenden** —
   der Bayes/PMG und Stalwart selbst werten Body-Regeln anhand ihres
   eigenen Parsers aus; Abweichungen bei exotischen Encodings sind möglich.
