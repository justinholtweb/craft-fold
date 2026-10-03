<?php

declare(strict_types=1);

namespace justinholtweb\fold\elements;

use Craft;
use craft\base\Element;
use craft\elements\Address;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use DateTime;
use DateTimeInterface;
use justinholtweb\fold\elements\db\LocationQuery;
use justinholtweb\fold\helpers\Geo;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\models\LocationGroup;
use justinholtweb\fold\models\OpeningHours;
use justinholtweb\fold\Plugin;
use justinholtweb\fold\records\LocationRecord;

/**
 * A place a customer can walk into.
 *
 * An element rather than a settings row, because everything a store page needs is element
 * plumbing: a URI and a template per site, a field layout so "drive-through" and "store manager"
 * are the site's own fields rather than Fold's guesses, search, relations, the trash, drafts, and
 * per-site enablement so a branch can exist on the US site and not the Canadian one.
 *
 * ## Which facts are per site, and which are not
 *
 * The **physical** facts — the address, the coordinates, the phone number, the opening hours —
 * live once, on `fold_locations`, and are shared across every site. A shop does not move, gain a
 * second phone line, or open earlier because a visitor switched to the Spanish site.
 *
 * The **words** — the title, and everything in the field layout — are Craft's own per-site
 * content, translated or not according to each field's translation method. So the Spanish site
 * says "Tienda del centro" about the same building at the same coordinates.
 *
 * @property-read LocationGroup $group
 * @property-read OpeningHours $hours
 * @property-read Address|null $address
 */
class Location extends Element
{
    /** Never geocoded, or the address changed since it last was. */
    public const GEOCODE_PENDING = 'pending';

    /** Coordinates came from the geocoder and match the current address. */
    public const GEOCODE_OK = 'ok';

    /** The geocoder was asked and could not place the address. */
    public const GEOCODE_FAILED = 'failed';

    /** Somebody typed the coordinates. Fold will not overwrite them. */
    public const GEOCODE_MANUAL = 'manual';

    public const GEOCODE_STATES = [self::GEOCODE_PENDING, self::GEOCODE_OK, self::GEOCODE_FAILED, self::GEOCODE_MANUAL];

    public ?int $groupId = null;
    public ?int $addressId = null;
    public ?float $lat = null;
    public ?float $lng = null;
    public ?string $phone = null;
    public ?string $email = null;
    public ?string $websiteUrl = null;
    public ?string $timezone = null;
    public ?int $commerceInventoryLocationId = null;
    public string $geocodeState = self::GEOCODE_PENDING;
    public ?string $geocodeHash = null;
    public ?string $geocodeError = null;
    public ?DateTime $geocodedAt = null;

    /**
     * How far this location is from the origin of the search that found it, in that search's
     * unit. Populated by {@see LocationQuery::nearby()}; null on a location nobody searched for.
     */
    public ?float $distance = null;

