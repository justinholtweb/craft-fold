<?php

declare(strict_types=1);

namespace justinholtweb\fold\gql\types;

use Craft;
use craft\elements\Address;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A location's postal address, as plain fields.
 *
 * Its own small type rather than Craft's `AddressInterface`, so reading a shop's street does not
 * need the schema to be granted every address on the site — user addresses included.
 */
class AddressType
{
    public static function getName(): string
    {
        return 'FoldAddress';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A Fold location’s postal address.',
            'fields' => [
                'countryCode' => ['type' => Type::string(), 'description' => 'Two-letter country code.'],
                'administrativeArea' => ['type' => Type::string(), 'description' => 'State, province or region.'],
                'locality' => ['type' => Type::string(), 'description' => 'City or town.'],
                'dependentLocality' => ['type' => Type::string(), 'description' => 'Neighbourhood or suburb.'],
                'postalCode' => ['type' => Type::string(), 'description' => 'Postal or ZIP code.'],
                'sortingCode' => ['type' => Type::string(), 'description' => 'Sorting code, where the country uses one.'],
                'addressLine1' => ['type' => Type::string(), 'description' => 'First address line.'],
                'addressLine2' => ['type' => Type::string(), 'description' => 'Second address line.'],
                'addressLine3' => ['type' => Type::string(), 'description' => 'Third address line.'],
                'organization' => ['type' => Type::string(), 'description' => 'Organisation name on the address.'],
                'formatted' => [
                    'type' => Type::string(),
                    'description' => 'The whole address formatted for its country, lines separated by newlines.',
                    'resolve' => static fn(Address $address) => Craft::$app->getAddresses()->formatAddress($address, ['html' => false]),
                ],
            ],
        ]));
    }
}
