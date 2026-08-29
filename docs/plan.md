# Fold — plan

A store locator for Craft CMS 5. Package `justinholtweb/craft-fold`, namespace `justinholtweb\fold`,
handle `fold`.

The name is the pitch: **a map you unfold to find the nearest one**. WP Store Locator is the
benchmark — a store post type, a radius search, a map with a list beside it — and the thing it
cannot do, because WooCommerce has no inventory locations worth the name, is answer *"who near me
actually has it in stock"*. Craft Commerce 5 does have that, and Fold's differentiator is that it
asks.

## Decisions locked (2026-08-18)

1. **Editions: Lite (free) + Pro, $79 / $59 renewal** — priced with Stub. A store locator is a
   small-business ask; the money is in chains, Commerce, and import.
   Lite is a real, complete locator for a handful of shops: **10 locations, 1 location group**,
   Leaflet/OSM map, Nominatim geocoding, radius search, hours, Twig + JSON API.
   `models/Edition.php` is the single pure description of the boundary, as in Legs and Caffeine.
2. **Map and geocoding are drivers, and the default needs no API key.** Leaflet + OpenStreetMap
   tiles with Nominatim geocoding works on a fresh install with zero configuration; Google Maps
   and Mapbox are Pro drivers for sites that want Places autocomplete and vector tiles. Leaflet
   itself is **vendored**, not pulled from a CDN, so a default install makes no third-party
   request but the tile fetch.
3. **Commerce: link a Location to a Commerce `InventoryLocation`, and filter by stock.** Both
   directions, opt-in per location — a Location is standalone unless it names an inventory
   location. Linked, it can mirror Commerce's Address and answer
   `.hasStockOf(purchasable)`. Stock goes through `Inventory::getInventoryLevelsForPurchasable()`,
   never Commerce's transaction ledger, so Commerce owns its own arithmetic.
4. **`elements\Location` is a first-class element, grouped by `LocationGroup`**, plus a field type
   to attach locations to any element. Groups are project-config, carry the field layout and the
   per-site URI format, and are what makes "Retail Stores" and "Service Centers" different things.
5. **Addresses are `craft\elements\Address` elements**, owned by the Location — the same choice
   Commerce made for `InventoryLocation`. Country-aware fields, native formatting, and a linked
   Commerce location's address is then the *same kind of thing*, not a parallel schema.
6. **Radius search is a bounding box plus haversine in SQL**, not spatial types. MySQL's
   `ST_Distance_Sphere` and PostGIS are not the same feature and Craft supports both databases;
   an indexed `lat`/`lng` prefilter with a haversine `ORDER BY` is portable and fast to the tens
   of thousands of rows a store locator ever holds.

## Architecture

### Elements

`elements\Location` — the store. An element buys the index, search, permissions, relations,
revisions, drafts, the trash, and per-site enablement.

- **Localized.** A location exists on the sites its group is enabled for; custom fields translate
  through the field layout. The *physical* facts — address, coordinates, phone, hours — are
  shared across sites, because a shop does not move when you switch to the Spanish site.
- **Has URIs.** A group's per-site `uriFormat`/`template` gives every store a page
  (`/stores/{slug}`), which is what WP Store Locator's post type buys and what a settings screen
  cannot.
- `refHandle() = 'fold'`, so `{fold:downtown:link}` resolves in rich text.

`models\LocationGroup` — project config (`fold.locationGroups.<uid>`), like Owl's calendars:
name, handle, field layout, default country, marker set, and per-site settings
(`enabledByDefault`, `uriFormat`, `template`).

**Categories are Craft's, not Fold's.** "Amenities", "store type", "brands carried" are a
relation field in the group's field layout pointing at entries or categories. The search API
accepts `relatedTo`, so filtering by them costs Fold no taxonomy of its own.

### Database

