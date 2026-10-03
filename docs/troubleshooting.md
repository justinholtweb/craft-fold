---
title: Troubleshooting
slug: troubleshooting
order: 140
summary: Missing pins, empty searches, a 429 from the endpoint, and opening times that are hours out.
---

## New locations never get a pin

Geocoding after a save runs on Craft's **queue**. If nothing is processing the queue, nothing gets
placed. Check *Utilities → Queue Manager*, and make sure a queue runner is running in production (or
that `runQueueAutomatically` is on).

To place them now without the queue:

```sh
php craft fold/locations/geocode
```

Also check that **Look up coordinates automatically** is on, and that the location is not marked as
placed by hand — those are never geocoded automatically. Press **Look up** and save to hand one
back to the geocoder.

## A location says "Could not be placed"

The geocoder was asked and found nothing. It is nearly always the address: a typo in the street, a
postcode in the wrong field, the wrong country. The *Could not be placed* source on the locations
index lists them all. Fix the address and save; or, if the geocoder simply does not know the place
(a new development, a unit on a retail park), type the coordinates — Fold will then leave them alone.

## A pin is in the wrong place, and keeps going back there

Type the correct coordinates on the location. Hand-typed coordinates mark the location as placed by
hand, and Fold stops geocoding it — so the pin stays where you put it, instead of being moved back to
the geocoder's guess on the next save.

## A shop is missing from the results

In order of likelihood:

1. **It has no coordinates.** Locations that are not on the map are excluded from every search.
2. **It is outside the radius.** The radius is a real circle — a shop 26 miles away is not in a
   25-mile search, even though it may be inside the map's visible box.
3. **It is disabled**, or disabled for this site, or its group is not available on this site.
4. **`openNow` is on** and it is closed — or it has no hours at all, which `openNow` treats as not
   open.
5. **`inStockOf` is on** and it is not linked to a Commerce inventory location, or has none available.

## Every search says "We couldn't find …"

The term could not be geocoded and did not match any location's name.

- **Check the default country.** Searches are biased to **Default country**. A UK site left on `US`
  will not find a UK postcode.
- **Check the provider.** If Google is selected, check **Google Geocoding API key** — or, if it is
  blank, the map key, which must then not be referrer-restricted. An invalid key or exhausted quota
  is a provider *failure* — logged to the `fold` log category, and not cached, so the search works again
  as soon as the key is fixed. A missing key falls back to Nominatim with a warning in the log.
- **Nominatim under a burst.** Nominatim can time out when several new searches arrive at once.
  Fold gives up after six seconds rather than hanging the page. The cache is the real defence — a
  term that has been answered once is never asked again for 30 days — and a site with real traffic
  should move to a paid provider.

## A search finds a town on the wrong continent

Bias it. Searches use **Default country** unless the request passes `country`. "NoDa" without a
country resolves to a town in Japan.

## The JSON endpoint returns 429

The visitor's IP has made more than **Searches per visitor per minute** searches (30 by default) in the
current minute. That is usually a front end searching on every keystroke — debounce it, or search on
submit. Behind a proxy or CDN, make sure Craft sees the visitor's real IP rather than the proxy's
(the `request` component's `trustedHosts` and `ipHeaders` in `config/app.php`), or every visitor shares one budget. If you rate-limit
at the edge already, set the limit to `0`.

## Searches fall back to name matches under load

Each visitor also has a budget of **uncached** geocoder lookups per minute, the same number as the
search limit. Over it, the term is not sent to the provider and the search falls back to matching
location names. And with Nominatim, every request on the site waits its turn for a one-per-second
slot; a request that cannot get one within two seconds is treated as a provider failure (logged under
`fold`, not cached). The cure for both is the same: cached terms cost nothing, so they settle down as
the cache fills — and a busy site should use a paid provider.

## "Open now" is wrong by a few hours

Set the location's **Timezone**. Blank falls back to the site's timezone, which is wrong for any
branch in another one. "Open now" is always answered in the shop's timezone, never the server's or
the visitor's.

If a late-night location shows as closed just after midnight, enter the night as one range whose
closing time is earlier than its opening time — `22:00` to `02:00` on Friday. Fold reads that as
running into Saturday morning.

## The map is blank or the pins are invisible

- **Google or Mapbox:** check the key or token, and that it allows your domain. A Mapbox token must
  be a public `pk.` token; the settings screen refuses an `sk.` one. The runtime hides
  the map rather than leaving a broken box, and logs the error to the browser console.
- **On Lite, or a lapsed Pro licence**, a Google or Mapbox setting is served as Leaflet. That is
  deliberate; see [Editions](editions).
- **After editing `fold.js` or `fold.css`**, Craft may keep serving the previously published copy.
  Clear it with `php craft clear-caches/cp-resources`.
- **A Content-Security-Policy** must allow the tile host (`tile.openstreetmap.org` by default), and
  for Google or Mapbox, their script and API hosts.

## My changes to `_result.twig` disappear after a search

The first set of results is rendered by your Twig template; results that arrive from a later search
are drawn by `fold.js` with the same classes. Restyle by class. If you need different *content* in
every result, build your own front end against the [JSON endpoint](json-endpoint).

## I copied `locator.twig` and now the page errors

Copy `_result.twig` as well. Once your copy of the locator is in use, its include of the result
template resolves against your site's templates. See [The front-end locator](front-end#changing-the-markup).

## "Fold Lite supports up to 10 locations"

Lite holds ten locations in one group. Trashed locations do not count, so deleting test locations
frees their slots. Otherwise, [Pro](editions) is unlimited.
