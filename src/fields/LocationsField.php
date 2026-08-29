<?php

declare(strict_types=1);

namespace justinholtweb\fold\fields;

use Craft;
use craft\fields\BaseRelationField;
use justinholtweb\fold\elements\Location;

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
}
