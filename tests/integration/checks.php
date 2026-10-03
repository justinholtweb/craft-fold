<?php
/**
 * Fold integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-fold/tests/integration/checks.php
 *
 * Covers what unit fixtures cannot: a real element save through Craft, a real address element,
 * the radius query as SQL against the actual database, and the edition boundary. Idempotent and
 * self-cleaning — every run creates its own group and removes it at the end.
 *
 * Nothing here touches a geocoding provider. Coordinates are set by hand, because a test suite
 * that depends on Nominatim being up is a test suite that fails for reasons that are not bugs.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Address;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\helpers\Geo;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\models\GeoPoint;
use justinholtweb\fold\models\LocationGroup;
use justinholtweb\fold\models\LocationGroupSiteSettings;
use justinholtweb\fold\models\OpeningHours;
use justinholtweb\fold\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
Craft::$app->getPlugins()->switchEdition('fold', Plugin::EDITION_PRO);

// Real places, so a wrong answer is recognisable rather than merely different.
$charlotte = [35.2271, -80.8431];
$raleigh = [35.7796, -78.6382];
$columbia = [34.0007, -81.0348];
$london = [51.5074, -0.1278];

section('Geo maths');

check('Charlotte to Raleigh is about 130 miles', function() use ($charlotte, $raleigh) {
    $d = Geo::distance($charlotte[0], $charlotte[1], $raleigh[0], $raleigh[1], 'mi');

    return ($d > 125 && $d < 135) ?: "got $d";
});

check('the same distance in kilometres is about 210', function() use ($charlotte, $raleigh) {
    $d = Geo::distance($charlotte[0], $charlotte[1], $raleigh[0], $raleigh[1], 'km');

    return ($d > 203 && $d < 216) ?: "got $d";
});

check('a point is zero miles from itself', function() use ($charlotte) {
    return Geo::distance($charlotte[0], $charlotte[1], $charlotte[0], $charlotte[1]) === 0.0;
});

check('the bounding box contains the circle', function() use ($charlotte) {
    $box = Geo::boundingBox($charlotte[0], $charlotte[1], 25, 'mi');

    // Every point exactly 25 miles due north/south/east/west must fall inside the box.
    $northOk = $box['north'] >= $charlotte[0] + (25 * 1.609344 / Geo::KM_PER_DEGREE_LAT);
    $eastOk = $box['east'] >= $charlotte[1];

    return ($northOk && $eastOk && !$box['wraps']) ?: json_encode($box);
});

check('a box at the antimeridian reports that it wraps', function() {
    $box = Geo::boundingBox(-16.5, 179.9, 100, 'mi');

    return ($box['wraps'] && $box['west'] > $box['east']) ?: json_encode($box);
});

check('a box at the pole covers every longitude without dividing by zero', function() {
    $box = Geo::boundingBox(89.99, 10.0, 500, 'mi');

    return ($box['west'] === -180.0 && $box['east'] === 180.0) ?: json_encode($box);
});

check('a longitude past the antimeridian wraps to the other side', function() {
    return abs(Geo::wrapLng(190.0) - -170.0) < 0.0001 ?: 'got ' . Geo::wrapLng(190.0);
});

check('a coordinate pair is recognised without a geocoder', function() {
    return Geo::parseCoordinates(' 35.2271, -80.8431 ') === [35.2271, -80.8431];
});

check('an out-of-range pair is not', function() {
    return Geo::parseCoordinates('99.0,-80.0') === null;
});

check('a place name is not mistaken for coordinates', function() {
    return Geo::parseCoordinates('Charlotte, NC') === null;
});

check('0,0 is not a valid geocoding result', function() {
    return GeoPoint::make(0.0, 0.0)->isValid() === false;
});

section('Opening hours');

check('a 24-hour clock range parses', function() {
    $hours = new OpeningHours(['mon' => [['open' => '09:00', 'close' => '17:00']]]);

    return $hours->forDay('mon') === [['open' => '09:00', 'close' => '17:00']];
});

check('a 12-hour clock range normalises', function() {
    $hours = new OpeningHours(['mon' => [['open' => '9am', 'close' => '5:30pm']]]);

    return $hours->forDay('mon') === [['open' => '09:00', 'close' => '17:30']]
        ?: json_encode($hours->forDay('mon'));
});

check('a CSV-style "09:00-17:00" string parses', function() {
    $hours = new OpeningHours(['tue' => ['09:00-17:00']]);

    return $hours->forDay('tue') === [['open' => '09:00', 'close' => '17:00']]
        ?: json_encode($hours->forDay('tue'));
});

check('an unreadable range is dropped rather than defaulted', function() {
    $hours = new OpeningHours(['wed' => [['open' => 'noonish', 'close' => 'later']]]);

    return $hours->forDay('wed') === [];
});

check('a shop is open in the middle of its hours', function() {
    $hours = new OpeningHours(['mon' => [['open' => '09:00', 'close' => '17:00']]]);

    return $hours->isOpenAt(new DateTime('2026-08-17 12:00:00', new DateTimeZone('UTC')), 'UTC');
});

check('and closed after them', function() {
    $hours = new OpeningHours(['mon' => [['open' => '09:00', 'close' => '17:00']]]);

    return !$hours->isOpenAt(new DateTime('2026-08-17 18:00:00', new DateTimeZone('UTC')), 'UTC');
});

check('closing time is exclusive', function() {
    $hours = new OpeningHours(['mon' => [['open' => '09:00', 'close' => '17:00']]]);

    return !$hours->isOpenAt(new DateTime('2026-08-17 17:00:00', new DateTimeZone('UTC')), 'UTC');
});

check('a bar open 22:00–02:00 is open at half past midnight', function() {
    // Monday's range runs into Tuesday; the moment tested is Tuesday.
    $hours = new OpeningHours(['mon' => [['open' => '22:00', 'close' => '02:00']]]);

    return $hours->isOpenAt(new DateTime('2026-08-18 00:30:00', new DateTimeZone('UTC')), 'UTC');
});

check('and closed at half past three', function() {
    $hours = new OpeningHours(['mon' => [['open' => '22:00', 'close' => '02:00']]]);

    return !$hours->isOpenAt(new DateTime('2026-08-18 03:30:00', new DateTimeZone('UTC')), 'UTC');
});

check('open now is answered in the shop’s own timezone', function() {
    $hours = new OpeningHours(['mon' => [['open' => '09:00', 'close' => '17:00']]]);
    $noonInLA = new DateTime('2026-08-17 19:00:00', new DateTimeZone('UTC'));

    // 19:00 UTC is noon in Los Angeles and 19:00 in London: open there, shut here.
    return $hours->isOpenAt($noonInLA, 'America/Los_Angeles')
        && !$hours->isOpenAt($noonInLA, 'Europe/London');
});

check('a dated exception closes the shop', function() {
    $hours = new OpeningHours([
        'fri' => [['open' => '09:00', 'close' => '17:00']],
        'exceptions' => ['2026-12-25' => []],
    ]);

    return !$hours->isOpenAt(new DateTime('2026-12-25 12:00:00', new DateTimeZone('UTC')), 'UTC');
});

check('a dated exception can also shorten the day', function() {
    $hours = new OpeningHours([
        'thu' => [['open' => '09:00', 'close' => '17:00']],
        'exceptions' => ['2026-12-24' => [['open' => '09:00', 'close' => '13:00']]],
    ]);

    $christmasEve = new DateTimeZone('UTC');

    return $hours->isOpenAt(new DateTime('2026-12-24 10:00:00', $christmasEve), 'UTC')
        && !$hours->isOpenAt(new DateTime('2026-12-24 15:00:00', $christmasEve), 'UTC');
});

check('the next opening is found across a closed day', function() {
    $hours = new OpeningHours(['mon' => [['open' => '09:00', 'close' => '17:00']]]);
    $next = $hours->nextOpeningAt(new DateTime('2026-08-18 12:00:00', new DateTimeZone('UTC')), 'UTC');

    return $next?->format('Y-m-d H:i') === '2026-08-24 09:00' ?: 'got ' . ($next?->format('Y-m-d H:i') ?? 'null');
});

check('hours with nothing in them are empty', function() {
    return (new OpeningHours())->isEmpty();
});

check('hours round-trip through JSON', function() {
    $hours = new OpeningHours(['sat' => [['open' => '10:00', 'close' => '16:00']], 'note' => 'By appointment']);
    $again = OpeningHours::fromJson(json_encode($hours));

    return $again->forDay('sat') === [['open' => '10:00', 'close' => '16:00']] && $again->note === 'By appointment';
});

check('dated exceptions come back in date order, whatever order they were entered in', function() {
    $hours = new OpeningHours(['exceptions' => ['2026-12-25' => [], '2026-11-26' => [], '2026-12-24' => ['09:00-13:00']]]);

    return array_keys($hours->exceptions()) === ['2026-11-26', '2026-12-24', '2026-12-25']
        ?: json_encode(array_keys($hours->exceptions()));
});

section('Groups');

$suffix = substr(md5((string)microtime(true)), 0, 6);
$group = new LocationGroup([
    'name' => 'Fold Check ' . $suffix,
    'handle' => 'foldCheck' . $suffix,
    'defaultCountryCode' => 'US',
]);
$group->setSiteSettings([
    new LocationGroupSiteSettings([
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        'enabledByDefault' => true,
    ]),
]);

check('a group saves through project config', function() use ($plugin, $group) {
    return $plugin->groups->saveGroup($group) ?: json_encode($group->getErrors());
});

check('and gets an ID and a record', function() use ($plugin, $group) {
    return $group->id !== null && $plugin->groups->getGroupById($group->id) !== null;
});

check('its site settings were written', function() use ($plugin, $group) {
    $settings = $plugin->groups->getGroupSiteSettings($group->id);

    return count($settings) === 1 ?: 'got ' . count($settings) . ' rows';
});

check('a group with no sites is rejected', function() {
    $orphan = new LocationGroup(['name' => 'No Sites', 'handle' => 'noSites']);
    $orphan->setSiteSettings([]);

    return $orphan->validate() === false && $orphan->hasErrors('siteSettings');
});

section('Locations');

/**
 * Builds a location with hand-set coordinates, so nothing here needs a geocoder.
 */
