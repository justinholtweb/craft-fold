<?php

declare(strict_types=1);

namespace justinholtweb\fold\elements\db;

use craft\db\QueryAbortedException;
use craft\elements\db\ElementQuery;
use craft\elements\db\OrderByPlaceholderExpression;
use craft\helpers\Db;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\helpers\Geo;
use justinholtweb\fold\models\LocationGroup;
use justinholtweb\fold\Plugin;
use yii\db\Expression;

/**
 * Element query for locations.
 *
 * ## How radius search stays fast
 *
 * A haversine cannot use an index — it is a function of both columns — so asking the database to
 * evaluate it against every location is a table scan that gets slower with every shop opened. So
 * the search happens in two passes inside one query:
 *
 * 1. A **bounding box** on `lat`/`lng`, which the composite index answers directly, throws away
 *    everything obviously too far. It is deliberately generous: a square around the circle.
 * 2. The **haversine** then runs only on what the box let through, both as the real distance
 *    filter (the corners of the box are outside the circle) and as the sort.
 *
 * The distance expression is selected in the **sub**-query, not just the outer one. Craft applies
 * `limit`, `offset` *and* `orderBy` to the sub-query — so a distance computed only on the outer
 * query would sort the page after the database had already chosen which 25 rows the page was.
 * That failure is quiet and looks like "the results are nearly right".
 *
 * @method Location[] all($db = null)
 * @method Location|null one($db = null)
 * @method Location|null nth(int $n, ?\yii\db\Connection $db = null)
 */
class LocationQuery extends ElementQuery
{
    public mixed $groupId = null;
    public mixed $commerceInventoryLocationId = null;
    public mixed $geocodeState = null;

    /** Null means "don't care"; true/false filter on whether the location is on the map at all. */
    public ?bool $hasCoordinates = null;

    public ?float $latitude = null;
    public ?float $longitude = null;
    public ?float $radius = null;
    public ?string $unit = null;

    /**
     * Purchasable, or its ID, that a location must have stock of. Pro + Commerce.
     *
     * Typed `mixed` rather than against Commerce's `PurchasableInterface` on purpose: Commerce is
     * a soft dependency, and a type declaration referencing a class that may not be installed is
     * a fatal error at load time on every site that does not have it.
     */
    public mixed $inStockOf = null;

    /** Minimum available quantity `inStockOf` has to clear. */
    public int $inStockQty = 1;

    protected array $defaultOrderBy = ['elements_sites.title' => SORT_ASC];

    public function group(mixed $value): static
    {
        if ($value instanceof LocationGroup) {
            $this->groupId = $value->id;

            return $this;
        }

        if ($value === null) {
            $this->groupId = null;

            return $this;
        }

        // Handles, one or many. Resolved to IDs here rather than joined, because groups are a
        // short, always-loaded list — a join would cost more than the lookup.
        $handles = is_array($value) ? $value : [$value];
        $ids = [];

        foreach ($handles as $handle) {
            $group = is_numeric($handle)
                ? Plugin::getInstance()->groups->getGroupById((int)$handle)
                : Plugin::getInstance()->groups->getGroupByHandle((string)$handle);

            if ($group !== null) {
                $ids[] = $group->id;
            }
        }

        // No match must return nothing, not everything. `false` is Craft's convention for a
        // param that can never be satisfied.
        $this->groupId = $ids !== [] ? $ids : false;

        return $this;
    }

    public function groupId(mixed $value): static
    {
        $this->groupId = $value;

        return $this;
    }

    public function hasCoordinates(?bool $value = true): static
    {
        $this->hasCoordinates = $value;

        return $this;
    }

    public function geocodeState(mixed $value): static
    {
        $this->geocodeState = $value;

        return $this;
    }

    public function commerceInventoryLocationId(mixed $value): static
    {
        $this->commerceInventoryLocationId = $value;

        return $this;
    }

    /** Locations linked to *some* Commerce inventory location, or to none. */
    public function linkedToCommerce(bool $value = true): static
    {
        $this->commerceInventoryLocationId = $value ? 'not :empty:' : ':empty:';

        return $this;
    }

    /**
     * Only locations that currently have stock of a purchasable.
     *
     * The stock itself is Commerce's arithmetic, read through its own service — Fold never sums
     * the inventory transaction ledger, because Commerce changes how it does that and a locator
     * that quietly disagrees with the product page is worse than one that cannot answer.
     */
    public function inStockOf(mixed $value, int $qty = 1): static
    {
        $this->inStockOf = $value;
        $this->inStockQty = max(1, $qty);

        return $this;
    }

