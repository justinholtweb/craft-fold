---
title: Twig
slug: twig
order: 60
summary: craft.fold, the location query and its parameters, search results, and everything a location exposes.
---

Everything under `craft.fold` hands back either an element query or a service result, so a
template is never limited to what Fold thought of.

## `craft.fold`

| | Returns |
| --- | --- |
| `craft.fold.locations` | A location element query |
| `craft.fold.search({ … })` | A search result — see below |
| `craft.fold.locator({ … })` | The ready-made locator markup — see [The front-end locator](front-end) |
| `craft.fold.groups` | Every location group |
| `craft.fold.group('retail')` | One group by handle, or `null` |
| `craft.fold.settings` | The plugin settings |
| `craft.fold.distance(lat1, lng1, lat2, lng2, unit)` | Great-circle distance between two points; `unit` defaults to the **Distance unit** setting |
| `craft.fold.mapConfig({ … })` | The JSON the front-end runtime reads, for building your own locator markup |
| `craft.fold.schema(location, { … })` | LocalBusiness JSON-LD as a `<script>` tag — see [Structured data](structured-data) |
| `craft.fold.schemaData(location, { … })` | The same structured data as an array |

## Location queries

`craft.fold.locations` is an ordinary element query. Everything you already know — `limit`,
`relatedTo`, `search`, `with`, `orderBy`, `site` — works, plus these:

| Parameter | Meaning |
| --- | --- |
| `group('retail')` | A group handle, an ID, an array of either, or a group model. An unknown handle matches nothing, not everything. |
| `groupId(3)` | By ID |
| `nearby({ lat, lng, radius, unit })` | Within `radius` of a point, nearest first. Omit `radius` for no distance limit. `latitude`/`longitude` are accepted as well as `lat`/`lng`. |
| `hasCoordinates(true)` | Only locations on the map (`false` for only those that are not) |
| `geocodeState('failed')` | `pending`, `ok`, `failed` or `manual` |
| `inStockOf(variant, qty)` | Only locations with at least `qty` (default 1) available — Pro + Commerce, see [Commerce stock](commerce-stock) |
| `linkedToCommerce(true)` | Locations linked to a Commerce inventory location (`false` for unlinked) |
| `commerceInventoryLocationId(5)` | Linked to a particular inventory location |

`nearby()` sorts by distance only if you have not set an order yourself. An explicit `orderBy` wins.

```twig
{% set stores = craft.fold.locations
    .group('retail')
    .nearby({ lat: 35.2271, lng: -80.8431, radius: 25, unit: 'mi' })
    .limit(10)
    .all() %}

{% for store in stores %}
    <h3><a href="{{ store.url }}">{{ store.title }}</a></h3>
    <p>{{ store.distance|round(1) }} miles away</p>
    <p>{{ store.getFormattedAddress({ html: false })|nl2br }}</p>
    <p>{{ store.isOpenNow() ? 'Open now' : 'Closed' }}</p>
{% endfor %}
```

## Searching by a place name

`craft.fold.search()` geocodes a term and runs the whole [search](search-and-radius): radius, the
name fallback, paging, open-now.

```twig
{% set results = craft.fold.search({
    q: craft.app.request.getParam('q'),
    radius: 25,
    group: 'retail',
}) %}

{% if results.originNotFound %}
    <p>We couldn’t find that. Try a town, a postcode, or a full address.</p>
{% elseif results.isEmpty %}
    <p>No shops within {{ results.radius }} {{ results.unit }}.</p>
{% else %}
    {% for store in results.locations %}…{% endfor %}
{% endif %}
```

### Parameters

| Parameter | Meaning |
| --- | --- |
| `q` (or `term`) | A town, postcode, address, or a `lat,lng` pair |
| `lat`, `lng` | Coordinates; skip geocoding entirely and win over `q` |
| `radius` | Defaults to **Default radius**; `0` for no limit (a template is trusted — public query strings are clamped, see [JSON endpoint](json-endpoint)) |
| `unit` | `mi` or `km` |
| `limit`, `offset` | Paging. `limit` defaults to **Results per search** and is clamped to **Maximum results**. |
| `group` / `groupId` | Restrict to a group |
| `relatedTo` | Passed to the element query — how a category or brand filter works |
| `search` | An additional element-search filter |
| `openNow` | Only locations open at this moment, in their own timezones |
| `inStockOf`, `inStockQty` | A purchasable (or its ID) and a minimum available quantity — Pro + Commerce |
| `countryCode` | Bias geocoding to a country; defaults to **Default country** |
| `siteId` | Search another site's locations |

### The result

| | |
| --- | --- |
| `locations` | The page of locations, each with `distance` set when there was an origin |
| `total` | How many matched, before paging (and before `openNow`) |
| `origin` | Where the search was measured from: `lat`, `lng`, and the provider's `formatted` name for it |
| `term`, `radius`, `unit` | What was searched |
| `originNotFound` | The term could not be placed and matched no names — the visitor's mistake |
| `matchedByName` | The term was not a place but matched a location's name — a success |
| `isEmpty` | No locations on this page |
| `nearest()` | The first location, or `null` |
| `bounds()` | `[south, west, north, east]` around the results and the origin — the order Leaflet and Google both take |

## A location

| | |
| --- | --- |
| `title`, `slug`, `url`, custom fields | As on any element |
| `group` | The location group |
| `address` | The `craft\elements\Address`, or `null` |
| `getFormattedAddress({ html: false })` | The address as the country writes it |
| `lat`, `lng`, `coordinates`, `hasCoordinates()` | Where it is |
| `distance` | From the search origin, in the search's unit — set by `nearby()` and `search()`, otherwise `null` |
| `distanceTo(lat, lng, unit)` | Distance to any point, for a location the query did not measure |
| `phone`, `email`, `websiteUrl` | Contact details |
| `directionsUrl` / `getDirectionsUrl(from)` | A Google Maps directions link to the location; pass a starting point to fill in the origin |
| `hours` | The opening hours — below |
| `isOpenNow()`, `isOpenAt(date)` | In the location's own timezone |
| `nextOpeningAt` | The next time it opens, searched up to two weeks ahead, or `null` |
| `timezone` | The location's timezone, falling back to the site's |
| `geocodeState` | `pending`, `ok`, `failed` or `manual` |
| `isLinkedToCommerce`, `inventoryLocation` | The Commerce link, if any |

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

{% if store.hours.note %}<p>{{ store.hours.note }}</p>{% endif %}

{% if not store.isOpenNow() and store.nextOpeningAt %}
    <p>Opens {{ store.nextOpeningAt|datetime('short') }}</p>
{% endif %}
```

`week()` is keyed `mon` to `sun`; each day is a list of `{ open, close }` in `HH:MM`. Also available:
`forDay('sat')`, `forDate(date)` (which applies a dated exception if there is one), `exceptions()`,
and `isEmpty()` for a location with no hours at all.
