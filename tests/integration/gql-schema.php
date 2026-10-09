<?php
/**
 * Fold integration checks — GraphQL and LocalBusiness structured data.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-fold/tests/integration/gql-schema.php
 *
 * Every GraphQL check executes a real query through `Craft::$app->getGql()->executeQuery()`
 * against an in-memory schema, so the schema permissions are the real ones and not a reading of
 * them. Self-cleaning: two groups are made and deleted, and settings changes stay in memory.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Address;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\models\LocationGroup;
use justinholtweb\fold\models\LocationGroupSiteSettings;
use justinholtweb\fold\Plugin;

// Off for the run: Craft keys its GraphQL result cache by schema UID + query + variables, so a cached
// answer from an earlier run could otherwise stand in for a denied case.
Craft::$app->getConfig()->getGeneral()->enableGraphqlCaching = false;

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

if (!$plugin->isPro()) {
    echo "Fold must be on Pro in the harness (two groups are needed).\n";
    exit(1);
}

$settings = $plugin->getSettings();
$charlotte = [35.2271, -80.8431];
$raleigh = [35.7796, -78.6382];
$london = [51.5074, -0.1278];
$suffix = substr(md5((string)microtime(true)), 0, 6);
$siteId = Craft::$app->getSites()->getPrimarySite()->id;

$makeGroup = function(string $name, string $handle, ?string $schemaType = null) use ($plugin, $siteId): LocationGroup {
    $group = new LocationGroup(['name' => $name, 'handle' => $handle, 'schemaType' => $schemaType]);
    $group->setSiteSettings([new LocationGroupSiteSettings([
        'siteId' => $siteId,
        'enabledByDefault' => true,
        'uriFormat' => 'fold-gql-' . strtolower($handle) . '/{slug}',
        'template' => 'stores/_location',
    ])]);

    if (!$plugin->groups->saveGroup($group)) {
        throw new RuntimeException('Group did not save: ' . json_encode($group->getErrors()));
    }

    Craft::$app->getProjectConfig()->saveModifiedConfigData();

    return $group;
};

$makeLocation = function(LocationGroup $group, string $title, array $coords, array $extra = []) use ($plugin): Location {
    $address = new Address();
    $address->countryCode = 'US';
    $address->addressLine1 = $extra['line1'] ?? '100 Main St';
    $address->addressLine2 = $extra['line2'] ?? null;
    $address->locality = $extra['locality'] ?? 'Charlotte';
    $address->administrativeArea = 'NC';
    $address->postalCode = '28202';

    $location = new Location();
    $location->groupId = $group->id;
    $location->title = $title;
    $location->lat = $coords[0];
    $location->lng = $coords[1];
    $location->phone = $extra['phone'] ?? null;
    $location->websiteUrl = $extra['websiteUrl'] ?? null;
    $location->geocodeState = Location::GEOCODE_MANUAL;
    $location->setAddress($address);

    if (isset($extra['hours'])) {
        $location->setHours($extra['hours']);
    }

    if (!$plugin->locations->saveLocation($location)) {
        throw new RuntimeException('Location did not save: ' . json_encode($location->getErrors()));
    }

    return $location;
};

$allDay = [['open' => '00:00', 'close' => '24:00']];
$alwaysOpen = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $allDay);
$future = (new DateTime('+20 days'))->format('Y-m-d');
$past = (new DateTime('-20 days'))->format('Y-m-d');

$shops = $makeGroup('GQL Shops ' . $suffix, 'gqlShops' . $suffix, 'Restaurant');
$depots = $makeGroup('GQL Depots ' . $suffix, 'gqlDepots' . $suffix);

$uptown = $makeLocation($shops, 'Uptown ' . $suffix, $charlotte, [
    'line1' => '201 S Tryon St',
    'line2' => 'Suite 5',
    'phone' => '+1 704 555 0100',
    'websiteUrl' => 'https://uptown.example.com',
    'hours' => [
        'mon' => [['open' => '09:00', 'close' => '17:00']],
        'tue' => [['open' => '09:00', 'close' => '17:00']],
        'wed' => [['open' => '09:00', 'close' => '17:00']],
        'thu' => [['open' => '09:00', 'close' => '17:00']],
        'fri' => [['open' => '09:00', 'close' => '17:00']],
        'sat' => [['open' => '10:00', 'close' => '14:00']],
        'exceptions' => [$future => [], $past => []],
    ],
]);
$allNight = $makeLocation($shops, 'All Night ' . $suffix, [35.2300, -80.8400], ['hours' => $alwaysOpen]);
$raleighShop = $makeLocation($shops, 'Raleigh ' . $suffix, $raleigh, ['locality' => 'Raleigh', 'hours' => ['note' => 'By appointment']]);
$londonShop = $makeLocation($shops, 'London ' . $suffix, $london, ['locality' => 'London']);
$depot = $makeLocation($depots, 'Depot ' . $suffix, [35.2280, -80.8420]);

// Narrowed by ID rather than by search, because the search index may be updated by a queue job
// that has not run yet — and the harness holds other locations these checks must not count.
$ids = 'id: [' . implode(', ', [$uptown->id, $allNight->id, $raleighShop->id, $londonShop->id, $depot->id]) . ']';

$schemaFor = function(array $groups): GqlSchema {
    $scope = [];

    foreach ($groups as $group) {
        $scope[] = 'foldLocationGroups.' . $group->uid . ':read';
    }

    return new GqlSchema(['name' => 'Fold check', 'scope' => $scope, 'uid' => StringHelper::UUID()]);
};

$run = function(GqlSchema $schema, string $query, array $variables = []): array {
    $gql = Craft::$app->getGql();
    // Types are built per schema; a type generated for the previous schema must not leak.
    $gql->flushCaches();

    return $gql->executeQuery($schema, $query, $variables);
};

$titles = function(array $result, string $key = 'foldLocations'): array {
    return array_map(static fn($row) => $row['title'], $result['data'][$key] ?? []);
};

section('GraphQL: schema permissions');

check('each location group is offered as a schema component', function() use ($shops) {
    $components = Craft::$app->getGql()->getAllSchemaComponents();
    $queries = $components['queries'] ?? [];
    $found = false;

    foreach ($queries as $heading => $items) {
        if (isset($items['foldLocationGroups.' . $shops->uid . ':read'])) {
            $found = true;
        }
    }

    return $found ?: 'not among the schema components';
});

check('a schema with no Fold permission has no foldLocations query at all', function() use ($run) {
    $result = $run(new GqlSchema(['name' => 'none', 'scope' => [], 'uid' => StringHelper::UUID()]), '{ foldLocations { id } }');

    // Craft drops a field the schema does not have rather than erroring, as it does for
    // `entries` on a schema without sections — so the proof is that nothing came back.
    Craft::$app->getGql()->setActiveSchema(new GqlSchema(['name' => 'none', 'scope' => [], 'uid' => StringHelper::UUID()]));
    $registered = justinholtweb\fold\gql\LocationQueries::getQueries();

    return (!array_key_exists('foldLocations', $result['data'] ?? []) && $registered === [])
        ?: json_encode($result);
});

check('a schema granted one group sees that group’s locations and never the other’s', function() use ($run, $schemaFor, $shops, $titles, $depot, $uptown, $ids) {
    $result = $run($schemaFor([$shops]), '{ foldLocations(' . $ids . ') { title groupHandle } }');
    $got = $titles($result);

    return (in_array($uptown->title, $got, true) && !in_array($depot->title, $got, true))
        ?: json_encode($result);
});

check('asking for the other group by handle does not get round it', function() use ($run, $schemaFor, $shops, $depots) {
    $result = $run($schemaFor([$shops]), '{ foldLocations(group: "' . $depots->handle . '") { title } }');

    return ($result['data']['foldLocations'] ?? null) === [] ?: json_encode($result);
});

check('nor does asking for one of its locations by ID', function() use ($run, $schemaFor, $shops, $depot) {
    $result = $run($schemaFor([$shops]), '{ foldLocation(id: ' . $depot->id . ') { title } }');

    return array_key_exists('foldLocation', $result['data'] ?? []) && $result['data']['foldLocation'] === null
        ?: json_encode($result);
});

check('each group gets its own type, named for its handle', function() use ($run, $schemaFor, $shops, $uptown) {
    $result = $run($schemaFor([$shops]), '{ foldLocation(id: ' . $uptown->id . ') { __typename } }');

    return ($result['data']['foldLocation']['__typename'] ?? null) === $shops->handle . '_FoldLocation' ?: json_encode($result);
});

check('the Locations field’s eager-loading condition is held to the schema’s groups', function() use ($schemaFor, $shops) {
    $field = new justinholtweb\fold\fields\LocationsField(['handle' => 'stores']);
    $gql = Craft::$app->getGql();

    $gql->setActiveSchema($schemaFor([$shops]));
    $granted = $field->getEagerLoadingGqlConditions();
    $gql->setActiveSchema(new GqlSchema(['name' => 'none', 'scope' => [], 'uid' => StringHelper::UUID()]));
    $none = $field->getEagerLoadingGqlConditions();

    return ($granted === ['groupId' => [$shops->id]] && $none === null)
        ?: json_encode(compact('granted', 'none'));
});

section('GraphQL: radius search');

$both = $schemaFor([$shops, $depots]);

check('near sorts by distance and fills it in', function() use ($run, $both, $charlotte, $uptown, $allNight, $depot, $ids) {
    $result = $run($both, '{ foldLocations(near: { lat: ' . $charlotte[0] . ', lng: ' . $charlotte[1] . ', radius: 5 }, ' . $ids . ') { title distance } }');
    $rows = $result['data']['foldLocations'] ?? [];
    $got = array_column($rows, 'title');

    return ($got === [$uptown->title, $depot->title, $allNight->title] && $rows[0]['distance'] === 0.0 && $rows[2]['distance'] > 0)
        ?: json_encode($result);
});

check('the radius is a circle: Raleigh is not within 5 miles of Charlotte', function() use ($run, $both, $charlotte, $raleighShop, $titles) {
    $result = $run($both, '{ foldLocations(near: { lat: ' . $charlotte[0] . ', lng: ' . $charlotte[1] . ', radius: 5 }) { title } }');

    return !in_array($raleighShop->title, $titles($result), true) ?: json_encode($result);
});

check('kilometres are honoured', function() use ($run, $both, $charlotte, $raleighShop, $ids) {
    $result = $run($both, '{ foldLocations(near: { lat: ' . $charlotte[0] . ', lng: ' . $charlotte[1] . ', radius: 100, unit: "km" }, ' . $ids . ') { title } }');

    return !in_array($raleighShop->title, array_column($result['data']['foldLocations'] ?? [], 'title'), true) ?: json_encode($result);
});

check('a radius wider than the site offers is clamped, so London never comes back for Charlotte', function() use ($run, $both, $charlotte, $londonShop, $raleighShop, $settings, $ids) {
    $result = $run($both, '{ foldLocations(near: { lat: ' . $charlotte[0] . ', lng: ' . $charlotte[1] . ', radius: 100000 }, ' . $ids . ') { title } }');
    $got = array_column($result['data']['foldLocations'] ?? [], 'title');

    // The widest option is 100 miles by default; Raleigh is ~130 away and must be out too.
    return ($settings->getMaxPublicRadius() <= 130 && !in_array($londonShop->title, $got, true) && !in_array($raleighShop->title, $got, true) && $got !== [])
        ?: json_encode($result);
});

check('a zero or negative radius is clamped rather than read as "everywhere"', function() use ($run, $both, $charlotte, $londonShop, $ids) {
    $result = $run($both, '{ foldLocations(near: { lat: ' . $charlotte[0] . ', lng: ' . $charlotte[1] . ', radius: -1 }, ' . $ids . ') { title } }');

    return !in_array($londonShop->title, array_column($result['data']['foldLocations'] ?? [], 'title'), true) ?: json_encode($result);
});

check('0,0 is not an origin: an empty list, not the whole chain', function() use ($run, $both) {
    $result = $run($both, '{ foldLocations(near: { lat: 0, lng: 0 }) { title } }');

    return ($result['data']['foldLocations'] ?? null) === [] ?: json_encode($result);
});

check('no more than maxLimit rows, however many are asked for', function() use ($run, $both, $settings, $ids) {
    $was = $settings->maxLimit;
    $settings->maxLimit = 2;

    try {
        $result = $run($both, '{ foldLocations(' . $ids . ', limit: 1000) { title } }');
        $none = $run($both, '{ foldLocations(' . $ids . ') { title } }');
    } finally {
        $settings->maxLimit = $was;
    }

    return (count($result['data']['foldLocations'] ?? []) === 2 && count($none['data']['foldLocations'] ?? []) === 2)
        ?: json_encode([$result, $none]);
});

check('foldLocationCount counts within the radius', function() use ($run, $both, $charlotte, $ids) {
    $result = $run($both, '{ foldLocationCount(near: { lat: ' . $charlotte[0] . ', lng: ' . $charlotte[1] . ', radius: 5 }, ' . $ids . ') }');

    return ($result['data']['foldLocationCount'] ?? null) === 3 ?: json_encode($result);
});

check('openNow keeps only what is open, and treats no hours as not open', function() use ($run, $both, $allNight, $uptown, $ids) {
    $result = $run($both, '{ foldLocations(openNow: true, ' . $ids . ', orderBy: "title") { title openNow } }');
    $got = array_column($result['data']['foldLocations'] ?? [], 'title');
    // Uptown keeps weekday office hours, so whether it belongs depends on when this runs.
    $expected = $uptown->isOpenNow() ? [$allNight->title, $uptown->title] : [$allNight->title];

    return $got === $expected ?: json_encode($result);
});

section('GraphQL: fields');

check('address, hours and contact come back in the JSON endpoint’s shape', function() use ($run, $schemaFor, $shops, $uptown, $future) {
    $result = $run($schemaFor([$shops]), '{ foldLocation(id: ' . $uptown->id . ') {
        title groupId groupHandle lat lng phone websiteUrl directionsUrl timezone openNow addressLines
        address { addressLine1 addressLine2 locality administrativeArea postalCode countryCode formatted }
        hours { note week { day ranges { open close } } exceptions { date ranges { open close } } }
    } }');
    $row = $result['data']['foldLocation'] ?? null;

    if ($row === null) {
        return json_encode($result);
    }

    $ok = $row['groupId'] === $shops->id
        && $row['lat'] === 35.2271
        && $row['phone'] === '+1 704 555 0100'
        && str_starts_with((string)$row['directionsUrl'], 'https://www.google.com/maps/dir/')
        && $row['address']['addressLine1'] === '201 S Tryon St'
        && $row['address']['countryCode'] === 'US'
        && str_contains((string)$row['address']['formatted'], 'Charlotte')
        && count($row['hours']['week']) === 7
        && $row['hours']['week'][0] === ['day' => 'mon', 'ranges' => [['open' => '09:00', 'close' => '17:00']]]
        && $row['hours']['week'][6]['ranges'] === []
        && in_array(['date' => $future, 'ranges' => []], $row['hours']['exceptions'], true)
        && is_bool($row['openNow']);

    return $ok ?: json_encode($row);
});

check('distance is null when the query had no origin', function() use ($run, $schemaFor, $shops, $uptown) {
    $result = $run($schemaFor([$shops]), '{ foldLocation(id: ' . $uptown->id . ') { distance } }');

    return array_key_exists('distance', $result['data']['foldLocation'] ?? []) && $result['data']['foldLocation']['distance'] === null
        ?: json_encode($result);
});

section('Structured data');

$schema = $plugin->schema;

check('a group’s schema type becomes the @type', function() use ($schema, $uptown) {
    $data = $schema->build($uptown);

    return ($data['@context'] === 'https://schema.org' && $data['@type'] === 'Restaurant') ?: json_encode($data);
});

check('a group with no type is a LocalBusiness', function() use ($schema, $depot) {
    return $schema->build($depot)['@type'] === 'LocalBusiness';
});

check('a type that is not a schema.org name is refused on save and ignored on output', function() use ($shops) {
    $bad = clone $shops;
    $bad->schemaType = 'Restaurant"><script>';
    $refused = !$bad->validate(['schemaType']);

    return ($refused && $bad->getSchemaType() === 'LocalBusiness') ?: json_encode($bad->getErrors());
});

check('address, geo, phone and url are filled in', function() use ($schema, $uptown) {
    $data = $schema->build($uptown);

    $ok = $data['name'] === $uptown->title
        && $data['telephone'] === '+1 704 555 0100'
        && $data['url'] === $uptown->getUrl()
        && $data['@id'] === $uptown->getUrl() . '#location'
        && $data['sameAs'] === 'https://uptown.example.com'
        && $data['address'] === [
            '@type' => 'PostalAddress',
            'streetAddress' => '201 S Tryon St, Suite 5',
            'addressLocality' => 'Charlotte',
            'addressRegion' => 'NC',
            'postalCode' => '28202',
            'addressCountry' => 'US',
        ]
        && $data['geo'] === ['@type' => 'GeoCoordinates', 'latitude' => 35.2271, 'longitude' => -80.8431];

    return $ok ?: json_encode($data);
});

check('weekdays that share hours share one specification', function() use ($schema, $uptown) {
    $specs = $schema->build($uptown)['openingHoursSpecification'] ?? [];

    $ok = count($specs) === 2
        && $specs[0]['dayOfWeek'] === ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']
        && $specs[0]['opens'] === '09:00' && $specs[0]['closes'] === '17:00'
        && $specs[1]['dayOfWeek'] === 'Saturday';

    return $ok ?: json_encode($specs);
});

check('a future closed day is 00:00–00:00, and past exceptions are dropped', function() use ($schema, $uptown, $future) {
    $special = $schema->build($uptown)['specialOpeningHoursSpecification'] ?? [];

    return $special === [[
        '@type' => 'OpeningHoursSpecification',
        'validFrom' => $future,
        'validThrough' => $future,
        'opens' => '00:00',
        'closes' => '00:00',
    ]] ?: json_encode($special);
});

check('empty facts are left out rather than printed empty', function() use ($schema, $raleighShop) {
    $data = $schema->build($raleighShop);

    return (!isset($data['telephone']) && !isset($data['openingHoursSpecification']) && !isset($data['specialOpeningHoursSpecification']))
        ?: json_encode($data);
});

check('a shop name cannot close the script tag', function() use ($schema, $uptown) {
    $evil = clone $uptown;
    $evil->title = 'Shop</script><script>alert(1)</script>';
    $tag = (string)$schema->render($evil);

    return (substr_count($tag, '</script>') === 1 && str_contains($tag, '</script>'))
        ?: $tag;
});

check('overrides and the define-schema event can change the block', function() use ($schema, $uptown) {
    $handler = static function(justinholtweb\fold\events\DefineSchemaEvent $event) {
        $event->schema['priceRange'] = '$$';
    };
    $schema->on(justinholtweb\fold\services\Schema::EVENT_DEFINE_SCHEMA, $handler);

    try {
        $data = $schema->build($uptown, ['image' => 'https://example.com/a.jpg']);
    } finally {
        $schema->off(justinholtweb\fold\services\Schema::EVENT_DEFINE_SCHEMA, $handler);
    }

    return ($data['priceRange'] === '$$' && $data['image'] === 'https://example.com/a.jpg') ?: json_encode($data);
});

check('craft.fold.schema renders the same block from Twig', function() use ($uptown) {
    $html = Craft::$app->getView()->renderString('{{ craft.fold.schema(location) }}', ['location' => $uptown]);
    $json = json_decode(preg_replace('#^<script type="application/ld\+json">(.*)</script>$#s', '$1', $html), true);

    return ($json['@type'] ?? null) === 'Restaurant' ?: $html;
});

check('injection puts the block in the head, and a template call then does not print it twice', function() use ($schema, $uptown) {
    $view = Craft::$app->getView();
    $view->getHeadHtml(true);

    $injected = $schema->inject($uptown);
    $head = $view->getHeadHtml(true);
    $again = (string)$schema->render($uptown);

    return ($injected && str_contains($head, 'application/ld+json') && str_contains($head, '"Restaurant"') && $again === '')
        ?: json_encode(compact('injected', 'head', 'again'));
});

check('automatic injection stands down while SEOmatic is installed, and when switched off', function() use ($schema, $settings) {
    $seomatic = Craft::$app->getPlugins()->isPluginEnabled('seomatic');
    $was = $settings->injectSchema;

    $settings->injectSchema = false;
    $off = $schema->shouldInject();
    $settings->injectSchema = true;
    $on = $schema->shouldInject();
    $settings->injectSchema = $was;

    return ($off === false && $on === !$seomatic) ?: json_encode(compact('seomatic', 'off', 'on'));
});

section('Cleanup');

check('the check groups and their locations are removed', function() use ($plugin, $shops, $depots) {
    $plugin->groups->deleteGroupById($shops->id);
    $plugin->groups->deleteGroupById($depots->id);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    // The harness's YAML must not keep the throwaway groups either.
    Craft::$app->getProjectConfig()->writeYamlFiles(true);

    return (int)(new craft\db\Query())->from('{{%fold_locationgroups}}')->where(['id' => [$shops->id, $depots->id]])->count() === 0;
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
