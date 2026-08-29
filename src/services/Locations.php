<?php

declare(strict_types=1);

namespace justinholtweb\fold\services;

use Craft;
use craft\base\Component;
use justinholtweb\fold\elements\db\LocationQuery;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\Plugin;
use justinholtweb\fold\queue\jobs\GeocodeLocationJob;

/**
 * Locations: the edition cap, and what happens after one is saved.
 */
class Locations extends Component
{
    public function getLocationById(int $id, ?int $siteId = null): ?Location
    {
        $element = Craft::$app->getElements()->getElementById($id, Location::class, $siteId);

        return $element instanceof Location ? $element : null;
    }

    public function find(): LocationQuery
    {
        return Location::find();
    }

    /**
     * How many locations exist, across every site.
     *
     * `siteId('*')->unique()` matters: without it a location that exists on four sites counts as
     * four, and a Lite site with three shops and four locales would be told it had hit a limit of
     * ten.
     */
    public function getTotalLocations(): int
    {
        return (int)Location::find()
            ->status(null)
            ->siteId('*')
            ->unique()
            ->count();
    }

    /**
     * Whether another location may be created.
     *
     * Asked before the CP offers a "New location" button and again when a save arrives, because
     * the first is a courtesy and the second is the rule.
     */
    public function canCreateLocation(): bool
    {
        $max = Edition::maxLocations(Plugin::getInstance()->isPro());

        return $max === null || $this->getTotalLocations() < $max;
    }

    public function getRemainingLocations(): ?int
    {
        $max = Edition::maxLocations(Plugin::getInstance()->isPro());

        return $max === null ? null : max(0, $max - $this->getTotalLocations());
    }

    public function saveLocation(Location $location, bool $runValidation = true): bool
    {
        if ($location->id === null && !$this->canCreateLocation()) {
            $location->addError('title', Craft::t('fold', 'Fold Lite supports up to {max} locations. Upgrade to Pro for unlimited locations.', [
                'max' => Edition::LITE_MAX_LOCATIONS,
            ]));

            return false;
        }

        return Craft::$app->getElements()->saveElement($location, $runValidation);
    }

    /**
     * Queues a geocode when a save has left the location's coordinates out of date.
     *
     * A queue job rather than an inline HTTP request, because the alternative is that importing
     * 400 stores means 400 sequential round trips inside one web request — which times out, and
     * on a rate-limited provider would take seven minutes even if it did not.
     *
     * The single-store case gets the same treatment for consistency; the CP offers *Geocode now*
     * for authors who do not want to wait for a queue runner.
     */
    public function afterLocationSaved(Location $location): void
    {
        if (!Plugin::getInstance()->getSettings()->autoGeocode) {
            return;
        }

        if (!$location->needsGeocoding()) {
            return;
        }

        // Drafts and revisions are not on the map and may never be. Geocoding every keystroke's
        // autosaved draft would be a request per keystroke.
        if ($location->getIsDraft() || $location->getIsRevision()) {
            return;
        }

        Craft::$app->getQueue()->push(new GeocodeLocationJob([
            'locationId' => $location->getCanonicalId(),
            'siteId' => $location->siteId,
        ]));
    }

    /**
     * Queues a geocode for every location that needs one.
     *
     * @return int How many were queued.
     */
    public function queueAllPending(bool $force = false): int
    {
        $query = Location::find()->status(null)->siteId('*')->unique();

        if (!$force) {
            $query->geocodeState([Location::GEOCODE_PENDING, Location::GEOCODE_FAILED]);
        }

        $queued = 0;

        foreach ($query->all() as $location) {
            if (!$force && !$location->needsGeocoding()) {
                continue;
            }

            Craft::$app->getQueue()->push(new GeocodeLocationJob([
                'locationId' => $location->id,
                'siteId' => $location->siteId,
                'force' => $force,
            ]));

            $queued++;
        }

        return $queued;
    }
}
