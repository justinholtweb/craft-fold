<?php

declare(strict_types=1);

namespace justinholtweb\fold\models;

use Craft;
use craft\base\Model;
use craft\behaviors\FieldLayoutBehavior;
use craft\models\FieldLayout;
use craft\validators\ColorValidator;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\Plugin;
use justinholtweb\fold\records\LocationGroupRecord;

/**
 * A kind of location — "Retail Stores", "Service Centres", "Stockists".
 *
 * Groups carry the field layout and the per-site URI format, which is why they exist at all: two
 * kinds of place a customer might visit rarely want the same fields, and a settings screen cannot
 * give either of them a page.
 *
 * Lives in project config (`fold.locationGroups.<uid>`), so groups and their field layouts version
 * with the rest of the project.
 *
 * @mixin FieldLayoutBehavior
 */
class LocationGroup extends Model
{
    /** A schema.org type name: one PascalCase word, no namespace, no URL. */
    public const SCHEMA_TYPE_PATTERN = '/^[A-Z][A-Za-z0-9]{1,63}$/';

    public ?int $id = null;
    public ?string $name = null;
    public ?string $handle = null;

    /** Shown behind the marker on the map and beside the group in the CP. */
    public ?string $color = null;

    /**
     * Reserved: a marker icon key. Stored and round-tripped through project config, but nothing
     * reads it yet — the front-end runtime colours the pin from `$color` and has no icon set.
     */
    public ?string $marker = null;

    /** Country new locations in this group start in; falls back to the plugin setting. */
    public ?string $defaultCountryCode = null;

    /**
     * The schema.org type this group's locations are published as in their structured data —
     * `Restaurant`, `AutoRepair`, `Store`. Null means `LocalBusiness`, which every subtype is.
     */
    public ?string $schemaType = null;

    public ?int $fieldLayoutId = null;
    public ?int $sortOrder = null;
    public ?string $uid = null;

    /** @var LocationGroupSiteSettings[]|null */
    private ?array $_siteSettings = null;

    public function behaviors(): array
    {
        return [
            'fieldLayout' => [
                'class' => FieldLayoutBehavior::class,
                'elementType' => Location::class,
            ],
        ];
    }

    public function __toString(): string
    {
        return (string)$this->name;
    }

    /**
     * @return LocationGroupSiteSettings[] Keyed by site ID.
     */
    public function getSiteSettings(): array
    {
        if ($this->_siteSettings !== null) {
            return $this->_siteSettings;
        }

        if ($this->id === null) {
            return [];
        }

        $this->setSiteSettings(Plugin::getInstance()->groups->getGroupSiteSettings($this->id));

        return $this->_siteSettings;
    }

    /**
     * @param LocationGroupSiteSettings[] $siteSettings
     */
    public function setSiteSettings(array $siteSettings): void
    {
        $this->_siteSettings = [];

        foreach ($siteSettings as $settings) {
            $settings->setGroup($this);
            $this->_siteSettings[(int)$settings->siteId] = $settings;
        }
    }

    public function getSiteSettingsForSite(int $siteId): ?LocationGroupSiteSettings
    {
        return $this->getSiteSettings()[$siteId] ?? null;
    }

    /** @return int[] */
    public function getSupportedSiteIds(): array
    {
        return array_keys($this->getSiteSettings());
    }

    public function getCountryCode(): string
    {
        return $this->defaultCountryCode ?: Plugin::getInstance()->getSettings()->defaultCountryCode;
    }

    /**
     * The pin colour as the front end should receive it: `#rrggbb`, or null for the map's default.
     *
     * Normalized and checked again on the way out, not only on save, because the value also
     * arrives through project config — and it is written into an inline SVG on a public page.
     */
    public function getMarkerColor(): ?string
    {
        if (!is_string($this->color) || $this->color === '') {
            return null;
        }

        $color = ColorValidator::normalizeColor($this->color);

        return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null;
    }

    /**
     * The `@type` for this group's structured data.
     *
     * Checked again on the way out, as the colour is: the value also arrives through project
     * config, and a type that is not a schema.org-shaped name is worse than the generic one —
     * Google drops the whole block rather than the one bad property.
     */
    public function getSchemaType(): string
    {
        $type = trim((string)$this->schemaType);

        return preg_match(self::SCHEMA_TYPE_PATTERN, $type) ? $type : 'LocalBusiness';
    }

    public function getCpEditUrl(): string
    {
        return sprintf('settings/fold/groups/%s', $this->id ?? 'new');
    }

    public function rules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['color'], 'string', 'max' => 16],
            [['color'], ColorValidator::class],
            [['marker'], 'string', 'max' => 64],
            [['defaultCountryCode'], 'string', 'max' => 2],
            [['schemaType'], 'match', 'pattern' => self::SCHEMA_TYPE_PATTERN, 'message' => Craft::t('fold', 'Enter a schema.org type name, such as Restaurant or AutoRepair.')],
            [
                ['handle'],
                HandleValidator::class,
                'reservedWords' => ['id', 'dateCreated', 'dateUpdated', 'uid', 'title', 'group', 'location', 'fold'],
            ],
            [
                ['name', 'handle'],
                UniqueValidator::class,
                'targetClass' => LocationGroupRecord::class,
                'targetAttribute' => ['name', 'handle'],
            ],
            // `skipOnEmpty` is false on purpose. Yii defaults an inline validator to skipping
            // an empty value, and an empty array is empty — so a rule whose entire job is to
            // reject emptiness would be skipped exactly when it is needed.
            [['siteSettings'], 'validateSiteSettings', 'skipOnEmpty' => false],
        ];
    }

    /**
     * A group with no sites is a group nothing can be filed under, and the CP would show it as an
     * empty index with no way to explain why.
     */
    public function validateSiteSettings(): void
    {
        if ($this->getSiteSettings() === []) {
            $this->addError('siteSettings', Craft::t('fold', 'At least one site must be selected.'));

            return;
        }

        foreach ($this->getSiteSettings() as $settings) {
            if (!$settings->validate()) {
                $this->addError('siteSettings', Craft::t('fold', 'The site settings are invalid.'));
            }
        }
    }

    public function getFieldLayout(): ?FieldLayout
    {
        /** @var FieldLayoutBehavior $behavior */
        $behavior = $this->getBehavior('fieldLayout');

        return $behavior->getFieldLayout();
    }

    /**
     * @return array<string, mixed> This group as project config sees it.
     */
    public function getConfig(): array
    {
        $config = [
            'name' => $this->name,
            'handle' => $this->handle,
            'color' => $this->color ?: null,
            'marker' => $this->marker ?: null,
            'defaultCountryCode' => $this->defaultCountryCode ?: null,
            'schemaType' => $this->schemaType ?: null,
            'sortOrder' => $this->sortOrder,
            'siteSettings' => [],
        ];

        foreach ($this->getSiteSettings() as $settings) {
            $site = $settings->getSite();

            if ($site !== null) {
                $config['siteSettings'][$site->uid] = $settings->getConfig();
            }
        }

        $fieldLayout = $this->getFieldLayout();

        if ($fieldLayout !== null) {
            $layoutConfig = $fieldLayout->getConfig();

            if ($layoutConfig) {
                $config['fieldLayouts'] = [
                    ($fieldLayout->uid ?: \craft\helpers\StringHelper::UUID()) => $layoutConfig,
                ];
            }
        }

        return $config;
    }
}
