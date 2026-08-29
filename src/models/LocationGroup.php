<?php

declare(strict_types=1);

namespace justinholtweb\fold\models;

use Craft;
use craft\base\Model;
use craft\behaviors\FieldLayoutBehavior;
use craft\models\FieldLayout;
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
    public ?int $id = null;
    public ?string $name = null;
    public ?string $handle = null;

    /** Shown behind the marker on the map and beside the group in the CP. */
    public ?string $color = null;

    /** Marker icon key the front-end runtime looks up; null means the default pin. */
    public ?string $marker = null;

    /** Country new locations in this group start in; falls back to the plugin setting. */
    public ?string $defaultCountryCode = null;

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
            [['marker'], 'string', 'max' => 64],
            [['defaultCountryCode'], 'string', 'max' => 2],
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
