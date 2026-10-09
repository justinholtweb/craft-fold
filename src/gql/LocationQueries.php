<?php

declare(strict_types=1);

namespace justinholtweb\fold\gql;

use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\fold\gql\arguments\LocationArguments;
use justinholtweb\fold\gql\interfaces\LocationInterface;
use justinholtweb\fold\gql\resolvers\LocationResolver;

/**
 * The GraphQL queries Fold registers, prefixed `fold` so they cannot collide with anybody else's
 * `locations`.
 */
class LocationQueries
{
    /** @return array<string, array<string, mixed>> */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !self::canQueryAnyGroup()) {
            return [];
        }

        return [
            'foldLocations' => [
                'type' => Type::listOf(LocationInterface::getType()),
                'args' => LocationArguments::getArguments(),
                'resolve' => LocationResolver::class . '::resolve',
                'description' => 'This query is used to query for Fold locations.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
            'foldLocationCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => LocationArguments::getArguments(),
                'resolve' => LocationResolver::class . '::resolveCount',
                'description' => 'This query is used to return the number of Fold locations.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
            'foldLocation' => [
                'type' => LocationInterface::getType(),
                'args' => LocationArguments::getArguments(),
                'resolve' => LocationResolver::class . '::resolveOne',
                'description' => 'This query is used to query for a single Fold location.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
        ];
    }

    /** Whether the active schema can read at least one location group. */
    public static function canQueryAnyGroup(): bool
    {
        $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read');

        return !empty($pairs['foldLocationGroups']);
    }
}
