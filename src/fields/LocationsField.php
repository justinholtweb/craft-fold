<?php

declare(strict_types=1);

namespace justinholtweb\fold\fields;

use Craft;
use craft\fields\BaseRelationField;
use craft\helpers\Gql as GqlHelper;
use craft\models\GqlSchema;
use craft\services\Gql as GqlService;
use GraphQL\Type\Definition\Type;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\gql\arguments\LocationArguments;
use justinholtweb\fold\gql\interfaces\LocationInterface;
use justinholtweb\fold\gql\resolvers\LocationResolver;
use justinholtweb\fold\Plugin;

/**
 * A Locations relation field.
 *
 * `BaseRelationField` rather than anything bespoke: it gives the element selector, the eager
 * loading, GraphQL, the element index modal and the relations table for free, and it means a
 * Fold location relates to an entry exactly the way an entry relates to an entry — which is what
 * a Craft developer will expect without reading anything.
 *
 * The field is how a site says "this event happens at these shops" or "this brand is stocked
 * here", without Fold having to invent a taxonomy for it.
 */
class LocationsField extends BaseRelationField
{
    public static function displayName(): string
    {
        return Craft::t('fold', 'Locations');
    }

    public static function icon(): string
    {
        return 'location-dot';
    }

    public static function elementType(): string
    {
        return Location::class;
    }

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('fold', 'Add a location');
    }

    public static function phpType(): string
    {
        return sprintf('\\%s|\\%s<\\%s>', \craft\elements\db\ElementQueryInterface::class, \craft\elements\ElementCollection::class, Location::class);
    }

    /** Shown in a schema only when that schema can read at least one location group. */
    public function includeInGqlSchema(GqlSchema $schema): bool
    {
        return !empty($schema->getAllScopePairsForAction('read')['foldLocationGroups']);
    }

    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => Type::nonNull(Type::listOf(LocationInterface::getType())),
            'args' => LocationArguments::getArguments(),
            'resolve' => LocationResolver::class . '::resolve',
            'complexity' => GqlHelper::relatedArgumentComplexity(GqlService::GRAPHQL_COMPLEXITY_EAGER_LOAD),
        ];
    }

    /**
     * Eager-loaded relations are held to the schema's groups too — otherwise a Locations field
     * on an entry would be a way round a schema that was never given the group.
     */
    public function getEagerLoadingGqlConditions(): ?array
    {
        $uids = GqlHelper::extractAllowedEntitiesFromSchema()['foldLocationGroups'] ?? [];

        if (!is_array($uids) || $uids === []) {
            return null;
        }

        $groups = Plugin::getInstance()->groups;
        $ids = array_values(array_filter(array_map(
            static fn(string $uid) => $groups->getGroupByUid($uid)?->id,
            $uids,
        )));

        // Null is Craft's "return nothing"; an empty list would be no condition at all.
        return $ids !== [] ? ['groupId' => $ids] : null;
    }
}
