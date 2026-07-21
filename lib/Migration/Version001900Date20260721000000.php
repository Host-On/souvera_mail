<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates `oc_souvera_mail_devicetoken` — one row per registered FCM push
 * target (Android device) for a Nextcloud user.
 *
 * Ships with v0.19.0 (event-driven FCM push notifications for new mail).
 * See {@see \OCA\SouveraMail\Controller\DeviceTokenController} for the
 * register/unregister endpoints and {@see \OCA\SouveraMail\Cron\MailPushPoller}
 * for the fallback poller that reads/writes `last_push_state`.
 */
class Version001900Date20260721000000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('souvera_mail_devicetoken')) {
            return $schema;
        }

        $table = $schema->createTable('souvera_mail_devicetoken');
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
        $table->addColumn('fcm_token', Types::STRING, [
            'notnull' => true,
            'length' => 512,
        ]);
        $table->addColumn('platform', Types::STRING, [
            'notnull' => true,
            'length' => 32,
            'default' => 'android',
        ]);
        $table->addColumn('created_at', Types::BIGINT, [
            'notnull' => true,
            'length' => 20,
            'default' => 0,
        ]);
        $table->addColumn('last_seen_at', Types::BIGINT, [
            'notnull' => true,
            'length' => 20,
            'default' => 0,
        ]);
        // Cached JMAP Email/query `queryState` for the user's Inbox —
        // lets MailPushPoller detect "new mail since last check" without
        // re-notifying about a state the Stalwart webhook already pushed.
        $table->addColumn('last_push_state', Types::STRING, [
            'notnull' => false,
            'length' => 255,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id'], 'sm_devtok_uid');
        $table->addUniqueIndex(['fcm_token'], 'sm_devtok_token_uniq');

        return $schema;
    }
}
