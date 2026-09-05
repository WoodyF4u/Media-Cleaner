# Media Cleaner

A Joomla 6 backend component that scans your entire website for media files (images, video, audio, PDF) and shows a sortable, filterable overview with thumbnails, file sizes, locations, and whether each file is still linked anywhere on the site.

## Features

- Recursive site-wide media scan with a cached, sortable/filterable overview
- Linked-status check across articles, modules, categories, menu items, custom fields, contacts and banners, with deep links to the exact edit screen
- Deep links shown under each linked file's name, pointing to exactly where it's used
- Ignore list for files you want to keep but that show as unused
- Safe deletion: unlinked files move to a protected quarantine folder first, restorable or permanently removable later
- Bulk actions (delete / ignore / restore) via a single dropdown
- One-click WebP compression for linked images, saved alongside the original
- Dark mode support
- Built-in Help page

## Requirements

- Joomla 6.x
- MySQL

## Installation

1. Download the latest release `.zip` from the [Releases](../../releases) page.
2. In the Joomla administrator, go to **System → Install → Extensions** and upload the zip.
3. Open **Components → Media Cleaner** and run **Scan opnieuw uitvoeren / Rescan**.

## Updates

This component ships with a Joomla update server, so new releases show up automatically under **System → Manage → Updates** once published here on GitHub.

## License

GNU General Public License version 2 or later.
