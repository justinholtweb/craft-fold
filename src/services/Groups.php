<?php

declare(strict_types=1);

namespace justinholtweb\fold\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\models\Edition;
use justinholtweb\fold\models\LocationGroup;
use justinholtweb\fold\models\LocationGroupSiteSettings;
use justinholtweb\fold\Plugin;
use justinholtweb\fold\records\LocationGroupRecord;
use justinholtweb\fold\records\LocationGroupSiteSettingsRecord;
use Throwable;

/**
 * Location groups, backed by project config so a group and its field layout version and deploy
 * with the rest of the project rather than being re-entered by hand in production.
 */
class Groups extends Component
{
    public const CONFIG_GROUPS_KEY = 'fold.locationGroups';

    /** @var LocationGroup[]|null */
    private ?array $_groups = null;

    /** @return LocationGroup[] */
    public function getAllGroups(): array
    {
        if ($this->_groups === null) {
            $this->_groups = [];

            /** @var LocationGroupRecord[] $records */
            $records = LocationGroupRecord::find()
                ->orderBy(['sortOrder' => SORT_ASC, 'name' => SORT_ASC])
                ->all();

            foreach ($records as $record) {
                $this->_groups[] = $this->createGroupFromRecord($record);
            }
        }

        return $this->_groups;
    }

    public function getGroupById(?int $id): ?LocationGroup
    {
        if ($id === null) {
            return null;
        }

        foreach ($this->getAllGroups() as $group) {
            if ($group->id === $id) {
                return $group;
            }
        }

        return null;
    }

    public function getGroupByHandle(string $handle): ?LocationGroup
    {
        foreach ($this->getAllGroups() as $group) {
            if ($group->handle === $handle) {
                return $group;
            }
        }

        return null;
    }

    public function getGroupByUid(string $uid): ?LocationGroup
    {
        foreach ($this->getAllGroups() as $group) {
            if ($group->uid === $uid) {
                return $group;
            }
        }

        return null;
    }

    /** The groups available on a site — what a front-end locator on that site may show. */
    public function getGroupsBySiteId(int $siteId): array
    {
        return array_values(array_filter(
            $this->getAllGroups(),
            static fn(LocationGroup $group) => $group->getSiteSettingsForSite($siteId) !== null,
        ));
    }

    /**
     * @return LocationGroupSiteSettings[] Keyed by site ID.
     */
    public function getGroupSiteSettings(int $groupId): array
    {
        $rows = (new Query())
            ->select(['id', 'groupId', 'siteId', 'enabledByDefault', 'uriFormat', 'template', 'uid'])
            ->from(['{{%fold_locationgroups_sites}}'])
            ->where(['groupId' => $groupId])
            ->all();

        $settings = [];

        foreach ($rows as $row) {
            $settings[(int)$row['siteId']] = new LocationGroupSiteSettings([
                'id' => (int)$row['id'],
                'groupId' => (int)$row['groupId'],
                'siteId' => (int)$row['siteId'],
                'enabledByDefault' => (bool)$row['enabledByDefault'],
                'uriFormat' => $row['uriFormat'],
                'template' => $row['template'],
                'uid' => $row['uid'],
            ]);
        }

        return $settings;
    }

    public function refresh(): void
    {
        $this->_groups = null;
    }

    /**
     * Whether this edition may hold another group.
     *
     * Asked before the save rather than enforced by the save, so the CP can say *why* — a refusal
     * with no explanation reads as a bug.
     */
    public function canCreateGroup(): bool
    {
        $max = Edition::maxGroups(Plugin::getInstance()->isPro());

        return $max === null || count($this->getAllGroups()) < $max;
    }

    public function saveGroup(LocationGroup $group, bool $runValidation = true): bool
    {
        $isNew = $group->id === null;

        if ($isNew && !$this->canCreateGroup()) {
            $group->addError('name', Craft::t('fold', 'Fold Lite supports one location group. Upgrade to Pro for more.'));

            return false;
        }

        if ($runValidation && !$group->validate()) {
            return false;
        }

        if ($isNew) {
            $group->uid = StringHelper::UUID();
            $group->sortOrder ??= (int)((new Query())->from(['{{%fold_locationgroups}}'])->max('[[sortOrder]]') ?? 0) + 1;
        } elseif (!$group->uid) {
            $group->uid = Db::uidById('{{%fold_locationgroups}}', $group->id);
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_GROUPS_KEY . '.' . $group->uid,
            $group->getConfig(),
            "Save the “{$group->handle}” location group",
        );

        if ($isNew) {
            $group->id = Db::idByUid('{{%fold_locationgroups}}', $group->uid);
        }

        $this->refresh();

        return true;
    }

    public function deleteGroupById(int $id): bool
    {
        $group = $this->getGroupById($id);

        if ($group === null) {
            return true;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_GROUPS_KEY . '.' . $group->uid,
            "Delete the “{$group->handle}” location group",
        );

        $this->refresh();

        return true;
    }

