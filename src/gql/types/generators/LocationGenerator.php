<?php

declare(strict_types=1);

namespace justinholtweb\fold\gql\types\generators;

use Craft;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\ObjectType;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\gql\interfaces\LocationInterface;
use justinholtweb\fold\gql\types\LocationType;
use justinholtweb\fold\models\LocationGroup;
use justinholtweb\fold\Plugin;

/**
 * One GraphQL type per location group — `retail_FoldLocation`, `serviceCentres_FoldLocation` —
 * because each group has its own field layout, and a type is what carries the custom fields.
 *
 * Only the groups the active schema may read get a type at all.
 */
class LocationGenerator extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    public static function generateTypes(mixed $context = null): array
    {
        $types = [];

        foreach (Plugin::getInstance()->groups->getAllGroups() as $group) {
            if (!GqlHelper::isSchemaAwareOf(Location::gqlScopesByContext($group))) {
                continue;
            }

            $type = static::generateType($group);
            $types[$type->name] = $type;
        }

        return $types;
    }

    public static function generateType(mixed $context): ObjectType
    {
        /** @var LocationGroup $context */
        $typeName = Location::gqlTypeName($context);

        return GqlEntityRegistry::getOrCreate($typeName, fn() => new LocationType([
            'name' => $typeName,
            'fields' => function() use ($context, $typeName) {
                return Craft::$app->getGql()->prepareFieldDefinitions(
                    array_merge(LocationInterface::getFieldDefinitions(), self::getContentFields($context)),
                    $typeName,
                );
            },
        ]));
    }
}
