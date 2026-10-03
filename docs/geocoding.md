---
title: Geocoding
slug: geocoding
order: 40
summary: When Fold asks a provider for coordinates, when it doesn't, and why every answer — misses included — is cached.
---

Geocoding turns an address into a latitude and longitude. Fold does it in two places: when a
location's address changes, and when a visitor types a place into the search box.

## Providers

| Provider | Edition | Needs |
| --- | --- | --- |
| **Nominatim** (OpenStreetMap) | Lite and Pro | Nothing. The default. |
| **Google** | Pro | A server-side Google key with the Geocoding API enabled — see below |
| **Mapbox** | Pro | A Mapbox access token |
| **None** | Lite and Pro | Nothing — coordinates are entered by hand |

Pick one under **Fold → Settings → Geocoding provider**. *None* is for sites that type their own
coordinates, or that must not make outbound requests at all; searches then work only with
coordinates (*Use my location*, or a `lat,lng` pair) and the name match described in
[Search and radius](search-and-radius).

If Google or Mapbox is selected but has no key, Fold logs a warning and **falls back to
Nominatim** rather than failing every search. If a Pro licence lapses, a Google or Mapbox selection
is served by Nominatim too — see [Editions](editions).

### Google keys: one for the browser, one for the server

Google needs two different kinds of key restriction, so Fold has two settings:

- **Google Maps API key** draws the map. It is written into the page, so it is public — restrict it
  to your site's HTTP referrers.
- **Google Geocoding API key** is used only by your server and never sent to a browser — restrict it
  to your server's IP address.

A referrer-restricted key cannot geocode from a server, and an unrestricted key in page source is
somebody else's free Geocoding API. If **Google Geocoding API key** is blank, Fold falls back to the
map key — which works, but only if that key is left unrestricted. Set both.

### Being a good Nominatim citizen

Nominatim is run by volunteers and its usage policy asks for no more than **one request per
second** from a site, and an identifying `User-Agent`. Fold does both:

- **Every** Nominatim request — from the control panel, the console, the queue or a visitor's
  search — goes through one site-wide lock that spaces requests at least a second apart. A request
  that cannot get its turn within two seconds gives up, rather than holding a PHP worker; that
  counts as a provider failure (below), not a miss.
- Background geocoding also waits 1.1 seconds between jobs. 340 shops is a six-minute job, and
  that is the honest cost of a free geocoder.
- The `User-Agent` is built from your site's name and primary URL — `Fold/1.0 (Acme Stores;
  https://acme.example)` — so a blocked install is one identifiable site, not everyone who ever ran
  Fold. Override it with the **User agent** setting.

A busy site should still move to a paid provider. Not because Fold will break, but because it is
the decent thing to do.

## When a location is geocoded

On save, Fold hashes the address and compares it with the hash stored at the last lookup. For a
location that already has coordinates, **only a changed address triggers a lookup.** Editing a phone number, an opening time or a custom field does
not cost a provider request — and neither does a bulk resave of every location, which is how a free
geocoder ends up blocking you.

The lookup itself runs on Craft's **queue**, one job per location, so a provider hiccup costs one
retry rather than restarting four hundred lookups. That means:

- A queue runner must be running for new locations to get pins.
- Drafts and revisions are never geocoded.
- Locations placed by hand are never geocoded (see [Locations and hours](locations-and-hours)).
- A location with an address but no coordinates — not yet placed, or not found last time — is
  queued again on each save until it is placed.
- A location with no address at all is left alone.

Turn **Look up coordinates automatically** off to stop the on-save lookups entirely.

To geocode on demand:

- **Look up** on a location's edit screen — immediate, for the one you are looking at
- **Geocode pending** on the locations index — queues everything awaiting coordinates or not found
- `php craft fold/locations/geocode` — runs inline with progress; `--force` to redo all of them
- `php craft fold/locations/queue-geocoding` — hands the same work to the queue

See [Console commands](console-commands).

## When a search is geocoded

A visitor's search term is geocoded on the request, with a six-second timeout. A slow provider
should produce "we couldn't find that" quickly, not a page that hangs.

Front-end lookups are **budgeted per visitor**: each IP address may make **Searches per visitor per
minute** uncached lookups (30 by default) — the same setting that rate-limits the
[JSON endpoint](json-endpoint). A visitor over budget is not refused; their term just isn't sent to
the provider, so the search falls back to matching location names. Lookups from the control panel,
the console and the queue are never budgeted — they are your own people placing your own shops — and
cached terms never count.

Some terms never reach a provider:

- **Coordinates.** `35.2271,-80.8431` (or with a space instead of a comma) is used as-is. *Use my
  location* sends coordinates, so it never costs a lookup.
- **Anything already in the cache.**

### Country bias

Searches are biased to **Default country** unless the request says otherwise (the JSON endpoint's
`country` parameter). An unbiased geocoder is worse than a wrong one: *Springfield*, *Newport* and
*Richmond* exist on every continent, and "NoDa" — a Charlotte neighbourhood — resolves to a town in
Japan. Blank the setting to search the whole world.

Locations are geocoded with their own address's country, not the default.

## The cache

Every answer is stored in `{{%fold_geocodecache}}` for **Cache lookups for** seconds — 30 days by
default. That includes **misses**: "asdfgh" is a query real visitors produce constantly, and asking a
provider about it forever is how a free geocoder stops answering.

- The key includes the provider and the country bias, so switching providers does not keep serving
  the old one's opinion.
- A **provider failure** — a timeout, a bad key, an exhausted quota — is *not* cached. Caching it
  would keep answering "not found" for a month after someone fixed the key.
- Expired rows are deleted by Craft's garbage collection.
- Set the duration to `0` to turn the cache off. You almost certainly should not.

## Null Island

`0,0` is a real place in the Gulf of Guinea, and it is also what an empty provider response decodes
to. Fold treats `0,0` as "not found", so an unplaceable shop is flagged rather than quietly put in
the sea off West Africa.
