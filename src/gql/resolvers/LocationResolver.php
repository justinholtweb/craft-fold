<?php

declare(strict_types=1);

namespace justinholtweb\fold\gql\resolvers;

use craft\elements\db\ElementQuery;
use craft\elements\ElementCollection;
use craft\gql\base\ElementResolver;
use craft\helpers\Gql as GqlHelper;
use DateTime;
use GraphQL\Type\Definition\ResolveInfo;
use justinholtweb\fold\elements\db\LocationQuery;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\models\GeoPoint;
use justinholtweb\fold\Plugin;
use yii\base\UnknownMethodException;

/**
 * Resolves location queries, scoped to the groups the active schema may read.
 *
 * GraphQL is a public door the same way `/fold/search.json` is, so it is held to the same clamps:
 * a radius no wider than the widest the site's own search box offers (an unbounded haversine over
 * every row is the one query the bounding box exists to prevent), and no more rows than `maxLimit`.
 */
class LocationResolver extends ElementResolver
{
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        $value = parent::resolve($source, $arguments, $context, $resolveInfo);

        return !empty($arguments['openNow']) ? self::filterOpenNow($value) : $value;
    }

    public static function resolveOne(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        $value = parent::resolveOne($source, $arguments, $context, $resolveInfo);

        if (!empty($arguments['openNow']) && $value instanceof Location) {
            return self::filterOpenNow([$value])[0] ?? null;
        }

        return $value;
    }

    public static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
    {
        $query = $source === null ? Location::find() : $source->$fieldName;

        // Eager-loaded already: nothing left to narrow.
        if (!$query instanceof ElementQuery) {
            return $query;
        }

        $near = $arguments['near'] ?? null;
        $inStockOf = $arguments['inStockOf'] ?? null;
        unset($arguments['near'], $arguments['openNow'], $arguments['inStockOf']);

        foreach ($arguments as $key => $value) {
            try {
                $query->$key($value);
            } catch (UnknownMethodException $e) {
                if ($value !== null) {
                    throw $e;
                }
            }
        }

        $groupIds = self::allowedGroupIds();

        if ($groupIds === []) {
            return ElementCollection::empty();
        }

        $query->andWhere(['fold_locations.groupId' => $groupIds]);

        $plugin = Plugin::getInstance();

        /** @var LocationQuery $query */
        if (is_array($near)) {
            $origin = GeoPoint::make((float)$near['lat'], (float)$near['lng']);

            // An origin that is not a place returns nothing rather than everything: a front end
            // that sent `0,0` by mistake should see an empty list, not the whole chain unsorted.
            if (!$origin->isValid()) {
                return ElementCollection::empty();
            }

            $query->nearby([
                'lat' => $origin->lat,
                'lng' => $origin->lng,
                'radius' => $plugin->search->clampPublicRadius(isset($near['radius']) ? (float)$near['radius'] : null),
                'unit' => $near['unit'] ?? null,
            ]);
        }

        // Accepted only when Commerce is in play, as on the JSON endpoint: silently honouring it
        // otherwise would come back empty and look like "nothing has stock".
        if ($inStockOf && $plugin->commerce->isEnabled()) {
            $query->inStockOf((int)$inStockOf);
        }

        $max = $plugin->getSettings()->maxLimit;

        if ($query->limit === null || (int)$query->limit > $max) {
            $query->limit($max);
        }

        return $query;
    }

    /**
     * IDs of the location groups the active schema has `read` on.
     *
     * @return int[]
     */
    private static function allowedGroupIds(): array
    {
        $uids = GqlHelper::extractAllowedEntitiesFromSchema('read')['foldLocationGroups'] ?? [];

        if (!is_array($uids)) {
            return [];
        }

        $ids = [];

        foreach ($uids as $uid) {
            $group = Plugin::getInstance()->groups->getGroupByUid($uid);

            if ($group !== null) {
                $ids[] = $group->id;
            }
        }

        return $ids;
    }

    /**
     * The same rule as the JSON endpoint's `openNow`: a location with no hours is not "open".
     */
    private static function filterOpenNow(mixed $value): mixed
    {
        if (!is_iterable($value)) {
            return $value;
        }

        $now = new DateTime();
        $open = [];

        foreach ($value as $location) {
            if ($location instanceof Location && !$location->getHours()->isEmpty() && $location->isOpenAt($now)) {
                $open[] = $location;
            }
        }

        return $value instanceof ElementCollection ? ElementCollection::make($open) : $open;
    }
}
