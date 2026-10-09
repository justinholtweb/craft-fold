<?php

declare(strict_types=1);

namespace justinholtweb\fold\events;

use justinholtweb\fold\elements\Location;
use yii\base\Event;

/**
 * Fired once a location's LocalBusiness structured data is built, before it is encoded.
 */
class DefineSchemaEvent extends Event
{
    /** The location being described. */
    public Location $location;

    /** @var array<string, mixed> The JSON-LD, as an array. Change it to change what is printed. */
    public array $schema = [];
}
