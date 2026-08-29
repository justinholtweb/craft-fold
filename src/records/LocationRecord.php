<?php

declare(strict_types=1);

namespace justinholtweb\fold\records;

use craft\db\ActiveRecord;
use craft\records\Element;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $groupId
 * @property int|null $addressId
 * @property float|null $lat
 * @property float|null $lng
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $websiteUrl
 * @property string|null $hours
 * @property string|null $timezone
 * @property int|null $commerceInventoryLocationId
 * @property string $geocodeState
 * @property string|null $geocodeHash
 * @property string|null $geocodeError
 * @property string|null $geocodedAt
 * @property int|null $sortOrder
 */
class LocationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%fold_locations}}';
    }

    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }

    public function getGroup(): ActiveQueryInterface
    {
        return $this->hasOne(LocationGroupRecord::class, ['id' => 'groupId']);
    }
}
