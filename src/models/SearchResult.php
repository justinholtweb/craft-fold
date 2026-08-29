<?php

declare(strict_types=1);

namespace justinholtweb\fold\models;

use justinholtweb\fold\elements\Location;

/**
 * What a locator search came back with.
 *
 * A result object rather than a bare array of locations, because a search has answers the
 * locations themselves cannot give: where the searcher was placed, whether the term could be
 * understood at all, and how many results were dropped by the radius. A front end needs all of
 * them to say anything better than "no results".
 */
class SearchResult
{
    /** @param Location[] $locations */
    public function __construct(
        public array $locations = [],
        public ?GeoPoint $origin = null,
        public ?string $term = null,
        public ?float $radius = null,
        public string $unit = 'mi',
        public int $total = 0,
    ) {
    }

    /**
     * Whether these results came from matching the term against location *names* rather than
     * from placing it on the map.
     *
     * Kept separate from {@see self::originNotFound()} because the two want opposite messages: a
     * name match is a successful search, and telling somebody who searched "NoDa" that we could
     * not find "NoDa" — while showing them the NoDa shop — would be nonsense.
     */
    public bool $matchedByName = false;

    public function isEmpty(): bool
    {
        return $this->locations === [];
    }

    /**
     * Whether the term itself was the problem, as opposed to there being no shops near a place
     * that does exist.
     *
     * The distinction is the whole difference between "we could not find 'Springfeild'" and "we
     * have nothing within 25 miles of Springfield" — two different messages, and only one of them
     * is the visitor's mistake.
     */
    public function originNotFound(): bool
    {
        return $this->term !== null
            && trim($this->term) !== ''
            && $this->origin === null
            && !$this->matchedByName;
    }

    public function nearest(): ?Location
    {
        return $this->locations[0] ?? null;
    }

    /**
     * The map window that fits every result, plus the origin.
     *
     * Returned as `[south, west, north, east]`, which is the order Leaflet's `fitBounds` and
     * Google's `LatLngBounds` both take.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public function bounds(): ?array
    {
        $lats = [];
        $lngs = [];

        foreach ($this->locations as $location) {
            if ($location->hasCoordinates()) {
                $lats[] = $location->lat;
                $lngs[] = $location->lng;
            }
        }

        if ($this->origin !== null) {
            $lats[] = $this->origin->lat;
            $lngs[] = $this->origin->lng;
        }

        if ($lats === []) {
            return null;
        }

        return [min($lats), min($lngs), max($lats), max($lngs)];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'term' => $this->term,
            'origin' => $this->origin?->jsonSerialize(),
            'radius' => $this->radius,
            'unit' => $this->unit,
            'total' => $this->total,
            'count' => count($this->locations),
            'bounds' => $this->bounds(),
            'originNotFound' => $this->originNotFound(),
            'matchedByName' => $this->matchedByName,
        ];
    }
}
