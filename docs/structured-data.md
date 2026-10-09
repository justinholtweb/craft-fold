---
title: Structured data
slug: structured-data
order: 65
summary: LocalBusiness JSON-LD on every store page — address, coordinates, phone, opening hours and holiday hours — with a schema.org type per group.
---

Every location's own page gets [schema.org](https://schema.org/LocalBusiness) structured data in
its `<head>`: the JSON-LD that Google's local results, Maps and the answer engines read. Fold
already holds the facts as facts — the address as an address, the position as coordinates, the
hours as a week of ranges — so nothing has to be typed twice. Available in Lite and Pro.

```json
{
  "@context": "https://schema.org",
  "@type": "Restaurant",
  "@id": "https://example.com/stores/uptown#location",
  "name": "Uptown",
  "url": "https://example.com/stores/uptown",
  "telephone": "+1 704 555 0100",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "201 S Tryon St, Suite 5",
    "addressLocality": "Charlotte",
    "addressRegion": "NC",
    "postalCode": "28202",
    "addressCountry": "US"
  },
  "geo": { "@type": "GeoCoordinates", "latitude": 35.2271, "longitude": -80.8431 },
  "openingHoursSpecification": [
    { "@type": "OpeningHoursSpecification", "dayOfWeek": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"], "opens": "09:00", "closes": "17:00" },
    { "@type": "OpeningHoursSpecification", "dayOfWeek": "Saturday", "opens": "10:00", "closes": "14:00" }
  ],
  "specialOpeningHoursSpecification": [
    { "@type": "OpeningHoursSpecification", "validFrom": "2026-12-25", "validThrough": "2026-12-25", "opens": "00:00", "closes": "00:00" }
  ]
}
```

## What goes in it

| Property | From |
| --- | --- |
| `@type` | The group's **Schema.org type** — `LocalBusiness` when blank |
| `name`, `url`, `@id` | The title and the location's page (or its website, if it has no page) |
| `telephone`, `email` | The location's phone and email |
| `sameAs` | The location's website, when its page is the `url` |
| `address` | The address, as a `PostalAddress` |
| `geo` | The coordinates |
| `openingHoursSpecification` | The week. Days with the same hours share one entry; an overnight range is written as it is (`22:00`–`02:00`) |
| `specialOpeningHoursSpecification` | Dated exceptions from today on. A closed day is `00:00`–`00:00`, which is how schema.org says closed; past exceptions are left out |

Anything empty is left out rather than printed empty. A note-only location ("By appointment") has
no opening-hours entries — there are no hours to state.

## A type per group

*Settings → Fold → Groups → (group) → Schema.org type.* Use the most specific type that fits —
`Restaurant`, `AutoRepair`, `Pharmacy`, `Store`, `BankOrCreditUnion` — because a search engine
can do more with a dentist it knows is a `Dentist`. The field suggests the common ones and accepts
any schema.org type name; anything that is not one is refused on save.

## Automatic, on store pages

With **Add LocalBusiness structured data to location pages** on (*Settings → Fold → Structured
data*, `injectSchema`, on by default), Fold adds the block to the `<head>` of every page Craft
routes to a location through its group's URI format. Nothing in the template is needed.

It is skipped while **SEOmatic** is installed: SEOmatic owns the page's JSON-LD, and two blocks
describing one page in different words is what makes a search engine trust neither. Place it
yourself with `craft.fold.schema()` if you want it alongside SEOmatic's.

## In a template

```twig
{% block head %}
    {{ craft.fold.schema(location) }}
{% endblock %}
```

For a page that shows a location without being its own page — a "visit us" section on the home
page — or with automatic injection off. On a location's own page with injection on, the call
prints nothing, so a template written either way never ends up with two.

Overrides are merged in at the top level:

```twig
{{ craft.fold.schema(location, {
    image: location.photo.one().url ?? null,
    priceRange: '$$',
}) }}
```

`craft.fold.schemaData(location)` returns the same thing as an array, to change in Twig or to hand
to another SEO plugin.

## From a module

```php
use justinholtweb\fold\events\DefineSchemaEvent;
use justinholtweb\fold\services\Schema;
use yii\base\Event;

Event::on(Schema::class, Schema::EVENT_DEFINE_SCHEMA, function(DefineSchemaEvent $event) {
    $event->schema['parentOrganization'] = ['@type' => 'Organization', 'name' => 'Acme Coffee'];
});
```

The event runs for automatic injection, `craft.fold.schema()` and `craft.fold.schemaData()` alike.

The block is encoded so that nothing in a location's name or address can close the `<script>` tag.
