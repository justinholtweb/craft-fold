<?php

declare(strict_types=1);

namespace justinholtweb\fold\records;

use craft\db\ActiveRecord;
use craft\records\Site;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $groupId
 * @property int $siteId
 * @property bool $enabledByDefault
 * @property string|null $uriFormat
 * @property string|null $template
 */
class LocationGroupSiteSettingsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%fold_locationgroups_sites}}';
    }

    public function getGroup(): ActiveQueryInterface
    {
        return $this->hasOne(LocationGroupRecord::class, ['id' => 'groupId']);
    }

    public function getSite(): ActiveQueryInterface
    {
        return $this->hasOne(Site::class, ['id' => 'siteId']);
    }
}
