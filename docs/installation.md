---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and the three steps from a fresh install to a working locator.
---

Two commands, then one group, one location and one line of Twig. A fresh install needs no API
key: the map is Leaflet on OpenStreetMap tiles and the geocoder is Nominatim, both free.

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later
- Craft Commerce 5.0 or later, **only** for the stock features (optional, and Pro)

There is no build step. The front-end runtime is a plain script and a plain stylesheet, and
Leaflet ships inside the plugin rather than coming from a CDN.

## With Composer

```sh
composer require justinholtweb/craft-fold
php craft plugin/install fold
```

## From the Plugin Store

Or install it from the control panel: **Settings → Plugins**, search for *Fold*, and install.

| Edition | Price | What it covers |
| --- | --- | --- |
| Lite | Free | Up to 10 locations in 1 group; Leaflet map, Nominatim geocoding, radius search, opening hours, store pages, the JSON endpoint |
| Pro | $79, then $59/year for updates | Unlimited locations and groups; Google Maps and Mapbox; Commerce stock filtering; CSV/JSON import and export; search recording |

See [Editions](editions) for the exact split, and what happens when a licence lapses.

## Check it worked

A **Fold** item appears in the control panel sidebar. From the command line:

```sh
php craft fold/locations/groups
```

which will say there are no location groups yet. That is the correct state for a fresh install.

## From install to a working locator

**1. Make a location group.** *Fold → Groups → New location group.* Give it a name and handle
(`retail`), and on each site where shops should have their own page, set a URI format such as
`stores/{slug}` and a template such as `stores/_entry`. See [Location groups](location-groups).

**2. Add a location.** *Fold → Locations → New location.* Type the address. Fold queues a
geocode in the background when you save. **Look up**, next to the coordinates, places the pin
immediately instead, so you can check it before you save. See [Locations and hours](locations-and-hours).

**3. Put it on a page.**

```twig
{{ craft.fold.locator({ group: 'retail' }) }}
```

That renders a search box, a results list and a map. The list is rendered server-side, so it
works with JavaScript off; the runtime adds the map and the asynchronous search on top. See
[The front-end locator](front-end).

## Before you go live

- **Look at the geocoder.** Nominatim is run by volunteers and allows one request per second.
  Fold respects that and caches every answer, but a busy site should move to Google or Mapbox
  (Pro). See [Geocoding](geocoding).
- **Check the default country.** Searches are biased to **Default country** (`US` out of the
  box). A UK chain left on `US` will find Springfield, Illinois when someone types "Springfield".
- **Make sure a queue runner is running.** Geocoding after a save happens on Craft's queue.

## Uninstalling

```sh
php craft plugin/uninstall fold
```

This deletes every location element first, then drops Fold's tables: locations, groups, the
geocode cache and the search log. The elements go first on purpose — dropping the tables alone
would leave rows in Craft's `elements` table pointing at an element type that no longer exists,
and every element index in the control panel would then throw.

If you are uninstalling to start again, export first (Pro): see [Import and export](import-export).
