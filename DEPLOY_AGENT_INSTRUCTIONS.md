# Souvera Mail 0.13.18 — CloudManager Deploy-Agent Änderungsauftrag

> **Empfänger:** Der Agent, der das CloudManager-Deployment (Ansible/Terraform/…)
> für `souvera_mail` + `souvera_central` + Stalwart baut.
>
> **Status live:** ✅ am 2026-07-01 11:59 UTC manuell verifiziert auf dem
> Cluster `413346ae-…` (buxte.souvera.work / Stalwart 10.20.0.129 / NC-web
> 10.20.0.109). Alle Fixes unten sind live schon aktiv — sie müssen nur noch
> **in die Deploy-Templates** übernommen werden, damit jedes zukünftige Deploy
> sie automatisch mitbringt.

---

## 🔴 Root-Cause-Analyse (was war live kaputt)

Nach jedem frischen Proxmox-Redeploy lehnte Stalwart 0.16 jeden IMAP-
OAUTHBEARER / SMTP-XOAUTH2 / JMAP-Bearer-Versuch mit **`AUTHENTICATIONFAILED`
/ HTTP 401 „You have to authenticate first“** ab — *unabhängig davon, ob
`souvera_mail:setup` erfolgreich lief*.

Zwei Ursachen gleichzeitig. **Beide müssen im Deploy-Template gefixt werden**,
sonst kommt der Fehler beim nächsten Neuaufbau wieder.

### Ursache 1: Nginx 301-Redirect blockiert OIDC-Discovery

- Stalwart 0.16 (OIDC-Directory-Typ) macht beim ersten Bearer-Auth eine
  Discovery-Anfrage an `<issuerUrl>/.well-known/openid-configuration`.
- Das CloudManager-Nginx-Template (`/etc/nginx/sites-available/nextcloud`,
  Zeile ~87 im `.well-known` block) liefert dort einen **`301 → /index.php$request_uri;`**.
- Stalwart folgt dem Redirect nicht — es cached das Fetch-Fehlschlagen
  und schmeißt danach jeden gültigen JWT weg. Kein Log-Eintrag, kein Hinweis.

### Ursache 2: Fehlende `overwritehost` / `overwriteprotocol` in Nextcloud

- `config.php` enthält weder `overwritehost` noch `overwriteprotocol`.
- `trusted_domains` beginnt mit `localhost` (Index 0).
- H2CK/oidc greift bei URL-Generierung auf `trusted_domains[0]` zurück,
  wenn kein Request-Kontext da ist (occ CLI, Cron, Background-Jobs).
- Ergebnis: jeder aus solchen Kontexten ausgestellte JWT hat
  `iss = https://localhost`, was Stalwart natürlich ablehnt.
- **Kritisch:** auch wenn Ursache 1 gefixt ist, bleibt das Problem
  bestehen — jeder zweite Login schlägt fehl weil je nach Request-Path
  der Host-Header nicht durchgeschleift wird.

---

## ✅ Fix 1 — Nginx-Template patchen

**Datei:** dein CloudManager-Deploy-Template das
`/etc/nginx/sites-available/nextcloud` erzeugt.

**Vorher (defekt):**

```nginx
location ^~ /.well-known {
    location = /.well-known/carddav     { return 301 /remote.php/dav/; }
    location = /.well-known/caldav      { return 301 /remote.php/dav/; }
    location /.well-known/acme-challenge    { try_files $uri $uri/ =404; }
    location /.well-known/pki-validation    { try_files $uri $uri/ =404; }
    return 301 /index.php$request_uri;   # ← DAS ist die kaputte Zeile
}
```

**Nachher (richtig):**