    public function latitude(?float $value): static
    {
        $this->latitude = $value;

        return $this;
    }

    public function longitude(?float $value): static
    {
        $this->longitude = $value;

        return $this;
    }

    public function radius(?float $value): static
    {
        $this->radius = $value;

        return $this;
    }

    public function unit(?string $value): static
    {
        $this->unit = $value;

        return $this;
    }

    /**
     * Locations within `radius` of a point, nearest first.
     *
     * ```twig
     * {% set stores = craft.fold.locations.nearby({ lat: 35.2271, lng: -80.8431, radius: 25 }).all() %}
     * ```
     *
     * A missing radius means "no distance limit, but still sort by distance", which is the right
     * answer for a locator with a handful of shops spread over a country: the nearest is nearest
     * even when it is 300 miles away, and showing nothing is not more honest.
     *
     * @param array{lat?: float, lng?: float, latitude?: float, longitude?: float, radius?: float, unit?: string} $config
     */
    public function nearby(array $config): static
    {
        $this->latitude = isset($config['lat']) ? (float)$config['lat'] : (isset($config['latitude']) ? (float)$config['latitude'] : $this->latitude);
        $this->longitude = isset($config['lng']) ? (float)$config['lng'] : (isset($config['longitude']) ? (float)$config['longitude'] : $this->longitude);

        if (array_key_exists('radius', $config)) {
            $this->radius = $config['radius'] !== null ? (float)$config['radius'] : null;
        }

        if (isset($config['unit'])) {
            $this->unit = (string)$config['unit'];
        }

        return $this;
    }

