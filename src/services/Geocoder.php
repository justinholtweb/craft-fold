<?php

declare(strict_types=1);

namespace justinholtweb\fold\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use DateTime;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\helpers\Geo;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\models\GeoPoint;
use justinholtweb\fold\models\Settings;
use justinholtweb\fold\Plugin;
use justinholtweb\fold\records\GeocodeCacheRecord;
use justinholtweb\fold\services\geocoders\GeocoderInterface;
use justinholtweb\fold\services\geocoders\GeocodingException;
use justinholtweb\fold\services\geocoders\GoogleGeocoder;
use justinholtweb\fold\services\geocoders\MapboxGeocoder;
use justinholtweb\fold\services\geocoders\NominatimGeocoder;
use justinholtweb\fold\services\geocoders\NullGeocoder;

/**
 * The front door to geocoding: driver selection, the cache, and the one place that decides what
 * "could not find it" means.
 *
 * ## Why the cache is not optional
 *
 * A store locator asks the same handful of questions thousands of times. "28202", "London",
 * "near me" — a single busy afternoon can be tens of thousands of identical lookups, and every
 * provider charges for them or bans you for them. So every answer is stored, **including the
 * misses**: "asdfgh" is a query real visitors produce constantly, and re-asking a provider about
 * it forever is how a free geocoder stops answering.
 */
class Geocoder extends Component
{
    /** @var GeocoderInterface[] */
    private array $_drivers = [];

    /**
     * The driver this site actually uses, after the edition has had its say.
     *
     * A Lite site (or a lapsed Pro one) that has Google configured is quietly served by Nominatim
     * rather than by nothing — a locator that still works on free tiles and a free geocoder is a
     * better failure than one that goes blank on the day a licence expires.
     */
    public function getDriver(?string $name = null): GeocoderInterface
    {
        $settings = $this->settings();
        $name ??= Edition::geocoderDriverFor($settings->geocoderDriver, Plugin::getInstance()->isPro());

        if (isset($this->_drivers[$name])) {
            return $this->_drivers[$name];
        }

        $driver = match ($name) {
            Settings::GEOCODER_GOOGLE => new GoogleGeocoder($settings),
            Settings::GEOCODER_MAPBOX => new MapboxGeocoder($settings),
            Settings::GEOCODER_NONE => new NullGeocoder($settings),
            default => new NominatimGeocoder($settings),
        };

        // A driver named in settings but never given its key would otherwise fail on every
        // request with an exception the visitor sees as a broken search. Falling back to the
        // keyless one keeps the locator answering while somebody fixes the setting.
        if (!$driver->isConfigured() && $name !== Settings::GEOCODER_NONE) {
            Craft::warning(sprintf(
                'The %s geocoder has no API key; falling back to Nominatim.',
                $name,
            ), Plugin::LOG_CATEGORY);

            $driver = new NominatimGeocoder($settings);
        }

        return $this->_drivers[$name] = $driver;
    }

    /**
     * Turns a search term into a point.
     *
     * Coordinates typed directly — which is what "use my location" sends — skip the provider
     * entirely. There is nothing to look up, and spending a request on it would be both slower
     * and, on a metered provider, billed.
     *
     * @param array $options `countryCode` to bias results; `useCache => false` to force a refetch.
     */
    public function geocode(string $query, array $options = []): ?GeoPoint
    {
        $query = trim($query);

        if ($query === '') {
            return null;
        }

        $coordinates = Geo::parseCoordinates($query);

        if ($coordinates !== null) {
            return GeoPoint::make($coordinates[0], $coordinates[1], $query);
        }

        $driver = $this->getDriver();
        $useCache = ($options['useCache'] ?? true) && $this->settings()->geocodeCacheDuration > 0;
        $hash = $this->cacheKey($query, $driver::driverName(), $options);

        if ($useCache) {
            $cached = $this->readCache($hash);

            if ($cached !== false) {
                return $cached;
            }
        }

        try {
            $point = $driver->geocode($query, $options);
        } catch (GeocodingException $e) {
            // A provider failure is *not* a miss. Caching it would keep answering "not found" for
            // a month after somebody fixed the API key.
            Craft::error(sprintf('Geocoding “%s” failed: %s', $query, $e->getMessage()), Plugin::LOG_CATEGORY);

            return null;
        }

        if ($useCache) {
            $this->writeCache($hash, $driver::driverName(), $query, $point);
        }

        return $point;
    }

    public function reverse(float $lat, float $lng): ?GeoPoint
    {
        try {
            return $this->getDriver()->reverse($lat, $lng);
        } catch (GeocodingException $e) {
            Craft::error(sprintf('Reverse geocoding %s,%s failed: %s', $lat, $lng, $e->getMessage()), Plugin::LOG_CATEGORY);

            return null;
        }
    }

