# Fold — Craft CMS 5 Plugin

## Project Overview

Fold is a store locator for Craft CMS 5 — mapped, searchable store locations with opening hours,
radius search, and Craft Commerce inventory awareness. WP Store Locator is the benchmark; the
thing it cannot do, and Fold can, is answer *"which shop near me has this in stock"*.

Distributed as `justinholtweb/craft-fold`. Lite (free) + Pro ($79 / $59 renewal).

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- **No build step anywhere.** The front-end runtime is a plain script and plain CSS; Leaflet is
  vendored under `src/web/assets/locator/dist/vendor/leaflet/`.
- Craft Commerce 5 is a **soft** dependency — `suggest`, never `require`.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\fold`
- Package: `justinholtweb/craft-fold`
- Handle: `fold`

### The load-bearing idea: a bounding box, then a haversine

A haversine is a function of two columns, so no index can answer it, so asking the database to
evaluate one per row is a table scan that gets slower with every shop opened. `LocationQuery::nearby()`
therefore does two passes inside one query: an indexed `lat`/`lng` **bounding box** throws away
everything obviously too far, and the **haversine** then runs only on the survivors — as both the
real distance filter (the box is square; its corners are outside the circle) and the sort.

No spatial types: MySQL's `ST_Distance_Sphere` and PostGIS are not the same feature, and Craft
supports both databases.

**The distance expression is selected on the *sub*-query, not just the outer one.** Craft applies
`limit`, `offset` *and* `orderBy` to the sub-query, so a distance computed only on the outer query
sorts the page *after* the database has already chosen which rows the page is. That failure is
quiet and looks like "the results are nearly right". There is a check for it.

### Data model

- `elements\Location` — the shop. **Localized**; per-site presence comes from its group's site
  settings. The *physical* facts (address, coordinates, phone, hours) live once and are shared
  across sites — a shop does not move when you switch site. The *words* are Craft's per-site
  content.
- `models\LocationGroup` — project config (`fold.locationGroups.<uid>`), carrying the field layout
  and per-site `uriFormat`/`template`. Groups are why "Retail Stores" and "Service Centres" can be
  different things with different fields and different URLs.
- Addresses are `craft\elements\Address` elements, owned by the location via `addressId` — the
  same choice Commerce made for `InventoryLocation`, which is what makes mirroring a copy rather
  than a translation.
- `{{%fold_locations}}` — `lat`/`lng` `decimal(10,7)` indexed together, `hours` as one JSON
  document, `geocodeState`/`geocodeHash`/`geocodedAt`, `commerceInventoryLocationId`.
- `{{%fold_geocodecache}}` — every geocode, **misses included**.
- `{{%fold_searches}}` — the Pro search log; the report that matters is the zero-result one.

`geocodeHash` is the point of the geocode columns: hash the address, and a save re-geocodes only
when the address actually changed. Editing a phone number must not cost a geocoder request.

### Services

- `locations` — the edition cap, and queueing a geocode after a save
- `groups` — project config for location groups
- `geocoder` — driver selection, the cache, and what "not found" means
- `search` — parse, geocode, query, shape; the *one* implementation the Twig variable, the JSON
  endpoint and the console all use
- `commerce` — inventory-location linking and stock filtering; completely inert without Commerce
- `importer` / `exporter` — CSV and JSON, round-tripping

## Traps found while building this

- **Yii's inline validators default to `skipOnEmpty = true`.** A rule whose whole job is to reject
  an empty value is therefore skipped exactly when it is needed — `LocationGroup`'s "at least one
  site" rule silently passed until it was given `'skipOnEmpty' => false`.
- **Twig's `{% namespace %}` breaks Craft's address fields.** `Cp::addressFieldsHtml()` includes
  selectize-enhanced subdivision selects whose init JS is registered with `id|namespaceInputId`,
  resolved against the *View's* namespace at render time. Wrapping the output in `{% namespace %}`
  rewrites the HTML ids after that JS has bound to the old ones, so selectize attaches to nothing
  and the State field renders as a label with an empty space under it. Namespace through
  `View::namespaceInputs()` instead.
- **`.status` is a dot, not a text container.** Craft styles it as a small fixed-size circle, so a
  label placed *inside* it wraps one letter per line. Emit the dot and the label as siblings, as
  Craft's own element types do.
- **`Query::count()` returns a string.** Every `count() === 0` in a test needs a cast.
- **`instanceof` against a missing class is safe; a type declaration is not.** Nothing in Fold
  type-hints a Commerce class, because a declaration referencing a class that may not be installed
  is a fatal at load time on every site that does not have it. `LocationQuery::$inStockOf` is
  `mixed` for this reason alone.
- **`0,0` is what a failed geocode decodes to**, and it is a real place in the Gulf of Guinea.
  `GeoPoint::isValid()` rejects it, or every unplaceable shop ends up on Null Island.
- **Nominatim will time out under a burst.** The front-end search path is uncached on first ask,
  and a handful of quick requests gets 6-second timeouts. The cache is the real defence; the
  name-match fallback is what stops a timeout from looking like "no shops near you".
- **An unbiased geocoder is worse than a wrong one.** "NoDa" resolves to a town in Japan without a
  country bias. Searches are biased to `defaultCountryCode` unless told otherwise.
- **`{{ redirectInput() }}` is hashed**, so a curl-driven form post must omit `redirect` entirely
  or Craft rejects the whole request with "invalid body param".

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-fold/tests/integration/checks.php     # 84 checks
ddev exec bash -c 'find /var/www/craft-fold/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The checks are idempotent and self-cleaning — each run makes its own group and deletes it. None of
them touch a geocoding provider: coordinates are set by hand, and the driver is switched to `none`
to test the fallbacks, because a suite that depends on Nominatim being up fails for reasons that
are not bugs.

Front-end demo page in the harness: `/fold-test`. Store pages: `/stores/<slug>`.
`ddev exec php craft clear-caches/cp-resources` after editing anything under
`src/web/assets/*/dist`, or Craft keeps serving the published copy.

## Coding conventions

- `Craft::t('fold', '…')` for user-facing strings; `src/translations/en/fold.php` lists them all
- Business logic in services; controllers stay thin
- Never mark plugin settings `required` — it breaks a fresh install
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- The front end is progressive enhancement, and meant literally: nothing in `fold.js` may *remove*
  server-rendered content on init
