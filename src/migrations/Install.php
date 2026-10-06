<?php

namespace samuelreichor\betterCache\migrations;

use craft\db\Migration;
use craft\db\Table;
use samuelreichor\betterCache\Constants;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(Constants::TABLE_PAGES, [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'path' => $this->text()->notNull(),
            'query' => $this->text()->notNull(),
            // path and query can exceed MySQL's index key length, so uniqueness goes through a hash.
            'variantHash' => $this->char(40)->notNull(),
            'stale' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, Constants::TABLE_PAGES, ['siteId', 'variantHash'], true);
        $this->addForeignKey(null, Constants::TABLE_PAGES, 'siteId', Table::SITES, 'id', 'CASCADE');

        $this->createTable(Constants::TABLE_PAGE_TAGS, [
            'pageId' => $this->integer()->notNull(),
            'tag' => $this->string()->notNull(),
            'PRIMARY KEY([[pageId]], [[tag]])',
        ]);
        $this->createIndex(null, Constants::TABLE_PAGE_TAGS, ['tag']);
        $this->addForeignKey(null, Constants::TABLE_PAGE_TAGS, 'pageId', Constants::TABLE_PAGES, 'id', 'CASCADE');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Constants::TABLE_PAGE_TAGS);
        $this->dropTableIfExists(Constants::TABLE_PAGES);

        return true;
    }
}
