---
title: Configuration
slug: configuration
order: 110
summary: Every setting, its default, and the two worth changing before launch.
---

Every setting lives under **Fold → Settings**, and can also be set in `config/fold.php` like any
Craft plugin. None of them is required: a fresh install has a working locator — free map, free
geocoder — before anybody opens the settings screen.

Two are worth changing before launch: **Default country**, which biases every search, and — on a
busy site — **Geocoding provider**.

## Map

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| Map provider | `mapDriver` | `leaflet` | `leaflet`, `google` (Pro) or `mapbox` (Pro) |
| Tile URL | `leafletTileUrl` | OpenStreetMap's tiles | Leaflet's tile URL template |
| Attribution | `leafletAttribution` | OpenStreetMap credit | Shown on the map. OpenStreetMap's tiles require it. |
| Google Maps API key | `googleApiKey` | — | The **browser** key that draws a Google map. Public; restrict it by HTTP referrer. |
| Mapbox access token | `mapboxAccessToken` | — | Used by the Mapbox map and the Mapbox geocoder. Must be a **public** `pk.` token — an `sk.` token is rejected, because this one is sent to the browser. |
| — | `leafletJsUrl`, `leafletCssUrl` | the bundled copy | Load Leaflet from somewhere else. Config file only. |
| — | `leafletClusterJsUrl`, `leafletClusterCssUrl`, `leafletClusterDefaultCssUrl` | the bundled copy | Load Leaflet.markercluster (and its two stylesheets — the second is the default bubble styling) from somewhere else. Config file only; only used when clustering is on. |

## Geocoding

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| Geocoding provider | `geocoderDriver` | `nominatim` | `nominatim`, `google` (Pro), `mapbox` (Pro) or `none` |
| Google Geocoding API key | `googleGeocodingApiKey` | — | The **server** key used for Google geocoding; never sent to a browser. Restrict it by IP. Blank falls back to the map key. |
| Look up coordinates automatically | `autoGeocode` | On | Queue a lookup when a location's address changes |
| Cache lookups for | `geocodeCacheDuration` | `2592000` (30 days) | Seconds. `0` turns the cache off. |
| User agent | `geocoderUserAgent` | built from the site | Sent to Nominatim, which blocks requests without one |

## Searching

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| Distance unit | `distanceUnit` | `mi` | `mi` or `km`, everywhere Fold shows a distance |
| Default radius | `defaultRadius` | `25` | Used when a search does not give one |
| Radius options | `radiusOptions` | `[5, 10, 25, 50, 100]` | What the built-in radius select offers. With one option, the select is hidden. |
| Results per search | `defaultLimit` | `25` | Page size when a search does not give one |
| Maximum results | `maxLimit` | `200` | Ceiling on `limit`, however large a public request asks for |
| Searches per visitor per minute | `searchRateLimit` | `30` | Per IP address. Over it, the JSON endpoint answers `429`, and front-end searches stop sending new terms to the geocoder. `0` turns it off — for a site that rate-limits at its CDN. |
| Ask for the visitor's location | `requestBrowserLocation` | Off | Prompt for the browser's position as soon as the map loads |
| Cluster markers | `clusterMarkers` | On | Pro, **Leaflet maps only**. Overlapping pins are grouped into numbered bubbles with the bundled Leaflet.markercluster, which is only loaded when this is on. Google and Mapbox maps ignore it and show every pin. |
| Record searches | `logSearches` | Off | Pro. Keep a log of searches; see [Search and radius](search-and-radius#recording-searches-pro) |
| Keep searches for | `searchLogRetentionDays` | `90` | Pro. Days a search log row is kept; older rows are deleted by Craft's garbage collection. `0` keeps them forever. |
| Publish exact stock counts | `exposeStockLevels` | Off | Pro + Commerce. Adds `availableStock` to stock searches on the JSON endpoint; off, only `inStock` is published. See [Commerce stock](commerce-stock). |

## Structured data

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| Add LocalBusiness structured data to location pages | `injectSchema` | On | Puts schema.org JSON-LD in the `<head>` of every location's own page. Skipped while SEOmatic is installed. See [Structured data](structured-data). |

## Defaults

| Setting | Config key | Default | What it does |
| --- | --- | --- | --- |
| Default country | `defaultCountryCode` | `US` | Biases search geocoding, and is the starting country for new addresses (a group can override the latter) |
| Map centre | `defaultLat`, `defaultLng` | `39.8283`, `-98.5795` | Where the map sits before there are results — the middle of the continental US |
| Zoom | `defaultZoom` | `4` | The zoom that goes with it |

## `config/fold.php`

```php
<?php

return [
    'mapDriver' => 'google',
    'geocoderDriver' => 'google',
    'googleApiKey' => '$GOOGLE_MAPS_BROWSER_KEY',     // referrer-restricted
    'googleGeocodingApiKey' => '$GOOGLE_GEOCODING_KEY', // IP-restricted

    'distanceUnit' => 'km',
    'defaultRadius' => 40,
    'radiusOptions' => [10, 25, 40, 80],
    'defaultCountryCode' => 'GB',
    'defaultLat' => 54.0,
    'defaultLng' => -2.5,
    'defaultZoom' => 5,
];
```

The three key settings — `googleApiKey`, `googleGeocodingApiKey` and `mapboxAccessToken` — resolve
environment variables, so `'$GOOGLE_MAPS_BROWSER_KEY'` (here, or typed into the settings screen)
reads the value from `.env` and keeps the key itself out of project files. `App::env()` works too.

Settings in `config/fold.php` win over the control panel, and can be set per environment in the
usual way — Nominatim and no logging in development, a paid provider in production.

## Keys and the page source

The map key is necessarily public: a browser cannot draw a Google or Mapbox map without it. Fold
writes **only the key the chosen map provider needs** into the page, so a Mapbox token never appears
on a site that renders Google maps. Restrict the Google map key to your domains (HTTP referrers) in the
Google Cloud console, and a Mapbox token to your URLs in the Mapbox account.

Geocoding requests come from your server, not a browser, and a key restricted to HTTP referrers
will refuse them. That is why Google has two settings: give **Google Geocoding API key** its own key,
restricted to your server's IP, and it is never written into a page. Left blank, geocoding falls back
to the map key, which then has to be unrestricted — and an unrestricted key in page source is
somebody else's free Geocoding API.
