<?php

declare(strict_types=1);

namespace justinholtweb\fold\gql\types;

use craft\gql\types\elements\Element;
use GraphQL\Type\Definition\ResolveInfo;
use justinholtweb\fold\elements\Location;
use justinholtweb\fold\gql\interfaces\LocationInterface;
use justinholtweb\fold\gql\resolvers\LocationResolver;

/**
 * The GraphQL object type for one location group's locations.
 */
class LocationType extends Element
{
    public function __construct(array $config)
    {
        $config['interfaces'] = [
            LocationInterface::getType(),
        ];

        parent::__construct($config);
    }

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var Location $source */
        return match ($resolveInfo->fieldName) {
            'groupHandle' => $source->getGroup()->handle,
            'color' => $source->getGroup()->getMarkerColor(),
            'distance' => $source->distance !== null ? round($source->distance, 2) : null,
            'address' => $source->getAddress(),
            'addressLines' => array_values(array_filter(array_map(
                'trim',
                explode("\n", $source->getFormattedAddress(['html' => false])),
            ))),
            'directionsUrl' => $source->getDirectionsUrl(),
            'timezone' => $source->getTimezone(),
            'hours' => $source->getHours(),
            'openNow' => $source->getHours()->isEmpty() ? null : $source->isOpenNow(),
            'nextOpeningAt' => $source->getNextOpeningAt(),
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }

    protected static function elementResolverClass(): ?string
    {
        return LocationResolver::class;
    }
}
