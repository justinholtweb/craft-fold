---
title: Search and radius
slug: search-and-radius
order: 50
summary: A bounding box, then a haversine, in one query — and the three answers a search can give.
---

One search implementation serves the Twig variable, the JSON endpoint and the built-in locator. A
locator whose asynchronous results differed from its server-rendered ones would be a bug report
nobody could reproduce, so there is only one.

## How a radius search stays fast

The distance between two points on a sphere is a haversine: a function of a row's latitude *and*
longitude. No index can answer a function of two columns, so asking the database to evaluate one
against every row is a table scan — and a table scan gets slower with every shop you open.

So `nearby()` does two passes inside one query:

1. **A bounding box.** A square around the search circle, as plain `BETWEEN` comparisons on `lat`
   and `lng`, which the composite index on those two columns answers directly. Everything obviously
   too far away is gone before any trigonometry runs.
2. **A haversine**, on the rows the box let through — as the real distance filter, and as the sort.
   It has to be both: the box is square, and its corners are up to 41% further out than the radius.
   Without the second pass, a 25-mile search would return shops 35 miles away, and the distance
   column would prove it.

No spatial types are involved. MySQL's `ST_Distance_Sphere` and PostGIS are not the same feature,
and Craft supports both databases. The haversine uses only `RADIANS`, `SIN`, `COS`, `ASIN`, `SQRT`,
`POWER` and `LEAST`, which behave identically in MySQL and Postgres. A box that crosses the
antimeridian is split into two ranges, so a search near Fiji works.

### Why the sort is right on page two

Craft applies `limit`, `offset` *and* `orderBy` to the element sub-query. A distance computed only
on the outer query would sort a page *after* the database had already chosen which rows were on it
— and that failure is quiet: the results look nearly right. Fold selects the distance on the
sub-query, so the database picks the nearest 25 rather than 25 rows it then sorts.

## Radius, and no radius

```twig
{# Within 25 miles, nearest first #}
craft.fold.locations.nearby({ lat: 35.2271, lng: -80.8431, radius: 25 })

{# Nearest first, no limit on distance #}
craft.fold.locations.nearby({ lat: 35.2271, lng: -80.8431 })
```

Leaving the radius out is the right answer for a chain with a handful of shops spread thin: the
nearest is still the nearest at 300 miles, and showing nothing is not more honest. In
`craft.fold.search()`, where an absent radius means *the default radius*, pass `radius: 0` for no
limit.

Public requests cannot ask for that. A radius arriving in a query string — on the
[JSON endpoint](json-endpoint), or in the URL of a page with `craft.fold.locator()` — is clamped to
the widest of the **Radius options** and the **Default radius**, and `0` there means that maximum.
An unlimited public search would be a haversine over every row, which is the one thing the bounding
box is there to prevent. Twig and PHP are trusted, so a template can still search without a limit.

Units are `mi` or `km`; the default is the **Distance unit** setting. `location.distance` is in the
search's unit.

## What a search does

`craft.fold.search()`, the JSON endpoint and the locator all run the same steps:

1. **Find the origin.** `lat` and `lng` win if both are given. Otherwise the term is geocoded —
   unless it is already a coordinate pair, or it is in the cache. See [Geocoding](geocoding).
2. **Query.** Group, `relatedTo` and stock filters are applied, locations without coordinates are
   excluded, and with an origin the query is a `nearby()` with the radius.
3. **Count, then page.** The total is counted before the page is taken, so "showing 25 of 118" is
   possible without loading 118 locations. `limit` is clamped to **Maximum results**.
4. **Fall back to names.** If a typed term found nothing — because it is not a place at all, or
   because the geocoder placed it in the wrong one — the term is tried as a search against location
   names and addresses instead.
5. **Filter open-now**, if asked.

Without a term or coordinates, a search returns every location with coordinates, in title order.

### The name fallback

People type "NoDa", "Concord Mills" and "the airport one" into a store locator constantly. A
geocoder has no idea what any of those are, or worse, finds a different place with the same name.
The fallback answers both — and it only runs when the geocoded search found nothing, so it never
overrides a search that worked.

### Three answers, not two

A search that comes back empty has two very different explanations, and they deserve different
messages:

| Result | What happened | What to say |
| --- | --- | --- |
| `originNotFound` | The term could not be placed, and no name matched it | "We couldn't find 'Springfeild'. Try a town, a postcode, or a full address." |
| empty, origin found | The place exists, and nothing is within the radius | "No shops within 25 miles. Try a wider radius." |
| `matchedByName` | The term was not a place, but it matched a shop's name | Show the results — this is a success |

Only the first is the visitor's mistake. The built-in locator already says the right thing for each.

### Open now

`openNow` is evaluated in PHP, against each shop's hours in its own timezone, not in SQL — the
hours are a JSON document with overnight ranges and dated exceptions, and expressing that as a
`WHERE` would need a different query per database and a second implementation that would drift
from the first.

The cost is worth knowing: **`openNow` filters the page**, not the whole result. A page of 25 can
come back with 9 on it, and `total` is the count before filtering.

## Recording searches (Pro)

Turn on **Record searches** and every search with a term or coordinates is stored. *Fold →
Searches* — which needs the **View the search log** permission (see [Permissions](permissions)) —
shows two things:

- **Searches that found nothing**, over the last 90 days, most frequent first, with where each term
  was placed. A town people keep searching for and never find is a map of where to open next — and
  it is invisible in any other analytics tool.
- **Recent searches**, the last 100.

The log can be cleared from the same screen. Rows older than **Keep searches for** (90 days by
default) are deleted by Craft's garbage collection; `0` keeps them forever. To purge on your own
schedule, `php craft fold/searches/purge --days=30`.

### What the log stores — for your privacy notice

A search term is something a visitor typed, and where they searched from is roughly where they are.
In most places Fold will be installed that is personal data, so the log keeps as little as does the
job, and you should mention it in your privacy notice if you turn it on. Each row holds:

| Stored | |
| --- | --- |
| The search term | As typed, up to 255 characters |
| Where it was placed | Latitude and longitude **rounded to two decimal places** — about a kilometre. Enough for "people around here found nothing"; not enough to be anyone's front door. The browser already rounds *Use my location* to about ten metres before sending it, and the log rounds again, so an API caller sending full precision is rounded too. |
| The search | Site, radius, unit, and the number of results |
| The answer | The nearest location found, and its distance |
| When | The date and time |

It does **not** store an IP address, a user agent, a user or session ID, or any cookie. Nothing in the
log ties one search to another or to a person.

Separately from the log, the per-visitor [rate limit](json-endpoint#rate-limit) keeps a **hashed** IP
address and a counter in Craft's cache for one minute. That is not stored in the database and is
gone when the minute is.
