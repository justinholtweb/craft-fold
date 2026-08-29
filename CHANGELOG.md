# Release Notes for Fold

## 5.0.0

Initial release.

### Locations

- `Location` element type with a per-group field layout, per-site URI formats and templates,
  drafts, revisions, the trash, element search and permissions.
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
- `craft.fold.locations` element query and `craft.fold.search()` for building your own.
- A Locations relation field type.

### Commerce

- Link a location to a Commerce inventory location; the address mirrors from Commerce.
- `inStockOf()` filters the locator to shops holding a purchasable, through Commerce's own
  inventory service.
- `fold/commerce/sync` creates or updates locations from Commerce's inventory locations.

### Tools

- CSV and JSON import and export that round-trip, with generous column-name matching.
- Console commands for importing, exporting, geocoding and syncing.
- Search recording, with a report of the searches that found nothing.
