---
title: Locations and hours
slug: locations-and-hours
order: 30
summary: Addresses, coordinates you can trust, and opening hours that are right at midnight and in the shop's own timezone.
---

A location is a Craft element: it has a title, a slug, a status, a field layout from its group,
element search, relations and the trash. On top of that it carries the facts a store locator
needs — an address, a pair of coordinates, contact details and a week of opening hours.

## The edit screen

*Fold → Locations → New location.*

- **Name** and **Slug**, as on any element.
- **Address** — Craft's own address fields. Changing the country re-renders the fields, because
  which fields an address has is a per-country question: Ireland has no postcode field, Japan
  orders its subdivisions the other way round. Fold asks Craft's address formatter rather than
  guessing.
- **Coordinates** — latitude and longitude, with a **Look up** button.
- **Opening hours** — a row per weekday, and a free-text note.
- Your group's custom fields.
- In the sidebar: **Group**, **Enabled**, **Phone**, **Email**, **Website**, **Timezone**, and
  — with Commerce and Pro — **Commerce inventory location**.

Addresses are `craft\elements\Address` elements owned by the location — the same choice Commerce
made for its inventory locations, which is what lets a linked location copy Commerce's address
rather than translate it. See [Commerce stock](commerce-stock).

## Coordinates

There are four states, and the edit screen's sidebar tells you which one a location is in:

| State | Meaning |
| --- | --- |
| **Awaiting lookup** | Never geocoded, or the address has changed since it last was. |
| **Placed from the address** | The geocoder found it, and the coordinates match the current address. |
| **Could not be placed** | The geocoder was asked and found nothing. |
| **Placed by hand** | Someone typed the coordinates. Fold will not overwrite them. |

**Leave the coordinates blank** and Fold looks them up from the address. **Type them** and the
location is marked as placed by hand, and Fold stops geocoding it — so a pin that someone dragged
onto the correct side of a dual carriageway stays there instead of being put back on the wrong side
every time the location is saved.

**Look up** geocodes the address currently on the form — saved or not — and fills in the two boxes
so you can check the pin before committing to it. It does not save anything; **Save** does. A
location saved with looked-up coordinates is still **geocoded**, not placed by hand, so it keeps
following its address. Change a box yourself after looking up and it becomes placed by hand.

**Look up** is also how a location placed by hand goes back to following its address: press it,
then save.

A location without coordinates **never appears in a search**, of any kind. A locator should not list
a shop whose marker is missing.

See [Geocoding](geocoding) for when lookups happen and what they cost.

## Opening hours

The grid takes one opening and one closing time per weekday, on a 24-hour clock. Leave a day blank
for closed. The **Hours note** is free text shown alongside or instead of the grid — *By
appointment*, *Closed for refurbishment*.

Fold is generous about what a time is. `9:00`, `09:00`, `0900`, `9am` and `17:30:00` all mean what
you think. `24:00` is read as "end of the day" and stored as `23:59`. A range Fold cannot read is
dropped rather than defaulted, so it goes missing from the grid where someone will notice, instead
of showing as `00:00–00:00` and reading as a deliberate "closed".

### Overnight ranges

A closing time earlier than the opening time means **open past midnight**. A bar open
`22:00`–`02:00` on Friday is open at 00:30 on Saturday morning — Fold checks yesterday's ranges as
well as today's to answer that, because an overnight range belongs to the day it started.

### The shop's own timezone

"Open now" is a question about the shop, not the server and not the visitor. Each location has a
**Timezone**; blank means the site's timezone. A Los Angeles branch answered in a server's UTC
would be wrong by eight hours — the difference between open and closed for a whole working day —
so a chain that spans timezones should set this on every location.

### Dated exceptions and split shifts

The hours model also holds dated exceptions (Christmas Day, a stocktake) and more than one range
per day (closed for lunch). An exception with no ranges means closed that day. The edit screen
covers one range per weekday and the note; exceptions and split shifts are set from PHP — a module,
a migration, or a script:

```php
$location->setHours([
    'mon' => [['open' => '09:00', 'close' => '12:30'], ['open' => '13:30', 'close' => '17:30']],
    'tue' => ['09:00-17:30'],
    'sat' => ['open' => '10:00', 'close' => '16:00'],
    'exceptions' => [
        '2026-12-25' => [],                                    // closed
        '2026-12-24' => [['open' => '09:00', 'close' => '13:00']],
    ],
    'note' => 'Late opening on Thursdays in December',
]);

Craft::$app->getElements()->saveElement($location);
```

Exceptions win over the weekday for their date, in "open now", in the next opening time, and in
the JSON endpoint's `hours`.

## Contact details

**Phone** (up to 64 characters), **Email** (validated as an email address) and **Website**
(validated as a URL; `https://` is assumed if left off). Each location also gets a directions link
that opens Google Maps directions to its coordinates — `location.directionsUrl` in Twig,
`directionsUrl` in the JSON.

## The index

*Fold → Locations* is a normal Craft element index, with the columns a store list wants: address,
group, coordinates and open-now by default, with phone, Commerce link, URL and last-updated
available. Searching it matches the address too, so typing a postcode finds the shop on it.

Two sources under **On the map** find the locations that need attention:

- **Awaiting coordinates** — not yet geocoded
- **Could not be placed** — the geocoder found nothing; usually a typo in the address

**Geocode pending** in the toolbar queues a lookup for both.

On Lite, the sidebar shows how many of the 10 locations remain.