    private ?OpeningHours $_hours = null;
    private ?Address $_address = null;
    private ?LocationGroup $_group = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('fold', 'Location');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('fold', 'location');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('fold', 'Locations');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('fold', 'locations');
    }

    public static function refHandle(): ?string
    {
        return 'fold';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public static function trackChanges(): bool
    {
        return true;
    }

    public static function hasDrafts(): bool
    {
        return true;
    }

    public static function find(): ElementQueryInterface
    {
        return new LocationQuery(static::class);
    }

    // Group, sites and URLs
    // -------------------------------------------------------------------------

    public function getGroup(): LocationGroup
    {
        if ($this->_group !== null) {
            return $this->_group;
        }

        if ($this->groupId === null) {
            throw new \yii\base\InvalidConfigException('Location is missing its group.');
        }

        $group = Plugin::getInstance()->groups->getGroupById($this->groupId);

        if ($group === null) {
            throw new \yii\base\InvalidConfigException("Invalid location group ID: $this->groupId");
        }

        return $this->_group = $group;
    }

    /**
     * The sites this location can exist on — the ones its group is enabled for.
     *
     * Returning the group's sites rather than every site is what makes a per-site locator
     * possible at all: a "Stockists" group turned off for the Canadian site means its shops do
     * not appear there, are not saved there, and do not get a URL there.
     */
    public function getSupportedSites(): array
    {
        if ($this->groupId === null) {
            return [Craft::$app->getSites()->getPrimarySite()->id];
        }

        $sites = [];

        foreach ($this->getGroup()->getSiteSettings() as $settings) {
            $sites[] = [
                'siteId' => $settings->siteId,
                'enabledByDefault' => $settings->enabledByDefault,
            ];
        }

        // A group whose sites have all been deleted would otherwise return an empty array, which
        // Craft treats as a fatal misconfiguration mid-save rather than an empty result.
        return $sites !== [] ? $sites : [Craft::$app->getSites()->getPrimarySite()->id];
    }

    public function getUriFormat(): ?string
    {
        return $this->getGroup()->getSiteSettingsForSite((int)$this->siteId)?->uriFormat;
    }

    protected function previewTargets(): array
    {
        $settings = $this->getGroup()->getSiteSettingsForSite((int)$this->siteId);

        if ($settings === null || !$settings->getHasUrls()) {
            return [];
        }

        return [['label' => Craft::t('app', 'Primary {type} page', [
            'type' => self::lowerDisplayName(),
        ]), 'urlFormat' => $settings->uriFormat]];
    }

    protected function route(): array|string|null
    {
        $settings = $this->getGroup()->getSiteSettingsForSite((int)$this->siteId);

        if ($settings === null || !$settings->getHasUrls() || !$settings->template) {
            return null;
        }

        return [
            'templates/render',
            [
                'template' => $settings->template,
                'variables' => ['location' => $this],
            ],
        ];
    }

    public function getFieldLayout(): ?FieldLayout
    {
        return $this->getGroup()->getFieldLayout();
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl(sprintf('fold/locations/%s', $this->getCanonicalId()));
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('fold/locations');
    }

    public function getUiLabel(): string
    {
        return $this->title ?: Craft::t('fold', 'Untitled location');
    }

    // Address
    // -------------------------------------------------------------------------

    /**
     * The location's address, as a Craft Address element.
     *
     * Craft's own element rather than a set of columns on `fold_locations`, for the same reason
     * Commerce made the same choice for its inventory locations: addresses are not the same shape
     * in every country, and `commerceguys/addressing` already knows which fields Japan wants and
     * what to call a "state" in Ireland. It also means a location linked to a Commerce inventory
     * location and the inventory location itself hold the *same kind of thing*, so mirroring one
     * onto the other is a copy rather than a translation.
     */
    public function getAddress(): ?Address
    {
        if ($this->_address !== null) {
            return $this->_address;
        }

        if ($this->addressId !== null) {
            $address = Craft::$app->getElements()->getElementById($this->addressId, Address::class);

            if ($address instanceof Address) {
                return $this->_address = $address;
            }
        }

        return null;
    }

    public function setAddress(?Address $address): void
    {
        $this->_address = $address;
        $this->addressId = $address?->id;
    }

    /** An address to edit — the existing one, or a new one in the group's default country. */
    public function getAddressOrNew(): Address
    {
        $address = $this->getAddress();

        if ($address !== null) {
            return $address;
        }

        $address = new Address();
        $address->countryCode = $this->groupId !== null
            ? $this->getGroup()->getCountryCode()
            : Plugin::getInstance()->getSettings()->defaultCountryCode;

        return $this->_address = $address;
    }

    /** The address on one line, the way the country writes it. */
    public function getFormattedAddress(array $options = []): string
    {
        $address = $this->getAddress();

        return $address !== null
            ? Craft::$app->getAddresses()->formatAddress($address, $options)
            : '';
    }

    /**
     * The address as a single geocodable string.
     *
     * Deliberately *not* the formatted address: locale formatting inserts line breaks and country
     * names in the local language, and a geocoder does better with plain comma-separated parts in
     * the order it expects. Empty parts are dropped so a missing address line does not become a
     * double comma the geocoder reads as a missing component.
     */
    public function getGeocodableAddress(): string
    {
        $address = $this->getAddress();

        if ($address === null) {
            return '';
        }

        $parts = array_filter([
            $address->addressLine1,
            $address->addressLine2,
            $address->locality,
            $address->administrativeArea,
            $address->postalCode,
            $address->countryCode,
        ], static fn($part) => trim((string)$part) !== '');

        return implode(', ', $parts);
    }

    // Coordinates
    // -------------------------------------------------------------------------

    public function hasCoordinates(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /**
     * A fingerprint of the address the coordinates belong to.
     *
     * Compared against the stored hash on save, so a location is re-geocoded when its address
     * changes and not when anything else does. Without it, editing opening hours costs a geocoder
     * request, and a bulk resave costs one per location — which is how a free geocoder blocks you.
     */
    public function computeGeocodeHash(): ?string
    {
        $address = $this->getGeocodableAddress();

        return $address !== '' ? sha1(mb_strtolower($address)) : null;
    }

    public function needsGeocoding(): bool
    {
        if ($this->geocodeState === self::GEOCODE_MANUAL) {
            return false;
        }

        $hash = $this->computeGeocodeHash();

        if ($hash === null) {
            return false;
        }

        if ($hash !== $this->geocodeHash) {
            return true;
        }

        // Same address as last time. Missing coordinates only warrant another try if the last
        // attempt did not already fail on exactly this address — retrying an unchanged address
        // on every save is a slower way to get the same "not found".
        return !$this->hasCoordinates() && $this->geocodeState !== self::GEOCODE_FAILED;
    }

    /** @return array{lat: float, lng: float}|null */
    public function getCoordinates(): ?array
    {
        return $this->hasCoordinates() ? ['lat' => $this->lat, 'lng' => $this->lng] : null;
    }

    /** Distance from this location to a point, in `$unit` — for a location the query did not measure. */
    public function distanceTo(float $lat, float $lng, ?string $unit = null): ?float
    {
        if (!$this->hasCoordinates()) {
            return null;
        }

        return Geo::distance($this->lat, $this->lng, $lat, $lng, $unit ?? Plugin::getInstance()->getSettings()->distanceUnit);
    }

    /** A link that opens directions in whatever maps app the visitor's device prefers. */
    public function getDirectionsUrl(?string $from = null): ?string
    {
        if (!$this->hasCoordinates()) {
            return null;
        }

        $params = [
            'api' => '1',
            'destination' => sprintf('%s,%s', $this->lat, $this->lng),
        ];

        if ($from !== null && trim($from) !== '') {
            $params['origin'] = $from;
        }

        return 'https://www.google.com/maps/dir/?' . http_build_query($params);
    }

    // Hours
    // -------------------------------------------------------------------------

    public function getHours(): OpeningHours
    {
        return $this->_hours ??= new OpeningHours();
    }

    public function setHours(mixed $value): void
    {
        $this->_hours = OpeningHours::fromJson($value);
    }

    /**
     * The timezone the shop's clock is in — its own, then the site's.
     *
     * Falling through to the site's timezone rather than the server's matters: "open now" for a
     * Los Angeles branch answered in the server's UTC is wrong by eight hours, which is the
     * difference between open and closed for the entire working day.
     */
    public function getTimezone(): string
    {
        return $this->timezone ?: Craft::$app->getTimeZone();
    }

    public function isOpenNow(): bool
    {
        return $this->getHours()->isOpenAt(null, $this->getTimezone());
    }

    public function isOpenAt(DateTimeInterface $when): bool
    {
        return $this->getHours()->isOpenAt($when, $this->getTimezone());
    }

    public function getNextOpeningAt(): ?DateTime
    {
        return $this->getHours()->nextOpeningAt(null, $this->getTimezone());
    }

    // Commerce
    // -------------------------------------------------------------------------

    public function getIsLinkedToCommerce(): bool
    {
        return $this->commerceInventoryLocationId !== null;
    }

    /** The Commerce inventory location this is bound to, or null. Never throws without Commerce. */
    public function getInventoryLocation(): mixed
    {
        return Plugin::getInstance()->commerce->getInventoryLocationById($this->commerceInventoryLocationId);
    }

    // Saving
    // -------------------------------------------------------------------------

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['groupId'], 'required'];
        $rules[] = [['groupId', 'addressId', 'commerceInventoryLocationId'], 'integer'];
        $rules[] = [['lat'], 'number', 'min' => -90, 'max' => 90];
        $rules[] = [['lng'], 'number', 'min' => -180, 'max' => 180];
        $rules[] = [['email'], 'email'];
        $rules[] = [['websiteUrl'], 'url', 'defaultScheme' => 'https'];
        $rules[] = [['phone'], 'string', 'max' => 64];
        $rules[] = [['timezone'], 'in', 'range' => \DateTimeZone::listIdentifiers(), 'skipOnEmpty' => true];
        $rules[] = [['geocodeState'], 'in', 'range' => self::GEOCODE_STATES];
        // Latitude without longitude is not half a coordinate, it is a location in the sea off
        // west Africa. Both or neither.
        $rules[] = [['lat', 'lng'], 'validateCoordinatePair'];

        return $rules;
    }

    public function validateCoordinatePair(): void
    {
        if (($this->lat === null) !== ($this->lng === null)) {
            $this->addError('lat', Craft::t('fold', 'Enter both a latitude and a longitude, or neither.'));
        }
    }

    public function beforeSave(bool $isNew): bool
    {
        if ($this->groupId === null) {
            return false;
        }

        // The edition cap, enforced where every path ends up. `Locations::saveLocation()` checks
        // it too, but Craft's own Duplicate action, `elements/create` and applying a fresh draft
        // all reach `saveElement()` without passing through Fold's service. `firstSave` is the
        // draft-apply case: the element already has an ID, but it is becoming a real location now.
        if (
            ($isNew || $this->firstSave)
            && !$this->getIsDraft()
            && !$this->getIsRevision()
            && !$this->propagating
            && !Plugin::getInstance()->locations->canCreateLocation()
        ) {
            $this->addError('title', Craft::t('fold', 'Fold Lite supports up to {max} locations. Upgrade to Pro for unlimited locations.', [
                'max' => Edition::LITE_MAX_LOCATIONS,
            ]));

            return false;
        }

        // Saving an address the caller built but never persisted. Done before the location's own
        // row is written so `addressId` is real by the time it lands.
        $address = $this->_address;

        if ($address !== null && !$this->propagating) {
            $address->title = $address->title ?: $this->title;

            if (Craft::$app->getElements()->saveElement($address, false)) {
                $this->addressId = $address->id;
            }
        }

        if ($this->timezone === null && $this->hasCoordinates()) {
            $this->timezone = null;
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        // The physical facts are shared across sites, so a propagation pass has nothing to write:
        // it carries the target site's copy of the element and would only rewrite the same row
        // with the same values, once per site, on every save.
        if (!$this->propagating) {
            $record = $isNew ? new LocationRecord() : LocationRecord::findOne($this->id);

            if ($record === null) {
                // A restored element, or one whose row went missing. Written back rather than
                // failing the save and leaving a location with no address and no coordinates.
                $record = new LocationRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
            }

            $record->groupId = $this->groupId;
            $record->addressId = $this->addressId;
            $record->lat = $this->lat;
            $record->lng = $this->lng;
            $record->phone = $this->phone;
            $record->email = $this->email;
            $record->websiteUrl = $this->websiteUrl;
            $record->hours = $this->getHours()->isEmpty() ? null : json_encode($this->getHours());
            $record->timezone = $this->timezone;
            $record->commerceInventoryLocationId = $this->commerceInventoryLocationId;
            $record->geocodeState = $this->geocodeState;
            $record->geocodeHash = $this->geocodeHash;
            $record->geocodeError = $this->geocodeError;
            $record->geocodedAt = Db::prepareDateForDb($this->geocodedAt);
            $record->save(false);

            Plugin::getInstance()->locations->afterLocationSaved($this);
        }

        parent::afterSave($isNew);
    }

    /**
     * Takes the address with it, but only on a hard delete.
     *
     * Craft's ordinary delete is a soft one, and an address deleted alongside a trashed location
     * could not be restored with it — the location would come back with a blank address and no
     * indication that it ever had one.
     */
    public function afterDelete(): void
    {
        if ($this->hardDelete && $this->addressId !== null) {
            $address = Craft::$app->getElements()->getElementById($this->addressId, Address::class);

            if ($address !== null) {
                Craft::$app->getElements()->deleteElement($address, true);
            }
        }

        parent::afterDelete();
    }

    // Search
    // -------------------------------------------------------------------------

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'addressText', 'phone', 'email'];
    }

    /**
     * The address, so searching the CP for a postcode or a street finds the shop on it.
     */
    protected function searchKeywords(string $attribute): string
    {
        if ($attribute === 'addressText') {
            return $this->getGeocodableAddress();
        }

        return parent::searchKeywords($attribute);
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('fold', 'All locations'),
                'defaultSort' => ['title', 'asc'],
            ],
        ];

        $groups = Plugin::getInstance()->groups->getAllGroups();

        if (count($groups) > 1) {
            $sources[] = ['heading' => Craft::t('fold', 'Groups')];

            foreach ($groups as $group) {
                $sources[] = [
                    'key' => 'group:' . $group->uid,
                    'label' => $group->name,
                    'criteria' => ['groupId' => $group->id],
                    'data' => ['handle' => $group->handle],
                ];
            }
        }

        $sources[] = ['heading' => Craft::t('fold', 'On the map')];
        $sources[] = [
            'key' => 'geocode:pending',
            'label' => Craft::t('fold', 'Awaiting coordinates'),
            'criteria' => ['geocodeState' => self::GEOCODE_PENDING],
        ];
        $sources[] = [
            'key' => 'geocode:failed',
            'label' => Craft::t('fold', 'Could not be placed'),
            'criteria' => ['geocodeState' => self::GEOCODE_FAILED],
        ];

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'addressText' => ['label' => Craft::t('fold', 'Address')],
            'group' => ['label' => Craft::t('fold', 'Group')],
            'coordinates' => ['label' => Craft::t('fold', 'Coordinates')],
            'openNow' => ['label' => Craft::t('fold', 'Open now')],
            'phone' => ['label' => Craft::t('fold', 'Phone')],
            'commerce' => ['label' => Craft::t('fold', 'Commerce')],
            'link' => ['label' => Craft::t('app', 'Link')],
            'dateUpdated' => ['label' => Craft::t('app', 'Last Updated')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['addressText', 'group', 'coordinates', 'openNow'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'fold_locations.geocodeState' => Craft::t('fold', 'Geocoding'),
            'dateUpdated' => Craft::t('app', 'Last Updated'),
            'dateCreated' => Craft::t('app', 'Date Created'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            // Joined with commas rather than left as the formatter's newlines: an index cell is
            // one line high, and a multi-line address in it either wraps into a paragraph or gets
            // truncated at the first line.
            'addressText' => Html::encode(implode(', ', array_filter(array_map(
                'trim',
                explode("\n", $this->getFormattedAddress(['html' => false])),
            )))),
            'group' => Html::encode($this->groupId !== null ? (string)$this->getGroup()->name : ''),
            'coordinates' => $this->coordinatesHtml(),
            'openNow' => $this->getHours()->isEmpty()
                ? ''
                : $this->statusHtml(
                    $this->isOpenNow() ? 'green' : 'red',
                    $this->isOpenNow() ? Craft::t('fold', 'Open') : Craft::t('fold', 'Closed'),
                ),
            'phone' => $this->phone ? Html::a(Html::encode($this->phone), 'tel:' . $this->phone) : '',
            'commerce' => $this->commerceInventoryLocationId !== null
                ? $this->statusHtml('green', Craft::t('fold', 'Linked'))
                : '',
            default => parent::attributeHtml($attribute),
        };
    }

    /**
     * Craft's status indicator: a coloured dot, then the label beside it.
     *
     * `.status` is styled as a small fixed-size circle, so text placed *inside* it is squeezed
     * into a few pixels of width and wraps one letter per line. The label has to be its own
     * element next to the dot — which is what Craft's own element types do.
     */
    private function statusHtml(string $color, string $label): string
    {
        return Html::tag('span', '', ['class' => ['status', $color]])
            . Html::tag('span', Html::encode($label));
    }

    private function coordinatesHtml(): string
    {
        if ($this->hasCoordinates()) {
            return Html::tag('code', sprintf('%.5f, %.5f', $this->lat, $this->lng), ['class' => 'small light']);
        }

        return match ($this->geocodeState) {
            self::GEOCODE_FAILED => Html::tag('span', Craft::t('fold', 'Not found'), ['class' => 'error']),
            default => Html::tag('span', Craft::t('fold', 'Pending'), ['class' => 'light']),
        };
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDuplicate(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE);
    }

    public function canCreateDrafts(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }
}
