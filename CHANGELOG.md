# Release Notes for Fold

## 5.1.0 - 2026-10-09
### Added

- GraphQL: `foldLocations`, `foldLocation` and `foldLocationCount`, with `near: { lat, lng, radius, unit }`,
  `openNow`, `group`, `groupId`, `hasCoordinates` and `inStockOf` (Pro + Commerce) arguments. Each
  location group is its own type (`retail_FoldLocation`) carrying its custom fields, and its own schema
  permission. Address, opening hours, `openNow`, `distance` and `directionsUrl` come back in the JSON
  endpoint's shape.
- GraphQL is held to the JSON endpoint's limits: the radius is clamped to the widest the site offers,
  and no more than `maxLimit` rows are returned.
- The Locations relation field is available in GraphQL, takes the same arguments, and only returns
  locations in groups the schema was granted.
- LocalBusiness structured data. Every location's own page gets schema.org JSON-LD in its `<head>` —
  address, coordinates, telephone, opening hours, and upcoming holiday hours from dated exceptions —
  unless SEOmatic is installed. Controlled by the new **Add LocalBusiness structured data to location
  pages** setting (`injectSchema`, on by default).
- A **Schema.org type** per location group (`Restaurant`, `AutoRepair`, `Store`…), used as the
  structured data's `@type`. Blank means `LocalBusiness`.
- `craft.fold.schema(location)` and `craft.fold.schemaData(location)`, and
  `Schema::EVENT_DEFINE_SCHEMA` for adding properties Fold has no field for.

## 5.0.0

Initial release.

### Locations

- `Location` element type with a per-group field layout, per-site URI formats and templates,
  the trash, element search and permissions.
- Addresses are `craft\elements\Address` elements — country-aware fields and native formatting,
  and the same kind of thing Commerce stores against an inventory location.
- Opening hours with overnight ranges, dated exceptions, an hours note, and "open now" answered
  in each location's own timezone.
- Location groups in project config, so groups and their field layouts version and deploy with
  the rest of the project.

### Searching

- Radius search: an indexed bounding-box prefilter plus a haversine sort, in one query, portable
  across MySQL and Postgres.
- Distance is populated on every result, in miles or kilometres.
- Geocoding drivers: Nominatim (default, keyless), Google, Mapbox, and none.
- Every geocode is cached, misses included, with a configurable lifetime.
- Terms that are not places fall back to matching location names.
- Public JSON endpoint at `/fold/search.json`.

### Front end

- `craft.fold.locator()` renders a complete server-rendered locator, enhanced by a zero-build
  JavaScript runtime.
- Leaflet is bundled rather than loaded from a CDN.
- Searches update the address bar with the same `q`/`lat`/`lng`/`radius` the server reads, so a
  result is shareable and back/forward step through searches.
- Pins take their location group's marker colour, on Leaflet, Google and Mapbox maps.
- Marker clustering on Leaflet maps (Pro), with Leaflet.markercluster bundled and loaded only when
  clustering is on.
- `craft.fold.locations` element query and `craft.fold.search()` for building your own.
- A Locations relation field type.

### Commerce

- Link a location to a Commerce inventory location; the address mirrors from Commerce.
- `inStockOf()` filters the locator to shops holding a purchasable, through Commerce's own
  inventory service.
- `fold/commerce/sync` creates or updates locations from Commerce's inventory locations.

### Tools

- CSV import, and CSV and JSON export, that round-trip — split shifts included — with generous
  column-name matching.
- Console commands for importing, exporting, geocoding and syncing.
- Search recording, with a report of the searches that found nothing.

### Security and privacy

- The public search endpoint is rate-limited per visitor, caps the term length, validates the
  country bias and clamps the radius, so an anonymous loop cannot run up a geocoding bill or
  force a full-table scan.
- Front-end geocoding has a per-visitor budget, and Nominatim is held to one request per second
  site-wide, not only in the queue.
- Separate browser and server Google keys. Every key setting accepts environment variables, and
  secret Mapbox tokens are refused because the token is sent to the browser.
- Stock searches report in or out of stock. Exact counts are opt-in, and purchasables resolve only
  when enabled and for sale.
- The search log rounds positions to about a kilometre, expires after 90 days by default, and has
  its own permission.
- CSV exports defuse spreadsheet formulas, and the importer reverses it.
- The Lite location cap is enforced on every save path, including Craft's Duplicate action.
