<?php

declare(strict_types=1);

namespace justinholtweb\fold\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use justinholtweb\fold\elements\db\LocationQuery;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\helpers\Geo;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\models\GeoPoint;
use justinholtweb\fold\models\SearchResult;
use justinholtweb\fold\Plugin;
use justinholtweb\fold\records\SearchRecord;

/**
 * Turns "somewhere near here" into a list of shops.
 *
 * The service exists so that the Twig variable, the JSON endpoint and the console all run the
 * *same* search. A locator whose AJAX results differ from its server-rendered ones is a bug
 * report nobody can reproduce, because the two came from two implementations.
 */
class Search extends Component
{
    /** The longest search term a visitor may send. */
    public const MAX_PUBLIC_TERM_LENGTH = 100;

    /**
     * @param array $params
     *   - `q` / `term`: free text, a postcode, or a "lat,lng" pair
     *   - `lat`, `lng`: coordinates, which skip geocoding entirely
     *   - `radius`, `unit`, `limit`, `offset`
     *   - `group`: group handle(s) to restrict to
     *   - `openNow`: only locations open at the moment of the search
     *   - `inStockOf`: a purchasable, or its ID (Pro + Commerce)
     *   - `relatedTo`: passed through to the element query, which is how category filters work
     */
    public function search(array $params = []): SearchResult
    {
        $settings = Plugin::getInstance()->getSettings();

        $term = trim((string)($params['q'] ?? $params['term'] ?? ''));
        $unit = Geo::normalizeUnit($params['unit'] ?? $settings->distanceUnit);
        $radius = $this->radius($params, $settings->defaultRadius);
        $limit = $this->limit($params, $settings->defaultLimit, $settings->maxLimit);
        $offset = max(0, (int)($params['offset'] ?? 0));

        $origin = $this->resolveOrigin($params, $term);

        $query = $this->buildQuery($params);

        if ($origin !== null) {
            $query->nearby([
                'lat' => $origin->lat,
                'lng' => $origin->lng,
                'radius' => $radius,
                'unit' => $unit,
            ]);
        }

        // Counted before the page is taken, so "showing 25 of 118" is possible. A separate count
        // query rather than a fetch-everything-and-count, because the whole point of the radius
        // search is not to load every shop in the country.
        $total = $origin !== null || $term === '' ? (int)$query->count() : 0;
        $locations = $total > 0 ? $query->offset($offset)->limit($limit)->all() : [];

        $matchedByName = false;

        // Nothing found for a term the visitor typed. Before giving up, try it as a *name*.
        //
        // Two real cases, and they are the two most common failures a store locator has:
        //
        // - The term is not a place at all. People type "NoDa", "Concord Mills", "the airport
        //   one" into a locator box constantly, and a geocoder has no idea what any of those are.
        // - The term is a place, and the geocoder found the wrong one. "NoDa" resolves to a town
        //   in Japan; a 25-mile radius around it contains nothing, and the visitor is told there
        //   are no shops near a neighbourhood they are standing in.
        //
        // Falling back to a name match answers both, and never overrides a search that worked.
        if ($total === 0 && $term !== '') {
            $nameQuery = $this->buildQuery($params)->search($term);
            $nameTotal = (int)$nameQuery->count();

            if ($nameTotal > 0) {
                $total = $nameTotal;
                $locations = $nameQuery->offset($offset)->limit($limit)->all();
                $matchedByName = true;
            }
        }

        if (!empty($params['openNow'])) {
            $locations = $this->filterOpenNow($locations);
        }

        $result = new SearchResult(
            locations: $locations,
            origin: $origin,
            term: $term !== '' ? $term : null,
            radius: $origin !== null ? $radius : null,
            unit: $unit,
            total: $total,
        );

        $result->matchedByName = $matchedByName;

        $this->log($result);

        return $result;
    }

