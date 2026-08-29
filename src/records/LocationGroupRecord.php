<?php

declare(strict_types=1);

namespace justinholtweb\fold\records;

use craft\db\ActiveRecord;
use craft\records\FieldLayout;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $color
 * @property string|null $marker
 * @property string|null $defaultCountryCode
 * @property int|null $fieldLayoutId
 * @property int|null $sortOrder
 */
class LocationGroupRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%fold_locationgroups}}';
    }

    public function getFieldLayout(): ActiveQueryInterface
    {
        return $this->hasOne(FieldLayout::class, ['id' => 'fieldLayoutId']);
    }
}
