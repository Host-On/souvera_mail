# CM-Team Emergency: souvera_mail 0.13.19 bündeln

**TL;DR:** Du müsst am Deploy-Timing NICHTS ändern. Der Grund weshalb der
Post-Bootstrap-Warmup in Agent 1.8.14 trotz aller korrekten Config-Fixes
fehlgeschlagen ist, war ein Bug **in `souvera_mail 0.13.18` selbst** —
nicht im CM-Ablauf.

## Was war der Bug?

`occ souvera_mail:warmup-oidc` (0.13.18) hat versucht, Stalwart's OIDC-
Provider-Cache mit einem `description`-only `x:Directory/set` + `ReloadSettings`
zurückzusetzen. Das reicht nicht: Stalwart 0.16 keyed den Cache auf
`issuerUrl` + `requireAudience` — eine Beschreibungsänderung ist für den
Cache ein No-Op, und `ReloadSettings` allein invalidiert ihn nicht.

## Was ist der Fix (0.13.19)?

Der Warmup macht jetzt einen **Trailing-Slash-Flip-Flop von `issuerUrl`**:

1. `issuerUrl` von `https://<host>` auf `https://<host>/` ändern +
   `ReloadSettings` + 500 ms Pause.
2. Zurück auf `https://<host>` + `ReloadSettings` + `InvalidateCaches`.

Persistente Config bleibt identisch, aber Stalwart's Runtime-OIDC-Cache
wird komplett neu initialisiert (Discovery + JWKS re-fetch). Live-verifiziert
2026-07-01 16:15 UTC auf `fccec267-nc34-web` (VM 117):

```
$ occ souvera_mail:warmup-oidc
Stalwart OIDC cache warmed successfully.
  probe user:            scadmin
  initial probe status:  401   ← cold cache after fresh deploy
  directories touched:   iysqmtu1noqa
  final probe status:    200   ← healed by flip-flop
```

## Was der CM-Agent tun muss

**Genau eine Änderung:** die im CM-Template gebündelte `souvera_mail`-App
von `0.13.18` auf `0.13.19` bumpen. Nichts sonst.

- Kein neuer Bootstrap-Step nötig.
- Kein Timing-Fix nötig — die `warmup-oidc`-Call darf weiterhin direkt
  nach `souvera_mail:setup` laufen (dann liefert initial_probe=401,
  Flip-Flop heilt, final_probe=200, exit 0).
- Kein Retry-Loop nötig — der Command ist idempotent + self-healing.

Konkret: wo auch immer der CM sein `custom_apps/souvera_mail/` Tarball
oder Git-Snapshot her hat, den auf 0.13.19 ziehen. Verify via:

```bash
sudo -u www-data php /var/www/nextcloud/occ souvera_mail:status | grep version
# muss "version: 0.13.19" zeigen
```

## Für die Cloud die JETZT kaputt live ist

`fccec267-nc34-web` (VM 117 auf prod-fra7-wk04) ist bereits geheilt —
ich hab 0.13.19 dort deployed und den Warmup laufen lassen. `scadmin`
und alle anderen `souvera-users`-Member können sich einloggen.

Für jede andere Cloud die noch mit 0.13.18 ausgerollt wurde und den
Fehler zeigt, reicht ein einmaliger:

```bash
sudo -u www-data php /var/www/nextcloud/occ souvera_mail:warmup-oidc
```

Nach diesem einen Aufruf ist der Cluster durchgehend gesund — der
0.13.18-Bug ist der Bootstrap-Moment; ist der Cache einmal warm, bleibt
er warm.

## Warum das die letzte Iteration in dieser Sache ist

`tests/test_warmup_oidc_command.php` (36 Assertions) pinnt:
- Flip-Flop-Toggle des trailing slash
- Zwei `Directory/set`-Updates pro OIDC-Directory (flip + restore)
- Zwei `ReloadSettings`-Actions (nach jedem Half-Flip)
- Ein `InvalidateCaches`-Action am Ende
- Client-seitiges `@type=Oidc` Filtering (Stalwart's `x:Directory/query`
  unterstützt kein Filtern auf `@type`)

Regression wird also im CI erkannt, bevor die App wieder in einen CM-
Build eingeht.
