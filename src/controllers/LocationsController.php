<?php

declare(strict_types=1);

namespace justinholtweb\fold\controllers;

use Craft;
use craft\elements\Address;
use craft\helpers\Cp;
use craft\web\Controller;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\models\OpeningHours;
use justinholtweb\fold\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The CP's locations screens.
 *
 * A bespoke edit screen rather than Craft's generic element editor, because the three things that
 * make a location a location — a country-aware address, a week of opening hours, and a pair of
 * coordinates with a map to check them on — are all editors Craft has no generic form control
 * for.
 */
class LocationsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('fold/locations/_index', [
            'groups' => $plugin->groups->getAllGroups(),
            'remaining' => $plugin->locations->getRemainingLocations(),
            'canCreate' => $plugin->locations->canCreateLocation(),
            'maxLocations' => Edition::maxLocations($plugin->isPro()),
        ]);
    }

    public function actionEdit(?int $locationId = null, ?Location $location = null): Response
    {
        $plugin = Plugin::getInstance();
        $groups = $plugin->groups->getAllGroups();

        if ($groups === []) {
            // Nothing can be filed anywhere yet. Sending an admin to the screen that fixes it is
            // more use than an empty form that cannot be saved.
            return $this->redirect('settings/fold/groups/new');
        }

        if ($location === null) {
            if ($locationId !== null) {
                $location = $plugin->locations->getLocationById($locationId);

                if ($location === null) {
                    throw new NotFoundHttpException('Location not found.');
                }
            } else {
                if (!$plugin->locations->canCreateLocation()) {
                    throw new ForbiddenHttpException(Craft::t('fold', 'Fold Lite supports up to {max} locations.', [
                        'max' => Edition::LITE_MAX_LOCATIONS,
                    ]));
                }

                $location = new Location();
                $location->groupId = $groups[0]->id;
                $location->siteId = Craft::$app->getSites()->getCurrentSite()->id;
            }
        }

        $groupOptions = [];

        foreach ($groups as $group) {
            $groupOptions[] = ['label' => $group->name, 'value' => $group->id];
        }

        $address = $location->getAddressOrNew();

        return $this->renderTemplate('fold/locations/_edit', [
            'location' => $location,
            'address' => $address,
            // Rendered here, and namespaced *here*, for a reason that is not obvious and costs
            // an afternoon to find: Craft's address fields include selectize-enhanced
            // subdivision selects whose initialisation JS is registered with `id|namespaceInputId`
            // — resolved against the View's namespace at render time. Wrapping the output in
            // Twig's `{% namespace %}` rewrites the HTML ids *after* that JS has been registered
            // against the old ones, so selectize binds to an element that no longer exists and
            // the State field renders as a label with nothing under it. Namespacing through the
            // View keeps the markup and the script talking about the same element.
            'addressFieldsHtml' => $this->namespacedAddressFields($address),
            'groups' => $groups,
            'groupOptions' => $groupOptions,
            'isNew' => $location->id === null,
            'plugin' => $plugin,
            'commerceOptions' => $this->commerceOptions(),
            'countryOptions' => $this->countryOptions(),
            'timezoneOptions' => $this->timezoneOptions(),
            'dayLabels' => $this->dayLabels(),
            'days' => OpeningHours::DAYS,
            'title' => $location->id !== null
                ? $location->getUiLabel()
                : Craft::t('fold', 'New location'),
        ]);
    }

    /**
     * Re-renders the address fields for a country.
     *
     * Which fields an address has is not Fold's opinion or the template's — `commerceguys/addressing`
     * knows that Ireland has no postal code, that Japan orders its subdivisions the other way
     * round, and what to call a "state" in each of them. So changing the country refetches the
     * fields from the same helper that rendered them, rather than showing or hiding a fixed set.
     */
    public function actionAddressFields(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $address = new Address();
        $address->countryCode = (string)Craft::$app->getRequest()->getRequiredBodyParam('countryCode');

        $view = Craft::$app->getView();
        $html = $this->namespacedAddressFields($address);

        return $this->asJson([
            'html' => $html,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $locationId = $request->getBodyParam('locationId');

        if ($locationId) {
            $location = $plugin->locations->getLocationById((int)$locationId);

            if ($location === null) {
                throw new NotFoundHttpException('Location not found.');
            }
        } else {
            $location = new Location();
            $location->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        // The words on a location are per-site, so editing them takes that site's permission —
        // the same rule Craft applies to entries.
        if (Craft::$app->getIsMultiSite()) {
            $site = Craft::$app->getSites()->getSiteById((int)$location->siteId);

            if ($site === null) {
                throw new NotFoundHttpException('Site not found.');
            }

            $this->requirePermission('editSite:' . $site->uid);
        }

        $location->groupId = (int)$request->getBodyParam('groupId', $location->groupId);
        $location->title = $request->getBodyParam('title', $location->title);
        $location->slug = $request->getBodyParam('slug', $location->slug);
        $location->enabled = (bool)$request->getBodyParam('enabled', true);
        $location->phone = $this->emptyToNull($request->getBodyParam('phone'));
        $location->email = $this->emptyToNull($request->getBodyParam('email'));
        $location->websiteUrl = $this->emptyToNull($request->getBodyParam('websiteUrl'));
        $location->timezone = $this->emptyToNull($request->getBodyParam('timezone'));
        $location->setHours($this->readHours($request->getBodyParam('hours', [])));
        $location->setFieldValuesFromRequest('fields');

        // Only touched when the form actually carried the field. On Lite, a lapsed Pro, or with
        // Commerce uninstalled the select is not rendered, and reading its absence as "unlink"
        // would quietly drop every link the moment somebody fixed a phone number.
        $commerceId = $request->getBodyParam('commerceInventoryLocationId');

        if ($commerceId !== null) {
            $location->commerceInventoryLocationId = $commerceId !== '' ? (int)$commerceId : null;
        }

        $this->applyAddress($location, (array)$request->getBodyParam('address', []));
        $this->applyCoordinates(
            $location,
            $request->getBodyParam('lat'),
            $request->getBodyParam('lng'),
            $request->getBodyParam('coordinatesSource') === 'geocoder',
        );

        // Commerce is the authority on a linked location's address, so the mirror runs after the
        // posted address has been applied and simply overwrites it. Editing the address of a
        // linked location in Fold is therefore impossible rather than merely discouraged — which
        // is the honest behaviour, since the next sync would undo it anyway.
        if ($location->commerceInventoryLocationId !== null && $plugin->commerce->isEnabled()) {
            $plugin->commerce->mirrorAddress($location);
        }

        if (!$plugin->locations->saveLocation($location)) {
            Craft::$app->getSession()->setError(Craft::t('fold', 'Couldn’t save location.'));
            Craft::$app->getUrlManager()->setRouteParams(['location' => $location]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('fold', 'Location saved.'));

        return $this->redirectToPostedUrl($location);
    }

    /**
     * Geocodes a location on demand, without waiting for the queue.
     *
     * The single-store case: an author who has just typed an address wants to see the pin move
     * now, not after the next queue runner. Bulk work still goes through the queue.
     */
    public function actionGeocode(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $locationId = $request->getBodyParam('locationId');

        $location = $locationId
            ? $plugin->locations->getLocationById((int)$locationId)
            : new Location();

        if ($location === null) {
            throw new NotFoundHttpException('Location not found.');
        }

        // Geocoding what is *on the form*, not what is in the database — the author is asking
        // about the address they have just typed and not yet saved.
        $this->applyAddress($location, (array)$request->getBodyParam('address', []));

        if (!$plugin->geocoder->geocodeLocation($location, true)) {
            return $this->asJson([
                'success' => false,
                'message' => $location->geocodeError ?: Craft::t('fold', 'The address could not be placed on the map.'),
            ]);
        }

        // Not saved here. The form puts these coordinates into its fields and *Save* persists
        // them with everything else — validated, and through the Commerce mirror. Saving the
        // half-typed address from this endpoint would skip both. The geocode is cached, so the
        // lookup Save may queue for the new address is answered without a second request.

        return $this->asJson([
            'success' => true,
            'lat' => $location->lat,
            'lng' => $location->lng,
        ]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE);

        $locationId = (int)Craft::$app->getRequest()->getRequiredBodyParam('locationId');
        $location = Plugin::getInstance()->locations->getLocationById($locationId);

        if ($location === null) {
            throw new NotFoundHttpException('Location not found.');
        }

        Craft::$app->getElements()->deleteElement($location);
        Craft::$app->getSession()->setNotice(Craft::t('fold', 'Location deleted.'));

        return $this->redirect('fold/locations');
    }

    /** Queues a geocode for every location that has not got coordinates. */
    public function actionGeocodeAll(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $queued = Plugin::getInstance()->locations->queueAllPending();

        Craft::$app->getSession()->setNotice(Craft::t('fold', '{count} locations queued for geocoding.', [
            'count' => $queued,
        ]));

        return $this->redirect('fold/locations');
    }

    // Internals
    // -------------------------------------------------------------------------

    /**
     * Craft's address fields, with every input, id and registered script under `address[…]`.
     *
     * @see actionEdit() for why this cannot be done in the template.
     */
    private function namespacedAddressFields(Address $address): string
    {
        return Craft::$app->getView()->namespaceInputs(
            static fn() => Cp::addressFieldsHtml($address),
            'address',
        );
    }

    private function applyAddress(Location $location, array $posted): void
    {
        if ($posted === []) {
            return;
        }

        $address = $location->getAddressOrNew();

        foreach (['addressLine1', 'addressLine2', 'addressLine3', 'locality', 'administrativeArea', 'postalCode', 'dependentLocality', 'sortingCode', 'organization'] as $attribute) {
            if (array_key_exists($attribute, $posted)) {
                $address->$attribute = $this->emptyToNull($posted[$attribute]);
            }
        }

        if (!empty($posted['countryCode'])) {
            $address->countryCode = $posted['countryCode'];
        }

        $location->setAddress($address);
    }

    /**
     * Coordinates typed by hand mean "leave these alone".
     *
     * Marking them manual is what stops the next save from geocoding over the top of an author
     * who has just dragged a pin onto the right side of a divided highway — which the geocoder
     * would get wrong again, in exactly the same way, every time.
     */
    /**
     * @param bool $fromGeocoder The form's *Look up* filled these in, so they follow the address
     *   like any geocode would — rather than being pinned as if somebody had typed them.
     */
    private function applyCoordinates(Location $location, mixed $lat, mixed $lng, bool $fromGeocoder = false): void
    {
        $hasLat = $lat !== null && $lat !== '';
        $hasLng = $lng !== null && $lng !== '';

        if (!$hasLat || !$hasLng) {
            return;
        }

        $lat = (float)$lat;
        $lng = (float)$lng;

        // Unchanged hand-placed coordinates stay as they were. A lookup that lands on the same spot
        // still counts, because it is how a pinned location goes back to following its address.
        if (!$fromGeocoder && $lat === $location->lat && $lng === $location->lng) {
            return;
        }

        $location->lat = $lat;
        $location->lng = $lng;
        $location->geocodeState = $fromGeocoder ? Location::GEOCODE_OK : Location::GEOCODE_MANUAL;
        $location->geocodedAt = $fromGeocoder ? new \DateTime() : $location->geocodedAt;
        $location->geocodeError = null;
        $location->geocodeHash = $location->computeGeocodeHash();
    }

    /**
     * The posted hours grid, which arrives as a day-keyed array of open/close pairs.
     *
     * Handed straight to {@see OpeningHours}, whose normaliser is the single authority on what a
     * time is — so "9am", "09:00" and "0900" all mean the same thing here and in an import.
     */
    private function readHours(mixed $posted): array
    {
        if (!is_array($posted)) {
            return [];
        }

        $hours = [];

        foreach (OpeningHours::DAYS as $day) {
            $ranges = $posted[$day] ?? [];

            if (is_array($ranges)) {
                $hours[$day] = array_values(array_filter($ranges, static function($range) {
                    return is_array($range)
                        && trim((string)($range['open'] ?? '')) !== ''
                        && trim((string)($range['close'] ?? '')) !== '';
                }));
            }
        }

        if (!empty($posted['note'])) {
            $hours['note'] = $posted['note'];
        }

        if (!empty($posted['exceptions']) && is_array($posted['exceptions'])) {
            $exceptions = [];

            foreach ($posted['exceptions'] as $row) {
                $date = trim((string)($row['date'] ?? ''));

                if ($date === '') {
                    continue;
                }

                // A row with a date and no times is how the form says "closed that day", and an
                // empty array is exactly what OpeningHours reads as closed.
                $exceptions[$date] = (!empty($row['open']) && !empty($row['close']))
                    ? [['open' => $row['open'], 'close' => $row['close']]]
                    : [];
            }

            $hours['exceptions'] = $exceptions;
        }

        return $hours;
    }

    private function commerceOptions(): array
    {
        $commerce = Plugin::getInstance()->commerce;

        if (!$commerce->isEnabled()) {
            return [];
        }

        $options = [['label' => Craft::t('fold', 'Not linked'), 'value' => '']];

        foreach ($commerce->getInventoryLocationOptions() as $id => $name) {
            $options[] = ['label' => $name, 'value' => $id];
        }

        return $options;
    }

    /** @return array<int, array{label: string, value: string}> */
    private function countryOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getAddresses()->getCountryRepository()->getList() as $code => $name) {
            $options[] = ['label' => $name, 'value' => $code];
        }

        return $options;
    }

    /**
     * Timezones, grouped the way PHP lists them.
     *
     * Offered rather than guessed from the coordinates: a timezone lookup needs a shape file
     * Fold is not going to ship, and the shop's own staff know the answer.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function timezoneOptions(): array
    {
        $options = [['label' => Craft::t('fold', 'Site default'), 'value' => '']];

        foreach (\DateTimeZone::listIdentifiers() as $identifier) {
            $options[] = ['label' => str_replace('_', ' ', $identifier), 'value' => $identifier];
        }

        return $options;
    }

    /** @return array<string, string> */
    private function dayLabels(): array
    {
        return [
            'mon' => Craft::t('fold', 'Monday'),
            'tue' => Craft::t('fold', 'Tuesday'),
            'wed' => Craft::t('fold', 'Wednesday'),
            'thu' => Craft::t('fold', 'Thursday'),
            'fri' => Craft::t('fold', 'Friday'),
            'sat' => Craft::t('fold', 'Saturday'),
            'sun' => Craft::t('fold', 'Sunday'),
        ];
    }

    private function emptyToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === '' || $value === null) ? null : (string)$value;
    }
}