$makeLocation = function(string $title, array $coords, array $extra = []) use ($group): Location {
    $address = new Address();
    $address->countryCode = 'US';
    $address->addressLine1 = $extra['line1'] ?? '100 Main St';
    $address->locality = $extra['locality'] ?? 'Somewhere';
    $address->administrativeArea = $extra['state'] ?? 'NC';
    $address->postalCode = $extra['postalCode'] ?? '28202';

    $location = new Location();
    $location->groupId = $group->id;
    $location->title = $title;
    $location->lat = $coords[0];
    $location->lng = $coords[1];
    $location->geocodeState = Location::GEOCODE_MANUAL;
    $location->setAddress($address);

    if (isset($extra['hours'])) {
        $location->setHours($extra['hours']);
    }

    if (isset($extra['phone'])) {
        $location->phone = $extra['phone'];
    }

    return $location;
};

$uptown = $makeLocation('Uptown ' . $suffix, $charlotte, [
    'line1' => '201 S Tryon St',
    'locality' => 'Charlotte',
    'phone' => '+1 704 555 0100',
    'hours' => ['mon' => [['open' => '09:00', 'close' => '17:00']]],
]);
$raleighStore = $makeLocation('Raleigh ' . $suffix, $raleigh, ['locality' => 'Raleigh']);
$columbiaStore = $makeLocation('Columbia ' . $suffix, $columbia, ['locality' => 'Columbia', 'state' => 'SC']);
$londonStore = $makeLocation('London ' . $suffix, $london, ['locality' => 'London']);

