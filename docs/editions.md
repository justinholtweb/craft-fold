---
title: Editions
slug: editions
order: 130
summary: What Lite does, what Pro adds, and why a lapsed licence still shows a map.
---

**Lite is a complete store locator for a small business, not a demo.** A bakery with three shops
should never see a paywall, so everything that makes the locator *work* is free. Pro is for the
problems that arrive with scale: many shops, many kinds of shop, a spreadsheet to import, a
commercial geocoder, and the Commerce questions.

## Lite — free

- Up to **10 locations** in **1 location group**
- The map, on Leaflet and OpenStreetMap tiles — no API key
- Geocoding with Nominatim, cached, misses included — or none, with coordinates typed by hand
- Radius search with the bounding-box-then-haversine query, the name fallback, and "use my location"
- Opening hours with overnight ranges and per-location timezones, and the open-now filter
- Store pages with per-site URIs and templates, a field layout, and the Locations relation field
- The ready-made, server-rendered locator and the [JSON endpoint](json-endpoint)
- [GraphQL](graphql) queries with radius search, and a schema permission per group
- [LocalBusiness structured data](structured-data) on every store page, with a schema.org type per group
- The geocoding console commands

Trashed locations do not count towards the ten — they are not on the map. A location that exists on
four sites counts once.

## Pro — $79

A licence is **$79**, with **$59/year** for continued updates. Everything in Lite, plus:

- **Unlimited locations and location groups**
- **Google Maps and Mapbox** maps
- **Google and Mapbox** geocoding
- **Craft Commerce inventory**: link locations to inventory locations, sync them, and filter the
  locator by available stock — see [Commerce stock](commerce-stock)
- **CSV import, and CSV and JSON export** — see [Import and export](import-export)
- **Search recording**, and the report of searches that found nothing, with its retention setting
- The **Cluster markers** setting (Leaflet maps)

## What Lite refuses, and how

The control panel and the commands **refuse** what Lite cannot do, rather than quietly doing
something else — so an author is told:

- The 11th location cannot be created: the *New location* button disappears, and a save is refused
  with "Fold Lite supports up to 10 locations". The check runs on the element itself, so Craft's
  *Duplicate* action, applying a draft and saves from other plugins are held to it too.
- A second group cannot be created.
- Pro-only providers are shown in the settings but cannot be selected.
- `fold/locations/import`, `export` and `fold/commerce/sync` exit with "is a Pro feature".

## When a Pro licence lapses

Fold degrades rather than going blank. A store locator that stops working on the day a licence
expires punishes the shop's customers, not the developer.

- **Maps** set to Google or Mapbox are served as **Leaflet on OpenStreetMap tiles**, with every
  location still on them.
- **Geocoding** set to Google or Mapbox is served by **Nominatim**.
- **Commerce stock filtering**, **import and export**, and **search recording** stop. The search log
  stays where it is. Commerce links stay in place — but the link select is not shown on Lite, so
  saving a linked location in the control panel while unlicensed clears its link.
- **Locations and groups beyond the Lite limits are kept, and keep working** on the map and in
  search. You cannot add more until you are back under the limit or renew.

Your stored settings are never changed. Renewing restores exactly what was there.