```nginx
location ^~ /.well-known {
    location = /.well-known/carddav     { return 301 /remote.php/dav/; }
    location = /.well-known/caldav      { return 301 /remote.php/dav/; }
    location /.well-known/acme-challenge    { try_files $uri $uri/ =404; }
    location /.well-known/pki-validation    { try_files $uri $uri/ =404; }
    # OIDC-Discovery / webfinger / nodeinfo brauchen HTTP 200, nicht 301.
    # Stalwart 0.16 OIDC-Directory folgt dem 301 nicht → silent auth-fail.
    try_files $uri $uri/ /index.php$request_uri;
}
```

**Einzige Änderung:** `return 301 /index.php$request_uri;` →
`try_files $uri $uri/ /index.php$request_uri;`

**Nach dem Patch:**

```bash
nginx -t && systemctl reload nginx
# Verifikation:
curl -sSk -o /dev/null -w "%{http_code}\n" \
  "https://<MAIL_HOST>/.well-known/openid-configuration"
# Erwartet: 200 (nicht 301!)
```

Gilt zusätzlich auch für das Template `/etc/nginx/sites-available/nextcloud-template.conf`,
das dieselbe kaputte Zeile bei Zeile 103 hat. Beide Stellen fixen.

---

## ✅ Fix 2 — Nextcloud `overwritehost` + `overwriteprotocol` beim Bootstrap setzen

**Datei:** dein Ansible/Terraform/Shell-Playbook, das `config.php` seedet
oder direkt nach dem `occ maintenance:install` läuft.

**Zeile hinzufügen (idempotent):**

```bash
# Canonical URL für alle URLGenerator-Aufrufe pinnen — sonst fällt
# H2CK/oidc bei Requests ohne Host-Header (CLI, Cron, JMAP-Middleware)
# auf trusted_domains[0]=localhost zurück und mintet JWTs mit
# iss=https://localhost, die Stalwart als Issuer-Mismatch ablehnt.
sudo -u www-data php /var/www/nextcloud/occ config:system:set \
    overwritehost --value "<MAIL_HOST>"
sudo -u www-data php /var/www/nextcloud/occ config:system:set \
    overwriteprotocol --value "https"
```

Wobei `<MAIL_HOST>` **exakt** das ist, was in Stalwart als OIDC-issuerUrl
konfiguriert wurde (also `buxte.souvera.work` für den Buxtehude-Cluster,
generisch: der DNS-Name unter dem die Nextcloud öffentlich erreichbar ist).

**Wichtig:** darf **nicht** die interne IP oder ein Loadbalancer-Alias sein —
Stalwart macht einen exakten String-Compare zwischen JWT `iss` und OIDC-
Directory `issuerUrl`.

**Verifikation:**

```bash
sudo -u www-data php /var/www/nextcloud/occ config:system:get overwritehost
# → buxte.souvera.work
sudo -u www-data php /var/www/nextcloud/occ config:system:get overwriteprotocol
# → https
```

---

## ✅ Fix 3 — Post-Bootstrap: `souvera_mail:warmup-oidc` aufrufen

**Datei:** dein Playbook-Step nach `occ souvera_mail:setup` / `souvera_mail:bootstrap`.

**Neuer Step (idempotent, kann so oft laufen wie du willst):**

```bash
# Stalwart 0.16 caches OIDC-Discovery lazily. Wenn der erste Fetch beim
# Cold-Boot vor der Nginx-Fix-Zeit passiert ist, sitzt die negative
# Cache-Antwort fest bis ein Admin das Directory nudged. souvera_mail
# hat dafür einen Command:
sudo -u www-data php /var/www/nextcloud/occ souvera_mail:warmup-oidc --json
```

**Output-Beispiel (JSON), warm:**

```json
{
  "command": "souvera_mail:warmup-oidc",
  "probe_user": "scadmin",
  "initial_probe_status": 200,
  "admin_refresh": null,
  "final_probe_status": null,
  "ok": true,
  "errors": []
}
```

**Output-Beispiel (JSON), kalt-und-erfolgreich-genudged:**

```json
{
  "command": "souvera_mail:warmup-oidc",
  "probe_user": "scadmin",
  "initial_probe_status": 401,
  "admin_refresh": {"ok": true, "touched": ["iyrz2msylxqa"], "error": null},
  "final_probe_status": 200,
  "ok": true,
  "errors": []
}
```