check('a location saves', function() use ($plugin, $uptown) {
    return $plugin->locations->saveLocation($uptown) ?: json_encode($uptown->getErrors());
});

check('its address was saved as an Address element', function() use ($uptown) {
    return $uptown->addressId !== null
        && Craft::$app->getElements()->getElementById($uptown->addressId, Address::class) !== null;
});

check('its row holds the coordinates', function() use ($uptown) {
    $row = (new craft\db\Query())
        ->select(['lat', 'lng', 'geocodeState'])
        ->from('{{%fold_locations}}')
        ->where(['id' => $uptown->id])
        ->one();

    return (abs((float)$row['lat'] - 35.2271) < 0.00001 && $row['geocodeState'] === 'manual')
        ?: json_encode($row);
});

check('the hours survive the round trip', function() use ($uptown) {
    $reloaded = Location::find()->id($uptown->id)->one();

    return $reloaded->getHours()->forDay('mon') === [['open' => '09:00', 'close' => '17:00']]
        ?: json_encode($reloaded->getHours()->toArray());
});

check('a manually placed location is not queued for geocoding again', function() use ($uptown) {
    return $uptown->needsGeocoding() === false;
});

check('the geocodable address is comma-separated, not locale-formatted', function() use ($uptown) {
    $address = $uptown->getGeocodableAddress();

    return str_contains($address, '201 S Tryon St, Charlotte') && !str_contains($address, "\n")
        ?: "got $address";
});

check('changing the address changes the geocode hash', function() use ($uptown) {
    $before = $uptown->computeGeocodeHash();
    $uptown->getAddress()->addressLine1 = '300 S Tryon St';
    $after = $uptown->computeGeocodeHash();

    // Put it back, so later checks see the address they expect.
    $uptown->getAddress()->addressLine1 = '201 S Tryon St';

    return $before !== $after && $before !== null;
});

check('latitude without longitude is rejected', function() use ($group) {
    $half = new Location();
    $half->groupId = $group->id;
    $half->title = 'Half a coordinate';
    $half->lat = 35.0;

    return $half->validate() === false && $half->hasErrors('lat');
});

foreach ([$raleighStore, $columbiaStore, $londonStore] as $store) {
    $plugin->locations->saveLocation($store);
}

check('the other three locations saved', function() use ($raleighStore, $columbiaStore, $londonStore) {
    return $raleighStore->id !== null && $columbiaStore->id !== null && $londonStore->id !== null;
});

section('Radius search');

check('a 25-mile search from Charlotte finds only Charlotte', function() use ($group, $charlotte) {
    $found = Location::find()
        ->groupId($group->id)
        ->nearby(['lat' => $charlotte[0], 'lng' => $charlotte[1], 'radius' => 25, 'unit' => 'mi'])
        ->all();

    return count($found) === 1 ?: 'found ' . count($found) . ': ' . implode(', ', array_map(fn($l) => $l->title, $found));
});

check('a 200-mile search finds Charlotte, Columbia and Raleigh', function() use ($group, $charlotte) {
    $found = Location::find()
        ->groupId($group->id)
        ->nearby(['lat' => $charlotte[0], 'lng' => $charlotte[1], 'radius' => 200, 'unit' => 'mi'])
        ->all();

    return count($found) === 3 ?: 'found ' . count($found);
});

check('and orders them nearest first', function() use ($group, $charlotte, $suffix) {
    $found = Location::find()
        ->groupId($group->id)
        ->nearby(['lat' => $charlotte[0], 'lng' => $charlotte[1], 'radius' => 200, 'unit' => 'mi'])
        ->all();

    $titles = array_map(fn($l) => $l->title, $found);

    return $titles === ["Uptown $suffix", "Columbia $suffix", "Raleigh $suffix"] ?: json_encode($titles);
});

check('the distance is populated and agrees with the PHP maths', function() use ($group, $charlotte, $raleigh) {
    $found = Location::find()
        ->groupId($group->id)
        ->nearby(['lat' => $charlotte[0], 'lng' => $charlotte[1], 'radius' => 200, 'unit' => 'mi'])
        ->all();

    $raleighResult = null;

    foreach ($found as $location) {
        if (abs((float)$location->lat - $raleigh[0]) < 0.001) {
            $raleighResult = $location;
        }
    }

    if ($raleighResult === null) {
        return 'Raleigh was not in the results';
    }

    $expected = Geo::distance($charlotte[0], $charlotte[1], $raleigh[0], $raleigh[1], 'mi');

    // SQL and PHP compute the same formula; a discrepancy over a tenth of a mile means the two
    // implementations have drifted apart, which is the whole failure this check exists for.
    return abs($raleighResult->distance - $expected) < 0.1
        ?: sprintf('sql=%s php=%s', $raleighResult->distance, $expected);
});

check('kilometres give a larger number than miles for the same pair', function() use ($group, $charlotte) {
    $mi = Location::find()->groupId($group->id)
        ->nearby(['lat' => $charlotte[0], 'lng' => $charlotte[1], 'radius' => 500, 'unit' => 'mi'])->all();
    $km = Location::find()->groupId($group->id)
        ->nearby(['lat' => $charlotte[0], 'lng' => $charlotte[1], 'radius' => 800, 'unit' => 'km'])->all();

    return ($km[1]->distance > $mi[1]->distance) ?: sprintf('km=%s mi=%s', $km[1]->distance, $mi[1]->distance);
});

