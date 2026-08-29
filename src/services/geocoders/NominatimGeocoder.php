<?php

declare(strict_types=1);

namespace justinholtweb\fold\services\geocoders;

use justinholtweb\fold\models\GeoPoint;

/**
 * OpenStreetMap's own geocoder. Free, keyless, and the reason a fresh Fold install works.
 *
 * It is also a volunteer-funded service with a usage policy, and Fold is a good citizen about it
 * or nobody gets to use it:
 *
 * - **One request per second, absolute maximum.** Enforced by the queue job that geocodes
 *   locations, not here, because the limit is per site and not per call.
 * - **An identifying User-Agent**, built from the site name and URL. Nominatim blocks requests
 *   without one, and rightly.
 * - **Results are cached hard**, misses included — see {@see \justinholtweb\fold\services\Geocoder}.
 *
 * A site that outgrows this changes one setting and gives Google or Mapbox a key.
 */
class NominatimGeocoder extends BaseGeocoder
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org';

    public static function driverName(): string
    {
        return 'nominatim';
    }

    public function geocode(string $query, array $options = []): ?GeoPoint
    {
        $params = [
            'q' => $query,
            'format' => 'jsonv2',
            'limit' => (int)($options['limit'] ?? 1),
            'addressdetails' => 0,
        ];

        if (!empty($options['countryCode'])) {
            $params['countrycodes'] = strtolower((string)$options['countryCode']);
        }

        $results = $this->getJson(self::ENDPOINT . '/search', $params, [
            'User-Agent' => $this->settings->getGeocoderUserAgent(),
            'Accept' => 'application/json',
        ]);

        $first = $results[0] ?? null;

        if (!is_array($first) || !isset($first['lat'], $first['lon'])) {
            return null;
        }

        $point = GeoPoint::make((float)$first['lat'], (float)$first['lon'], $first['display_name'] ?? null);
        $point->driver = self::driverName();

        // Nominatim's box is [south, north, west, east]; Fold's convention is
        // [south, west, north, east], which is the order Leaflet and Google both take.
        if (isset($first['boundingbox']) && is_array($first['boundingbox']) && count($first['boundingbox']) === 4) {
            [$south, $north, $west, $east] = array_map('floatval', $first['boundingbox']);
            $point->bounds = [$south, $west, $north, $east];
        }

        return $point->isValid() ? $point : null;
    }

    public function reverse(float $lat, float $lng): ?GeoPoint
    {
        $result = $this->getJson(self::ENDPOINT . '/reverse', [
            'lat' => $lat,
            'lon' => $lng,
            'format' => 'jsonv2',
        ], [
            'User-Agent' => $this->settings->getGeocoderUserAgent(),
            'Accept' => 'application/json',
        ]);

        if (!isset($result['lat'], $result['lon'])) {
            return null;
        }

        $point = GeoPoint::make((float)$result['lat'], (float)$result['lon'], $result['display_name'] ?? null);
        $point->driver = self::driverName();

        return $point->isValid() ? $point : null;
    }
}
