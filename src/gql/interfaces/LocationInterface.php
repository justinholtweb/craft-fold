<?php

declare(strict_types=1);

namespace justinholtweb\fold\gql\interfaces;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use justinholtweb\fold\gql\types\AddressType;
use justinholtweb\fold\gql\types\generators\LocationGenerator;
use justinholtweb\fold\gql\types\HoursType;

/**
 * The GraphQL interface every Fold location implements, whatever its group.
 *
 * Carries the same facts as the JSON endpoint, under the same names, so a front end can move from
 * `/fold/search.json` to GraphQL without renaming anything.
 */
class LocationInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return LocationGenerator::class;
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all Fold locations.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        LocationGenerator::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'FoldLocationInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
            'url' => [
                'name' => 'url',
                'type' => Type::string(),
                'description' => 'The location’s own page, if its group gives it one on this site.',
            ],
            'groupId' => [
                'name' => 'groupId',
                'type' => Type::nonNull(Type::int()),
                'description' => 'The ID of the location group.',
            ],
            'groupHandle' => [
                'name' => 'groupHandle',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The handle of the location group.',
            ],
            'color' => [
                'name' => 'color',
                'type' => Type::string(),
                'description' => 'The group’s marker colour as `#rrggbb`, or null for the map’s default.',
            ],
            'lat' => [
                'name' => 'lat',
                'type' => Type::float(),
                'description' => 'Latitude.',
            ],
            'lng' => [
                'name' => 'lng',
                'type' => Type::float(),
                'description' => 'Longitude.',
            ],
            'distance' => [
                'name' => 'distance',
                'type' => Type::float(),
                'description' => 'Distance from the `near` origin, in its unit. Null when the query had no origin.',
            ],
            'address' => [
                'name' => 'address',
                'type' => AddressType::getType(),
                'description' => 'The postal address.',
            ],
            'addressLines' => [
                'name' => 'addressLines',
                'type' => Type::listOf(Type::string()),
                'description' => 'The address formatted for its country, one line per item.',
            ],
            'phone' => [
                'name' => 'phone',
                'type' => Type::string(),
                'description' => 'Phone number.',
            ],
            'email' => [
                'name' => 'email',
                'type' => Type::string(),
                'description' => 'Email address.',
            ],
            'websiteUrl' => [
                'name' => 'websiteUrl',
                'type' => Type::string(),
                'description' => 'The location’s own website.',
            ],
            'directionsUrl' => [
                'name' => 'directionsUrl',
                'type' => Type::string(),
                'description' => 'A link that opens directions to the location.',
            ],
            'timezone' => [
                'name' => 'timezone',
                'type' => Type::string(),
                'description' => 'The timezone the opening hours are in.',
            ],
            'hours' => [
                'name' => 'hours',
                'type' => HoursType::getType(),
                'description' => 'Opening hours: the week, dated exceptions and a note.',
            ],
            'openNow' => [
                'name' => 'openNow',
                'type' => Type::boolean(),
                'description' => 'Whether the location is open right now, in its own timezone. Null when it has no hours.',
            ],
            'nextOpeningAt' => [
                'name' => 'nextOpeningAt',
                'type' => DateTime::getType(),
                'description' => 'When the location next opens, within the next fortnight.',
            ],
        ]), self::getName());
    }
}