check('the limit is applied after the distance sort, not before it', function() use ($group, $charlotte, $suffix) {
    // The regression this guards: Craft applies limit and orderBy to the *sub*-query, so a
    // distance expression selected only on the outer query pages the wrong rows and then sorts
    // the page — which looks nearly right and is not.
    $found = Location::find()
        ->groupId($group->id)
        ->nearby(['lat' => $charlotte[0], 'lng' => $charlotte[1], 'radius' => 5000, 'unit' => 'mi'])
        ->limit(2)
        ->all();

    $titles = array_map(fn($l) => $l->title, $found);

    return $titles === ["Uptown $suffix", "Columbia $suffix"] ?: json_encode($titles);
});

check('a search with no radius still sorts by distance', function() use ($group, $london, $suffix) {
    $found = Location::find()
        ->groupId($group->id)
        ->nearby(['lat' => $london[0], 'lng' => $london[1]])
        ->all();

    return $found[0]->title === "London $suffix" ?: 'nearest was ' . $found[0]->title;
});

check('an explicit orderBy is not overridden by the distance sort', function() use ($group, $charlotte) {
    $found = Location::find()
        ->groupId($group->id)
        ->nearby(['lat' => $charlotte[0], 'lng' => $charlotte[1], 'radius' => 5000])
        ->orderBy(['elements_sites.title' => SORT_ASC])
        ->all();

    $titles = array_map(fn($l) => $l->title, $found);
    $sorted = $titles;
    sort($sorted);

    return $titles === $sorted ?: json_encode($titles);
});

check('a location with no coordinates is never in a distance search', function() use ($group, $charlotte) {
    $blank = new Location();
    $blank->groupId = $group->id;
    $blank->title = 'No coordinates';
    $blank->geocodeState = Location::GEOCODE_PENDING;
    Plugin::getInstance()->locations->saveLocation($blank);

    $found = Location::find()
        ->groupId($group->id)
        ->nearby(['lat' => $charlotte[0], 'lng' => $charlotte[1], 'radius' => 20000])
        ->ids();

    $result = !in_array($blank->id, $found, true);
    Craft::$app->getElements()->deleteElement($blank, true);

    return $result ?: 'the blank location was returned';
});

check('hasCoordinates(false) finds the ones awaiting a lookup', function() use ($group) {
    $blank = new Location();
    $blank->groupId = $group->id;
    $blank->title = 'Awaiting';
    Plugin::getInstance()->locations->saveLocation($blank);

    $count = (int)Location::find()->groupId($group->id)->hasCoordinates(false)->count();
    Craft::$app->getElements()->deleteElement($blank, true);

    return $count === 1 ?: "found $count";
});

check('a group handle that matches nothing returns nothing, not everything', function() {
    return (int)Location::find()->group('noSuchGroupHandleAnywhere')->count() === 0;
});

section('Search service');

check('coordinates given directly skip the geocoder', function() use ($plugin, $charlotte, $group) {
    $result = $plugin->search->search([
        'lat' => $charlotte[0],
        'lng' => $charlotte[1],
        'radius' => 200,
        'group' => $group->handle,
    ]);

    return count($result->locations) === 3 && $result->origin !== null
        ?: 'got ' . count($result->locations) . ' results';
});

check('the result reports a total independent of the page', function() use ($plugin, $charlotte, $group) {
    $result = $plugin->search->search([
        'lat' => $charlotte[0],
        'lng' => $charlotte[1],
        'radius' => 200,
        'limit' => 1,
        'group' => $group->handle,
    ]);

    return (count($result->locations) === 1 && $result->total === 3)
        ?: sprintf('page=%s total=%s', count($result->locations), $result->total);
});

check('the bounds enclose every result', function() use ($plugin, $charlotte, $group) {
    $result = $plugin->search->search([
        'lat' => $charlotte[0],
        'lng' => $charlotte[1],
        'radius' => 200,
        'group' => $group->handle,
    ]);

    [$south, $west, $north, $east] = $result->bounds();

    foreach ($result->locations as $location) {
        if ($location->lat < $south || $location->lat > $north || $location->lng < $west || $location->lng > $east) {
            return "location {$location->title} is outside the bounds";
        }
    }

    return true;
});

check('a limit above the maximum is clamped', function() use ($plugin, $charlotte, $group) {
    $result = $plugin->search->search([
        'lat' => $charlotte[0],
        'lng' => $charlotte[1],
        'radius' => 20000,
        'limit' => 999999,
        'group' => $group->handle,
    ]);

    // The clamp is invisible in the results here (there are only four), so the check is that the
    // request was served at all rather than allocating a million rows.
    return count($result->locations) <= $plugin->getSettings()->maxLimit;
});

check('an unfindable term is reported as an unfindable term', function() use ($plugin) {
    $result = new justinholtweb\fold\models\SearchResult(term: 'Zzyzx Nowhere 99999', origin: null);

    return $result->originNotFound() === true;
});

section('Search fallbacks');

check('a term that cannot be placed falls back to matching location names', function() use ($plugin, $group, $suffix) {
    // The geocoder is switched off rather than fed a nonsense term, so the check is
    // deterministic and needs no network: with no geocoder there is never an origin, which is
    // exactly the state the fallback exists for.
    $before = $plugin->getSettings()->geocoderDriver;
    $plugin->getSettings()->geocoderDriver = 'none';

    foreach (Location::find()->groupId($group->id)->all() as $location) {
        Craft::$app->getSearch()->indexElementAttributes($location);
    }

    $result = $plugin->search->search(['q' => 'Columbia ' . $suffix, 'group' => $group->handle]);
    $plugin->getSettings()->geocoderDriver = $before;

    return ($result->matchedByName && count($result->locations) >= 1)
        ?: sprintf('byName=%s count=%d', var_export($result->matchedByName, true), count($result->locations));
});

