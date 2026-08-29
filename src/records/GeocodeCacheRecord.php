<?php

declare(strict_types=1);

namespace justinholtweb\fold\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $hash
 * @property string $driver
 * @property string $query
 * @property float|null $lat
 * @property float|null $lng
 * @property string|null $formatted
 * @property string|null $bounds
 * @property bool $found
 * @property string $expiryDate
 */
class GeocodeCacheRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%fold_geocodecache}}';
    }
}
