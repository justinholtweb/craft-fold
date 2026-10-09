<?php

declare(strict_types=1);

namespace justinholtweb\fold;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\Gc;
use craft\services\Gql;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\fields\LocationsField;
use justinholtweb\fold\gql\interfaces\LocationInterface;
use justinholtweb\fold\gql\LocationQueries;
use justinholtweb\fold\models\Settings;
use justinholtweb\fold\services\Commerce;
use justinholtweb\fold\services\Exporter;
use justinholtweb\fold\services\Geocoder;
use justinholtweb\fold\services\Importer;
use justinholtweb\fold\services\Groups;
use justinholtweb\fold\services\Locations;
use justinholtweb\fold\services\Schema;
use justinholtweb\fold\services\Search;
use justinholtweb\fold\twig\FoldVariable;
use yii\base\Event;

/**
 * Fold — a store locator for Craft CMS.
 *
 * @property-read Locations $locations
 * @property-read Groups $groups
 * @property-read Geocoder $geocoder
 * @property-read Search $search
 * @property-read Commerce $commerce
 * @property-read Importer $importer
 * @property-read Exporter $exporter
 * @property-read Schema $schema
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public const PERMISSION_VIEW = 'fold:viewLocations';
    public const PERMISSION_MANAGE = 'fold:manageLocations';
    public const PERMISSION_DELETE = 'fold:deleteLocations';
    public const PERMISSION_SEARCHES = 'fold:viewSearches';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'fold';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'locations' => Locations::class,
                'groups' => Groups::class,
                'geocoder' => Geocoder::class,
                'search' => Search::class,
                'commerce' => Commerce::class,
                'importer' => Importer::class,
                'exporter' => Exporter::class,
                'schema' => Schema::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerFieldTypes();
        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerProjectConfig();
        $this->registerTwig();
        $this->registerGraphQl();
        $this->registerStructuredData();
        $this->registerGarbageCollection();
    }

    /** Whether the Pro feature set is available. Every edition check goes through here. */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('fold', 'Fold');

        $item['subnav']['locations'] = [
            'label' => Craft::t('fold', 'Locations'),
            'url' => 'fold/locations',
        ];

        if ($this->isPro() && $this->getSettings()->logSearches && Craft::$app->getUser()->checkPermission(self::PERMISSION_SEARCHES)) {
            $item['subnav']['searches'] = [
                'label' => Craft::t('fold', 'Searches'),
                'url' => 'fold/searches',
            ];
        }

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['groups'] = [
                'label' => Craft::t('fold', 'Groups'),
                'url' => 'settings/fold/groups',
            ];
            $item['subnav']['settings'] = [
                'label' => Craft::t('fold', 'Settings'),
                'url' => 'settings/plugins/fold',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('fold/_settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Location::class;
        });
    }

    private function registerFieldTypes(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = LocationsField::class;
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'fold' => 'fold/locations/index',
                'fold/locations' => 'fold/locations/index',
                'fold/locations/new' => 'fold/locations/edit',
                'fold/locations/<locationId:\d+>' => 'fold/locations/edit',
                'fold/searches' => 'fold/searches/index',
                'settings/fold/groups' => 'fold/groups/index',
                'settings/fold/groups/new' => 'fold/groups/edit',
                'settings/fold/groups/<groupId:\d+>' => 'fold/groups/edit',
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            // A stable, documented endpoint as well as the action URL, so a front end can be
            // written against a URL that does not contain the word "actions".
            $event->rules['fold/search.json'] = 'fold/search/index';
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('fold', 'Fold'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('fold', 'View locations'),
                        'nested' => [
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('fold', 'Create and edit locations'),
                            ],
                            self::PERMISSION_DELETE => [
                                'label' => Craft::t('fold', 'Delete locations'),
                            ],
                        ],
                    ],
                    // Its own permission, not nested under locations: the log is what visitors
                    // typed and roughly where they were, and editing a shop's phone number is no
                    // reason to read that.
                    self::PERMISSION_SEARCHES => [
                        'label' => Craft::t('fold', 'View the search log'),
                    ],
                ],
            ];
        });
    }

    private function registerProjectConfig(): void
    {
        Craft::$app->getProjectConfig()
            ->onAdd(Groups::CONFIG_GROUPS_KEY . '.{uid}', [$this->groups, 'handleChangedGroup'])
            ->onUpdate(Groups::CONFIG_GROUPS_KEY . '.{uid}', [$this->groups, 'handleChangedGroup'])
            ->onRemove(Groups::CONFIG_GROUPS_KEY . '.{uid}', [$this->groups, 'handleDeletedGroup']);

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $event) {
            $event->config['fold']['locationGroups'] = $this->groups->rebuildProjectConfig();
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('fold', FoldVariable::class);
        });
    }

    /**
     * `foldLocations` / `foldLocation` / `foldLocationCount`, one type per group, and a schema
     * permission per group — the same shape as Craft's own category groups.
     */
    private function registerGraphQl(): void
    {
        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_TYPES, function(RegisterGqlTypesEvent $event) {
            $event->types[] = LocationInterface::class;
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_QUERIES, function(RegisterGqlQueriesEvent $event) {
            $event->queries = array_merge($event->queries, LocationQueries::getQueries());
        });

        Event::on(Gql::class, Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS, function(RegisterGqlSchemaComponentsEvent $event) {
            $components = [];

            foreach ($this->groups->getAllGroups() as $group) {
                $components['foldLocationGroups.' . $group->uid . ':read'] = [
                    'label' => Craft::t('fold', 'Query for locations in the “{name}” location group', ['name' => $group->name]),
                ];
            }

            if ($components !== []) {
                $event->queries[Craft::t('fold', 'Fold locations')] = $components;
            }
        });
    }

    /**
     * LocalBusiness JSON-LD in the `<head>` of every location's own page — see {@see Schema}.
     */
    private function registerStructuredData(): void
    {
        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function() {
            if (Craft::$app->getView()->getTemplateMode() === View::TEMPLATE_MODE_SITE) {
                $this->schema->injectForMatchedElement();
            }
        });
    }

    /**
     * Expired geocode cache rows and old search log rows go out with Craft's own rubbish.
     *
     * Hooked to garbage collection rather than swept on read: an expired row is harmless until
     * somebody asks for it, and deleting on read turns every cache miss into a write.
     */
    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->geocoder->purgeExpiredCache();

            $days = $this->getSettings()->searchLogRetentionDays;

            if ($days > 0) {
                $this->search->purgeSearchLog($days);
            }
        });
    }
}
