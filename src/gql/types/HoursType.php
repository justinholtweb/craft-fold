<?php

declare(strict_types=1);

namespace justinholtweb\fold\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\fold\models\OpeningHours;

/**
 * Opening hours as GraphQL types.
 *
 * Lists rather than an object keyed by day or date: GraphQL has no map type, and a list of
 * `{ day, ranges }` is what a template iterates anyway.
 */
class HoursType
{
    public static function getName(): string
    {
        return 'FoldOpeningHours';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A Fold location’s opening hours.',
            'fields' => fn() => [
                'note' => [
                    'type' => Type::string(),
                    'description' => 'A free-text note shown instead of, or beside, the hours.',
                    'resolve' => static fn(OpeningHours $hours) => $hours->note,
                ],
                'week' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::dayType()))),
                    'description' => 'Every day of the week, Monday first. A day with no ranges is closed.',
                    'resolve' => static function(OpeningHours $hours) {
                        $days = [];

                        foreach ($hours->week() as $day => $ranges) {
                            $days[] = ['day' => $day, 'ranges' => $ranges];
                        }

                        return $days;
                    },
                ],
                'exceptions' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(self::exceptionType()))),
                    'description' => 'Dated exceptions — holidays, short days — in date order. No ranges means closed that day.',
                    'resolve' => static function(OpeningHours $hours) {
                        $out = [];

                        foreach ($hours->exceptions() as $date => $ranges) {
                            $out[] = ['date' => $date, 'ranges' => $ranges];
                        }

                        return $out;
                    },
                ],
            ],
        ]));
    }

    public static function rangeType(): Type
    {
        return GqlEntityRegistry::getOrCreate('FoldTimeRange', fn() => new ObjectType([
            'name' => 'FoldTimeRange',
            'description' => 'An opening range in 24-hour `HH:MM`. A close before the open runs past midnight.',
            'fields' => [
                'open' => ['type' => Type::nonNull(Type::string())],
                'close' => ['type' => Type::nonNull(Type::string())],
            ],
        ]));
    }

    public static function dayType(): Type
    {
        return GqlEntityRegistry::getOrCreate('FoldOpeningDay', fn() => new ObjectType([
            'name' => 'FoldOpeningDay',
            'fields' => fn() => [
                'day' => ['type' => Type::nonNull(Type::string()), 'description' => '`mon` to `sun`.'],
                'ranges' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(self::rangeType())))],
            ],
        ]));
    }

    public static function exceptionType(): Type
    {
        return GqlEntityRegistry::getOrCreate('FoldOpeningException', fn() => new ObjectType([
            'name' => 'FoldOpeningException',
            'fields' => fn() => [
                'date' => ['type' => Type::nonNull(Type::string()), 'description' => '`YYYY-MM-DD`.'],
                'ranges' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(self::rangeType())))],
            ],
        ]));
    }
}
