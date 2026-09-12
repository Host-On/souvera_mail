# PMG Spam/Ham-Learning — API-Vertrag für die Webmail-UI (smail)

Backend: souvera_mail (OCS, prefix `/apps/souvera_mail/api/v2/pmg`).
Die Endpunkte laufen im User-Kontext und melden die ORIGINAL-Mail (JMAP-Blob,
RFC 822) an die PMG Learning API (mx10, alle 5 Nodes).

## Architektur (serverseitige Meldung)

Das Webmail meldet PMG-Spam/Ham **serverseitig**: Der JMAP-Proxy-Hook in
`V2JmapProxy::call()` erkennt Junk-Moves (Email/set mit `mailboxIds`-Patch)
und reiht die Meldung als `PmgReportJob` in den Background-Job ein — kein
fire-and-forget-Call mehr aus der UI. Der Shield-Quarantäne-Release lernt ham
ebenfalls serverseitig (souvera_shield ≥ 4.0.68). Die Endpunkte bleiben für
externe API-Clients und mobile Apps bestehen.

## Endpunkte

| Method | Path | Body | Wirkung |
|---|---|---|---|
| POST | `/report/spam` | `{accountId, emailId}` | learn/spam + Vermerk „selbst gemeldet" |
| POST | `/report/ham` | `{accountId, emailId}` | Vermerk prüfen: **selbst gemeldet → forget/spam** (Rücknahme), **sonst → learn/ham** (False Positive) |
| POST | `/report/forget` | `{accountId, emailId}` | Letzte Meldung zurücknehmen (egal welche Klasse) |
| GET | `/status` | — | Konfiguration + eigene Meldungen (letzte 50) |

## Aktionen in der Webmail-UI (empfohlener Aufruf)

| UI-Aktion | Aufruf |
|---|---|
| User verschiebt Mail in Junk-Ordner | `POST /report/spam` |
| User verschiebt Mail aus Junk-Ordner | `POST /report/ham` (die Unterscheidung Rücknahme/False-Positive macht das Backend) |
| User klickt explizit „Kein Spam" | `POST /report/ham` |
| User korrigiert eine Fehlmeldung | `POST /report/forget` |

## Antwort

```json
{ "operation": "learn", "class": "spam", "success": true, "partial": false, "nodes_ok": "5/5" }
```
`partial: true` = einige Nodes gelernt, einige nicht (kein blindes Retry —
Duplikate sind harmlos, ein erneuter Aufruf ist sicher).

## Konfiguration (einmalig)

```
occ config:app:set souvera_mail pmg.api_token --value <PMG-TOKEN>
occ config:app:set souvera_mail pmg.api_url --value https://mx10.mail-gw.org/learn-api
```
Status prüfen: `GET /status` → `configured: true`.
Firewall: Der NC-Server muss in den PMG-Allowlist-Netzen stehen (sonst 403).

## Mobile-Clients (Android/iOS)

Die Apps sprechen JMAP direkt mit Stalwart und melden **selbst** (Phase-3-
Dokumentation für die App-Teams):

- Nach erfolgreichem Move in Junk → `POST /apps/souvera_mail/api/v2/pmg/report/spam`
  mit Body `{accountId, emailId}`.
- Nach Move aus Junk in die Inbox → `POST /apps/souvera_mail/api/v2/pmg/report/ham`
  (die Unterscheidung Rücknahme/False-Positive macht das Backend).

Fire-and-forget: nicht awaiten/blockieren, Fehler nur loggen. Auth: NC-Session
des App-Logins. `accountId` = JMAP-accountId-String (mitgeben).

Beispiel-Response:

```json
{ "operation": "learn", "class": "spam", "success": true, "partial": false, "nodes_ok": "5/5" }
```

Ham-Fehlerfälle (400 mit `error`) nur loggen.
