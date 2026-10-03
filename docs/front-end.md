---
title: The front-end locator
slug: front-end
order: 70
summary: The ready-made locator, progressive enhancement taken literally, the three map providers, and changing the markup.
---

```twig
{{ craft.fold.locator({ group: 'retail' }) }}
```

That one line is a working store locator: a search box with a radius select and *Use my location*,
a results list, and a map.

## Server-rendered first

The results are rendered **in Twig, before any JavaScript runs**. With scripting off — or blocked,
or broken, or still downloading — the page is a complete, linkable, indexable directory of shops,
and the search form is an ordinary `GET` form that reloads the page with `?q=…&radius=…`.

The runtime (`fold.js`) then enhances it:

- It draws the map, taking the first set of markers **from the list already on the page** — no
  request is made for the page to become useful, and the map always matches the list.
- It turns the form into an asynchronous search against the [JSON endpoint](json-endpoint).
- *Use my location* asks the browser for a position (rounded to about ten metres) and searches
  from it.
- Clicking a result pans the map to it; clicking a marker highlights the result.
- Pins take their location group's **Marker colour**; a group without one gets the provider's
  default pin.
- Each search updates the address bar with the same `q`, `lat`, `lng` and `radius` the server reads
  (other query-string parameters on the page are kept), so the URL after a search is shareable,
  and back/forward step through searches. Going back to the page as loaded restores the
  server-rendered list exactly, without a request. With several locators on one page, only the
  first writes to the address bar; pass `map: { syncUrl: false }` to stop a locator doing it.
- When a visitor goes over the search rate limit, the endpoint's message is shown in the status
  line and the results already on screen stay where they are.

Progressive enhancement is meant literally here: **nothing in `fold.js` removes server-rendered
content on init.** A map that fails to load hides itself and leaves the list alone. A failed search
shows an error and leaves the previous results in place. Only the newest of several overlapping
searches is allowed to write to the page, so a slow search for "London" cannot land after a fast one
for "Leeds".

Because the initial state comes from the query string, a locator URL like
`/stores?q=28202&radius=10` is shareable: it renders the same results for whoever opens it — and
that is the URL the address bar shows after a visitor searches with JavaScript on, too.

## Options

Anything `craft.fold.search()` accepts can be passed, and is applied to the server-rendered results:

```twig
{{ craft.fold.locator({
    group: 'retail',
    radius: 25,
    limit: 50,
    openNow: true,
    relatedTo: brand,
    map: { zoom: 6, center: { lat: 53.48, lng: -2.24 } },
}) }}
```

`map` overrides keys of the map configuration for this locator — `center`, `zoom`, and so on.

Options written in the template are trusted. Values from the page's query string — `q`, `lat`,
`lng`, `radius` — are a visitor's to write, so they go through the same checks as the
[JSON endpoint](json-endpoint): `q` is cut to 100 characters and `radius` is clamped to the widest
radius the site offers. An option you pass wins over the query string.

Searches the visitor runs afterwards are built **from the form's fields**. The bundled form carries
`q` and `radius`, plus `group`, `inStockOf` and `openNow` as hidden inputs when you pass them, so a
stock-filtered locator stays stock-filtered when the visitor searches again. Any other option needs
a hidden input in your own copy of the template (below).

With `inStockOf`, each result says **In stock** or **Out of stock** in the server-rendered HTML as
well as after a search, so the label is there with scripting off.

## Map providers

| Provider | Edition | Needs | Marker clustering (Pro) |
| --- | --- | --- | --- |
| **Leaflet** with OpenStreetMap tiles | Lite and Pro | Nothing. The default. | Yes |
| **Google Maps** | Pro | **Google Maps API key** with the Maps JavaScript API enabled | No — every pin is shown |
| **Mapbox** (Mapbox GL JS) | Pro | **Mapbox access token** | No — every pin is shown |

**Clustering** groups pins that overlap at the current zoom into numbered bubbles that split apart
as you zoom in. It uses Leaflet.markercluster (MIT), bundled like Leaflet and loaded only when
**Cluster markers** is on — it attaches to whichever Leaflet is on the page, so it works with a
CDN-hosted Leaflet too. Google and Mapbox clustering would each mean another third-party library,
so on those maps the setting has no effect and the map config sends `cluster: false`.

