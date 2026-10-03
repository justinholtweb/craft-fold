---
title: FAQ
slug: faq
order: 150
summary: How Fold compares to WP Store Locator, what it costs, and the questions worth asking before you install it.
---

## How does Fold compare to WP Store Locator?

WP Store Locator is the benchmark for what a store locator should do, and Fold covers the same
ground: a map, a search box, a radius, opening hours, a page per shop. It is built the way Craft does
things, though — locations are real elements with field layouts, per-site URLs, relations, search
and the trash — and it can answer the one question WP Store Locator cannot: **which shop near me has
this in stock**, from Craft Commerce's own inventory. See [Commerce stock](commerce-stock).

## Do I need a Google Maps API key?

No. The default map is Leaflet on OpenStreetMap tiles and the default geocoder is Nominatim, and
neither needs a key. Google Maps and Mapbox are there when you want them (Pro).

## Does the locator work without JavaScript?

Yes. The results are rendered in Twig, and the search form is an ordinary form that reloads the page.
JavaScript adds the map and the asynchronous search; it never removes what was rendered. That also
means search engines see a real list of your shops, with links to their pages.

## Will radius search get slow with hundreds of shops?

No. An indexed bounding box eliminates everything obviously too far away before any distance is
calculated, and the haversine only runs on what is left — inside one query, on MySQL or Postgres,
with no spatial extensions. See [Search and radius](search-and-radius).

## What happens when someone types a neighbourhood the geocoder doesn't know?

Fold tries the term against your location names and addresses before giving up. "NoDa", "Concord
Mills" and "the airport one" find the shop with that name. When nothing matches at all, the response
says the *place* could not be found — a different message from "no shops near it".

## Is "open now" right for shops in other timezones, or open past midnight?

Yes, both. Each location has its own timezone, and a range like `22:00`–`02:00` is open at 00:30 the
next morning. The opening-hours model also holds dated exceptions for holidays.

## Will editing a location cost a geocoding request every time?

No. Fold hashes the address and only re-geocodes a placed location when the address actually
changes. Coordinates you type are never overwritten. Search lookups are cached for 30 days by
default, misses included.

## Can I import my stores from a spreadsheet?

Yes, with Pro. The importer reads the column names other systems export — `Store Name`, `address_1`,
`ZIP`, `Telephone`, a column per weekday — and matches on slug or name, so running it twice updates
rather than duplicates. Export writes the same columns, so a spreadsheet round trip works. See
[Import and export](import-export).

## Can I build my own front end?

Yes. `/fold/search.json` returns the same results the built-in locator gets, from the same search
code, with the fields a front end needs — including `originNotFound`, so you can tell "we couldn't
find that place" from "there's nothing near it". See [JSON endpoint](json-endpoint).

## Does it work on a multisite install?

Yes. Each group chooses which sites it is on and has its own URI format and template per site.
Physical facts — address, coordinates, hours — are shared across sites; titles and custom fields are
per site, translated or not as each field says.

## Do I need Craft Commerce?

No. Commerce is optional and only needed for the stock features. Without it, nothing in Fold
references Commerce at all.

## What happens if my Pro licence lapses?

Fold falls back to the free map and geocoder rather than going blank, and stops the Pro-only
features. Your settings are untouched, so renewing restores exactly what was there. See
[Editions](editions).

## What does Fold cost?

Lite is free, for up to 10 locations in one group, with the map, radius search, hours, store pages
and the JSON endpoint. Pro is **$79**, with **$59/year** for continued updates, and adds unlimited
locations and groups, Google and Mapbox, Commerce stock filtering, import and export, and search
recording.

## What are the requirements?

Craft CMS 5.3 or later and PHP 8.2 or later; Craft Commerce 5 for the stock features. No build step,
and no runtime dependency beyond Craft.
