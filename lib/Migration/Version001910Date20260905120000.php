<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Migration;

use Closure;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\IMigrationStep;

/**
 * PMG learning: tracks which mails the user personally reported to the PMG
 * spamfilter (needed to distinguish "revert my own report" from "report a
 * system-sorted mail as ham" when a mail leaves the junk folder).
 */
class Version001910Date20260905120000 implements IMigrationStep
{
    public function name(): string
    {
        return 'Souvera Mail 1.2.53 — PMG report tracking';
    }

    public function description(): string
    {
        return 'Adds the pmg report tracking table (user-reported spam/ham feedback).';
    }

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // no-op
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?Closure
    {
        $schema = $schemaClosure();

        if (!$schema->hasTable('souvera_mail_pmg_reports')) {
            $table = $schema->createTable('souvera_mail_pmg_reports');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 8,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('account_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('email_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('message_id', Types::STRING, [
                'notnull' => false,
                'length' => 512,
            ]);
            $table->addColumn('message_id_hash', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('class', Types::STRING, [
                'notnull' => true,
                'length' => 16,
            ]);
            $table->addColumn('reported_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'message_id_hash'], 'sdms_pmg_u_msg_uq');
            $table->addIndex(['user_id', 'account_id'], 'sdms_pmg_user_idx');
        }

        return static function (): void {
        };
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // no-op
    }
}
