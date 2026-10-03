<?php
/**
 * Seed a believable Fold demo into the plugin-testing harness — for screenshots and promos.
 *
 * Run from the site root:
 *
 *     ddev exec php /var/www/craft-fold/tests/manual/seed-demo.php
 *     ddev exec php /var/www/craft-fold/tests/manual/seed-demo.php --clean
 *
 * What it leaves behind, on purpose:
 *
 * - A "Tidewater Chandlery" location group (`tidewater`) with fifteen shops along the Carolina
 *   coast, store pages at `/chandlery/<slug>`, and real-looking opening hours.
 * - A Commerce inventory location for nine of those shops, linked to the Fold location, with stock
 *   of one variant (the handheld VHF radio) — some shops have it, some have none — so the
 *   "which shop near me has this in stock" filter has something to answer.
 * - Geocode-cache rows for the demo search terms, written by hand, so the demo pages never send a
 *   request to Nominatim. Coordinates everywhere are typed in, not looked up.
 * - A few weeks of search-log rows, including the searches that found nothing.
 * - The demo templates from `tests/manual/templates/`, copied into the harness's `templates/`.
 *
 * Idempotent: every run removes what the previous one made and builds it again. `--clean` only
 * removes. The plugin's `logSearches` setting is switched on and left on.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Address;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\models\LocationGroup;
use justinholtweb\fold\models\LocationGroupSiteSettings;
use justinholtweb\fold\Plugin;

const GROUP_HANDLE = 'tidewater';
const INVENTORY_PREFIX = 'tidewater';
const STOCK_SKU = 'WAVER-A-84787f'; // "Handheld VHF radio" in the harness catalogue

$plugin = Plugin::getInstance();
$db = Craft::$app->getDb();
$clean = in_array('--clean', $argv, true);
$hasCommerce = class_exists('craft\commerce\Plugin') && Craft::$app->getPlugins()->isPluginEnabled('commerce');

Craft::$app->getPlugins()->switchEdition('fold', Plugin::EDITION_PRO);

// -------------------------------------------------------------------------------------------------
// Removal
// -------------------------------------------------------------------------------------------------

$removeAll = function() use ($plugin, $db, $hasCommerce) {
    $group = $plugin->groups->getGroupByHandle(GROUP_HANDLE);

    if ($group !== null) {
        $locations = Location::find()->groupId($group->id)->status(null)->site('*')->unique()->all();

        foreach ($locations as $location) {
            Craft::$app->getElements()->deleteElement($location, true);
        }
    }

    if ($hasCommerce) {
        $ids = (new craft\db\Query())
            ->select(['id', 'addressId'])
            ->from('{{%commerce_inventorylocations}}')
            ->where(['like', 'handle', INVENTORY_PREFIX . '%', false])
            ->all();

        foreach ($ids as $row) {
            // Removed directly rather than through DeactivateInventoryLocation, which insists on
            // somewhere to move the stock to — this is demo stock, and it is going nowhere.
            $db->createCommand()->delete('{{%commerce_inventorytransactions}}', ['inventoryLocationId' => $row['id']])->execute();
            $db->createCommand()->delete('{{%commerce_inventorylocations_stores}}', ['inventoryLocationId' => $row['id']])->execute();
            $db->createCommand()->delete('{{%commerce_inventorylocations}}', ['id' => $row['id']])->execute();

            if ($row['addressId'] && ($address = Address::find()->id($row['addressId'])->status(null)->one())) {
                Craft::$app->getElements()->deleteElement($address, true);
            }
        }
    }

    $db->createCommand()->delete('{{%fold_searches}}', ['term' => array_column(DEMO_SEARCHES, 0)])->execute();
    $db->createCommand()->delete('{{%fold_geocodecache}}', ['driver' => 'nominatim', 'query' => array_keys(DEMO_GEOCODES)])->execute();
};

// Hand-typed coordinates for the terms the demo pages search for.
const DEMO_GEOCODES = [
    'Wilmington, NC' => [34.2257, -77.9447, 'Wilmington, New Hanover County, North Carolina, United States'],
    'Wilmington' => [34.2257, -77.9447, 'Wilmington, New Hanover County, North Carolina, United States'],
    'Morehead City' => [34.7229, -76.7260, 'Morehead City, Carteret County, North Carolina, United States'],
    'Charleston, SC' => [32.7765, -79.9311, 'Charleston, Charleston County, South Carolina, United States'],
    'Myrtle Beach' => [33.6891, -78.8867, 'Myrtle Beach, Horry County, South Carolina, United States'],
];

// [term, lat, lng, results, nearest, how many times, days ago]
const DEMO_SEARCHES = [
    ['Outer Banks', 35.9582, -75.6201, 0, null, 9, 2],
    ['Nags Head', 35.9574, -75.6241, 0, null, 6, 4],
    ['Hilton Head', 32.2163, -80.7526, 0, null, 5, 1],
    ['Beaufort SC', 32.4316, -80.6698, 0, null, 3, 6],
    ['the one by the bridge', null, null, 0, null, 2, 3],
    ['Lake Norman', 35.5032, -80.9372, 0, null, 2, 8],
    ['Wilmington', 34.2257, -77.9447, 4, 0.8, 14, 0],
    ['28480', 34.2104, -77.7966, 4, 0.4, 7, 1],
    ['Morehead City', 34.7229, -76.7260, 3, 1.0, 6, 2],
    ['Charleston', 32.7765, -79.9311, 2, 1.0, 8, 0],
    ['Myrtle Beach', 33.6891, -78.8867, 2, 17.2, 5, 3],
    ['Southport', 33.9213, -78.0203, 3, 0.7, 4, 5],
    ['New Bern', 35.1085, -77.0441, 2, 0.3, 3, 9],
];

$removeAll();

if ($clean) {
    echo "Removed the Fold demo.\n";
    exit(0);
}

// -------------------------------------------------------------------------------------------------
// Group
// -------------------------------------------------------------------------------------------------

$siteId = Craft::$app->getSites()->getPrimarySite()->id;
$group = $plugin->groups->getGroupByHandle(GROUP_HANDLE) ?? new LocationGroup();
$group->name = 'Tidewater Chandlery';
$group->handle = GROUP_HANDLE;
$group->defaultCountryCode = 'US';
$group->setSiteSettings([
    new LocationGroupSiteSettings([
        'siteId' => $siteId,
        'enabledByDefault' => true,
        'uriFormat' => 'chandlery/{slug}',
        'template' => 'chandlery/_location',
    ]),
]);

if (!$plugin->groups->saveGroup($group)) {
    fwrite(STDERR, 'Could not save the group: ' . json_encode($group->getErrors()) . "\n");
    exit(1);
}

Craft::$app->getProjectConfig()->saveModifiedConfigData();
$group = $plugin->groups->getGroupByHandle(GROUP_HANDLE);
echo "Group: {$group->name} (#{$group->id})\n";

// -------------------------------------------------------------------------------------------------
// Shops
// -------------------------------------------------------------------------------------------------

$standard = [
    'mon' => ['08:00-18:00'], 'tue' => ['08:00-18:00'], 'wed' => ['08:00-18:00'],
    'thu' => ['08:00-18:00'], 'fri' => ['08:00-18:00'], 'sat' => ['08:00-17:00'], 'sun' => ['10:00-16:00'],
];
$fuelDock = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], ['07:00-19:00']);
$village = [
    'tue' => ['09:00-17:00'], 'wed' => ['09:00-17:00'], 'thu' => ['09:00-17:00'],
    'fri' => ['09:00-17:00'], 'sat' => ['08:00-14:00'],
];
$lunch = [
    'mon' => ['08:00-12:00', '13:00-17:30'], 'tue' => ['08:00-12:00', '13:00-17:30'],
    'wed' => ['08:00-12:00', '13:00-17:30'], 'thu' => ['08:00-12:00', '13:00-17:30'],
    'fri' => ['08:00-12:00', '13:00-17:30'], 'sat' => ['09:00-13:00'],
];
$holidays = ['exceptions' => ['2026-11-26' => [], '2026-12-25' => [], '2026-12-24' => ['08:00-13:00']]];

// [title, slug, line1, locality, state, zip, lat, lng, phone, hours, VHF stock or null for no link]
$shops = [
    ['Wilmington Riverfront', 'wilmington-riverfront', '22 S Water St', 'Wilmington', 'NC', '28401', 34.2357, -77.9496, '+1 910 555 0141', $standard + $holidays, 6],
    ['Wrightsville Beach', 'wrightsville-beach', '530 Causeway Dr', 'Wrightsville Beach', 'NC', '28480', 34.2127, -77.8040, '+1 910 555 0156', $fuelDock + $holidays, 0],
    ['Carolina Beach', 'carolina-beach', '300 Canal Dr', 'Carolina Beach', 'NC', '28428', 34.0384, -77.8960, '+1 910 555 0172', $standard, 3],
    ['Southport Marina', 'southport-marina', '606 W West St', 'Southport', 'NC', '28461', 33.9163, -78.0262, '+1 910 555 0188', $standard + $holidays, 0],
    ['Hampstead', 'hampstead', '15200 US Highway 17', 'Hampstead', 'NC', '28443', 34.3681, -77.7105, '+1 910 555 0119', $standard, null],
    ['Swansboro', 'swansboro', '104 Front St', 'Swansboro', 'NC', '28584', 34.6876, -77.1191, '+1 910 555 0134', $village, 2],
    ['Morehead City', 'morehead-city', '708 Evans St', 'Morehead City', 'NC', '28557', 34.7176, -76.7095, '+1 252 555 0147', $fuelDock, 11],
    ['Beaufort Waterfront', 'beaufort-waterfront', '400 Front St', 'Beaufort', 'NC', '28516', 34.7171, -76.6640, '+1 252 555 0163', $standard, null],
    ['Oriental Harbor', 'oriental-harbor', '801 Broad St', 'Oriental', 'NC', '28571', 35.0310, -76.6930, '+1 252 555 0178', $village, 1],
    ['New Bern', 'new-bern', '100 Middle St', 'New Bern', 'NC', '28560', 35.1066, -77.0395, '+1 252 555 0192', $lunch, null],
    ['North Myrtle Beach', 'north-myrtle-beach', '2120 Sea Mountain Hwy', 'North Myrtle Beach', 'SC', '29582', 33.8170, -78.6680, '+1 843 555 0115', $standard, 4],
    ['Murrells Inlet', 'murrells-inlet', '4123 US Highway 17 Business', 'Murrells Inlet', 'SC', '29576', 33.5510, -79.0420, '+1 843 555 0129', $fuelDock, null],
    ['Georgetown', 'georgetown', '525 Front St', 'Georgetown', 'SC', '29440', 33.3660, -79.2840, '+1 843 555 0144', $lunch, null],
    ['Charleston City Marina', 'charleston-city-marina', '17 Lockwood Dr', 'Charleston', 'SC', '29401', 32.7790, -79.9490, '+1 843 555 0158', $standard + $holidays, 8],
    ['Mount Pleasant', 'mount-pleasant', '24 Patriots Point Rd', 'Mount Pleasant', 'SC', '29464', 32.7920, -79.9050, '+1 843 555 0175', $standard, null],
];

$locations = [];

foreach ($shops as [$title, $slug, $line1, $locality, $state, $zip, $lat, $lng, $phone, $hours]) {
    $address = new Address();
    $address->countryCode = 'US';
    $address->addressLine1 = $line1;
    $address->locality = $locality;
    $address->administrativeArea = $state;
    $address->postalCode = $zip;

    $location = new Location();
    $location->groupId = $group->id;
    $location->siteId = $siteId;
    $location->title = $title;
    $location->slug = $slug;
    $location->lat = $lat;
    $location->lng = $lng;
    // Manual: the coordinates were typed in, so nothing queues a geocode for them.
    $location->geocodeState = Location::GEOCODE_MANUAL;
    $location->timezone = 'America/New_York';
    $location->phone = $phone;
    $location->email = $slug . '@tidewater.example';
    $location->setAddress($address);
    $location->setHours($hours);

    if (!Craft::$app->getElements()->saveElement($location)) {
        fwrite(STDERR, "Could not save $title: " . json_encode($location->getErrors()) . "\n");
        exit(1);
    }

    $locations[$slug] = $location;
}

echo 'Shops: ' . count($locations) . "\n";

// -------------------------------------------------------------------------------------------------
// Commerce: inventory locations, stock, and the link
// -------------------------------------------------------------------------------------------------

if ($hasCommerce) {
    $commerce = craft\commerce\Plugin::getInstance();
    $store = $commerce->getStores()->getPrimaryStore();
    $variant = craft\commerce\elements\Variant::find()->sku(STOCK_SKU)->status(null)->one();

    if ($variant === null) {
        echo "Commerce: no variant with SKU " . STOCK_SKU . " — skipping stock.\n";
    } else {
        $inventoryItem = $commerce->getInventory()->getInventoryItemByPurchasable($variant);
        $newIds = [];
        $levels = craft\commerce\collections\UpdateInventoryLevelCollection::make();

        foreach ($shops as $shop) {
            [$title, $slug, $line1, $locality, $state, $zip, , , , , $stock] = $shop;

            if ($stock === null) {
                continue;
            }

            $address = new Address();
            $address->title = 'Tidewater ' . $title;
            $address->countryCode = 'US';
            $address->addressLine1 = $line1;
            $address->locality = $locality;
            $address->administrativeArea = $state;
            $address->postalCode = $zip;
            Craft::$app->getElements()->saveElement($address, false);

            $inventoryLocation = new craft\commerce\models\InventoryLocation([
                'name' => 'Tidewater ' . $title,
                'handle' => INVENTORY_PREFIX . str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))),
            ]);
            $inventoryLocation->setAddress($address);

            if (!$commerce->getInventoryLocations()->saveInventoryLocation($inventoryLocation)) {
                fwrite(STDERR, "Could not save inventory location for $title: " . json_encode($inventoryLocation->getErrors()) . "\n");
                exit(1);
            }

            $newIds[] = $inventoryLocation->id;

            $levels->push(new craft\commerce\models\inventory\UpdateInventoryLevel([
                'type' => craft\commerce\enums\InventoryTransactionType::AVAILABLE->value,
                'updateAction' => craft\commerce\enums\InventoryUpdateQuantityType::SET,
                'inventoryItemId' => $inventoryItem->id,
                'inventoryLocationId' => $inventoryLocation->id,
                'quantity' => $stock,
                'note' => 'Fold demo stock',
            ]));

            $location = $locations[$slug];
            $location->commerceInventoryLocationId = $inventoryLocation->id;
            Craft::$app->getElements()->saveElement($location);
        }

        // The store keeps every inventory location it already had, first, so its default
        // fulfilment location does not change underneath anything else in the harness.
        $existing = $commerce->getInventoryLocations()->getInventoryLocations($store->id)->pluck('id')->all();
        $commerce->getInventoryLocations()->saveStoreInventoryLocations($store, array_values(array_unique([...$existing, ...$newIds])));
        $commerce->getInventory()->executeUpdateInventoryLevels($levels);

        echo "Commerce: " . count($newIds) . " inventory locations linked; stock of “{$variant->title}” (#{$variant->id}) set.\n";
    }
}

// -------------------------------------------------------------------------------------------------
// Geocode cache — typed in, so the demo never asks Nominatim
// -------------------------------------------------------------------------------------------------

$now = Db::prepareDateForDb(new DateTime());
$expires = Db::prepareDateForDb(new DateTime('+1 year'));

foreach (DEMO_GEOCODES as $query => [$lat, $lng, $formatted]) {
    // Same key as Geocoder::cacheKey(): driver | lowercased query | lowercased country bias.
    foreach (['us', ''] as $country) {
        $hash = sha1(implode('|', ['nominatim', mb_strtolower(trim($query)), $country]));
        $db->createCommand()->delete('{{%fold_geocodecache}}', ['hash' => $hash])->execute();
        $db->createCommand()->insert('{{%fold_geocodecache}}', [
            'hash' => $hash,
            'driver' => 'nominatim',
            'query' => $query,
            'lat' => $lat,
            'lng' => $lng,
            'formatted' => $formatted,
            'found' => true,
            'expiryDate' => $expires,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => craft\helpers\StringHelper::UUID(),
        ])->execute();
    }
}

echo 'Geocode cache: ' . count(DEMO_GEOCODES) . " terms.\n";

// -------------------------------------------------------------------------------------------------
// Search log
// -------------------------------------------------------------------------------------------------

// Set on the one key rather than through savePluginSettings(), which would rewrite every other
// setting with whatever this process happened to load.
Craft::$app->getProjectConfig()->set('plugins.fold.settings.logSearches', true, 'Fold demo: record searches');
Craft::$app->getProjectConfig()->saveModifiedConfigData();


$siteId = Craft::$app->getSites()->getPrimarySite()->id;
$rows = 0;

foreach (DEMO_SEARCHES as [$term, $lat, $lng, $count, $nearest, $times, $daysAgo]) {
    for ($i = 0; $i < $times; $i++) {
        $when = (new DateTime())->modify(sprintf('-%d days -%d minutes', $daysAgo + intdiv($i, 3), 37 * $i + 11));
        $db->createCommand()->insert('{{%fold_searches}}', [
            'siteId' => $siteId,
            'term' => $term,
            'lat' => $lat,
            'lng' => $lng,
            'radius' => 25,
            'unit' => 'mi',
            'resultCount' => $count,
            'nearestDistance' => $nearest,
            'dateCreated' => Db::prepareDateForDb($when),
            'dateUpdated' => Db::prepareDateForDb($when),
            'uid' => craft\helpers\StringHelper::UUID(),
        ])->execute();
        $rows++;
    }
}

echo "Search log: $rows rows.\n";

// -------------------------------------------------------------------------------------------------
// Demo templates
// -------------------------------------------------------------------------------------------------

$source = dirname(__FILE__) . '/templates';
$target = Craft::$app->getPath()->getSiteTemplatesPath();

foreach (FileHelper::findFiles($source, ['only' => ['*.twig']]) as $file) {
    $relative = substr($file, strlen($source) + 1);
    FileHelper::createDirectory(dirname($target . '/' . $relative));
    copy($file, $target . '/' . $relative);
    echo "Template: $relative\n";
}

echo "\nDone. /fold-demo, /fold-demo/stock, /chandlery/wilmington-riverfront\n";
