<?php

declare(strict_types=1);

namespace justinholtweb\fold\services\geocoders;

use justinholtweb\fold\models\GeoPoint;

/**
 * Google's Geocoding API. The best results, and a bill.
 */
class GoogleGeocoder extends BaseGeocoder
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    public static function driverName(): string
    {
        return 'google';
    }

    public function isConfigured(): bool
    {
        return !empty($this->settings->getGoogleGeocodingApiKey());
    }

    public function geocode(string $query, array $options = []): ?GeoPoint
    {
        $params = [
            'address' => $query,
            'key' => $this->settings->getGoogleGeocodingApiKey(),
        ];

        if (!empty($options['countryCode'])) {
            $params['components'] = 'country:' . strtoupper((string)$options['countryCode']);
        }

        return $this->readResponse($this->getJson(self::ENDPOINT, $params));
    }

    public function reverse(float $lat, float $lng): ?GeoPoint
    {
        return $this->readResponse($this->getJson(self::ENDPOINT, [
            'latlng' => sprintf('%s,%s', $lat, $lng),
            'key' => $this->settings->getGoogleGeocodingApiKey(),
        ]));
    }

    /**
     * Google reports failures in the body with a 200 status, so the status field is the only
     * place a bad key or an exhausted quota shows up. `ZERO_RESULTS` is a genuine miss and must
     * not be raised as a failure — the difference decides whether Fold caches the answer.
     *
     * @throws GeocodingException
     */
    private function readResponse(array $response): ?GeoPoint
    {
        $status = $response['status'] ?? 'UNKNOWN_ERROR';

        if ($status === 'ZERO_RESULTS') {
            return null;
        }

        if ($status !== 'OK') {
            throw new GeocodingException(sprintf(
                'Google geocoding failed: %s%s',
                $status,
                isset($response['error_message']) ? ' — ' . $response['error_message'] : '',
            ));
        }

        $result = $response['results'][0] ?? null;
        $location = $result['geometry']['location'] ?? null;

        if (!isset($location['lat'], $location['lng'])) {
            return null;
        }

        $point = GeoPoint::make((float)$location['lat'], (float)$location['lng'], $result['formatted_address'] ?? null);
        $point->driver = self::driverName();

        $viewport = $result['geometry']['viewport'] ?? null;

        if (isset($viewport['southwest']['lat'], $viewport['southwest']['lng'], $viewport['northeast']['lat'], $viewport['northeast']['lng'])) {
            $point->bounds = [
                (float)$viewport['southwest']['lat'],
                (float)$viewport['southwest']['lng'],
                (float)$viewport['northeast']['lat'],
                (float)$viewport['northeast']['lng'],
            ];
        }

        return $point->isValid() ? $point : null;
    }
}
