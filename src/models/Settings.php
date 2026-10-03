<?php

declare(strict_types=1);

namespace justinholtweb\fold\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use justinholtweb\fold\helpers\Geo;

/**
 * Plugin settings.
 *
 * Nothing here is `required`. A settings model with a required attribute cannot be saved by the
 * installer, and the plugin then fails to install at all — see `[[craft-plugin-gotchas]]`. Every
 * default below is chosen so that a fresh install has a working locator before anybody opens this
 * screen.
 */
class Settings extends Model
{
    public const MAP_LEAFLET = 'leaflet';
    public const MAP_GOOGLE = 'google';
    public const MAP_MAPBOX = 'mapbox';

    public const GEOCODER_NOMINATIM = 'nominatim';
    public const GEOCODER_GOOGLE = 'google';
    public const GEOCODER_MAPBOX = 'mapbox';
    public const GEOCODER_NONE = 'none';

    /** Which map renders on the front end. Leaflet needs no key, so it is the default. */
    public string $mapDriver = self::MAP_LEAFLET;

    /** Which service turns an address into coordinates. */
    public string $geocoderDriver = self::GEOCODER_NOMINATIM;

    /**
     * The Google key the *browser* uses to draw the map. It is printed in the page source, so it
     * must be restricted to the site's HTTP referrers in the Google Cloud console.
     */
    public ?string $googleApiKey = null;

    /**
     * The Google key the *server* uses to geocode.
     *
     * Separate because a referrer-restricted key cannot be used server-side, and an unrestricted
     * key in page source is somebody else's free Geocoding API. Restrict this one by IP. Empty
     * falls back to `googleApiKey`, which works but means shipping an unrestricted key.
     */
    public ?string $googleGeocodingApiKey = null;

    /** A Mapbox *public* (`pk.`) token — it is sent to the browser. */
    public ?string $mapboxAccessToken = null;

    /**
     * Tile URL template for the Leaflet driver.
     *
     * OpenStreetMap's own tiles by default. They are free, and their usage policy asks for
     * attribution and no heavy traffic — a site that outgrows that changes this line rather than
     * the plugin.
     */
    public string $leafletTileUrl = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

    public string $leafletAttribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

    /**
     * Load Leaflet from somewhere other than the copy Fold ships.
     *
     * Empty means the bundled copy, which is the default precisely so a fresh install makes no
     * third-party request but the tile fetch.
     */
    public ?string $leafletJsUrl = null;
    public ?string $leafletCssUrl = null;

    /**
     * Load Leaflet.markercluster from somewhere other than the bundled copy. Only used when
     * clustering is on. The second stylesheet is the plugin's default cluster bubbles; point it
     * at your own to restyle them, or at an empty file to style them entirely in the site's CSS.
     */
    public ?string $leafletClusterJsUrl = null;
    public ?string $leafletClusterCssUrl = null;
    public ?string $leafletClusterDefaultCssUrl = null;

    /** Miles or kilometres, everywhere Fold says a distance. */
    public string $distanceUnit = Geo::UNIT_MI;

    /** The radius a search uses when the visitor did not choose one. */
    public float $defaultRadius = 25.0;

    /** The radii the built-in UI offers. */
    public array $radiusOptions = [5, 10, 25, 50, 100];

    /** How many results a search returns unless asked for more. */
    public int $defaultLimit = 25;

    /** Ceiling on what the JSON endpoint will return, however large a `limit` is asked for. */
    public int $maxLimit = 200;

    /** Where the map centres before anybody has searched. Continental US, like WPSL's default. */
    public float $defaultLat = 39.8283;
    public float $defaultLng = -98.5795;
    public int $defaultZoom = 4;

    /** Country code new addresses start in. */
    public string $defaultCountryCode = 'US';

    /** Re-geocode a location automatically when its address changes. */
    public bool $autoGeocode = true;

    /**
     * How long a geocoded search term stays cached, in seconds. 30 days by default — street
     * addresses move rarely, and a locator's front page asks the same handful of questions
     * thousands of times.
     */
    public int $geocodeCacheDuration = 2592000;

    /**
     * What Fold calls itself to Nominatim.
     *
     * OpenStreetMap's policy requires an identifying User-Agent and blocks requests without one.
     * Empty means "build one from the site name and the primary site URL", which is a better
     * citizen than a plugin-shaped default that every install shares.
     */
    public ?string $geocoderUserAgent = null;

    /** Record what visitors searched for and how many results they got (Pro). */
    public bool $logSearches = false;

    /**
     * Days a search log row is kept. Rows are a visitor's search term and roughly where they
     * were, which is personal data in most of the places Fold will be installed; 0 keeps them
     * forever.
     */
    public int $searchLogRetentionDays = 90;

    /**
     * Searches a single visitor (IP address) may make through the public endpoint per minute.
     *
     * The endpoint is anonymous and every new term is a geocoder request — a bill on Google, an
     * IP ban on Nominatim. 0 turns the limit off, for a site that rate-limits at the edge.
     */
    public int $searchRateLimit = 30;

