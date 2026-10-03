---
title: Location groups
slug: location-groups
order: 20
summary: What a group carries — field layout, per-site URLs and templates — and why there can be more than one.
---

Every location belongs to exactly one group. A group is to Fold what a section is to entries: it
decides which fields a location has, which sites it exists on, and whether it gets a page of its
own.

Groups are why "Retail Stores" and "Service Centres" can be different things. A service centre
wants *Bays* and *Brands serviced*; a shop wants *Store manager* and *Click & collect*. One
settings screen could not give both a field layout and a URL, so groups exist.

## Creating a group

*Fold → Groups → New location group*, or *Settings → Fold → Groups*. Groups are a settings screen,
so they are **admin-only**.

| Setting | What it does |
| --- | --- |
| **Name**, **Handle** | The handle is what templates and the JSON endpoint use: `group: 'retail'`. |
| **Marker colour** | The colour of this group's pins on the built-in locator's map (all three providers). Blank keeps the provider's default pin. Also available in templates as `location.group.color`, and as `color` on each location in the [JSON endpoint](json-endpoint). |
| **Default country** | Two-letter code new addresses in this group start in. Blank falls back to the plugin's **Default country**. |
| **Field layout** | Any Craft fields you like, laid out with the usual designer. |

Lite allows **one** group; Pro allows any number. See [Editions](editions).

## Site settings

Per site, the same shape Craft gives a section:

| Setting | What it does |
| --- | --- |
| **Available on this site** | Whether the group's locations exist on this site at all. A group must be available on at least one site. |
| **Locations enabled by default** | Whether a new location starts enabled here. |
| **Location URI format** | e.g. `stores/{slug}`. Blank means locations in this group get no page on this site. |
| **Template** | The template that renders the page. Required whenever a URI format is set. |

A new group starts available on every site with URLs off. That is the choice that surprises nobody
on a single-site install, and it is one click to narrow on a multisite one.

### Store pages

With a URI format and a template set, every location gets a URL, and the template receives the
location as `location`:

```twig
{# templates/stores/_entry.twig #}
{% extends '_layouts/site' %}

{% block content %}
    <h1>{{ location.title }}</h1>
    <address>{{ location.getFormattedAddress({ html: false })|nl2br }}</address>

    {% if location.phone %}<a href="tel:{{ location.phone }}">{{ location.phone }}</a>{% endif %}
    <a href="{{ location.directionsUrl }}">Directions</a>

    <p>{{ location.isOpenNow() ? 'Open now' : 'Closed' }}</p>
{% endblock %}
```

See [Twig](twig) for everything a location exposes.

## What is per site and what is not

Locations are localized elements, but not everything about them is translated:

- **Shared across sites:** the address, the coordinates, the phone, email and website, the opening
  hours, the timezone and the Commerce link. A shop does not move, or open earlier, because a
  visitor switched to the Spanish site.
- **Per site:** the title, the slug, and every custom field in the layout — translated or not
  according to each field's own translation method. So the Spanish site can say *Tienda del
  centro* about the same building at the same coordinates.

## Project config

Groups live in project config under `fold.locationGroups.<uid>`, field layout included, so they
deploy with the rest of the site. On an environment with `allowAdminChanges` off, edit groups
locally and deploy them like any other schema change.

## Deleting a group

Deleting a group **permanently deletes every location in it** — each one through Craft's element
service, so its address, search index entries and relations are cleaned up too. There is no trash
for this; export the group first if you might want it back (`php craft fold/locations/export
--group=<handle>`, Pro).

## Relating content to locations

Fold adds a **Locations** relation field. Use it wherever a piece of content belongs to shops:
an event that happens at three branches, a brand stocked at some of them, a member of staff who
works at one.

```twig
{% for store in entry.stockists.all() %}
    <a href="{{ store.url }}">{{ store.title }}</a>
{% endfor %}
```

It behaves exactly like an Entries field — eager loading, the element selector modal, and
`relatedTo` all work — which also means a locator can be filtered by a relation:

```twig
{{ craft.fold.locator({ group: 'retail', relatedTo: brand }) }}
```