    /**
     * Geocodes a location from its address and writes the result onto it.
     *
     * Returns whether the location now has coordinates. The location is **not** saved here —
     * the caller decides, because the two callers want different things: the queue job saves, and
     * the CP's "geocode now" hands the result back to a form the author has not submitted yet.
     */
    public function geocodeLocation(Location $location, bool $force = false): bool
    {
        if (!$force && !$location->needsGeocoding()) {
            return $location->hasCoordinates();
        }

        $address = $location->getGeocodableAddress();

        if ($address === '') {
            $location->geocodeState = Location::GEOCODE_PENDING;
            $location->geocodeError = null;

            return false;
        }

        $countryCode = $location->getAddress()?->countryCode;
        $point = $this->geocode($address, array_filter(['countryCode' => $countryCode]));

        if ($point === null) {
            $location->geocodeState = Location::GEOCODE_FAILED;
            $location->geocodeError = Craft::t('fold', 'The address could not be placed on the map.');
            // The hash is still recorded on a failure, so a resave does not retry an address that
            // has not changed. Fixing the address changes the hash and the retry happens by
            // itself; retrying an unchanged one is just a slower way to fail.
            $location->geocodeHash = $location->computeGeocodeHash();

            return false;
        }

        $location->lat = $point->lat;
        $location->lng = $point->lng;
        $location->geocodeState = Location::GEOCODE_OK;
        $location->geocodeError = null;
        $location->geocodeHash = $location->computeGeocodeHash();
        $location->geocodedAt = new DateTime();

        // Mirrored onto the Address element as well, where Craft's own `latitude`/`longitude`
        // live. Nothing in Fold reads them — they are strings, and a string cannot be a bounding
        // box — but other plugins and templates look there, and leaving them empty on an address
        // Fold has geocoded is unhelpful.
        $address = $location->getAddress();

        if ($address !== null) {
            $address->latitude = (string)$point->lat;
            $address->longitude = (string)$point->lng;
        }

        return true;
    }

    /**
     * Drops cache rows that have expired.
     *
     * Called from Craft's garbage collection rather than on read: an expired row is harmless
     * until somebody asks for it, and deleting on read turns every cache miss into a write.
     */
    public function purgeExpiredCache(): int
    {
        return Craft::$app->getDb()->createCommand()
            ->delete('{{%fold_geocodecache}}', ['<', 'expiryDate', Db::prepareDateForDb(new DateTime())])
            ->execute();
    }

    public function clearCache(): int
    {
        return Craft::$app->getDb()->createCommand()->delete('{{%fold_geocodecache}}')->execute();
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * Cache key.
     *
     * The driver is part of it because two providers disagree about where "Springfield" is, and a
     * site that switches providers should not keep serving the old one's opinion. The country
     * bias is part of it for the same reason.
     */
    private function cacheKey(string $query, string $driver, array $options): string
    {
        return sha1(implode('|', [
            $driver,
            mb_strtolower(trim($query)),
            strtolower((string)($options['countryCode'] ?? '')),
        ]));
    }

    /**
     * @return GeoPoint|null|false The point, null for a cached miss, false for "not cached".
     *
     * Three states, because a cached miss and an absent entry must lead to different behaviour —
     * conflating them is what makes a cache stop protecting the provider from nonsense queries.
     */
    private function readCache(string $hash): GeoPoint|null|false
    {
        $record = GeocodeCacheRecord::findOne(['hash' => $hash]);

        if ($record === null) {
            return false;
        }

        if (new DateTime($record->expiryDate) < new DateTime()) {
            return false;
        }

        if (!$record->found) {
            return null;
        }

        $point = GeoPoint::make((float)$record->lat, (float)$record->lng, $record->formatted);
        $point->driver = $record->driver;
        $point->bounds = $record->bounds ? array_map('floatval', explode(',', $record->bounds)) : null;

        return $point;
    }

    private function writeCache(string $hash, string $driver, string $query, ?GeoPoint $point): void
    {
        $record = GeocodeCacheRecord::findOne(['hash' => $hash]) ?? new GeocodeCacheRecord();
        $record->hash = $hash;
        $record->driver = $driver;
        // Truncated to the column: a query long enough to overflow it is not one worth storing
        // whole, and the hash is what the lookup uses anyway.
        $record->query = mb_substr($query, 0, 500);
        $record->found = $point !== null;
        $record->lat = $point?->lat;
        $record->lng = $point?->lng;
        $record->formatted = $point?->formatted !== null ? mb_substr($point->formatted, 0, 500) : null;
        $record->bounds = $point?->bounds !== null ? implode(',', $point->bounds) : null;
        $record->expiryDate = Db::prepareDateForDb(
            (new DateTime())->modify('+' . $this->settings()->geocodeCacheDuration . ' seconds'),
        );
        $record->save(false);
    }

    private function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}