    /**
     * Project config: a group was added or changed.
     */
    public function handleChangedGroup(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;

        // Sites and fields have to exist before the site settings and field layout can reference
        // them — a config apply on a fresh environment arrives in an order nobody controls.
        ProjectConfigHelper::ensureAllSitesProcessed();
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $record = LocationGroupRecord::findOne(['uid' => $uid]) ?? new LocationGroupRecord();
            $record->uid = $uid;
            $record->name = $data['name'];
            $record->handle = $data['handle'];
            $record->color = $data['color'] ?? null;
            $record->marker = $data['marker'] ?? null;
            $record->defaultCountryCode = $data['defaultCountryCode'] ?? null;
            $record->sortOrder = $data['sortOrder'] ?? null;

            if (!empty($data['fieldLayouts']) && !empty($config = reset($data['fieldLayouts']))) {
                $layout = FieldLayout::createFromConfig($config);
                $layout->id = $record->fieldLayoutId;
                $layout->type = Location::class;
                $layout->uid = key($data['fieldLayouts']);
                Craft::$app->getFields()->saveLayout($layout, false);
                $record->fieldLayoutId = $layout->id;
            } elseif ($record->fieldLayoutId) {
                Craft::$app->getFields()->deleteLayoutById($record->fieldLayoutId);
                $record->fieldLayoutId = null;
            }

            $record->save(false);

            $this->applySiteSettings((int)$record->id, $data['siteSettings'] ?? []);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->refresh();
    }

    /**
     * Writes the per-site rows, and removes the ones the config no longer has.
     *
     * Dropping a site from a group leaves its locations with no presence there. Craft's own
     * resave handles emptying the element's site rows; what must not happen is the group site row
     * outliving the config, because `getSupportedSites()` reads it and would keep saving
     * locations to a site the group no longer claims.
     */
    private function applySiteSettings(int $groupId, array $siteSettings): void
    {
        $siteIdsByUid = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $siteIdsByUid[$site->uid] = $site->id;
        }

        $keptSiteIds = [];

        foreach ($siteSettings as $siteUid => $settings) {
            $siteId = $siteIdsByUid[$siteUid] ?? null;

            if ($siteId === null) {
                continue;
            }

            $record = LocationGroupSiteSettingsRecord::findOne(['groupId' => $groupId, 'siteId' => $siteId])
                ?? new LocationGroupSiteSettingsRecord();

            $record->groupId = $groupId;
            $record->siteId = $siteId;
            $record->enabledByDefault = (bool)($settings['enabledByDefault'] ?? true);
            $record->uriFormat = $settings['uriFormat'] ?? null;
            $record->template = $settings['template'] ?? null;
            $record->save(false);

            $keptSiteIds[] = $siteId;
        }

        $condition = ['groupId' => $groupId];

        if ($keptSiteIds !== []) {
            $condition = ['and', $condition, ['not', ['siteId' => $keptSiteIds]]];
        }

        Craft::$app->getDb()->createCommand()
            ->delete('{{%fold_locationgroups_sites}}', $condition)
            ->execute();
    }

    /**
     * Project config: a group was removed — its locations go with it.
     */
    public function handleDeletedGroup(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $record = LocationGroupRecord::findOne(['uid' => $uid]);

        if ($record === null) {
            return;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            // Deleted one at a time through the Elements service rather than by dropping rows, so
            // each location's address, search index entries and relations are cleaned up too.
            foreach (Location::find()->groupId($record->id)->status(null)->siteId('*')->unique()->all() as $location) {
                Craft::$app->getElements()->deleteElement($location, true);
            }

            if ($record->fieldLayoutId !== null) {
                Craft::$app->getFields()->deleteLayoutById((int)$record->fieldLayoutId);
            }

            $record->delete();
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $this->refresh();
    }

    /** @return array<string, mixed> */
    public function rebuildProjectConfig(): array
    {
        $config = [];

        foreach ($this->getAllGroups() as $group) {
            if ($group->uid !== null) {
                $config[$group->uid] = $group->getConfig();
            }
        }

        return $config;
    }

    private function createGroupFromRecord(LocationGroupRecord $record): LocationGroup
    {
        $group = new LocationGroup([
            'id' => (int)$record->id,
            'name' => $record->name,
            'handle' => $record->handle,
            'color' => $record->color,
            'marker' => $record->marker,
            'defaultCountryCode' => $record->defaultCountryCode,
            'fieldLayoutId' => $record->fieldLayoutId !== null ? (int)$record->fieldLayoutId : null,
            'sortOrder' => $record->sortOrder !== null ? (int)$record->sortOrder : null,
            'uid' => $record->uid,
        ]);

        $group->setSiteSettings($this->getGroupSiteSettings((int)$record->id));

        return $group;
    }
}
