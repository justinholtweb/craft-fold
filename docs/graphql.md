---
title: GraphQL
slug: graphql
order: 77
summary: foldLocations, foldLocation and foldLocationCount — radius search, open-now and group filters over Craft's GraphQL API, with a schema permission per location group.
---

Fold adds its locations to Craft's GraphQL API, so a headless front end can run the same radius
search as the [JSON endpoint](json-endpoint) without a second request shape. Available in Lite and Pro.

```graphql
{
  foldLocations(near: { lat: 35.2271, lng: -80.8431, radius: 25 }, group: "retail", limit: 10) {
    title
    url
    distance
    address { addressLine1 locality administrativeArea postalCode }
    phone
    openNow
    hours { week { day ranges { open close } } exceptions { date ranges { open close } } }
  }
}
```

## Schema permissions

Nothing is exposed until a schema is given it. Each location group is its own permission, under
**Fold locations** on the schema's edit screen in *GraphQL → Schemas*:

> Query for locations in the "Retail Stores" location group

A schema sees only the groups it was granted — `group:` and `id:` cannot reach round it, and a
Locations relation field on an entry only returns locations in granted groups. A schema with no
Fold groups has no `foldLocations` query at all.

Disabled locations follow Craft's own rule: the `status` argument is only offered to a schema
that has been allowed inactive elements.

## Queries

| Query | Returns |
| --- | --- |
| `foldLocations` | A list of locations |
| `foldLocation` | One location, or null |
| `foldLocationCount` | How many locations match |

Each group gets its own type, named for its handle — `retail_FoldLocation` — carrying that
group's custom fields. Every type implements `FoldLocationInterface`, so a query across groups
can use inline fragments for the fields that only some groups have:

```graphql
{
  foldLocations(near: { lat: 35.2271, lng: -80.8431 }) {
    title
    ... on retail_FoldLocation { storeManager }
  }
}
```

## Arguments

Everything Craft's element queries accept (`id`, `slug`, `search`, `limit`, `offset`, `orderBy`,
`relatedTo`, `site`…), the groups' custom fields, and:

| Argument | Meaning |
| --- | --- |
| `group` | Group handle(s) |
| `groupId` | Group ID(s) |
| `near` | `{ lat, lng, radius, unit }` — locations within `radius` of the point, nearest first, with `distance` filled in |
| `openNow` | Only locations open right now, each in its own timezone |
| `hasCoordinates` | Only locations that are (or are not) on the map |
| `inStockOf` | A purchasable ID: only locations with it in stock. Pro with Commerce; ignored otherwise |

GraphQL is a public door, so it is held to the same limits as the JSON endpoint:

- **`radius` is clamped** to the widest radius the site's own search box offers (100 miles by
  default). Zero, negative or missing is the default radius, not "everywhere" — an unbounded
  distance sort over every row is the one query the bounding box exists to prevent. For an
  unlimited search, use `craft.fold.locations.nearby()` from Twig, where the template is trusted.
- **At most `maxLimit` rows** (200 by default) come back, however large a `limit` is asked for,
  and Craft's own `maxGraphqlResults` still applies on top.
- **An origin of `0, 0`** returns nothing rather than everything — it is what a failed geocode
  looks like, not a place anybody is searching from.

`near` takes coordinates, not a place name. Geocoding is a request to a paid or rate-limited
provider, and GraphQL is not the place to spend it; geocode in the front end (or call the
[JSON endpoint](json-endpoint) with `q`) and pass the coordinates.

`openNow` is applied to the page that was fetched, as it is on the JSON endpoint, so a page of 25
can come back with fewer on it; `foldLocationCount` does not apply it.

## Fields

Every element field Craft provides (`id`, `title`, `slug`, `uri`, `siteId`, `dateUpdated`…), each
group's custom fields, and:

| Field | Type | Notes |
| --- | --- | --- |
| `url` | String | The location's own page, if its group gives it one on the site |
| `groupId`, `groupHandle` | Int, String | |
| `color` | String | The group's marker colour, `#rrggbb`, or null |
| `lat`, `lng` | Float | |
| `distance` | Float | From the `near` origin, in its unit; null without one |
| `address` | `FoldAddress` | `addressLine1`–`3`, `locality`, `dependentLocality`, `administrativeArea`, `postalCode`, `sortingCode`, `countryCode`, `organization`, `formatted` |
| `addressLines` | [String] | The address formatted for its country, one line each |
| `phone`, `email`, `websiteUrl` | String | |
| `directionsUrl` | String | Opens directions in the visitor's maps app |
| `timezone` | String | The timezone the hours are in |
| `hours` | `FoldOpeningHours` | `note`, `week` (seven `{ day, ranges }`, Monday first) and `exceptions` (`{ date, ranges }`; no ranges means closed) |
| `openNow` | Boolean | Null when the location has no hours |
| `nextOpeningAt` | DateTime | Within the next fortnight |

Fold's own address type is used rather than Craft's `AddressInterface`, so reading a shop's street
does not need the schema to be granted every address on the site.

## The Locations field

A [Locations relation field](location-groups#relating-content-to-locations) appears in GraphQL as
a list of `FoldLocationInterface`, takes the same arguments — including `near` — and is
eager-loaded like any relation field:

```graphql
{
  entries(section: "events") {
    title
    ... on event_Entry {
      venues(near: { lat: 35.2271, lng: -80.8431 }) { title distance }
    }
  }
}
```
