# Media Cleaner

A Joomla 6 administrator component that finds unused media files on your
site, shows exactly where the ones that are still in use are referenced
from, and lets you clean up the rest safely.

Author: **Wouter** ([WoodyF4u](https://github.com/WoodyF4u)) · [CompactWeb](https://compactweb.nl)
Source & releases: **https://github.com/WoodyF4u/Media-Cleaner**
Questions or issues: please [open a GitHub issue](https://github.com/WoodyF4u/Media-Cleaner/issues) on the repository above.

*[Deze README is ook beschikbaar in het Nederlands: README.nl.md](README.nl.md)*

---

## What it does

Media Cleaner scans your site's file system and database, then tells you,
for every image, document, video and other media file it finds:

- whether it's **linked** (still referenced from somewhere) or
  **unlinked** (nothing points to it anymore),
- exactly **where** it's used, when it is linked, with a direct edit link,
- and gives you safe, reversible ways to deal with the files that aren't.

Nothing is ever deleted permanently without an explicit, separate
confirmation step.

## How the "linked" check works

A rescan checks every discovered file against:

1. Joomla's own content — articles, modules, categories, menu items,
   custom fields, contacts and banners.
2. **Every other database table on the site**, generic text/varchar
   columns included — so third-party extensions (event calendars,
   download managers, galleries, and so on) are picked up automatically,
   without Media Cleaner needing to know their table names in advance.
3. The **source code** (`.php`, `.css`, `.js`, `.xml`) of everything
   under `templates/`, `plugins/`, `components/` and `modules/` — so
   files referenced from a template's own layout, a plugin's or
   component's bundled assets, are found too.

A file counts as:

- **Linked** (green) — its full path was found somewhere.
- **Probably linked** (orange) — only its exact file name was found
  (some extensions store just a bare filename, not the folder). Almost
  always a real reference, but slightly less certain than a full-path
  match — worth a quick manual check before deleting.
- **Unlinked** — nothing was found. Still not an absolute guarantee the
  file is safe to delete (see limitations below), but a strong signal.

### Limitations

Media Cleaner can't detect a file that's referenced only *outside* the
areas above — for example from a hand-edited server configuration file,
or from code outside the four scanned extension folders. Always take a
quick look before deleting a file marked "Unlinked", especially if it's
large or looks important.

## Reducing false positives: the Options screen

Some files that are technically "unlinked" by the check above are still
completely legitimate — they belong to an extension that just hasn't
called them yet, or they're generated caches. The Options screen
(**Options → Settings** tab) has three switches, each independently
toggling whether that category is hidden from the "Unlinked media" list
and its count (the files themselves are **never** touched by these
switches — they always remain visible under "All media" and "Linked
media", and still show up as unlinked again the moment the switch is
turned back off):

| Switch | Hides from Unlinked... |
|---|---|
| `'thumbs'` folders inside `'images'` | Files in any folder literally named `thumbs` (e.g. `/images/icagenda/thumbs/...`) — usually auto-generated thumbnail caches whose filenames are derived on the fly by the owning extension, so they never appear as a literal match in the database. |
| Files from active components/modules/plugins/templates | Files under `/components/<element>/...`, `/modules/<element>/...`, `/plugins/<group>/<element>/...` or `/templates/<element>/...`, but **only** while that extension is still installed and enabled. |
| Media files from active extensions | Files under `/images/<element>/...` (matching either the full element, e.g. `com_jdownloads`, or the bare name, e.g. `icagenda`) — several extensions create their own upload/thumbnail folder directly under `/images/`. Also only while that component is still active. |

All three default to **Yes**. Saving the Options screen automatically
triggers a fresh rescan in the background (with a progress bar), so
changes take effect immediately without an extra manual step.

For anything that remains genuinely unlinked and sits in a
`components/`, `modules/`, `plugins/` or `templates/` folder that
**doesn't** match a currently active extension, the overview adds a small
note under the file's location: *"May belong to a Joomla extension that
is no longer installed"* — a strong hint it's leftover data from
something that was later disabled or uninstalled.

## The main overview

**Filter bar:** All media / Linked media / Unlinked media, plus two more:
Show ignored media, and Show deleted media (opens the quarantine
overview — see below).

**Columns:** Preview thumbnail, File name, File size, Location, Type,
Linked status, and an Action column with the buttons below. For linked
files, the file name row also expands to show every place the file is
referenced, each with a direct link to that item's own edit screen.

**Per-file / bulk actions** (bulk via the "Action" dropdown above the
table, for one or more selected unlinked files):

- **Ignore** — marks a file as deliberately kept, so it stops showing up
  as something needing attention. Nothing is moved. **Restore ignored**
  undoes it.
- **Delete (temporary)** — moves the file to a protected quarantine
  folder, unreachable from the live website but not yet permanently
  gone.
- **Compress to WebP** (linked files only) — creates a new, compressed
  WebP copy alongside the original image; nothing is overwritten. You
  still need to manually update the reference to point at the new file.

**Quarantine** ("Show deleted media"): restore a file to its original
location, or delete it permanently — a real, unrecoverable delete, kept
as a deliberate second, separate step from the temporary removal above.

## Requirements

- Joomla 6.x
- MySQL or MariaDB (uses `information_schema` for a couple of internal
  self-checks)

## Installation & updates

Install like any Joomla extension: **System → Install → Extensions**,
upload the zip from the [releases page](https://github.com/WoodyF4u/Media-Cleaner/releases).
Media Cleaner registers its own update site, so future versions also
show up under **System → Update → Extensions**.

After installing or updating, visit the main Media Cleaner page once and
run a rescan — several features rely on data computed at scan time, so a
fresh scan after an update ensures the overview reflects the newest
logic.

## Permissions

The **Rechten**/**Permissions** tab on the Options screen controls who
can manage this component, using Joomla's standard access control list.

## Support

This is an independently maintained, free and open-source project. For
bugs, feature requests or questions, please use
[GitHub Issues](https://github.com/WoodyF4u/Media-Cleaner/issues) on the
repository linked at the top of this file.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full, version-by-version history.

## License

GNU General Public License version 2 or later. See [LICENSE.txt](LICENSE.txt).
