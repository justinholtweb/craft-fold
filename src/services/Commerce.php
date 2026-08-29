<?php

declare(strict_types=1);

namespace justinholtweb\fold\services;

use Craft;
use craft\base\Component;
use craft\elements\Address;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\Plugin;
use Throwable;

/**
 * Everything Fold knows about Craft Commerce — and nothing outside this file knows any of it.
 *
 * Commerce is a soft dependency, so this service is written to be **completely inert** when it is
 * absent: every method answers with an empty, honest value rather than throwing, and no Commerce
 * class is named in a type declaration anywhere (a type hint referencing a missing class is a
 * fatal error at load time, on every site that does not have it, whether or not the method is
 * ever called).
 *
 * ## What the link buys
 *
 * Commerce 5 models a physical place as an `InventoryLocation`, which owns a `craft\elements\Address`
 * — the same element Fold uses. So a linked location is not a translation of Commerce's data, it
 * is a pointer at it, and mirroring the address is a copy.
 *
 * The point of the link is the question WP Store Locator cannot ask: **which shop near me
 * actually has this in stock**. That is answered through Commerce's own
 * `Inventory::getInventoryLevelsForPurchasable()`, never by summing its inventory transaction
 * ledger here. Commerce changes how it does that arithmetic; a locator that quietly disagrees
 * with the product page is worse than one that declines to answer.
 */
class Commerce extends Component
{
    private ?bool $_available = null;

