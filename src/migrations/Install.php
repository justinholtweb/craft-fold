<?php

declare(strict_types=1);

namespace justinholtweb\fold\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\fold\elements\Location;

/**
 * Fold's schema.
 *
 * Two shapes worth explaining:
 *
 * - **`lat`/`lng` are `decimal(10,7)`, indexed together.** Seven decimal places is about a
 *   centimetre, which is far past what a geocoded address knows and comfortably past what any map
 *   renders — but a float would round, and two locations that round to the same coordinate sort
 *   unstably against each other. The composite index is what makes the bounding-box prefilter in
 *   {@see \justinholtweb\fold\elements\db\LocationQuery::nearby()} an index scan rather than a
 *   table scan; latitude leads it, because latitude is the half of the box that always has a
 *   bounded range.
 *
 * - **`hours` is one JSON column, not a table of intervals.** Opening hours are read whole every
 *   time they are read at all, are never queried interval-wise, and are at most a couple of dozen
 *   rows per shop. A table would cost a join on every result in a search that returns 25 of them.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%fold_locationgroups}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'color' => $this->string(16),
            'marker' => $this->string(64),
            'defaultCountryCode' => $this->string(2),
            'fieldLayoutId' => $this->integer(),
            'sortOrder' => $this->smallInteger()->unsigned(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%fold_locationgroups}}', ['handle'], true);
        $this->createIndex(null, '{{%fold_locationgroups}}', ['name'], true);
        $this->addForeignKey(null, '{{%fold_locationgroups}}', ['fieldLayoutId'], CraftTable::FIELDLAYOUTS, ['id'], 'SET NULL', null);

        $this->createTable('{{%fold_locationgroups_sites}}', [
            'id' => $this->primaryKey(),
            'groupId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'enabledByDefault' => $this->boolean()->defaultValue(true)->notNull(),
            'uriFormat' => $this->text(),
            'template' => $this->string(500),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%fold_locationgroups_sites}}', ['groupId', 'siteId'], true);
        $this->createIndex(null, '{{%fold_locationgroups_sites}}', ['siteId'], false);
        $this->addForeignKey(null, '{{%fold_locationgroups_sites}}', ['groupId'], '{{%fold_locationgroups}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%fold_locationgroups_sites}}', ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');

        $this->createTable('{{%fold_locations}}', [
            'id' => $this->integer()->notNull(),
            'groupId' => $this->integer()->notNull(),
            // SET NULL rather than CASCADE: deleting the Address element must not take the shop
            // with it. A location with no address is a fixable mistake; a silently vanished
            // location is a support ticket nobody can reconstruct.
            'addressId' => $this->integer(),
            'lat' => $this->decimal(10, 7),
            'lng' => $this->decimal(10, 7),
            'phone' => $this->string(64),
            'email' => $this->string(255),
            'websiteUrl' => $this->string(500),
            'hours' => $this->text(),
            'timezone' => $this->string(64),
            'commerceInventoryLocationId' => $this->integer(),
            'geocodeState' => $this->string(16)->notNull()->defaultValue(Location::GEOCODE_PENDING),
            // A hash of the address as it stood when the coordinates were fetched, so a save
            // re-geocodes only when the address actually changed. Editing a phone number must not
            // cost a geocoder request.
            'geocodeHash' => $this->string(40),
            'geocodeError' => $this->string(500),
            'geocodedAt' => $this->dateTime(),
            'sortOrder' => $this->integer(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, '{{%fold_locations}}', ['lat', 'lng'], false);
        $this->createIndex(null, '{{%fold_locations}}', ['groupId'], false);
        $this->createIndex(null, '{{%fold_locations}}', ['geocodeState'], false);
        $this->createIndex(null, '{{%fold_locations}}', ['commerceInventoryLocationId'], false);
        $this->addForeignKey(null, '{{%fold_locations}}', ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%fold_locations}}', ['groupId'], '{{%fold_locationgroups}}', ['id'], 'CASCADE', null);
        $this->addForeignKey(null, '{{%fold_locations}}', ['addressId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);

        $this->createTable('{{%fold_geocodecache}}', [
            'id' => $this->primaryKey(),
            'hash' => $this->string(40)->notNull(),
            'driver' => $this->string(32)->notNull(),
            'query' => $this->string(500)->notNull(),
            'lat' => $this->decimal(10, 7),
            'lng' => $this->decimal(10, 7),
            'formatted' => $this->string(500),
            'bounds' => $this->string(255),
            // A miss is cached too. "asdfgh" is a search term visitors produce constantly, and
            // re-asking the provider about it every time is how a free geocoder gets you blocked.
            'found' => $this->boolean()->defaultValue(true)->notNull(),
            'expiryDate' => $this->dateTime()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%fold_geocodecache}}', ['hash'], true);
        $this->createIndex(null, '{{%fold_geocodecache}}', ['expiryDate'], false);

        $this->createTable('{{%fold_searches}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer(),
            'term' => $this->string(255)->notNull(),
            'lat' => $this->decimal(10, 7),
            'lng' => $this->decimal(10, 7),
            'radius' => $this->decimal(10, 2),
            'unit' => $this->string(2),
            'resultCount' => $this->integer()->notNull()->defaultValue(0),
            'nearestLocationId' => $this->integer(),
            'nearestDistance' => $this->decimal(10, 2),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%fold_searches}}', ['dateCreated'], false);
        // The report that matters is "what did people search for and find nothing", so the index
        // that matters leads with the result count.
        $this->createIndex(null, '{{%fold_searches}}', ['resultCount', 'dateCreated'], false);
        $this->addForeignKey(null, '{{%fold_searches}}', ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, '{{%fold_searches}}', ['nearestLocationId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        // Delete the elements first: the CASCADE runs the other way, so dropping the tables alone
        // would leave rows in `elements` pointing at an element type that no longer exists — and
        // every element index in the CP then throws.
        $this->delete(CraftTable::ELEMENTS, ['type' => Location::class]);

        $this->dropTableIfExists('{{%fold_searches}}');
        $this->dropTableIfExists('{{%fold_geocodecache}}');
        $this->dropTableIfExists('{{%fold_locations}}');
        $this->dropTableIfExists('{{%fold_locationgroups_sites}}');
        $this->dropTableIfExists('{{%fold_locationgroups}}');

        return true;
    }
}
