<?php

declare(strict_types=1);

namespace justinholtweb\fold\queue\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\models\Settings;
use justinholtweb\fold\Plugin;

/**
 * Geocodes one location, off the request.
 *
 * One location per job rather than a batch, so that a provider hiccup costs one retry rather than
 * restarting four hundred lookups — and so the queue's own progress is a number an author can
 * watch.
 */
class GeocodeLocationJob extends BaseJob
{
    public ?int $locationId = null;
    public ?int $siteId = null;
    public bool $force = false;

    public function execute($queue): void
    {
        if ($this->locationId === null) {
            return;
        }

        $location = Craft::$app->getElements()->getElementById($this->locationId, Location::class, $this->siteId);

        if (!$location instanceof Location) {
            // Deleted between the save and the runner picking the job up. Not an error.
            return;
        }

        $this->throttle();

        $plugin = Plugin::getInstance();
        $plugin->geocoder->geocodeLocation($location, $this->force);

        // Saved without validation on purpose: the location was already valid when it was saved,
        // and a validation failure here — a custom field that has since become required, say —
        // would throw away coordinates that were fetched correctly and cost another provider
        // request the next time round.
        Craft::$app->getElements()->saveElement($location, false, false, false);
    }

    /**
     * Nominatim's usage policy is one request per second, and it is enforced by them.
     *
     * The sleep sits in the job rather than in the driver because the limit is per *site*, not
     * per call: a cached lookup makes no request and should not wait, and a paid provider has no
     * such limit and should not be slowed down to match one.
     */
    private function throttle(): void
    {
        $driver = Plugin::getInstance()->getSettings()->geocoderDriver;

        if ($driver === Settings::GEOCODER_NOMINATIM) {
            usleep(1100000);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('fold', 'Geocoding a location');
    }
}