check('a name match is not reported as an unfindable place', function() use ($plugin, $group, $suffix) {
    $before = $plugin->getSettings()->geocoderDriver;
    $plugin->getSettings()->geocoderDriver = 'none';

    $result = $plugin->search->search(['q' => 'Columbia ' . $suffix, 'group' => $group->handle]);
    $plugin->getSettings()->geocoderDriver = $before;

    // Telling somebody who searched "Columbia" that we could not find "Columbia", while showing
    // them the Columbia shop, is the nonsense this flag exists to prevent.
    return $result->originNotFound() === false;
});

check('a term matching neither a place nor a name is reported as not found', function() use ($plugin, $group) {
    $before = $plugin->getSettings()->geocoderDriver;
    $plugin->getSettings()->geocoderDriver = 'none';

    $result = $plugin->search->search(['q' => 'zzyzxqqq nowhereville', 'group' => $group->handle]);
    $plugin->getSettings()->geocoderDriver = $before;

    return ($result->originNotFound() && $result->locations === [])
        ?: sprintf('notFound=%s count=%d', var_export($result->originNotFound(), true), count($result->locations));
});

check('an empty search returns the whole group rather than nothing', function() use ($plugin, $group) {
    $result = $plugin->search->search(['group' => $group->handle]);

    return count($result->locations) === 4 ?: 'got ' . count($result->locations);
});

section('Import and export');

check('a CSV with somebody else’s column names imports', function() use ($plugin, $group) {
    $csv = sys_get_temp_dir() . '/fold-check-import.csv';
    file_put_contents($csv, implode("\n", [
        'Store Name,Address 1,City,State,Zip,Country,Latitude,Longitude,Telephone,mon',
        '"Imported Shop","1 High St","Boone","NC","28607","US","36.2168","-81.6746","+1 828 555 0000","9am-5pm"',
        '',
    ]));

    $result = $plugin->importer->importCsv($csv, ['groupId' => $group->id]);
    @unlink($csv);

    return ($result['imported'] === 1 && $result['errors'] === [])
        ?: json_encode($result);
});

check('the imported shop has its address, coordinates and hours', function() use ($group) {
    $shop = Location::find()->groupId($group->id)->title('Imported Shop')->one();

    if ($shop === null) {
        return 'the shop was not found';
    }

    return (abs($shop->lat - 36.2168) < 0.0001
        && $shop->getAddress()?->locality === 'Boone'
        && $shop->phone === '+1 828 555 0000'
        && $shop->getHours()->forDay('mon') === [['open' => '09:00', 'close' => '17:00']])
        ?: json_encode(['lat' => $shop->lat, 'city' => $shop->getAddress()?->locality, 'hours' => $shop->getHours()->toArray()]);
});

check('coordinates from a file are treated as placed by hand', function() use ($group) {
    $shop = Location::find()->groupId($group->id)->title('Imported Shop')->one();

    // Otherwise the next geocode run would overwrite a coordinate somebody deliberately supplied.
    return $shop->geocodeState === Location::GEOCODE_MANUAL ?: 'state is ' . $shop->geocodeState;
});

check('importing the same file twice updates rather than duplicates', function() use ($plugin, $group) {
    $csv = sys_get_temp_dir() . '/fold-check-import.csv';
    file_put_contents($csv, implode("\n", [
        'Store Name,Address 1,City,State,Zip,Country,Latitude,Longitude',
        '"Imported Shop","2 Low St","Boone","NC","28607","US","36.2168","-81.6746"',
        '',
    ]));

    $result = $plugin->importer->importCsv($csv, ['groupId' => $group->id]);
    @unlink($csv);

    $count = (int)Location::find()->groupId($group->id)->title('Imported Shop')->count();
    $shop = Location::find()->groupId($group->id)->title('Imported Shop')->one();

    return ($result['updated'] === 1 && $count === 1 && $shop->getAddress()?->addressLine1 === '2 Low St')
        ?: json_encode($result + ['count' => $count]);
});

check('a dry run writes nothing', function() use ($plugin, $group) {
    $csv = sys_get_temp_dir() . '/fold-check-dry.csv';
    file_put_contents($csv, "name,city\n\"Never Saved\",\"Nowhere\"\n");

    $result = $plugin->importer->importCsv($csv, ['groupId' => $group->id, 'dryRun' => true]);
    @unlink($csv);

    return ($result['imported'] === 1 && (int)Location::find()->groupId($group->id)->title('Never Saved')->count() === 0)
        ?: json_encode($result);
});

check('a file with no name column is refused with a reason', function() use ($plugin, $group) {
    $csv = sys_get_temp_dir() . '/fold-check-bad.csv';
    file_put_contents($csv, "city,zip\nBoone,28607\n");

    $result = $plugin->importer->importCsv($csv, ['groupId' => $group->id]);
    @unlink($csv);

    return $result['errors'] !== [] && $result['imported'] === 0;
});

check('an Excel byte-order mark does not hide the first column', function() use ($plugin, $group) {
    $csv = sys_get_temp_dir() . '/fold-check-bom.csv';
    file_put_contents($csv, "\xEF\xBB\xBFname,city\n\"BOM Shop\",\"Boone\"\n");

    $result = $plugin->importer->importCsv($csv, ['groupId' => $group->id]);
    @unlink($csv);

    return ($result['imported'] === 1 && $result['errors'] === []) ?: json_encode($result);
});