**Leaflet is bundled**, not linked from a CDN. A default install therefore makes one third-party
request — for map tiles — and none at all for code: no third-party script on your pages, no
dependency on somebody else's uptime, and nothing extra to disclose in a privacy notice. Leaflet and
its stylesheet are only loaded on pages that render a locator.

OpenStreetMap's tiles are free and ask for attribution and modest traffic. A busy site should point
**Tile URL** at its own tile service, or one it pays for. To load Leaflet from your own location
instead of the bundled copy, set `leafletJsUrl` and `leafletCssUrl` in `config/fold.php`.

Only the key the chosen provider needs is written into the page. A Mapbox token in the source of a
site that renders Google maps would be a leaked credential for nothing.

If a Pro licence lapses, a Google or Mapbox site is served Leaflet instead — a working map on free
tiles rather than a blank box. See [Editions](editions).

## Changing the markup

The locator is two ordinary Twig templates:

- `_locator/locator.twig` — the form, status line, list and map container
- `_locator/_result.twig` — one result

Copy **both** from `vendor/justinholtweb/craft-fold/src/templates/_locator/` into your site's
`templates/fold/_locator/` and edit them there. Fold uses the site's copy whenever
`templates/fold/_locator/locator.twig` exists — no override settings, no theme layer, no fork. Copy
both because the locator includes the result template, and once your copy is in use that include
resolves against your site's templates.

The runtime finds its pieces by data attribute, so keep these on whatever markup you write:

| Attribute | On |
| --- | --- |
| `data-fold-locator`, `data-fold-config`, `data-fold-strings` | The wrapper |
| `data-fold-form` | The search form |
| `data-fold-results` | The list |
| `data-fold-status` | The status line (keep `role="status"` and `aria-live="polite"`, so a screen reader hears that the results changed) |
| `data-fold-map` | The map container |
| `data-fold-locate` | The *Use my location* button |
| `data-fold-id`, `data-fold-json` | Each server-rendered result — `data-fold-json` is where the first markers come from |

Results that arrive from an asynchronous search are drawn by `fold.js` with the same classes as the
Twig result (`fold-result`, `fold-result__name`, `fold-result__distance`, and so on), so restyle by
class rather than by changing `_result.twig` alone.

### Styles

`fold.css` is plain CSS: single-class selectors, no fonts and no brand colours, with the results
beside the map on wide screens and stacked on narrow ones. The things a site usually wants to change
are custom properties on `.fold`:

```css
.fold {
    --fold-accent: #753fa0;
    --fold-map-height: 36rem;
    --fold-list-height: 36rem;
    --fold-gap: 1.5rem;
    --fold-radius: 6px;
    --fold-border: #e2e4e8;
    --fold-muted: #6b7280;
    --fold-open: #15803d;
    --fold-closed: #b91c1c;
}
```

`.fold--loading` is on the wrapper while a search is in flight.

### Strings

Everything the visitor reads goes through Craft's translation system in the `fold` category, so it
can be changed or translated with a `translations/<locale>/fold.php` file in your site.

## Building your own

If you want your own front end entirely — a framework component, a native app — use the
[JSON endpoint](json-endpoint). It returns the same results the built-in locator gets, from the same
search.

To reuse the runtime on markup you have rendered yourself, register the bundle and give the wrapper
the config:

```twig
{% do view.registerAssetBundle('justinholtweb\\fold\\web\\assets\\locator\\LocatorAsset') %}

<div data-fold-locator data-fold-config="{{ craft.fold.mapConfig() }}" data-fold-strings="{{ { … }|json_encode }}">
    …
</div>
```

Locators added to the page later can be started with `Fold.initAll(container)`.

### Ask for location on load

**Ask for the visitor's location** prompts for the browser's position as soon as the map loads. It
is off by default: an unprompted permission dialog is a poor first impression, and *Use my location*
does the same thing when the visitor asks.
