<?php

declare(strict_types=1);

namespace justinholtweb\fold\gql\arguments;

use Craft;
use craft\gql\base\ElementArguments;
use craft\gql\types\QueryArgument;
use GraphQL\Type\Definition\Type;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\gql\types\NearInput;
use justinholtweb\fold\Plugin;

/**
 * Arguments for `foldLocations`, `foldLocation`, `foldLocationCount` and Locations fields.
 */
class LocationArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), self::getContentArguments(), [
            'group' => [
                'name' => 'group',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results to locations in the given group handle(s).',
            ],
            'groupId' => [
                'name' => 'groupId',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the results to locations in the given group ID(s).',
            ],
            'near' => [
                'name' => 'near',
                'type' => NearInput::getType(),
                'description' => 'Locations within `radius` of a point, nearest first, each with its `distance` filled in.',
            ],
            'openNow' => [
                'name' => 'openNow',
                'type' => Type::boolean(),
                'description' => 'Only locations open right now, each in its own timezone. Filters the page that was fetched, so a page may come back short; `foldLocationCount` does not apply it.',
            ],
            'hasCoordinates' => [
                'name' => 'hasCoordinates',
                'type' => Type::boolean(),
                'description' => 'Only locations that are (or are not) on the map.',
            ],
            'inStockOf' => [
                'name' => 'inStockOf',
                'type' => Type::int(),
                'description' => 'Only locations whose linked Commerce inventory location has stock of this purchasable ID. Fold Pro with Commerce; ignored otherwise.',
            ],
        ]);
    }

    public static function getContentArguments(): array
    {
        return array_merge(
            parent::getContentArguments(),
            Craft::$app->getGql()->getContentArguments(Plugin::getInstance()->groups->getAllGroups(), Location::class),
        );
    }
}
