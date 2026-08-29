# Fold

A store locator for Craft CMS 5.

Fold gives a Craft site the thing every business with a front door eventually asks for: a map,
a search box, and a list of shops sorted by how far away they are. It is what
[WP Store Locator](https://wordpress.org/plugins/wp-store-locator/) is to WordPress, built the way
Craft does things — locations are real elements, with a field layout, per-site URLs, permissions,
search, relations and the trash.

And it can answer one question WP Store Locator cannot: **which shop near me actually has this in
stock**. Craft Commerce 5 knows where its inventory is; Fold asks it.

```twig
{{ craft.fold.locator({ group: 'retail' }) }}
```

That is a working locator — search box, results list, map — rendered server-side so it works with
JavaScript off, and enhanced by a small runtime when JavaScript is on.

---

## What you get

- **Locations are elements.** A field layout per group, a URL and template per site, drafts,
  revisions, the trash, element search, relations, and the element index with the columns a store
  list wants — address, coordinates, group, open-now.
- **Radius search that scales.** An indexed bounding box does the elimination and a haversine
  does the sorting, both inside one query. Portable across MySQL and Postgres — no spatial types,
  no PostGIS.
- **A map with no API key.** Leaflet and OpenStreetMap tiles by default, with Leaflet *bundled*
  rather than pulled from a CDN, so a default install makes no third-party request but the tile
  fetch. Google Maps and Mapbox are there when you want them.
- **Geocoding that respects the provider.** Nominatim by default, one request per second as its
  policy requires, every answer cached — misses included.
- **Opening hours that are actually correct.** Overnight ranges (`22:00–02:00` is open at
  midnight), dated exceptions for holidays, and "open now" answered in *the shop's* timezone
  rather than the server's.
- **Craft Commerce integration.** Bind a location to a Commerce inventory location: the address
  comes from Commerce, and the locator can filter to shops that have a purchasable in stock.
- **A JSON endpoint** at `/fold/search.json`, anonymous and cacheable, so you can build your own
  front end and still get the same results the built-in one gets.
- **CSV import and export** that round-trips, and reads the column names somebody else's system
  exported.

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later
- Craft Commerce 5.0 or later, for the Commerce features (optional)

## Installation

```sh
composer require justinholtweb/craft-fold
php craft plugin/install fold
```

## Editions

| | Lite (free) | Pro |
|---|---|---|
| Locations | up to 10 | unlimited |
| Location groups | 1 | unlimited |
| Map | Leaflet / OpenStreetMap | + Google Maps, Mapbox |
| Geocoding | Nominatim | + Google, Mapbox |
| Radius search, hours, store pages, JSON API | ✓ | ✓ |
| Commerce inventory link and stock filter | | ✓ |
| CSV / JSON import and export | | ✓ |
| Search recording | | ✓ |
| Marker clustering | | ✓ |

Lite is a complete locator for a small business, not a demo: everything that makes the locator
*work* is in it. Pro is for the problems that arrive with scale.

If a Pro licence lapses, Fold falls back to the free map and geocoding drivers rather than going
blank. Your settings are untouched, so renewing restores exactly what was there.

## Getting started

**1. Make a location group.** *Settings → Fold → Groups.* A group carries the field layout and,
per site, the URI format and template — so `stores/{slug}` gives every shop its own page.

**2. Add locations.** *Fold → Locations.* Type an address; Fold looks up the coordinates in the
background. Or type coordinates yourself, and Fold will leave them alone from then on.

**3. Put it on a page.**

```twig
{{ craft.fold.locator({ group: 'retail' }) }}
```

## Templating

### The ready-made locator

```twig
{{ craft.fold.locator({
    group: 'retail',
    radius: 25,
}) }}
```

To change the markup, copy `vendor/justinholtweb/craft-fold/src/templates/_locator/` into
`templates/fold/_locator/` and edit it there — Fold looks for the site's copy first.

### Element queries

`craft.fold.locations` is an ordinary element query, so everything you already know works:

```twig
{% set stores = craft.fold.locations
    .group('retail')
    .nearby({ lat: 35.2271, lng: -80.8431, radius: 25, unit: 'mi' })
    .limit(10)
    .all() %}

{% for store in stores %}
    <h3><a href="{{ store.url }}">{{ store.title }}</a></h3>
    <p>{{ store.distance|round(1) }} miles away</p>
    <p>{{ store.formattedAddress|nl2br }}</p>
    <p>{{ store.isOpenNow() ? 'Open now' : 'Closed' }}</p>
{% endfor %}
```

Omit `radius` to sort by distance without a limit — right for a chain whose shops are spread
thin, where the nearest is still the nearest at 300 miles.

### Searching by a place name

```twig
{% set results = craft.fold.search({
    q: craft.app.request.getParam('q'),
    radius: 25,
}) %}

{% if results.originNotFound %}
    <p>We couldn’t find that. Try a town, a postcode, or a full address.</p>
{% elseif results.isEmpty %}
    <p>No shops within 25 miles.</p>
{% else %}
    {% for store in results.locations %}…{% endfor %}
{% endif %}
```

`search()` geocodes the term, and if the term is not a place — people type "NoDa" and "the
airport one" into a locator box constantly — falls back to matching location names.

### Opening hours

```twig
{% for day, ranges in store.hours.week() %}
    <tr>
        <th>{{ day|capitalize }}</th>
        <td>
            {%- for range in ranges %}{{ range.open }}–{{ range.close }}{% if not loop.last %}, {% endif %}
            {%- else %}Closed{% endfor -%}
        </td>
    </tr>
{% endfor %}

{% if not store.isOpenNow() and store.nextOpeningAt %}
    <p>Opens {{ store.nextOpeningAt|datetime('short') }}</p>
{% endif %}
```

### Commerce: who has it in stock

```twig
{% set stockists = craft.fold.locations
    .nearby({ lat: lat, lng: lng, radius: 50 })
    .inStockOf(product.defaultVariant)
    .all() %}
```

Stock comes from Commerce's own `Inventory::getInventoryLevelsForPurchasable()` and uses
*available* quantity, not on-hand — so a locator never sends somebody to collect the last one
when it is already in a picking box with a stranger's name on it.

## The JSON endpoint

```
GET /fold/search.json?q=28202&radius=25
```

| Parameter | Meaning |
|---|---|
| `q` | A town, postcode, address, or a `lat,lng` pair |
| `lat`, `lng` | Coordinates, which skip geocoding entirely |
| `radius`, `unit` | `mi` or `km`; omit the radius for no limit |
| `limit`, `offset` | Paging, clamped to the configured maximum |
| `group` | Restrict to a location group's handle |
| `openNow` | Only shops open at the moment of the search |
| `inStockOf` | A purchasable's ID (Pro + Commerce) |
| `country` | Two-letter code to bias geocoding |

The response carries the locations, their distances, the geocoded origin, the map bounds, and —
importantly — `originNotFound`, so a front end can tell "we couldn't find that place" apart from
"there's nothing near it". They deserve different messages.

## Console commands

```sh
php craft fold/locations/groups                              # list groups
php craft fold/locations/import stores.csv --group=retail    # import (Pro)
php craft fold/locations/import stores.csv --dry-run         # parse and report only
php craft fold/locations/export --format=json --file=out.json
php craft fold/locations/geocode                             # geocode everything pending
php craft fold/locations/geocode --force
php craft fold/locations/queue-geocoding                     # hand it to the queue instead
php craft fold/commerce/sync --group=retail                  # create locations from Commerce
php craft fold/commerce/locations                            # what's linked to what
```

The importer reads the column names other systems export — `Store Name`, `address_1`, `ZIP`,
`Telephone`, and a column per weekday — and matches on slug or name, so running it twice updates
rather than duplicates.

## Settings

Everything is configurable in the CP, and overridable from `config/fold.php`:

```php
<?php
return [
    'mapDriver' => 'leaflet',
    'geocoderDriver' => 'nominatim',
    'distanceUnit' => 'mi',
    'defaultRadius' => 25,
    'defaultCountryCode' => 'US',
    'geocodeCacheDuration' => 2592000,
    'googleApiKey' => '$GOOGLE_MAPS_KEY',
];
```

A note on the default geocoder: Nominatim is run by volunteers and asks for no more than one
request per second and an identifying User-Agent. Fold does both, and caches every answer. A busy
site should still move to a paid provider — not because Fold will break, but because it is the
decent thing to do.

## Licence

Proprietary. See `LICENSE.md`.
