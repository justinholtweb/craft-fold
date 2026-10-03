---
title: JSON endpoint
slug: json-endpoint
order: 75
summary: GET /fold/search.json — parameters, the response shape, and the field that tells "not found" from "nothing near".
---

```
GET /fold/search.json?q=28202&radius=25
```

The same search the built-in locator runs, as JSON, for anyone building their own front end. It is
read-only, anonymous, and `GET`-shaped, which is what lets it be cached at the edge.

It answers in JSON whatever the request's `Accept` header says, so it can be pasted into a browser
or `curl`ed as it is:

```sh
curl 'https://example.com/fold/search.json?q=28202&radius=25'
```

The action URL, `/actions/fold/search/index`, answers
the same way; `/fold/search.json` is the stable, documented one.

## Parameters

| Parameter | Meaning |
| --- | --- |
| `q` | A town, postcode, address, or a `lat,lng` pair. Cut to 100 characters. |
| `lat`, `lng` | Coordinates, which skip geocoding entirely and win over `q` |
| `radius` | Defaults to **Default radius**. Clamped to the widest radius the site offers — see below. |
| `unit` | `mi` or `km` |
| `limit`, `offset` | Paging. `limit` is clamped to **Maximum results** (200 by default), however much is asked for. |
| `group` | Restrict to a location group, by handle. `group[]=retail&group[]=outlet` for several. |
| `openNow` | Only locations open at the moment of the search |
| `inStockOf` | A purchasable's ID — Pro, with Commerce installed |
| `country` | A two-letter code to bias geocoding; defaults to **Default country**. Anything that is not two letters is ignored. |

The query string belongs to whoever is sending it, so it is checked before it is searched with:

- **Array values are ignored** (except `group`), rather than becoming a server error.
- **The radius is clamped** to the widest of the **Radius options** and the **Default radius** —
  100 miles out of the box. `radius=0`, or a negative, means that maximum, not "no limit": an
  unlimited public search would be a distance calculation over every row, which is exactly what the
  bounding box exists to prevent. Unlimited searches remain available from Twig and PHP, which are
  trusted.
- `q` is capped and `country` validated because both are part of the geocode cache key — every
  distinct value would otherwise be a fresh provider request.

`craft.fold.locator()` reads `q`, `lat`, `lng` and `radius` from the page's query string through the
same checks.

## Rate limit

Each visitor IP may make **Searches per visitor per minute** requests (30 by default). Past that, the
endpoint answers **`429`** with a JSON error until the minute is up. The same budget limits how many
*uncached* geocoder lookups a visitor's searches can cause anywhere on the front end. Set it to `0`
to turn it off if you rate-limit at a CDN instead.

`inStockOf` is **ignored** unless Commerce is installed and the edition is Pro. Rather than
pretending to filter, the response then simply carries no `inStock` on its locations — so a front
end can tell the difference between "filtered by stock" and "not filtered".

An ID only resolves to a purchasable a visitor could buy: enabled, on the current site, and
available for purchase. Anything else — a disabled or unreleased product — matches no locations.

## Response

```json
{
    "term": "28202",
    "origin": { "lat": 35.2271, "lng": -80.8431, "formatted": "Charlotte, Mecklenburg County, North Carolina, 28202, United States" },
    "radius": 25,
    "unit": "mi",
    "total": 4,
    "count": 4,
    "bounds": [35.0613, -80.9432, 35.4121, -80.7194],
    "originNotFound": false,
    "matchedByName": false,
    "locations": [
        {
            "id": 1042,
            "title": "Uptown",
            "url": "https://example.com/stores/uptown",
            "group": "retail",
            "color": "#d9480f",
            "lat": 35.2269,
            "lng": -80.8433,
            "distance": 0.02,
            "address": "200 S Tryon St\nCharlotte, NC 28202\nUnited States",
            "addressLines": ["200 S Tryon St", "Charlotte, NC 28202", "United States"],
            "phone": "704-555-0100",
            "email": "uptown@example.com",
            "websiteUrl": null,
            "directionsUrl": "https://www.google.com/maps/dir/?api=1&destination=35.2269%2C-80.8433",
            "hours": { "mon": [{ "open": "09:00", "close": "17:30" }], "sat": [{ "open": "10:00", "close": "16:00" }] },
            "openNow": true,
            "timezone": "America/New_York"
        }
    ]
}
```

| Field | Meaning |
| --- | --- |
| `origin` | Where the search was measured from, or `null`. `formatted` is the provider's name for the place it matched — worth showing back to the visitor ("Showing shops near Charlotte, NC"). |
| `total` | Matches before paging (and before `openNow`) |
| `count` | Locations in this response |
| `bounds` | `[south, west, north, east]` around the results and the origin — the order Leaflet's `fitBounds` and Google's `LatLngBounds` both take |
| `originNotFound` | The term could not be placed **and** matched no location names |
| `matchedByName` | The term was not a place, but matched a location's name |
| `color` | The location's group **Marker colour** as `#rrggbb`, or `null` when the group has none |
| `distance` | In `unit`, rounded to two places; `null` when there was no origin |
| `openNow` | `true` or `false`, or `null` for a location with no hours |
| `inStock` | Only when `inStockOf` was honoured: whether that location has it available |
| `availableStock` | Only when `inStockOf` was honoured **and** **Publish exact stock counts** is on: the available quantity there |

### Tell "not found" from "nothing near"

A front end that only looks at `count` will tell a visitor who misspelt their own town that there
are no shops near them. Check `originNotFound` first:

```js
if (data.originNotFound) {
    show(`We couldn't find “${data.term}”. Try a town, a postcode, or a full address.`);
} else if (!data.count) {
    show('No shops within that distance. Try a wider radius.');
}
```

## The shape is a contract

The response is composed field by field, not by serializing location elements. Adding a custom field
to a location group cannot change the endpoint's output, and nothing that happens to be public on an
element in PHP leaks into a public response. If you need a custom field in your own front end,
render it in Twig — or build a small endpoint of your own on top of `craft.fold.search()`.
