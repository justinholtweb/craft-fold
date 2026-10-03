---
title: Import and export
slug: import-export
order: 90
summary: CSV in, CSV or JSON out, and a round trip through a spreadsheet that updates rather than duplicates. Pro.
---

The realistic shape of this job: someone has a spreadsheet of 340 shops exported from whatever the
last site ran on, and the columns are named whatever that system called them. Fold's importer is
generous about column names, strict about what it writes, and never guesses at a coordinate.

Import and export are **Pro**, and run from the command line.

## Importing a CSV

```sh
php craft fold/locations/import stores.csv --group=retail --dry-run
php craft fold/locations/import stores.csv --group=retail
php craft fold/locations/geocode
```

`--dry-run` parses the whole file and reports what it would import and update without writing
anything. `--group` can be left off when there is only one group.

The import itself never waits on a geocoder. Rows without coordinates are queued for a lookup as
they are saved (while **Look up coordinates automatically** is on); `fold/locations/geocode`
places them inline instead, with progress, if you would rather watch it happen.

The command exits non-zero if any row failed, and prints each failure with its line number.

### Columns

The first row is the header. Names are matched case-insensitively with spaces and punctuation
ignored, so `Address 1`, `address_1` and `ADDRESS1` are the same column.

| Fold reads | From any of |
| --- | --- |
| Name (**required**) | `title`, `name`, `store name`, `store`, `location`, `location name` |
| Address line 1 | `address line 1`, `address 1`, `address`, `street`, `street address` |
| Address line 2 | `address line 2`, `address 2`, `street 2` |
| Town | `locality`, `city`, `town` |
| State / county | `administrative area`, `state`, `province`, `region`, `county` |
| Postcode | `postal code`, `postcode`, `zip`, `zip code` |
| Country | `country code`, `country` — the first two letters are used, so give ISO codes (`US`, `GB`) |
| Coordinates | `lat`, `latitude` and `lng`, `lon`, `long`, `longitude` |
| Phone | `phone`, `telephone`, `tel`, `phone number` |
| Email | `email`, `email address` |
| Website | `website url`, `website`, `url`, `web` |
| Timezone | `timezone`, `tz` |
| Slug | `slug` |
| Hours | one column per day: `mon` … `sun`, or `hours_mon`, or `mon_hours` |

Hours cells take a single range such as `09:00-17:30`, `9am-5:30pm` or `09:00 to 17:30`. Leave a
cell empty for closed. A row with any hours replaces that location's whole week; a row with none
leaves the existing hours alone.

A UTF-8 byte-order mark — which Excel writes on every save — is stripped from the header, so the
first column is not silently unmatchable.

### Coordinates in the file

If a row has both a latitude and a longitude, they are **trusted**: the location is marked as placed
by hand, and the geocoder will not overwrite somebody's carefully corrected pin with its own guess.
A row without coordinates is imported anyway and queued for geocoding — never dropped, and never
placed at `0,0`.

### Running it twice

Rows are matched to existing locations in the group **by slug** when the file has a slug column,
otherwise **by name**. A second run updates what the first created instead of duplicating it — which
is the difference between a repeatable sync and a one-shot you can never re-run.

On an update, an empty cell does not blank the phone, email, website or timezone already there.
Address columns that are present in the file are written as they are, empty included.

On Lite, the import refuses. On Pro there is no cap.

## Exporting

```sh
php craft fold/locations/export --file=stores.csv
php craft fold/locations/export --format=json --file=stores.json
php craft fold/locations/export --group=retail          # to stdout
```

The CSV has the columns the importer reads — `id`, `title`, `slug`, the address parts, `lat`, `lng`,
`phone`, `email`, `websiteUrl`, `timezone`, and a column per weekday — so an export is a round trip:
pull the shops out, fix them in a spreadsheet, and import the same file back. It starts with a
byte-order mark so Excel opens accented town names correctly.

A text cell that starts with `=`, `+`, `-` or `@` is written with a leading apostrophe, so a
spreadsheet treats it as text rather than running it as a formula — anyone who can name a location
could otherwise put `=HYPERLINK(…)` in a title and have it run on whoever opens the export. Numbers,
including negative longitudes, are left alone. The importer strips the apostrophe again, so a phone
number like `+1 704 …` survives the round trip.

The JSON export carries a little more: the group handle, the address as an object, the full hours
document (exceptions and the note included) and `commerceInventoryLocationId`. It is for backups and
for other systems; the importer reads CSV.

Every location is exported, enabled or not, once each regardless of how many sites it is on.