check('an export round-trips back through the importer', function() use ($plugin, $group) {
    $locations = Location::find()->groupId($group->id)->all();
    $csv = $plugin->exporter->toCsv($locations);

    $path = sys_get_temp_dir() . '/fold-check-roundtrip.csv';
    file_put_contents($path, $csv);

    $result = $plugin->importer->importCsv($path, ['groupId' => $group->id, 'dryRun' => true]);
    @unlink($path);

    // Every row already exists, so a re-import is all updates and no new records — which is the
    // definition of a round trip that works.
    return ($result['updated'] === count($locations) && $result['imported'] === 0 && $result['errors'] === [])
        ?: json_encode($result + ['expected' => count($locations)]);
});

check('the JSON export carries the hours', function() use ($plugin, $group) {
    $shop = Location::find()->groupId($group->id)->title('Imported Shop')->one();
    $json = json_decode($plugin->exporter->toJson([$shop]), true);

    return isset($json[0]['hours']['mon'][0]['open']) ?: json_encode($json);
});

check('Lite refuses to export', function() use ($plugin, $group) {
    Craft::$app->getPlugins()->switchEdition('fold', Plugin::EDITION_LITE);
    $csv = Plugin::getInstance()->exporter->toCsv(Location::find()->groupId($group->id)->all());
    Craft::$app->getPlugins()->switchEdition('fold', Plugin::EDITION_PRO);

    return $csv === '';
});

section('Geocode cache');

check('a cached point comes back without a provider', function() use ($plugin) {
    $plugin->geocoder->clearCache();

    $reflection = new ReflectionClass($plugin->geocoder);
    $write = $reflection->getMethod('writeCache');
    $write->setAccessible(true);
    $key = $reflection->getMethod('cacheKey');
    $key->setAccessible(true);
    $read = $reflection->getMethod('readCache');
    $read->setAccessible(true);

    $hash = $key->invoke($plugin->geocoder, 'Charlotte NC', 'nominatim', []);
    $write->invoke($plugin->geocoder, $hash, 'nominatim', 'Charlotte NC', GeoPoint::make(35.2271, -80.8431, 'Charlotte'));

    $point = $read->invoke($plugin->geocoder, $hash);

    return ($point instanceof GeoPoint && abs($point->lat - 35.2271) < 0.0001) ?: var_export($point, true);
});

check('a cached miss is remembered as a miss, not as "not cached"', function() use ($plugin) {
    $reflection = new ReflectionClass($plugin->geocoder);
    $write = $reflection->getMethod('writeCache');
    $write->setAccessible(true);
    $key = $reflection->getMethod('cacheKey');
    $key->setAccessible(true);
    $read = $reflection->getMethod('readCache');
    $read->setAccessible(true);

    $hash = $key->invoke($plugin->geocoder, 'asdfghjkl', 'nominatim', []);
    $write->invoke($plugin->geocoder, $hash, 'nominatim', 'asdfghjkl', null);

    // null means "cached, and it was a miss"; false would mean "ask the provider again".
    return $read->invoke($plugin->geocoder, $hash) === null;
});

check('an uncached term reports itself as uncached', function() use ($plugin) {
    $reflection = new ReflectionClass($plugin->geocoder);
    $read = $reflection->getMethod('readCache');
    $read->setAccessible(true);

    return $read->invoke($plugin->geocoder, str_repeat('0', 40)) === false;
});

check('a coordinate pair is geocoded without touching the network', function() use ($plugin) {
    $point = $plugin->geocoder->geocode('35.2271,-80.8431');

    return ($point !== null && abs($point->lat - 35.2271) < 0.0001) ?: var_export($point, true);
});

check('the cache clears', function() use ($plugin) {
    $plugin->geocoder->clearCache();

    return (int)(new craft\db\Query())->from('{{%fold_geocodecache}}')->count() === 0;
});

section('Commerce');

check('the Commerce service answers safely whether or not Commerce is installed', function() use ($plugin) {
    // The contract is that none of this throws. What it returns depends on the harness.
    $available = $plugin->commerce->isAvailable();
    $locations = $plugin->commerce->getInventoryLocations();
    $byId = $plugin->commerce->getInventoryLocationById(999999);

    return is_bool($available) && is_array($locations) && $byId === null;
});

check('a stock filter with nothing in stock returns nothing rather than everything', function() use ($group) {
    $found = Location::find()->groupId($group->id)->inStockOf(999999)->all();

    return $found === [] ?: 'got ' . count($found) . ' locations';
});

section('Editions');

check('Lite caps locations and Pro does not', function() {
    return Edition::maxLocations(false) === Edition::LITE_MAX_LOCATIONS && Edition::maxLocations(true) === null;
});

check('Lite caps groups at one', function() {
    return Edition::maxGroups(false) === 1 && Edition::maxGroups(true) === null;
});

check('Lite may use Leaflet but not Google', function() {
    return Edition::allowsMapDriver('leaflet', false)
        && !Edition::allowsMapDriver('google', false)
        && Edition::allowsMapDriver('google', true);
});

check('a lapsed licence is downgraded to a working map, not to none', function() {
    return Edition::mapDriverFor('google', false) === 'leaflet'
        && Edition::geocoderDriverFor('mapbox', false) === 'nominatim';
});

check('Lite refuses Commerce and the search log', function() {
    return !Edition::allowsCommerce(false) && !Edition::allowsSearchLog(false)
        && Edition::allowsCommerce(true) && Edition::allowsSearchLog(true);
});

