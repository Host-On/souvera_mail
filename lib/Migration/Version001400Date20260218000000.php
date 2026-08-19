<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates `oc_souvera_mail_apppwd` — persistent mapping between a
 * Stalwart App Password and the paired Nextcloud auth token that
 * shares the same plaintext secret.
 *
 * The mapping is required so `revoke` can zero out BOTH sides
 * atomically, and so pre-v0.14.0 Stalwart-only passwords remain
 * distinguishable (flagged as "legacy" in the UI).
 */
class Version001400Date20260218000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('souvera_mail_apppwd')) {
            return $schema;
        }

        $table = $schema->createTable('souvera_mail_apppwd');
        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'length' => 20,
            'unsigned' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('nc_token_id', Types::BIGINT, [
            'notnull' => true,
            'length' => 20,
            'unsigned' => true,
        ]);
        $table->addColumn('stalwart_app_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('description', Types::STRING, [
            'notnull' => true,
            'length' => 120,
            'default' => '',
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'length' => 20,
            'default' => 0,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'sm_apppwd_uid');
        $table->addUniqueIndex(['user_id', 'stalwart_app_id'], 'sm_apppwd_uid_stalw');
        // Fast reverse lookup when NC tells us "this token was invalidated".
        $table->addIndex(['nc_token_id'], 'sm_apppwd_nctoken');

        return $schema;
    }
}
