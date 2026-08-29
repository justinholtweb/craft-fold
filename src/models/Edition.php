<?php

declare(strict_types=1);

namespace justinholtweb\fold\models;

/**
 * What each edition allows.
 *
 * Pure and static, taking `$isPro` rather than reaching for the plugin, so the boundary can be
 * tested without an application and read in one place as the answer to "what exactly does Pro
 * buy".
 *
 * The rule the caps follow: **Lite is a complete locator for a small business, not a demo.** A
 * bakery with three shops should never see a paywall — every feature that makes the locator
 * *work* (map, radius search, hours, the JSON API, store pages) is in Lite. Pro is for the
 * problems that only arrive with scale: many stores, many groups, an import, a commercial
 * geocoder, and the Commerce questions.
 *
 * Two things enforce it, and they do different jobs:
 *
 * - The CP and the importers **refuse** what Lite cannot do, so an author is told rather than
 *   quietly given something else.
 * - {@see self::mapDriverFor()} and {@see self::geocoderDriverFor()} **downgrade** on the way to
 *   the runtime, so a site whose licence lapsed keeps serving a working map on the free driver
 *   instead of a blank div. Stored settings are untouched, so renewing restores what was there.
 */
class Edition
{
    /** Locations Lite may hold. Trashed locations are not counted — they are not on the map. */
    public const LITE_MAX_LOCATIONS = 10;

    /** Location groups Lite may hold. One group is a store locator; two is a taxonomy. */
    public const LITE_MAX_GROUPS = 1;

    /** The drivers that cost nothing and need no key. Lite gets these, and so does a lapsed Pro. */
    public const FREE_MAP_DRIVERS = ['leaflet'];
    public const FREE_GEOCODER_DRIVERS = ['nominatim', 'none'];

    public static function maxLocations(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_LOCATIONS;
    }

    public static function maxGroups(bool $isPro): ?int
    {
        return $isPro ? null : self::LITE_MAX_GROUPS;
    }

    /** Commerce inventory-location linking, and the stock filter that is the point of it. */
    public static function allowsCommerce(bool $isPro): bool
    {
        return $isPro;
    }

    /** CSV/JSON import and export, and the bulk geocode that follows an import. */
    public static function allowsImportExport(bool $isPro): bool
    {
        return $isPro;
    }

    /** Recording what visitors searched for, and where they found nothing. */
    public static function allowsSearchLog(bool $isPro): bool
    {
        return $isPro;
    }

    /** Marker clustering, which only matters once there are enough markers to overlap. */
    public static function allowsClustering(bool $isPro): bool
    {
        return $isPro;
    }

    public static function allowsMapDriver(string $driver, bool $isPro): bool
    {
        return $isPro || in_array($driver, self::FREE_MAP_DRIVERS, true);
    }

    public static function allowsGeocoderDriver(string $driver, bool $isPro): bool
    {
        return $isPro || in_array($driver, self::FREE_GEOCODER_DRIVERS, true);
    }

    /**
     * The map driver this edition will actually serve.
     *
     * A downgrade is to the *free* driver, never to nothing: a locator whose licence lapsed still
     * shows a map, on OpenStreetMap tiles, with every location on it.
     */
    public static function mapDriverFor(string $driver, bool $isPro): string
    {
        return self::allowsMapDriver($driver, $isPro) ? $driver : self::FREE_MAP_DRIVERS[0];
    }

    public static function geocoderDriverFor(string $driver, bool $isPro): string
    {
        return self::allowsGeocoderDriver($driver, $isPro) ? $driver : self::FREE_GEOCODER_DRIVERS[0];
    }
}