    /** Whether Commerce is installed and running. */
    public function isAvailable(): bool
    {
        if ($this->_available !== null) {
            return $this->_available;
        }

        return $this->_available = class_exists('craft\commerce\Plugin')
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /** Whether Fold will use it — Commerce present *and* the edition allows it. */
    public function isEnabled(): bool
    {
        return $this->isAvailable() && Edition::allowsCommerce(Plugin::getInstance()->isPro());
    }

    /**
     * Every inventory location Commerce knows about.
     *
     * @return array<int, object> Keyed by inventory location ID.
     */
    public function getInventoryLocations(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            $locations = $this->commerce()->getInventoryLocations()->getAllInventoryLocations();
        } catch (Throwable $e) {
            Craft::error('Could not read Commerce inventory locations: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return [];
        }

        $byId = [];

        foreach ($locations as $location) {
            $byId[(int)$location->id] = $location;
        }

        return $byId;
    }

    public function getInventoryLocationById(?int $id): mixed
    {
        if ($id === null || !$this->isAvailable()) {
            return null;
        }

        return $this->getInventoryLocations()[$id] ?? null;
    }

    /** @return array<int, string> Inventory location IDs to names, for a CP dropdown. */
    public function getInventoryLocationOptions(): array
    {
        $options = [];

        foreach ($this->getInventoryLocations() as $id => $location) {
            $options[$id] = (string)$location->name;
        }

        return $options;
    }

    /**
     * The inventory locations that hold at least `$qty` of a purchasable.
     *
     * @param mixed $purchasable A Commerce purchasable, or its element ID.
     * @return int[]
     */
    public function inventoryLocationIdsWithStock(mixed $purchasable, int $qty = 1): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $purchasable = $this->resolvePurchasable($purchasable);

        if ($purchasable === null) {
            return [];
        }

        try {
            $levels = $this->commerce()->getInventory()->getInventoryLevelsForPurchasable($purchasable);
        } catch (Throwable $e) {
            Craft::error('Could not read Commerce inventory levels: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return [];
        }

        $ids = [];

        foreach ($levels as $level) {
            // `availableTotal` and not `onHandTotal`: on-hand includes stock already committed to
            // an order that has not shipped. Telling somebody to drive to a shop for the last one
            // when it is in a picking box with a stranger's name on it is exactly the failure a
            // store locator exists to prevent.
            if ((int)$level->availableTotal >= $qty) {
                $ids[] = (int)$level->inventoryLocationId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether a location has stock of a purchasable, and how much.
     *
     * @return int|null Null when the question cannot be answered — no Commerce, no link, no Pro.
     */
    public function availableStock(Location $location, mixed $purchasable): ?int
    {
        if (!$this->isEnabled() || $location->commerceInventoryLocationId === null) {
            return null;
        }

        $purchasable = $this->resolvePurchasable($purchasable);

        if ($purchasable === null) {
            return null;
        }

        try {
            $levels = $this->commerce()->getInventory()->getInventoryLevelsForPurchasable($purchasable);
        } catch (Throwable $e) {
            return null;
        }

        foreach ($levels as $level) {
            if ((int)$level->inventoryLocationId === $location->commerceInventoryLocationId) {
                return (int)$level->availableTotal;
            }
        }

        return 0;
    }

    /**
     * Copies a linked inventory location's address onto the Fold location.
     *
     * One-way, Commerce → Fold, and deliberately so. Commerce's address is what the warehouse,
     * the tax engine and the shipping rules use; a store locator must not be able to move a
     * warehouse by fixing a typo on the front-end map.
     *
     * @return bool Whether anything changed.
     */
    public function mirrorAddress(Location $location): bool
    {
        $inventoryLocation = $this->getInventoryLocationById($location->commerceInventoryLocationId);

        if ($inventoryLocation === null) {
            return false;
        }

        try {
            $source = $inventoryLocation->getAddress();
        } catch (Throwable $e) {
            return false;
        }

        if (!$source instanceof Address) {
            return false;
        }

        $target = $location->getAddressOrNew();
        $before = $location->getGeocodableAddress();

        foreach (['addressLine1', 'addressLine2', 'addressLine3', 'locality', 'administrativeArea', 'postalCode', 'dependentLocality', 'sortingCode', 'organization'] as $attribute) {
            $target->$attribute = $source->$attribute;
        }

        $target->countryCode = $source->countryCode;
        $location->setAddress($target);

        return $location->getGeocodableAddress() !== $before;
    }

    /**
     * Creates a Fold location for every Commerce inventory location that has not got one.
     *
     * Existing links are refreshed rather than duplicated — the join is on
     * `commerceInventoryLocationId`, so running this twice is a no-op and running it after a
     * warehouse moves updates the map.
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function syncFromCommerce(int $groupId): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        if (!$this->isEnabled()) {
            return $result;
        }

        $locations = Plugin::getInstance()->locations;

        foreach ($this->getInventoryLocations() as $id => $inventoryLocation) {
            $existing = Location::find()
                ->commerceInventoryLocationId($id)
                ->status(null)
                ->siteId('*')
                ->unique()
                ->one();

            if ($existing === null) {
                if (!$locations->canCreateLocation()) {
                    $result['skipped']++;
                    continue;
                }

                $existing = new Location();
                $existing->groupId = $groupId;
                $existing->commerceInventoryLocationId = $id;
                $isNew = true;
            } else {
                $isNew = false;
            }

            $existing->title = (string)$inventoryLocation->name;
            $changed = $this->mirrorAddress($existing);

            if (!$isNew && !$changed) {
                $result['skipped']++;
                continue;
            }

            if ($locations->saveLocation($existing)) {
                $isNew ? $result['created']++ : $result['updated']++;
            } else {
                $result['skipped']++;
            }
        }

        return $result;
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * A purchasable from whatever the caller had — the element, or its ID.
     *
     * Loaded through Craft's element service rather than a Commerce query so that a variant, a
     * digital product, or anything else a plugin has made purchasable all arrive the same way.
     */
    private function resolvePurchasable(mixed $purchasable): mixed
    {
        if ($purchasable === null) {
            return null;
        }

        if (is_numeric($purchasable)) {
            $purchasable = Craft::$app->getElements()->getElementById((int)$purchasable);
        }

        // `interface_exists()` first, because the `instanceof` below is only *safe* without
        // Commerce (PHP returns false rather than autoloading) — it is not *meaningful*, and
        // saying so is cheaper than making the next reader look it up.
        if (!interface_exists('craft\commerce\base\PurchasableInterface')) {
            return null;
        }

        return $purchasable instanceof \craft\commerce\base\PurchasableInterface ? $purchasable : null;
    }

    /** @return \craft\commerce\Plugin */
    private function commerce(): mixed
    {
        return call_user_func(['craft\commerce\Plugin', 'getInstance']);
    }
}