check('the Lite location cap is actually enforced on save', function() use ($group, $makeLocation) {
    Craft::$app->getPlugins()->switchEdition('fold', Plugin::EDITION_LITE);

    $locations = Plugin::getInstance()->locations;
    $total = $locations->getTotalLocations();
    $canCreate = $locations->canCreateLocation();

    Craft::$app->getPlugins()->switchEdition('fold', Plugin::EDITION_PRO);

    // The harness may hold more or fewer than the cap depending on what else has run; what is
    // being checked is that the answer follows the count rather than being hard-coded.
    return ($total < Edition::LITE_MAX_LOCATIONS) === $canCreate
        ?: "total=$total canCreate=" . var_export($canCreate, true);
});

section('Hardening');

check('a CSV export defuses formulas but leaves numbers and phone numbers intact on re-import', function() use ($plugin, $makeLocation, $suffix) {
    $shop = $makeLocation('=HYPERLINK("http://evil/") ' . $suffix, [35.2, -80.8], ['phone' => '+1 704 555 0199']);

    if (!Craft::$app->getElements()->saveElement($shop)) {
        return 'save failed: ' . json_encode($shop->getErrors());
    }

    $csv = $plugin->exporter->toCsv([$shop]);
    $rows = array_map('str_getcsv', array_filter(explode("\n", preg_replace('/^\xEF\xBB\xBF/', '', $csv))));
    $header = $rows[0];
    $row = array_combine($header, array_slice($rows[1], 0, count($header)));

    Craft::$app->getElements()->deleteElement($shop, true);

    if (!str_starts_with($row['title'], "'=")) {
        return 'title not escaped: ' . $row['title'];
    }

    if ($row['lng'] !== '-80.8000000' && $row['lng'] !== '-80.8') {
        return 'longitude was touched: ' . $row['lng'];
    }

    return str_starts_with($row['phone'], "'+") ?: 'phone not escaped: ' . $row['phone'];
});

check('the importer strips the formula guard again', function() use ($plugin, $group, $suffix) {
    $path = sys_get_temp_dir() . '/fold-check-guard.csv';
    file_put_contents($path, "title,addressLine1,locality,countryCode,lat,lng,phone\n\"Guarded $suffix\",1 Main St,Charlotte,US,35.1,-80.9,'+1 704 555 0123\n");

    $result = $plugin->importer->importCsv($path, ['groupId' => $group->id]);
    @unlink($path);

    $shop = Location::find()->groupId($group->id)->title("Guarded $suffix")->one();

    return $shop?->phone === '+1 704 555 0123' ?: json_encode(['phone' => $shop?->phone, 'result' => $result]);
});

check('the Lite cap holds even for a save that bypasses Fold’s service', function() use ($makeLocation, $suffix) {
    Craft::$app->getPlugins()->switchEdition('fold', Plugin::EDITION_LITE);

    $locations = Plugin::getInstance()->locations;
    $made = [];
    $refused = false;

    // Fill to the cap if the harness is below it, then try one more. Straight to
    // `saveElement()`, the way Craft's Duplicate action and `elements/create` arrive.
    for ($i = 0; $i <= Edition::LITE_MAX_LOCATIONS; $i++) {
        $atCap = $locations->getTotalLocations() >= Edition::LITE_MAX_LOCATIONS;
        $shop = $makeLocation("Cap $i $suffix", [35.0, -80.0]);
        $saved = Craft::$app->getElements()->saveElement($shop);

        if ($saved) {
            $made[] = $shop;
        }

        if ($atCap) {
            $refused = !$saved;
            break;
        }
    }

    Craft::$app->getPlugins()->switchEdition('fold', Plugin::EDITION_PRO);

    foreach ($made as $shop) {
        Craft::$app->getElements()->deleteElement($shop, true);
    }

    return $refused ?: 'a location over the cap was saved';
});

check('Pro is not capped by the same rule', function() use ($makeLocation, $suffix) {
    $shop = $makeLocation("Uncapped $suffix", [35.0, -80.0]);
    $saved = Craft::$app->getElements()->saveElement($shop);

    if ($saved) {
        Craft::$app->getElements()->deleteElement($shop, true);
    }

    return $saved;
});

check('API keys resolve environment variables', function() {
    $settings = new justinholtweb\fold\models\Settings();
    putenv('FOLD_CHECK_KEY=resolved-key');
    $_SERVER['FOLD_CHECK_KEY'] = 'resolved-key';
    $settings->googleApiKey = '$FOLD_CHECK_KEY';

    return ($settings->getGoogleApiKey() === 'resolved-key' && $settings->getGoogleGeocodingApiKey() === 'resolved-key')
        ?: json_encode([$settings->getGoogleApiKey(), $settings->getGoogleGeocodingApiKey()]);
});

check('the server key is preferred over the browser key for geocoding', function() {
    $settings = new justinholtweb\fold\models\Settings();
    $settings->googleApiKey = 'browser';
    $settings->googleGeocodingApiKey = 'server';

    return $settings->getGoogleGeocodingApiKey() === 'server' && $settings->getGoogleApiKey() === 'browser';
});

check('a secret Mapbox token is refused', function() {
    $settings = new justinholtweb\fold\models\Settings();
    $settings->mapboxAccessToken = 'sk.secret';
    $refused = !$settings->validate(['mapboxAccessToken']);
    $settings->mapboxAccessToken = 'pk.public';

    return ($refused && $settings->validate(['mapboxAccessToken'])) ?: json_encode($settings->getErrors());
});

check('the widest public radius is the largest one the site offers', function() {
    $settings = new justinholtweb\fold\models\Settings();
    $settings->radiusOptions = [5, 10, 250];
    $settings->defaultRadius = 25;

    return $settings->getMaxPublicRadius() === 250.0 ?: (string)$settings->getMaxPublicRadius();
});

