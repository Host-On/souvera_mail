<?php

declare(strict_types=1);

namespace OCA\SouveraMail\DevOps;

use OC\DB\Connection;
use OC\DB\ConnectionAdapter;
use OC\DB\MigrationService;
use OCP\IDBConnection;
use OCP\Server;

/**
 * Führt ausstehende App-Migrationen im Prozess aus — identisch zu
 * `occ migrations:migrate <app>`.
 *
 * Wichtig: MigrationService verlangt das INNERE OC\DB\Connection-Objekt,
 * nicht den öffentlichen IDBConnection-Adapter (ConnectionAdapter). Der
 * Adapter leitet Doctrine-Methoden nicht weiter; übergibt man ihn trotzdem,
 * wirft der Konstruktor einen TypeError — genau der Fehler, der Mails
 * Self-Update-Migrationslauf still sterben ließ (Fehler nur im Log,
 * `migrations` lief nie aus dem Self-Update heraus).
 *
 * Kompatibilität verifiziert gegen NC 30–34:
 *  - ConnectionAdapter::getInner() existiert in allen Versionen,
 *  - MigrationService::__construct(string, Connection) unverändert.
 */
final class MigrationRunner {

	/**
	 * @throws \Throwable bei fehlgeschlagener Migration (Aufrufer fängt)
	 */
	public static function migrate(string $appId): void {
		$ms = new MigrationService($appId, self::innerConnection());
		$ms->migrate();
	}

	/**
	 * Inneres Connection-Objekt besorgen (NC 30–34):
	 *  1. regulär: getInner() am ConnectionAdapter,
	 *  2. direkt, falls IDBConnection bereits das innere Objekt ist,
	 *  3. DI-Auflösung (Core-Pattern, siehe OC\Updater::doUpgrade).
	 */
	private static function innerConnection(): Connection {
		$db = Server::get(IDBConnection::class);
		if ($db instanceof ConnectionAdapter && \method_exists($db, 'getInner')) {
			return $db->getInner();
		}
		if ($db instanceof Connection) {
			return $db;
		}
		return Server::get(Connection::class);
	}
}
