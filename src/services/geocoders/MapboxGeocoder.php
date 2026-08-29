<?php

declare(strict_types=1);

namespace justinholtweb\fold\services\geocoders;

use justinholtweb\fold\models\GeoPoint;

/**
 * Mapbox's geocoding API. A generous free tier, and vector maps to match.
 */
class MapboxGeocoder extends BaseGeocoder
{
    private const ENDPOINT = 'https://api.mapbox.com/geocoding/v5/mapbox.places';

    public static function driverName(): string
    {
        return 'mapbox';
    }

    public function isConfigured(): bool
    {
        return !empty($this->settings->mapboxAccessToken);
    }

    public function geocode(string $query, array $options = []): ?GeoPoint
    {
        $params = [
            'access_token' => $this->settings->mapboxAccessToken,
            'limit' => (int)($options['limit'] ?? 1),
        ];

        if (!empty($options['countryCode'])) {
            $params['country'] = strtolower((string)$options['countryCode']);
        }

        // The search term is a *path segment* here, not a query parameter, and Mapbox rejects an
        // unescaped semicolon outright — it means "batch these queries" in its URL grammar.
        $url = sprintf('%s/%s.json', self::ENDPOINT, rawurlencode($query));

        return $this->readFeature($this->getJson($url, $params));
    }

    public function reverse(float $lat, float $lng): ?GeoPoint
    {
        // Mapbox orders coordinates longitude-first, GeoJSON style — the opposite of everything
        // else Fold talks to, and a silent 90°-off bug if it is copied from another driver.
        $url = sprintf('%s/%s,%s.json', self::ENDPOINT, $lng, $lat);

        return $this->readFeature($this->getJson($url, [
            'access_token' => $this->settings->mapboxAccessToken,
            'limit' => 1,
        ]));
    }

    private function readFeature(array $response): ?GeoPoint
    {
        $feature = $response['features'][0] ?? null;
        $center = $feature['center'] ?? null;

        if (!is_array($center) || count($center) < 2) {
            return null;
        }

        $point = GeoPoint::make((float)$center[1], (float)$center[0], $feature['place_name'] ?? null);
        $point->driver = self::driverName();

        if (isset($feature['bbox']) && is_array($feature['bbox']) && count($feature['bbox']) === 4) {
            // Mapbox's bbox is [west, south, east, north].
            [$west, $south, $east, $north] = array_map('floatval', $feature['bbox']);
            $point->bounds = [$south, $west, $north, $east];
        }

        return $point->isValid() ? $point : null;
    }
}
