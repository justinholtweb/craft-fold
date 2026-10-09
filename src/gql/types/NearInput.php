<?php

declare(strict_types=1);

namespace justinholtweb\fold\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `near: { lat, lng, radius, unit }` — the origin of a radius search.
 */
class NearInput
{
    public static function getName(): string
    {
        return 'FoldNearInput';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new InputObjectType([
            'name' => self::getName(),
            'fields' => [
                'lat' => ['type' => Type::nonNull(Type::float()), 'description' => 'Latitude of the origin.'],
                'lng' => ['type' => Type::nonNull(Type::float()), 'description' => 'Longitude of the origin.'],
                'radius' => ['type' => Type::float(), 'description' => 'How far out to look. Clamped to the widest radius the site offers its own search box; omitted means the default radius.'],
                'unit' => ['type' => Type::string(), 'description' => '`mi` or `km`. Omitted means the plugin’s distance unit.'],
            ],
        ]));
    }
}