    /**
     * Search parameters taken from a visitor's request, made safe to search with.
     *
     * Everything a template or PHP passes to {@see search()} is trusted; this is the line between
     * that and an anonymous query string, used by both the JSON endpoint and `craft.fold.locator()`.
     *
     * - Scalars only. `q[]=x` is otherwise an "Array to string conversion" 500.
     * - The term is capped. Every distinct term is a geocoder request and a cache row, and nobody
     *   searches for a store with a paragraph.
     * - `country` must look like a country code. It is part of the geocode cache key, so a free
     *   string would let one term miss the cache as many times as anyone liked.
     * - The radius is clamped to (0, the widest the site offers]. A public "no limit" is a
     *   haversine over every row — the bounding box is the whole design, and this is its door.
     *
     * @param string[] $names The parameters to read.
     */
    public function requestParams(array $names): array
    {
        $request = Craft::$app->getRequest();
        $params = [];

        foreach ($names as $name) {
            $key = $name === 'countryCode' ? 'country' : $name;
            $value = $request->getParam($key);

            if ($value === null || $value === '') {
                continue;
            }

            // `group` alone may be a list — `?group[]=retail&group[]=outlet` is a real filter.
            if (is_array($value) && $name === 'group') {
                $value = array_values(array_filter($value, 'is_string'));

                if ($value === []) {
                    continue;
                }
            } elseif (!is_scalar($value)) {
                continue;
            }

            $params[$name] = $value;
        }

        if (isset($params['q'])) {
            $params['q'] = mb_substr(trim((string)$params['q']), 0, self::MAX_PUBLIC_TERM_LENGTH);
        }

        if (isset($params['countryCode']) && !preg_match('/^[A-Za-z]{2}$/', (string)$params['countryCode'])) {
            unset($params['countryCode']);
        }

        if (isset($params['radius'])) {
            $max = Plugin::getInstance()->getSettings()->getMaxPublicRadius();
            $radius = (float)$params['radius'];
            $params['radius'] = $radius > 0 ? min($radius, $max) : $max;
        }

        return $params;
    }

    /**
     * An element query with everything but the distance applied — the shared half of the search,
     * exposed so a template can take it further with any element-query method it likes.
     */
    public function buildQuery(array $params = []): LocationQuery
    {
        $query = Location::find();

        if (!empty($params['group'])) {
            $query->group($params['group']);
        }

        if (isset($params['groupId'])) {
            $query->groupId($params['groupId']);
        }

        if (isset($params['relatedTo'])) {
            $query->relatedTo($params['relatedTo']);
        }

        if (isset($params['search'])) {
            $query->search($params['search']);
        }

        if (!empty($params['inStockOf'])) {
            $query->inStockOf($params['inStockOf'], max(1, (int)($params['inStockQty'] ?? 1)));
        }

        if (isset($params['siteId'])) {
            $query->siteId($params['siteId']);
        }

        // A location with no coordinates cannot appear on a map or in a distance-sorted list.
        // Excluded from *every* search, not only distance ones, so a locator never shows a shop
        // whose marker would be missing.
        $query->hasCoordinates(true);

        return $query;
    }

    /**
     * Where the search is measured from.
     *
     * Coordinates given directly win over a term — "use my location" sends both, the term being
     * whatever was left in the box, and the coordinates are the more precise answer.
     */
    private function resolveOrigin(array $params, string $term): ?GeoPoint
    {
        if (isset($params['lat'], $params['lng']) && $params['lat'] !== '' && $params['lng'] !== '') {
            $point = GeoPoint::make((float)$params['lat'], (float)$params['lng']);

            if ($point->isValid()) {
                return $point;
            }
        }

        if ($term === '') {
            return null;
        }

        // Biased to a country by default, because an unbiased geocoder is worse than a wrong
        // one: "Springfield" and "Newport" and "Richmond" exist on every continent, and the
        // nearest match to a visitor of a North Carolina shop chain is not the one in New South
        // Wales. A site can pass `country` explicitly, or blank the setting to search the world.
        $countryCode = $params['countryCode'] ?? Plugin::getInstance()->getSettings()->defaultCountryCode;

        return Plugin::getInstance()->geocoder->geocode($term, array_filter([
            'countryCode' => $countryCode,
        ]));
    }

