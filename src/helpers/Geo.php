<?php

declare(strict_types=1);

namespace justinholtweb\fold\helpers;

/**
 * The spherical arithmetic, in one place, so PHP and SQL cannot disagree about how far apart two
 * shops are.
 *
 * Everything here treats the earth as a sphere. The error against the real ellipsoid is about
 * 0.3% — some 250 metres over 80 kilometres — which is invisible next to the error already in a
 * geocoded street address, and it buys a formula a database can evaluate in a `WHERE` clause.
 */
class Geo
{
    public const UNIT_MI = 'mi';
    public const UNIT_KM = 'km';

    /** Mean earth radius, the value the haversine convention uses. */
    public const EARTH_RADIUS_KM = 6371.0088;
    public const EARTH_RADIUS_MI = 3958.7613;

    /**
     * Kilometres in one degree of latitude. Constant everywhere; longitude is the one that
     * narrows as you leave the equator.
     */
    public const KM_PER_DEGREE_LAT = 111.045;

    public static function normalizeUnit(?string $unit): string
    {
        return strtolower((string)$unit) === self::UNIT_KM ? self::UNIT_KM : self::UNIT_MI;
    }

    public static function earthRadius(string $unit): float
    {
        return self::normalizeUnit($unit) === self::UNIT_KM ? self::EARTH_RADIUS_KM : self::EARTH_RADIUS_MI;
    }

    public static function toKm(float $distance, string $unit): float
    {
        return self::normalizeUnit($unit) === self::UNIT_KM ? $distance : $distance * 1.609344;
    }

    /**
     * Great-circle distance between two points, in `$unit`.
     *
     * The half-versine form rather than the plain spherical law of cosines: `acos()` loses its
     * precision for points close together, which for a store locator is *every interesting
     * case* — two branches a mile apart is exactly the comparison that has to come out right.
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2, string $unit = self::UNIT_MI): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::earthRadius($unit) * asin(min(1.0, sqrt($a)));
    }

    /**
     * The latitude/longitude window that certainly contains every point within `$radius`.
     *
     * This is the part that makes radius search fast: a haversine cannot use an index, but
     * `lat BETWEEN … AND lng BETWEEN …` can, so the box does the elimination and the haversine
     * only ever runs on what survives it. The box is deliberately generous — it may include
     * points just outside the circle, and the haversine filter removes them.
     *
     * @return array{south: float, north: float, west: float, east: float, wraps: bool}
     */
    public static function boundingBox(float $lat, float $lng, float $radius, string $unit = self::UNIT_MI): array
    {
        $radiusKm = self::toKm(max(0.0, $radius), $unit);
        $latDelta = $radiusKm / self::KM_PER_DEGREE_LAT;

        // Longitude degrees shrink by cos(latitude); at the poles they vanish, so the division is
        // floored. Without the floor, a search near Longyearbyen asks for a longitude window of
        // several thousand degrees, or divides by zero exactly at the pole.
        $cos = cos(deg2rad(max(-89.9, min(89.9, $lat))));
        $lngDelta = $cos > 0.000001
            ? $radiusKm / (self::KM_PER_DEGREE_LAT * $cos)
            : 180.0;

        $south = max(-90.0, $lat - $latDelta);
        $north = min(90.0, $lat + $latDelta);

        // A circle that reaches a pole covers every longitude, and one that crosses the
        // antimeridian has a west greater than its east. Both are reported rather than clamped,
        // because the query builder has to write a different WHERE for them.
        if ($lngDelta >= 180.0) {
            return ['south' => $south, 'north' => $north, 'west' => -180.0, 'east' => 180.0, 'wraps' => false];
        }

        $west = $lng - $lngDelta;
        $east = $lng + $lngDelta;
        $wraps = $west < -180.0 || $east > 180.0;

        return [
            'south' => $south,
            'north' => $north,
            'west' => self::wrapLng($west),
            'east' => self::wrapLng($east),
            'wraps' => $wraps,
        ];
    }

    /** Folds a longitude back into [-180, 180]. 190° east is 170° west, and Fiji stays findable. */
    public static function wrapLng(float $lng): float
    {
        $lng = fmod($lng + 180.0, 360.0);

        if ($lng < 0) {
            $lng += 360.0;
        }

        return $lng - 180.0;
    }

    /**
     * Whether a string is a bare coordinate pair — "35.2271,-80.8431" — rather than a place to
     * geocode.
     *
     * Worth checking before spending a geocoder request: "use my location" sends coordinates, and
     * so does anyone wiring their own UI.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function parseCoordinates(string $value): ?array
    {
        if (!preg_match('/^\s*(-?\d{1,3}(?:\.\d+)?)\s*[,\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $value, $m)) {
            return null;
        }

        $lat = (float)$m[1];
        $lng = (float)$m[2];

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return [$lat, $lng];
    }
}
