<?php

declare(strict_types=1);

namespace justinholtweb\fold\models;

use craft\base\Model;
use JsonSerializable;

/**
 * A coordinate pair, and whatever the geocoder was able to say about it.
 *
 * Value object rather than a bare array so a geocoder driver, the cache, the search service and
 * the JSON response all agree on what a "found place" is.
 */
class GeoPoint extends Model implements JsonSerializable
{
    public float $lat = 0.0;
    public float $lng = 0.0;

    /** The provider's own single-line rendering of what it matched, shown back to the searcher. */
    public ?string $formatted = null;

    /** Bounding box the provider suggested, `[south, west, north, east]`, when it gave one. */
    public ?array $bounds = null;

    /** Which driver answered — kept so a cached hit can be invalidated when the driver changes. */
    public ?string $driver = null;

    public static function make(float $lat, float $lng, ?string $formatted = null): self
    {
        return new self(['lat' => $lat, 'lng' => $lng, 'formatted' => $formatted]);
    }

    /**
     * Whether this is a usable coordinate.
     *
     * `0,0` is a real place in the Gulf of Guinea and never the answer a geocoder means by it —
     * it is what an empty response decodes to. Rejecting it here stops a failed geocode from
     * quietly putting every store on Null Island.
     */
    public function isValid(): bool
    {
        if ($this->lat === 0.0 && $this->lng === 0.0) {
            return false;
        }

        return $this->lat >= -90.0 && $this->lat <= 90.0 && $this->lng >= -180.0 && $this->lng <= 180.0;
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'lat' => $this->lat,
            'lng' => $this->lng,
            'formatted' => $this->formatted,
            'bounds' => $this->bounds,
        ], static fn($v) => $v !== null);
    }
}
