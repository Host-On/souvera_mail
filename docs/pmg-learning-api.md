# PMG Spam/Ham-Learning — API-Vertrag für die Webmail-UI (smail)

Backend: souvera_mail (OCS, prefix `/apps/souvera_mail/api/v2/pmg`).
Die Endpunkte laufen im User-Kontext und melden die ORIGINAL-Mail (JMAP-Blob,
RFC 822) an die PMG Learning API (mx10, alle 5 Nodes).

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
occ config:app:set souvera_mail pmg.api_url --value https://mx10.mail-gw.org:9911
```
Status prüfen: `GET /status` → `configured: true`.
Firewall: Der NC-Server muss in den PMG-Allowlist-Netzen stehen (sonst 403).
