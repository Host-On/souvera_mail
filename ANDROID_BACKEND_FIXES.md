# Backend-Fixes für Duplicate-AppPassword-Fehler (souvera_mail)

## Kontext

Android-User erhalten beim Mail-Login:
```
HTTP 502 — AppPassword mapping persistence failed — no combined app password was created:
SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 's.grassegger@host-on.de-b'
for key 'sm_apppwd_uid_stalw'
```

**Ursache:** `AppPasswordService::createForUser()` macht einen Blind-Insert in `oc_souvera_mail_apppwd`.
Wenn eine verwaiste Mapping-Row existiert (Stalwart-Password gelöscht, Mapping-Row überlebt) und
Stalwart dieselbe numerische ID wiederverwendet (ID 1 → base32 `b`), kollidiert der Insert mit
dem UNIQUE-Constraint `sm_apppwd_uid_stalw` auf `(user_id, stalwart_app_id)`.

Verwaiste Rows entstehen durch:
- `revokeForUser()`: Mapping-Delete schlägt stillschweigend fehl (Zeile 463-469)
- Account-Entfernung via Android löscht nur NC-Token X, nicht Combined-Password Y
- Stalwart-Admin-Aktionen (direktes Löschen ohne NC-Listener)


## Fix A (PRIO 1): Pre-insert-Check in createForUser()

### Datei: `lib/Service/AppPasswordService.php`

**Vor Zeile 300** (`$this->mappingMapper->insert($mapping)`), einen Check einfügen:

```php
// Guard: clean up stale mapping rows that outlived their Stalwart password.
try {
    $existing = $this->mappingMapper->findByStalwartId($userId, $stalwartId);
    $this->mappingMapper->delete($existing);
    $this->logger->warning(
        'Souvera Mail: cleaned up stale mapping row for stalwart_app_id ' . $stalwartId,
        ['app' => 'souvera_mail', 'user' => $userId]
    );
} catch (DoesNotExistException) {
    // Expected — no collision.
}
```

Die `findByStalwartId` query nutzt denselben `sm_apppwd_uid_stalw` Index — kein Performance-Impact.

**Alternativ (robuster):** Statt Blind-Insert einen Upsert implementieren.

### Datei: `lib/Db/AppPasswordMappingMapper.php`

Neue Methode `upsert()`:

```php
public function upsert(AppPasswordMapping $mapping): void
{
    $qb = $this->db->getQueryBuilder();
    $qb->insert(self::TABLE)
        ->values([
            'user_id' => $qb->createNamedParameter($mapping->getUserId()),
            'nc_token_id' => $qb->createNamedParameter($mapping->getNcTokenId(), IQueryBuilder::PARAM_INT),
            'stalwart_app_id' => $qb->createNamedParameter($mapping->getStalwartAppId()),
            'description' => $qb->createNamedParameter($mapping->getDescription()),
            'created_at' => $qb->createNamedParameter($mapping->getCreatedAt(), IQueryBuilder::PARAM_INT),
        ]);
    // MySQL / MariaDB
    $sql = $qb->getSQL() . ' ON DUPLICATE KEY UPDATE nc_token_id = VALUES(nc_token_id), description = VALUES(description), created_at = VALUES(created_at)';
    $qb->getConnection()->executeStatement($sql, $qb->getParameters(), $qb->getParameterTypes());
}
```

Und in `AppPasswordService.php` Zeile 300:
```php
$this->mappingMapper->upsert($mapping);
// statt: $this->mappingMapper->insert($mapping);
```


## Fix B (PRIO 2): Mapping-Delete nicht stillschweigend fehlschlagen lassen

### Datei: `lib/Service/AppPasswordService.php`, Zeilen 461-474

Aktuell:
```php
if ($mapping !== null) {
    try {
        $this->mappingMapper->delete($mapping);
    } catch (\Throwable $e) {
        // Log but do not throw
        $this->logger->warning(...);
    }
}
```

Stattdessen sicherstellen, dass der Delete NICHT stillschweigend scheitert:

```php
if ($mapping !== null) {
    try {
        $this->mappingMapper->delete($mapping);
    } catch (\Throwable $e) {
        // Retry once after short delay
        \usleep(500000); // 500ms
        try {
            $this->mappingMapper->delete($mapping);
        } catch (\Throwable $e2) {
            $this->logger->warning(
                'Souvera Mail: Mapping delete failed after retry — '
                . 'row id=' . $mapping->getId()
                . ' user=' . $userId
                . ' stalwart=' . $appPasswordId
                . ': ' . $e2->getMessage(),
                ['app' => 'souvera_mail']
            );
        }
    }
}
```


## Fix C (PRIO 3): Aufräum-Cron-Job

Neuer `BackgroundJob` der täglich alle Mapping-Rows prüft:

```
1. findAllForUser() für jeden User
2. Für jede Row: Stalwart x:AppPassword/get mit der stalwart_app_id
3. Wenn notFound → Mapping-Row löschen
4. Logge Anzahl bereinigter Rows
```

### Neue Datei: `lib/Cron/StaleMappingCleanup.php`


## Fix D (PRIO 1 — jetzt manuell): Soforthilfe für betroffenen User

```sql
-- Identifiziere verwaiste Rows (Stalwart-Password existiert nicht mehr)
-- und lösche sie:
DELETE FROM oc_souvera_mail_apppwd
WHERE user_id = 's.grassegger@host-on.de' AND stalwart_app_id = 'b';
```

Oder generisch alle verwaisten Rows finden und löschen.
