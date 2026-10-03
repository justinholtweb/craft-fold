---
title: Commerce stock
slug: commerce-stock
order: 80
summary: Link a location to a Commerce inventory location and answer "which shop near me has this in stock". Pro.
---

This is the question a store locator gets asked most and can almost never answer: **which shop near
me actually has this in stock?** Craft Commerce 5 knows where its inventory is. Fold asks it.

Requires **Pro** and **Craft Commerce 5**. Commerce is a soft dependency — Fold suggests it and never
requires it — and without it everything on this page is simply absent: no field on the edit screen,
no stock filter, nothing to configure.

## Linking a location

Commerce 5 models a physical place as an **inventory location**. With Commerce installed and Pro
active, a location's edit screen gains a **Commerce inventory location** select in the sidebar.

Linking does two things:

1. **The address comes from Commerce.** On save, the inventory location's address is copied onto
   the Fold location, overwriting whatever was typed. This is one-way, Commerce to Fold, on purpose:
   Commerce's address is what the warehouse, the tax engine and the shipping rules use, and a store
   locator must not be able to move a warehouse by fixing a typo on the front-end map. To change a
   linked location's address, change it in Commerce.
2. **The locator can filter by stock** at that location.

Because both Commerce and Fold store addresses as Craft address elements, this is a copy, not a
translation — nothing is lost between Commerce's fields and Fold's.

## Creating locations from Commerce

If your shops already exist as Commerce inventory locations, don't type them twice:

```sh
php craft fold/commerce/sync --group=retail
```

This creates a Fold location for every inventory location that does not have one, linked and with
its address copied, and refreshes the address of the ones that do. It matches on the inventory
location's ID, so it is safe to run repeatedly: a second run changes nothing, and a run after a
warehouse moves updates the map. New and moved locations are then geocoded like any other.

`--group` defaults to the first group. To see what is linked to what:

```sh
php craft fold/commerce/locations
```

## Filtering by stock

```twig
{% set stockists = craft.fold.locations
    .nearby({ lat: lat, lng: lng, radius: 50 })
    .inStockOf(product.defaultVariant)
    .all() %}
```

`inStockOf` takes a purchasable — a variant, or anything else a plugin has made purchasable — or its
element ID. A second argument sets the minimum quantity:

```twig
.inStockOf(variant, 4)   {# only shops with at least four available #}
```

Only **linked** locations can pass a stock filter. If no inventory location holds the purchasable,
the query returns nothing at all, rather than everything.

The same filter works in a search and on the JSON endpoint:

```twig
{% set results = craft.fold.search({ q: postcode, radius: 50, inStockOf: variant.id }) %}
```

```
GET /fold/search.json?q=28202&radius=50&inStockOf=1234
```

On the endpoint, each location then carries `inStock` — `true` or `false` — and the built-in runtime
shows *In stock* or *Out of stock* on each result.

Exact quantities are **not** published unless you turn on **Publish exact stock counts**, which adds
`availableStock` to each location. "In stock" is what a locator needs; exact counts per shop, from an
anonymous endpoint, are a competitor's inventory report for the price of a loop.

A purchasable ID from a request resolves only to something a visitor could buy — enabled, on the
current site, and available for purchase — so the endpoint cannot be used to read stock for a
disabled or unreleased product.

### Available, not on hand

Stock is read through Commerce's own `Inventory::getInventoryLevelsForPurchasable()`, and Fold uses
the **available** total, not the on-hand total. On-hand includes stock already committed to an order
that has not shipped. A locator that sends somebody across town for the last one — when it is already
in a picking box with a stranger's name on it — has failed at the only thing it was for.

Fold never sums Commerce's inventory ledger itself. Commerce owns that arithmetic and changes how it
does it; a locator that quietly disagreed with the product page would be worse than one that
declined to answer.

## A product page "find it nearby"

```twig
{% set results = craft.fold.search({
    q: craft.app.request.getParam('q'),
    radius: 25,
    inStockOf: product.defaultVariant.id,
}) %}

<form method="get">
    <input type="search" name="q" value="{{ results.term }}" placeholder="Postcode">
    <button>Check stock nearby</button>
</form>

{% if results.originNotFound %}
    <p>We couldn’t find that place.</p>
{% elseif results.term %}
    {% for store in results.locations %}
        <p>{{ store.title }}{% if store.distance is not null %} — {{ store.distance|round(1) }} mi{% endif %}</p>
    {% else %}
        <p>No shops within 25 miles have this in stock right now.</p>
    {% endfor %}
{% endif %}
```

## Without Pro or without Commerce

- With Commerce but on Lite, the link select is not offered, `fold/commerce/sync` refuses,
  `inStockOf` on the JSON endpoint is ignored, and `.inStockOf()` in Twig matches no locations.
  Existing links are not removed by the downgrade, but saving a linked location from the control
  panel while on Lite clears its link, because the select that carries it is not on the form.
- Without Commerce, nothing in Fold references a Commerce class, so there is nothing to break.
