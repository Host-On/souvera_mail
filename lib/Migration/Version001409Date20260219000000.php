<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates `oc_souvera_migrations` — one row per IMAP import job that
 * was ever started from a Souvera Mail welcome-wizard.
 *
 * Ships with v0.14.9 (Phase 1 of the migration wizard). See PRD.md
 * step 38 for the full data-flow diagram.
 */
class Version001409Date20260219000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('souvera_migrations')) {
            return $schema;
        }

        $table = $schema->createTable('souvera_migrations');
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
        // provider.tools returns a string migrationId. Nullable because
        // rows are inserted BEFORE the API call so an insert failure
        // can't leave a phantom job at provider.tools with no local
        // trace, and vice versa.
        $table->addColumn('provider_job_id', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        // pending / running / completed / failed / dismissed
        $table->addColumn('status', Types::STRING, [
            'notnull' => true,
            'length' => 16,
            'default' => 'pending',
        ]);
        // Source-provider fingerprint — kept for the "resume-view" UX
        // ("Deine letzte Migration von imap.gmx.net (max@example.com)")
        // and for the operator audit trail. Never the source password.
        $table->addColumn('source_host', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('source_user', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        // Stalwart App-Password id, kept so the poller / cleanup cron
        // can revoke it deterministically on completion / failure.
        $table->addColumn('stalwart_app_id', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        // Last provider.tools GET /migrate/:id response — cached so the
        // frontend polls US (fast, cheap, no rate-limit exposure to
        // the browser) and we only refresh upstream once per minute
        // via the MigrationPoller background job.
        $table->addColumn('progress_json', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('error_message', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'length' => 20,
            'default' => 0,
        ]);
        $table->addColumn('updated_at', Types::BIGINT, [
            'notnull' => true,
            'length' => 20,
            'default' => 0,
        ]);
        $table->addColumn('finished_at', Types::BIGINT, [
            'notnull' => false,
            'length' => 20,
        ]);

        $table->setPrimaryKey(['id']);
        // Fast per-user "latest job" + "any active?" queries used on
        // every welcome-state ping (once per browser tab open).
        $table->addIndex(['user_id', 'created_at'], 'sm_mig_uid_ctime');
        $table->addIndex(['user_id', 'status'], 'sm_mig_uid_status');
        // Poller candidate query: WHERE status IN (active) ORDER BY updated_at.
        $table->addIndex(['status', 'updated_at'], 'sm_mig_status_utime');

        return $schema;
    }
}