    /**
     * Publish exact stock counts in the JSON endpoint (Pro + Commerce).
     *
     * Off by default: "in stock" is what a locator needs, and exact counts per shop are a
     * competitor's inventory report for the price of a loop.
     */
    public bool $exposeStockLevels = false;

    /** Group markers that overlap at the current zoom (Pro; Leaflet maps only). */
    public bool $clusterMarkers = true;

    /** Ask the browser for the visitor's position, with their permission, on first load. */
    public bool $requestBrowserLocation = false;

    public function rules(): array
    {
        return [
            [['mapDriver'], 'in', 'range' => [self::MAP_LEAFLET, self::MAP_GOOGLE, self::MAP_MAPBOX]],
            [['geocoderDriver'], 'in', 'range' => [self::GEOCODER_NOMINATIM, self::GEOCODER_GOOGLE, self::GEOCODER_MAPBOX, self::GEOCODER_NONE]],
            [['distanceUnit'], 'in', 'range' => [Geo::UNIT_MI, Geo::UNIT_KM]],
            [['defaultRadius'], 'number', 'min' => 0.1, 'max' => 25000],
            [['defaultLimit', 'maxLimit'], 'integer', 'min' => 1, 'max' => 5000],
            [['defaultZoom'], 'integer', 'min' => 0, 'max' => 22],
            [['defaultLat'], 'number', 'min' => -90, 'max' => 90],
            [['defaultLng'], 'number', 'min' => -180, 'max' => 180],
            [['geocodeCacheDuration'], 'integer', 'min' => 0],
            [['defaultCountryCode'], 'string', 'max' => 2],
            [['searchLogRetentionDays', 'searchRateLimit'], 'integer', 'min' => 0],
            [['mapboxAccessToken'], function(string $attribute) {
                // A secret token in a page's source is a leaked secret. Mapbox's own guidance is
                // that only `pk.` tokens belong in a browser.
                if (str_starts_with((string)App::parseEnv($this->$attribute), 'sk.')) {
                    $this->addError($attribute, Craft::t('fold', 'Use a public (pk.) token — this one is sent to the browser.'));
                }
            }],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'mapDriver' => Craft::t('fold', 'Map provider'),
            'geocoderDriver' => Craft::t('fold', 'Geocoding provider'),
            'googleApiKey' => Craft::t('fold', 'Google Maps API key'),
            'googleGeocodingApiKey' => Craft::t('fold', 'Google Geocoding API key'),
            'mapboxAccessToken' => Craft::t('fold', 'Mapbox access token'),
            'leafletTileUrl' => Craft::t('fold', 'Tile URL'),
            'distanceUnit' => Craft::t('fold', 'Distance unit'),
            'defaultRadius' => Craft::t('fold', 'Default radius'),
            'defaultLimit' => Craft::t('fold', 'Results per search'),
            'defaultCountryCode' => Craft::t('fold', 'Default country'),
        ];
    }

    /** The browser's Google key, environment variables resolved. */
    public function getGoogleApiKey(): ?string
    {
        return App::parseEnv($this->googleApiKey) ?: null;
    }

    /** The server's Google key — its own if set, otherwise the browser's. */
    public function getGoogleGeocodingApiKey(): ?string
    {
        return App::parseEnv($this->googleGeocodingApiKey) ?: $this->getGoogleApiKey();
    }

    public function getMapboxAccessToken(): ?string
    {
        return App::parseEnv($this->mapboxAccessToken) ?: null;
    }

    /**
     * The widest radius a public search may ask for: the largest the built-in UI offers, or the
     * default if that is larger. A public "no limit" would be a haversine over every row.
     */
    public function getMaxPublicRadius(): float
    {
        return max(max($this->getRadiusOptions()), $this->defaultRadius);
    }

    /**
     * The radius options, cleaned up: numeric, positive, sorted, unique.
     *
     * These come out of a settings table where somebody can type anything, and they end up in a
     * `<select>` and in a JSON payload.
     *
     * @return float[]
     */
    public function getRadiusOptions(): array
    {
        $options = [];

        foreach ($this->radiusOptions as $option) {
            $value = is_array($option) ? ($option['radius'] ?? null) : $option;

            if (is_numeric($value) && (float)$value > 0) {
                $options[] = (float)$value;
            }
        }

        $options = array_values(array_unique($options));
        sort($options);

        return $options !== [] ? $options : [$this->defaultRadius];
    }

    /**
     * The User-Agent Nominatim will see.
     *
     * Built from the site rather than the plugin so that a blocked install is one identifiable
     * site, not everyone who ever ran Fold.
     */
    public function getGeocoderUserAgent(): string
    {
        if ($this->geocoderUserAgent) {
            return $this->geocoderUserAgent;
        }

        $site = Craft::$app->getSites()->getPrimarySite();
        $name = $site->getName() ?: 'Craft CMS site';
        $url = $site->getBaseUrl() ?: '';

        return trim(sprintf('Fold/1.0 (%s%s)', $name, $url !== '' ? '; ' . $url : ''));
    }
}
