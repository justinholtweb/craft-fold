<?php

declare(strict_types=1);

namespace justinholtweb\fold\services\geocoders;

use justinholtweb\fold\models\GeoPoint;

/**
 * Geocodes nothing.
 *
 * For sites that enter coordinates by hand, or that must not make outbound requests at all. A
 * real driver rather than a null check scattered through the callers, so "no geocoding" is a
 * configuration and not a special case.
 */
class NullGeocoder extends BaseGeocoder
{
    public static function driverName(): string
    {
        return 'none';
    }

    public function geocode(string $query, array $options = []): ?GeoPoint
    {
        return null;
    }

    public function reverse(float $lat, float $lng): ?GeoPoint
    {
        return null;
    }
}