check('the search log keeps coordinates to about a kilometre', function() use ($plugin, $charlotte) {
    $settings = $plugin->getSettings();
    $was = $settings->logSearches;
    $settings->logSearches = true;

    $plugin->search->search(['lat' => 35.227123, 'lng' => -80.843187, 'radius' => 5]);

    $settings->logSearches = $was;

    $row = (new craft\db\Query())
        ->from('{{%fold_searches}}')
        ->orderBy(['id' => SORT_DESC])
        ->one();

    Craft::$app->getDb()->createCommand()->delete('{{%fold_searches}}', ['id' => $row['id']])->execute();

    return ((float)$row['lat'] === 35.23 && (float)$row['lng'] === -80.84) ?: json_encode($row);
});

check('old search log rows are purged and recent ones kept', function() use ($plugin) {
    $db = Craft::$app->getDb();
    $now = new DateTime();
    $insert = fn(string $when, string $term) => $db->createCommand()->insert('{{%fold_searches}}', [
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        'term' => $term,
        'resultCount' => 0,
        'dateCreated' => craft\helpers\Db::prepareDateForDb(new DateTime($when)),
        'dateUpdated' => craft\helpers\Db::prepareDateForDb($now),
        'uid' => craft\helpers\StringHelper::UUID(),
    ])->execute();

    $insert('-200 days', 'fold-check-old');
    $insert('-1 day', 'fold-check-new');

    $plugin->search->purgeSearchLog(90);

    $left = (new craft\db\Query())->select('term')->from('{{%fold_searches}}')
        ->where(['term' => ['fold-check-old', 'fold-check-new']])->column();

    $db->createCommand()->delete('{{%fold_searches}}', ['term' => ['fold-check-old', 'fold-check-new']])->execute();

    return $left === ['fold-check-new'] ?: json_encode($left);
});

check('an address that failed to geocode is not retried until it changes', function() use ($makeLocation, $suffix) {
    $shop = $makeLocation("Unplaceable $suffix", [35.0, -80.0]);
    $shop->lat = null;
    $shop->lng = null;
    $shop->geocodeState = Location::GEOCODE_FAILED;
    $shop->geocodeHash = $shop->computeGeocodeHash();

    $unchanged = $shop->needsGeocoding();
    $shop->getAddress()->addressLine1 = '200 Other St';
    $changed = $shop->needsGeocoding();

    return (!$unchanged && $changed) ?: json_encode(['unchanged' => $unchanged, 'changed' => $changed]);
});

section('Front end');

check('a group colour is normalized for the map, and anything else is refused', function() {
    $group = new LocationGroup(['color' => 'C0FFEE']);
    $ok = $group->getMarkerColor() === '#c0ffee';
    $group->color = '#abc';
    $short = $group->getMarkerColor() === '#aabbcc';
    $group->color = '"><script>';
    $hostile = $group->getMarkerColor() === null;
    $group->color = null;

    return ($ok && $short && $hostile && $group->getMarkerColor() === null)
        ?: json_encode(compact('ok', 'short', 'hostile'));
});

check('the JSON endpoint carries the group colour, and null without one', function() use ($plugin, $group, $charlotte) {
    $shape = new ReflectionMethod(justinholtweb\fold\controllers\SearchController::class, 'shape');
    $controller = new justinholtweb\fold\controllers\SearchController('search', $plugin);
    $search = fn() => $plugin->search->search([
        'lat' => $charlotte[0],
        'lng' => $charlotte[1],
        'radius' => 200,
        'group' => $group->handle,
    ]);

    $plain = $shape->invoke($controller, $search(), null)['locations'][0] ?? [];

    $group->color = '#C0FFEE';
    $plugin->groups->saveGroup($group);
    $coloured = $shape->invoke($controller, $search(), null)['locations'][0] ?? [];
    $group->color = null;
    $plugin->groups->saveGroup($group);

    return (array_key_exists('color', $plain) && $plain['color'] === null && ($coloured['color'] ?? null) === '#c0ffee')
        ?: json_encode(['plain' => $plain['color'] ?? 'missing', 'coloured' => $coloured['color'] ?? 'missing']);
});

check('clustering is offered to Leaflet only, with its files', function() use ($plugin) {
    $settings = $plugin->getSettings();
    $was = [$settings->mapDriver, $settings->clusterMarkers];
    $variable = new justinholtweb\fold\twig\FoldVariable();

    $settings->clusterMarkers = true;
    $settings->mapDriver = 'leaflet';
    $leaflet = json_decode($variable->mapConfig(), true);
    $settings->mapDriver = 'google';
    $google = json_decode($variable->mapConfig(), true);
    $settings->mapDriver = 'leaflet';
    $settings->clusterMarkers = false;
    $off = json_decode($variable->mapConfig(), true);

    [$settings->mapDriver, $settings->clusterMarkers] = $was;

    $ok = $leaflet['cluster'] === true
        && str_ends_with((string)($leaflet['clusterJsUrl'] ?? ''), 'leaflet.markercluster.js')
        && count($leaflet['clusterCssUrls'] ?? []) === 2
        && $google['cluster'] === false
        && $off['cluster'] === false
        && !isset($off['clusterJsUrl']);

    return $ok ?: json_encode(compact('leaflet', 'google', 'off'));
});

section('Cleanup');

check('deleting the group takes its locations with it', function() use ($plugin, $group) {
    $plugin->groups->deleteGroupById($group->id);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    $remaining = (int)(new craft\db\Query())
        ->from('{{%fold_locations}}')
        ->where(['groupId' => $group->id])
        ->count();

    return $remaining === 0 ?: "$remaining rows left";
});

check('and the group record is gone', function() use ($plugin, $group) {
    return (int)(new craft\db\Query())
        ->from('{{%fold_locationgroups}}')
        ->where(['id' => $group->id])
        ->count() === 0;
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