**Exit-Code 0** bei `ok=true`, sonst 1 — im Playbook einfach mit
`failed_when: rc != 0` prüfen.

Der Command:
1. Mintet einen H2CK/oidc-JWT für den ersten `souvera-users`-Member
   (oder mit `--user <uid>` explizit).
2. Probet Stalwart `GET /jmap/session`. HTTP 200 → cache warm, fertig.
3. Sonst: Basic-Auth an Stalwart JMAP (Creds aus
   `souvera_central.stalwart_admin_user` + `_password` System-Config),
   `x:Directory/query` → für jedes OIDC-Directory ein `x:Directory/set`
   Update → `x:Action/set ReloadSettings` → neu proben.

Braucht keine extra Config, keine extra Ports, keine extra Secrets —
zieht alles aus den bereits von `souvera_central` gesetzten System-Config-
Keys.

---

## ✅ Fix 4 (empfohlen) — `cm_bootstrap.py` verlässt sich nicht mehr auf ID-Ordnung

In `/opt/stalwart-mail/cm_bootstrap.py` **Schritt 7** wird das OIDC-Directory
mit `first_id("Directory")` gefischt. Das ist fragil — wenn `souvera_central`
später weitere Directories (SQL/LDAP für Import-Migrationen) anlegt, greift
`first_id` den falschen Eintrag.

**Robuster (drop-in-Ersatz für die 4 Zeilen im Schritt 7):**

```python
if P.get("oidc_issuer"):
    apply_plan([{"@type": "create", "object": "Directory", "value": {"oidc": {
        "@type": "Oidc", "description": "Nextcloud OIDC",
        "issuerUrl": P["oidc_issuer"], "claimUsername": "email",
        "requireAudience": P.get("oidc_audience") or "smail"}}}])
    # Robust: enumeriere alle Directories, wähle den mit @type == 'Oidc'
    ls = subprocess.run(CLI + ["query", "Directory", "--json"],
                        capture_output=True, text=True)
    oidc_dir_id = None
    for line in (ls.stdout or "").splitlines():
        try:
            rec = json.loads(line)
            if rec.get("@type") == "Oidc":
                oidc_dir_id = rec.get("id")
                break
        except Exception:
            pass
    if oidc_dir_id:
        apply_plan([{"@type": "update", "object": "Authentication",
                     "value": {"directoryId": oidc_dir_id}}])
        log("OIDC directory %s set as active auth source" % oidc_dir_id)
```

Nicht dringend — nur „nice to have" für spätere Multi-Directory-Setups.

---

## 📋 Runbook nach dem Patch — 60-Sek-Check

```bash
# 1) Nginx-Fix aktiv?
curl -sSk -o /dev/null -w "openid-config HTTP=%{http_code}\n" \
  "https://buxte.souvera.work/.well-known/openid-configuration"
# Erwartet: 200

# 2) overwritehost aktiv?
sudo -u www-data php /var/www/nextcloud/occ config:system:get overwritehost
# Erwartet: buxte.souvera.work

# 3) Stalwart nimmt JWT?
sudo -u www-data php /var/www/nextcloud/occ souvera_mail:warmup-oidc
# Erwartet: „Stalwart OIDC cache is already warm (probe returned 200)."
```

Alle 3 grün? Dann läuft's stabil.

---

## 🧪 Regression-Tests im souvera_mail-Repo

Der neue Command ist in `/lib/Command/WarmupOidc.php` + registriert in
`/appinfo/info.xml`. Deckung: `/tests/test_warmup_oidc_command.php` (33
Assertions). Alle 24 lokalen Test-Files bleiben grün (785+ Assertions gesamt).

Kein Composer-Install nötig — Fallback-Autoloader in
`/composer/autoload.php` deckt das ab (0.13.16 Änderung).
