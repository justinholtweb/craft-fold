<?php

declare(strict_types=1);

namespace justinholtweb\fold\migrations;

use craft\db\Migration;

/**
 * Adds the per-group schema.org type used by the LocalBusiness structured data.
 *
 * Nullable, so every existing group keeps publishing plain `LocalBusiness` until somebody says
 * otherwise. Project config carries the value too; a group saved before this migration simply
 * has no key for it, which reads as null.
 */
class m261009_000000_group_schema_type extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists('{{%fold_locationgroups}}', 'schemaType')) {
            $this->addColumn('{{%fold_locationgroups}}', 'schemaType', $this->string(64)->after('defaultCountryCode'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists('{{%fold_locationgroups}}', 'schemaType')) {
            $this->dropColumn('{{%fold_locationgroups}}', 'schemaType');
        }

        return true;
    }
}
