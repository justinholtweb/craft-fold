---
title: Console commands
slug: console-commands
order: 100
summary: Getting several hundred shops in, geocoded and back out again — without a browser timing out.
---

The commands a store locator needs on the command line: import a chain's worth of shops, geocode
them without a web request timing out, export them again, and sync from Commerce.

## All of them

| Command | What it does | Edition |
| --- | --- | --- |
| `fold/locations/groups` | Lists location groups and how many locations each holds | Lite |
| `fold/locations/geocode` | Geocodes every location that needs it, inline, with progress | Lite |
| `fold/locations/queue-geocoding` | Queues the same work for the queue runner instead | Lite |
| `fold/locations/import <file.csv>` | Imports locations from a CSV | Pro |
| `fold/locations/export` | Exports locations as CSV or JSON | Pro |
| `fold/commerce/sync` | Creates or updates a location for every Commerce inventory location | Pro + Commerce |
| `fold/commerce/locations` | Lists Commerce inventory locations and what each is linked to | Commerce |
| `fold/searches/purge` | Deletes search log rows older than `--days` | Lite |

## Geocoding

```sh
php craft fold/locations/geocode
php craft fold/locations/geocode --group=retail
php craft fold/locations/geocode --force
```

Runs in the foreground and prints each location as it is placed — `✓ Uptown  35.22690, -80.84330`
or `✗ Ballantyne  The address could not be placed on the map.` — then a summary.

Without `--force`, only locations that need it are looked up: never geocoded, not found, or whose
address has changed. **Locations placed by hand are skipped.** `--force` looks up every location
again, placed by hand or not, so use it deliberately.

With Nominatim selected, the command waits 1.1 seconds between lookups, as Nominatim's usage policy
requires — and every Nominatim request on the site shares one lock that keeps them a second apart, so
a geocode run and the live locator cannot add up to more than the policy allows. 340 shops is a six-minute command. That is the honest cost of a free geocoder, and why
this is a console command rather than a button that would time out.

```sh
php craft fold/locations/queue-geocoding
```

Pushes one queue job per location that is awaiting coordinates or could not be placed, for sites
that would rather the queue did the waiting. This is what **Geocode pending** on the locations index does too.

## Import and export (Pro)

```sh
php craft fold/locations/import stores.csv --group=retail --dry-run
php craft fold/locations/import stores.csv --group=retail
php craft fold/locations/export --format=json --file=stores.json
```

| Option | Applies to | Meaning |
| --- | --- | --- |
| `--group=<handle>` | import, export, geocode | Which group. Required for import when there is more than one group. |
| `--dry-run` | import | Parse and report; write nothing |
| `--format=csv\|json` | export | Defaults to `csv` |
| `--file=<path>` | export | Where to write; stdout if omitted |
| `--force` | geocode | Re-geocode everything |

Import exits non-zero if any row failed. See [Import and export](import-export) for the columns.

## Commerce

```sh
php craft fold/commerce/sync --group=retail
php craft fold/commerce/locations
```

`sync` matches on the inventory location's ID, so it is safe to run on a schedule: a second run
reports everything unchanged, and a run after a warehouse moves updates its address. `--group`
defaults to the first group. See [Commerce stock](commerce-stock).

## Search log

```sh
php craft fold/searches/purge              # uses the Keep searches for setting
php craft fold/searches/purge --days=30
```

Craft's garbage collection already prunes the log to **Keep searches for**; this is for doing it now,
or on a cron of your own. If retention is set to keep searches forever (`0`) and no `--days` is given,
it refuses rather than guessing.

## Exit codes

Every command exits non-zero when it refuses — no group given where one is needed, an unknown group
handle, a Pro command on Lite, Commerce not installed — so a script that runs them stops where it
should.
