<?php

declare(strict_types=1);

namespace justinholtweb\fold\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int|null $siteId
 * @property string $term
 * @property float|null $lat
 * @property float|null $lng
 * @property float|null $radius
 * @property string|null $unit
 * @property int $resultCount
 * @property int|null $nearestLocationId
 * @property float|null $nearestDistance
 */
class SearchRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%fold_searches}}';
    }
}