- `{{%fold_locations}}` — `id` PK/FK→elements CASCADE, `groupId`, `addressId` FK→elements SET NULL,
  `lat`/`lng` decimal(10,7) (indexed together), `phone`, `email`, `websiteUrl`, `hours` JSON,
  `timezone`, `commerceInventoryLocationId`, `geocodeState`, `geocodeHash`, `geocodedAt`,
  `sortOrder`.
- `{{%fold_locationgroups}}` — mirror of the project-config group, so an element query can join a
  name without reading project config.
- `{{%fold_locationgroups_sites}}` — `uriFormat`, `template`, `enabledByDefault` per site.
- `{{%fold_geocode_cache}}` — `hash` (driver + normalised query), `lat`, `lng`, `formatted`,
  `dateCreated`. Front-end searches repeat *hard* ("28202" all day); an uncached locator is a
  bill and a rate limit.
- `{{%fold_searches}}` (Pro) — what people searched for, from where, and how many results came
  back. Zero results near a city you do not serve is the most actionable thing a locator knows.

`geocodeHash` is the point of the geocode columns: hash the address parts, and a save re-geocodes
only when the address actually changed. Editing a phone number must not cost a geocode.

### Services

- `locations` — CRUD, the element's authority
- `groups` — project config for location groups
- `geocoder` — driver front end + the cache + the rate limiter
- `search` — parse a query ("28202", "Charlotte NC", or a lat/lng pair), geocode it, run the
  query, shape the result
- `hours` — weekly schedule, exceptions, `isOpenAt()`, "opens at 9" in the store's own timezone
- `commerce` — inventory-location linking, address mirroring, stock filtering; inert without Commerce
- `importer` / `exporter` (Pro) — CSV and JSON

### Geo query

`elements\db\LocationQuery::nearby(['lat' => …, 'lng' => …, 'radius' => 25, 'unit' => 'mi'])`:

1. A bounding box on the indexed `lat`/`lng` — `lat ± r/111.045km`, `lng ± r/(111.045·cos φ)` —
   which the database can actually use an index for.
2. A haversine expression selected as `distance` and available to `ORDER BY`.
3. Antimeridian wrap handled with an `OR` on the longitude range, latitude clamped at the poles.
   A locator that loses Fiji is a bug nobody reports and everybody hits.

### Geocoding

`GeocoderInterface { geocode(string|Address): ?GeoPoint; reverse(float, float): ?array }`, with
`NominatimGeocoder` (default, keyless, 1 req/sec, a real User-Agent — Nominatim bans anonymous
floods and it is their right), `GoogleGeocoder`, `MapboxGeocoder`, and `NullGeocoder` for sites
that enter coordinates by hand.

Saving a location whose address changed pushes a `GeocodeLocationJob` rather than blocking the
save — an import of 400 stores must not be 400 sequential HTTP round trips inside one request.
The CP shows the pending state and offers *Geocode now* for the single-store case where waiting
for a queue runner is silly.

### Front end

- `craft.fold.locations` — an element query, so everything Craft can do to an element query works.
- `craft.fold.search(...)` — geocode-and-find, returning locations carrying `distance`.
- `fold/search` site action — the same thing as JSON, anonymous-allowed, for the JS runtime and
  for anyone building their own UI.
- `fold.js` / `fold.css` — a zero-build ES module: search box, results list, map, filters, and
  "use my location". Progressive enhancement over a server-rendered list, so the locator still
  works with JS off, which is also what makes it indexable.

## Phases

1. **Foundation** — skeleton, Location element, LocationGroup + project config, Install, records,
   CP index/edit, field layout, permissions, settings, Edition.
2. **Geo** — geocoder drivers, cache, queue job, `nearby()`, distance and units.
3. **Front end** — search service, JSON action, Twig variable, vendored Leaflet runtime, hours
   and open-now, default templates.
4. **Commerce** — inventory-location link, address mirroring, sync command, stock filter.
5. **Pro extras** — import/export, Google + Mapbox drivers, clustering, search analytics.
6. **Ship** — console commands, integration checks, README, CHANGELOG, icon, docs.
