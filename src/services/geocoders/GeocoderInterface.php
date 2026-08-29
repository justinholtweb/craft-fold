<?php

declare(strict_types=1);

namespace justinholtweb\fold\services\geocoders;

use justinholtweb\fold\models\GeoPoint;

/**
 * Turns an address into a point, and back.
 *
 * Deliberately small. Every provider offers autocomplete, place details, timezone lookup and
 * more, and all of it differs; what a store locator actually needs from all of them is the same
 * two questions, and keeping the interface to those two is what lets a site swap Nominatim for
 * Google without Fold's own code noticing.
 */
interface GeocoderInterface
{
    /** The key this driver is named by in settings. */
    public static function driverName(): string;

    /** Whether the driver has what it needs to run — usually an API key. */
    public function isConfigured(): bool;

    /**
     * @param string $query A free-text address or place.
     * @param array $options `countryCode` to bias results, `limit` for how many to consider.
     * @throws GeocodingException When the provider could be reached but refused or failed.
     */
    public function geocode(string $query, array $options = []): ?GeoPoint;

    /** @throws GeocodingException */
    public function reverse(float $lat, float $lng): ?GeoPoint;
}
