---
title: Permissions
slug: permissions
order: 120
summary: Who can see, edit and delete locations — and why groups and settings are admin-only.
---

Fold registers four permissions, under **Fold** in *Settings → Users → User Groups*:

| Permission | Allows |
| --- | --- |
| **View locations** | The locations index and edit screens — but not saving |
| ↳ **Create and edit locations** | Saving and duplicating locations; *Look up*; *Geocode pending* |
| ↳ **Delete locations** | Deleting locations |
| **View the search log** | The *Searches* report (Pro). Clearing the log also needs **Create and edit locations**. |

**Create and edit** and **Delete** are nested under **View**, as Craft does with its own: you cannot
edit what you cannot see.

**View the search log** is deliberately *not* nested. The log is what visitors typed and roughly where
they were (see [what it stores](search-and-radius#what-the-log-stores-for-your-privacy-notice)), and
being trusted to edit a shop's phone number is no reason to read that. Grant it to whoever plans where
the next shop goes.

## Multisite

On a multisite install, saving a location also needs Craft's own **Edit “site”** permission for the
site being edited — the same rule Craft applies to entries, since a location's title, slug and custom
fields are per site.

A shop manager who should keep their own opening hours current needs **View** and **Create and
edit**. Leave **Delete** with whoever owns the store list. A deleted location goes to Craft's trash
and can be restored from there, but until it is, its page, its relations and its pin are gone.

## Groups and settings are admin-only

Location groups and plugin settings are settings screens, so they are available to admins only, and
their subnav items only appear for admins. Groups are also project config: on an environment with
`allowAdminChanges` off, nobody can change them there, admin or not. That is Craft's behaviour and
the right one — a group's field layout is schema, and schema is authored locally and deployed.

## The front end is public

The [JSON endpoint](json-endpoint) and `craft.fold.locator()` are anonymous by design: a store
locator is for people who are not logged in. They only return **enabled** locations on the current
site, and the endpoint's response is a fixed set of fields, so nothing on a location that is not
meant for the public — a custom field, a disabled branch — reaches it.