    /** Whether this query is actually doing a distance search. */
    public function hasOrigin(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function getUnit(): string
    {
        return Geo::normalizeUnit($this->unit ?? Plugin::getInstance()->getSettings()->distanceUnit);
    }

    protected function beforePrepare(): bool
    {
        if (!parent::beforePrepare()) {
            return false;
        }

        $this->joinElementTable('fold_locations');

        $this->query->addSelect([
            'fold_locations.groupId',
            'fold_locations.addressId',
            'fold_locations.lat',
            'fold_locations.lng',
            'fold_locations.phone',
            'fold_locations.email',
            'fold_locations.websiteUrl',
            'fold_locations.hours',
            'fold_locations.timezone',
            'fold_locations.commerceInventoryLocationId',
            'fold_locations.geocodeState',
            'fold_locations.geocodeHash',
            'fold_locations.geocodeError',
            'fold_locations.geocodedAt',
        ]);

        if ($this->groupId !== null) {
            $this->subQuery->andWhere(Db::parseParam('fold_locations.groupId', $this->groupId));
        }

        if ($this->geocodeState !== null) {
            $this->subQuery->andWhere(Db::parseParam('fold_locations.geocodeState', $this->geocodeState));
        }

        if ($this->commerceInventoryLocationId !== null) {
            $this->subQuery->andWhere(Db::parseParam('fold_locations.commerceInventoryLocationId', $this->commerceInventoryLocationId));
        }

        if ($this->hasCoordinates !== null) {
            $this->subQuery->andWhere($this->hasCoordinates
                ? ['not', ['fold_locations.lat' => null]]
                : ['fold_locations.lat' => null]);
        }

        $this->applyStockParam();
        $this->applyDistance();

        return true;
    }

    /**
     * Narrows to the locations Commerce says hold the purchasable.
     *
     * Resolved to a list of inventory-location IDs rather than joined: Commerce derives a level
     * from a transaction ledger, and reproducing that join here would be a second implementation
     * of somebody else's arithmetic, wrong the first time Commerce changes it.
     */
    private function applyStockParam(): void
    {
        if ($this->inStockOf === null) {
            return;
        }

        $ids = Plugin::getInstance()->commerce->inventoryLocationIdsWithStock($this->inStockOf, $this->inStockQty);

        if ($ids === []) {
            // Nothing has it. An empty `IN ()` is a syntax error in some drivers and a silent
            // "match everything" in careless code, so abort the query outright.
            throw new QueryAbortedException();
        }

        $this->subQuery->andWhere(['fold_locations.commerceInventoryLocationId' => $ids]);
    }

    /**
     * The bounding box, the distance expression, and the sort.
     */
    private function applyDistance(): void
    {
        if (!$this->hasOrigin()) {
            return;
        }

        $unit = $this->getUnit();
        $lat = $this->latitude;
        $lng = $this->longitude;

        // A location with no coordinates cannot be anywhere near anything. Stated explicitly
        // rather than left to the box comparison, because `NULL BETWEEN x AND y` is NULL, and a
        // reader should not have to know that to see that the un-geocoded are excluded.
        $this->subQuery->andWhere(['not', ['fold_locations.lat' => null]]);
        $this->subQuery->andWhere(['not', ['fold_locations.lng' => null]]);

        if ($this->radius !== null && $this->radius > 0) {
            $box = Geo::boundingBox($lat, $lng, $this->radius, $unit);

            $this->subQuery->andWhere(['between', 'fold_locations.lat', $box['south'], $box['north']]);

            if ($box['wraps']) {
                // The box crosses the antimeridian, so its west is numerically *greater* than its
                // east and a BETWEEN would match nothing. Two open-ended ranges instead.
                $this->subQuery->andWhere([
                    'or',
                    ['>=', 'fold_locations.lng', $box['west']],
                    ['<=', 'fold_locations.lng', $box['east']],
                ]);
            } else {
                $this->subQuery->andWhere(['between', 'fold_locations.lng', $box['west'], $box['east']]);
            }
        }

        $params = [
            ':foldLat' => $lat,
            ':foldLng' => $lng,
            ':foldRadius' => Geo::earthRadius($unit),
        ];

        $distance = new Expression($this->haversineSql(), $params);

        // Selected on the sub-query as well as the outer one: the sub-query is what carries
        // ORDER BY, LIMIT and OFFSET, so this is the copy that decides which rows are on the page.
        $this->subQuery->addSelect(['distance' => $distance]);
        $this->query->addSelect(['distance' => 'subquery.distance']);

        if ($this->radius !== null && $this->radius > 0) {
            // The real circle. The box above is square, so its corners are up to 41% further out
            // than the radius asked for — without this, a 25-mile search returns shops 35 miles
            // away and the distance column proves it.
            $this->subQuery->andWhere(new Expression(
                $this->haversineSql() . ' <= :foldMaxDistance',
                $params + [':foldMaxDistance' => $this->radius],
            ));
        }

        if ($this->orderByIsUnset()) {
            $this->orderBy(['distance' => SORT_ASC]);
        }
    }

    /**
     * Great-circle distance in SQL, in the query's unit.
     *
     * The half-versine form rather than `ACOS` of the spherical law of cosines: `ACOS` loses
     * precision for points close together, which in a store locator is every comparison that
     * matters — two branches on the same street have to sort correctly.
     *
     * `LEAST(1, …)` guards the `ASIN`: floating-point drift can push the argument a hair over 1
     * for two rows at the same coordinates, and `ASIN(1.0000000001)` is NULL in MySQL and an
     * error in Postgres. Two shops at one address is a data-entry mistake made every day.
     *
     * Every function used here — `RADIANS`, `SIN`, `COS`, `ASIN`, `SQRT`, `POWER`, `LEAST` — is
     * present in both MySQL and Postgres with the same semantics, which is the reason none of
     * this is a spatial type.
     */
    private function haversineSql(): string
    {
        return '(2 * :foldRadius * ASIN(LEAST(1, SQRT('
            . 'POWER(SIN(RADIANS([[fold_locations.lat]] - :foldLat) / 2), 2)'
            . ' + COS(RADIANS(:foldLat)) * COS(RADIANS([[fold_locations.lat]]))'
            . ' * POWER(SIN(RADIANS([[fold_locations.lng]] - :foldLng) / 2), 2)'
            . '))))';
    }

    /**
     * Whether the caller has expressed no opinion about ordering.
     *
     * Craft seeds `orderBy` with a placeholder in its constructor, so "unset" is two states, and
     * treating the placeholder as a real sort would mean a nearby() search silently came back in
     * title order.
     */
    private function orderByIsUnset(): bool
    {
        if (!isset($this->orderBy) || $this->orderBy === []) {
            return true;
        }

        return count($this->orderBy) === 1
            && ($this->orderBy[0] ?? null) instanceof OrderByPlaceholderExpression;
    }
}
