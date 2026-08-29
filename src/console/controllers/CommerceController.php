<?php

declare(strict_types=1);

namespace justinholtweb\fold\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\fold\Plugin;
use yii\console\ExitCode;

/**
 * `php craft fold/commerce/…`
 */
class CommerceController extends Controller
{
    /** The location group synced Commerce locations are filed under. */
    public ?string $group = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['group']);
    }

    /**
     * Creates or updates a Fold location for every Commerce inventory location.
     *
     * Safe to run repeatedly: the match is on the inventory location's ID, so a second run
     * updates rather than duplicates, and a warehouse that has moved gets its new address.
     */
    public function actionSync(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->commerce->isAvailable()) {
            $this->stderr("Craft Commerce is not installed.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        if (!$plugin->commerce->isEnabled()) {
            $this->stderr("Commerce integration is a Pro feature.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        $groups = $plugin->groups->getAllGroups();

        if ($groups === []) {
            $this->stderr("Create a location group first.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $group = $this->group !== null
            ? $plugin->groups->getGroupByHandle($this->group)
            : $groups[0];

        if ($group === null) {
            $this->stderr("No location group with the handle “{$this->group}”.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $result = $plugin->commerce->syncFromCommerce($group->id);

        $this->stdout(sprintf(
            "%d created, %d updated, %d unchanged or skipped.\n",
            $result['created'],
            $result['updated'],
            $result['skipped'],
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Lists Commerce's inventory locations and which Fold location each is linked to.
     */
    public function actionLocations(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->commerce->isAvailable()) {
            $this->stderr("Craft Commerce is not installed.\n", Console::FG_RED);

            return ExitCode::UNAVAILABLE;
        }

        foreach ($plugin->commerce->getInventoryLocations() as $id => $inventoryLocation) {
            $linked = \justinholtweb\fold\elements\Location::find()
                ->commerceInventoryLocationId($id)
                ->status(null)
                ->siteId('*')
                ->unique()
                ->one();

            $this->stdout(sprintf(
                "  #%-4d %-30s %s\n",
                $id,
                $inventoryLocation->name,
                $linked !== null ? '→ ' . $linked->title : '(not linked)',
            ));
        }

        return ExitCode::OK;
    }
}