    /**
     * Opening hours are filtered in PHP, not SQL.
     *
     * Deliberate: the hours are a JSON document interpreted in each shop's own timezone, with
     * overnight ranges and dated exceptions. Expressing that as a `WHERE` would mean a different
     * query for MySQL and Postgres, a timezone conversion the database cannot do per row, and a
     * second implementation of {@see \justinholtweb\fold\models\OpeningHours} that would drift
     * from the first. The page is 25 rows; the loop is free.
     *
     * The cost is honest and worth stating: `openNow` filters the page, so a page of 25 can come
     * back with 9 on it. The alternative — filter, then page — needs every location loaded.
     *
     * @param Location[] $locations
     * @return Location[]
     */
    private function filterOpenNow(array $locations): array
    {
        $now = new DateTime();

        return array_values(array_filter(
            $locations,
            static fn(Location $location) => !$location->getHours()->isEmpty() && $location->isOpenAt($now),
        ));
    }

    private function radius(array $params, float $default): float
    {
        if (!isset($params['radius']) || $params['radius'] === '') {
            return $default;
        }

        // An explicit zero or a negative means "no limit", which is a useful thing for a locator
        // with a handful of shops spread thin: the nearest is still the nearest at 300 miles.
        $radius = (float)$params['radius'];

        return $radius > 0 ? $radius : 0.0;
    }

    private function limit(array $params, int $default, int $max): int
    {
        $limit = isset($params['limit']) && $params['limit'] !== '' ? (int)$params['limit'] : $default;

        // Clamped, because this number arrives from a public endpoint where anyone can ask for
        // a million rows.
        return max(1, min($limit, $max));
    }

    /**
     * Records the search, if the site asked for it.
     *
     * The report worth having is not "what did people search for" — it is *where people looked
     * and found nothing*, which is a map of the towns a chain should open in next. That is why
     * the row keeps the coordinates and the nearest distance even for a zero-result search.
     */
    private function log(SearchResult $result): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$settings->logSearches || !Edition::allowsSearchLog(Plugin::getInstance()->isPro())) {
            return;
        }

        if ($result->term === null && $result->origin === null) {
            return;
        }

        $nearest = $result->nearest();

        $record = new SearchRecord();
        $record->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $record->term = mb_substr((string)$result->term, 0, 255);
        // Two decimal places is about a kilometre: enough for "people near here found nothing",
        // not enough to be somebody's front door. The browser already rounds, but a direct API
        // caller can send whatever precision it likes.
        $record->lat = $result->origin !== null ? round($result->origin->lat, 2) : null;
        $record->lng = $result->origin !== null ? round($result->origin->lng, 2) : null;
        $record->radius = $result->radius;
        $record->unit = $result->unit;
        $record->resultCount = $result->total;
        $record->nearestLocationId = $nearest?->id;
        $record->nearestDistance = $nearest?->distance;
        $record->save(false);
    }

    /**
     * Terms that came back with nothing, most frequent first — the "open a shop here" report.
     *
     * @return array<int, array{term: string, searches: int, lat: float|null, lng: float|null}>
     */
    public function getEmptySearches(int $limit = 50, ?DateTime $since = null): array
    {
        $query = (new \craft\db\Query())
            ->select([
                'term',
                'searches' => 'COUNT(*)',
                'lat' => 'MAX([[lat]])',
                'lng' => 'MAX([[lng]])',
            ])
            ->from(['{{%fold_searches}}'])
            ->where(['resultCount' => 0])
            ->groupBy(['term'])
            ->orderBy(['searches' => SORT_DESC])
            ->limit($limit);

        if ($since !== null) {
            $query->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($since)]);
        }

        return $query->all();
    }

    /** Drops search log rows older than `$days`. */
    public function purgeSearchLog(int $days = 365): int
    {
        return Craft::$app->getDb()->createCommand()
            ->delete('{{%fold_searches}}', ['<', 'dateCreated', Db::prepareDateForDb(new DateTime("-$days days"))])
            ->execute();
    }
}
