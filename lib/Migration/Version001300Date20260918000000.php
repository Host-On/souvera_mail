<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Removes `oc_souvera_mail_devicetoken` — the direct FCM/APNs device
 * registry. Push delivery moved fully to the Nextcloud notification
 * pipeline (notifications app → E2E-encrypted push proxy push.souvera.eu
 * → FCM/APNs); devices register with the proxy, not with this app.
 * The old registry is obsolete and its tokens would never fire again.
 */
class Version001300Date20260918000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('souvera_mail_devicetoken')) {
            return null;
        }

        $schema->dropTable('souvera_mail_devicetoken');
        return $schema;
    }
}
