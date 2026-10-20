# ITBoffins Live Opening Hours Banner & Table

Show a dynamic opening-hours table and a friendly **We're open / Sorry, we're closed** banner from the same locally stored WordPress schedule. It is free, self-hosted, and works on ordinary WordPress hosting.

> This is the development repository for the WordPress.org plugin [itboffins-live-opening-hours](https://wordpress.org/plugins/itboffins-live-opening-hours). Release copies live in the (git-ignored) `svn/` folder.

## Why

Customers should not have to dig around to find out whether a business is open. This plugin gives small business owners a simple place to set regular hours, holidays, early finishes, late openings, and one-off changes.

The main USP is that both the opening-hours table and the notice bar stay live from the saved schedule. A cached page is still fast, but the customer-facing status can update in the browser without a manual edit or page rebuild.

## Features

- **Dynamic opening-hours table** for regular hours and upcoming changes.
- **Friendly live notice bar** for open, closed, opening soon, and closing soon messages.
- **Loading-state notice bar** so cached pages do not flash stale text before the live status is ready.
- **Flexible banner layout** with sticky/non-sticky and top spacing/overlay controls.
- **Simple weekly-hours editor** with an Open / Closed switch per day, time pickers, lunch breaks, and one-click copying of Monday's hours.
- **Timezone of your own** - follow the WordPress site timezone or pin the schedule to a city, with a live clock on the settings screen and a warning when a fixed UTC offset (no daylight saving) is in use.
- **Holidays and one-off changes** for bank holidays, Christmas, special Saturdays, early closes, and appointment-only dates.
- **UK bank-holiday helper** to add standard England & Wales, Scotland, or Northern Ireland bank holidays as closed dates for a selected year.
- **Bank-holiday priority** so saved bank holidays override same-date manual special dates.
- **Separate bank-holiday notice controls** to show bank holidays within 7 days, all future bank holidays, or no bank holidays in upcoming notices.
- **Upcoming changes** so customers can see future closures or special hours in advance.
- **Cleaner notice layout** with upcoming dates and times shown as separate, wrapped date/detail items.
- **Automatic cleanup** so expired special dates drop out after any overnight opening has finished.
- **Special-dates-only mode** for pop-ups, events, seasonal venues, and appointment-led businesses.
- **Daylight-saving aware** whenever a city timezone (not a fixed UTC offset) is chosen.
- **Cache-friendly saves** that ask common page-caching plugins to refresh when the schedule changes.
- **Overnight hours** for late-night venues, such as 22:00-02:00.
- **Block and shortcode** to show hours or status anywhere on the site.
- **Theme-friendly typography** so public banner and table text inherit the site's body/content font.
- **No accounts or tracking**. No external API, no API keys, and nothing sent off-site.

The schedule is evaluated in each visitor's browser so cached pages stay current. Its
hours, dates, labels, and public notes are therefore included in the page data. Do not
put confidential information in a schedule note.

## How it stays fresh on fast sites

When the public page loads, the browser uses the saved schedule to decide what customers should see. It checks again every 30 seconds and when a visitor returns to the tab, so the message can change without a page reload. The schedule timezone (the WordPress site timezone unless the plugin's own Timezone setting overrides it) is used, so daylight saving follows the chosen city. A PHP fallback keeps the page sensible if JavaScript is unavailable, and a shipped table of upcoming offset changes keeps browsers without time-zone support correct across daylight-saving changes on cached pages.

The schedule itself is part of the page, so when it is edited the plugin asks common page caches (WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache, LiteSpeed Cache, SiteGround Optimizer, Autoptimize, Cache Enabler, Breeze, Hummingbird, Nginx Helper) to purge, and fires `iboh_schedule_updated` for anything else.

Displayed hours follow the WordPress time format for the wall-clock tokens `G`, `H`,
`g`, `h`, `i`, `s`, `a`, and `A`, including backslash-escaped literals. Other PHP
date-format characters remain literal because a saved opening time is a wall time,
not a complete timestamp from which timezone- or instant-dependent tokens can be
calculated reliably.

## Usage

- **Admin:** *Live Opening Hours* in the WordPress menu.
- **Shortcode:** `[iboh_opening_hours show="table|status|both"]`
- **Block:** search for *Live Opening Hours* in the block inserter.

## Structure

```
itboffins-live-opening-hours.php      Bootstrap (constants, singleton, activation)
uninstall.php                 Removes the option (multisite-safe)
readme.txt                    WordPress.org readme
includes/
  class-iboh-settings.php     Options array: defaults / all / get / sanitize
  class-iboh-timezone.php     Schedule timezone (site or override) -> IANA name, offsets, labels
  class-iboh-config.php       Canonical config shared by PHP + JS
  class-iboh-evaluator.php    Server-side open/closed (no-JS fallback)
  class-iboh-frontend.php     Asset enqueue, localize, banner injection
  class-iboh-shortcode.php    [iboh_opening_hours] + table/status renderers
  class-iboh-block.php        Server-rendered block (reuses the shortcode)
  class-iboh-cache.php        Asks page caches to purge when the schedule changes
  class-iboh-admin.php        Settings page + schedule editor
assets/
  admin.css / admin.js        Schedule editor + customer preview
  frontend.css / frontend.js  Notice bar + live status logic
  editor.js                   Block editor (no build step)
  logo.png                    Admin header logo
languages/                    Translation template
tests/
  php-regression.php          PHP fallback + settings checks
  frontend-evaluator.test.js  Browser evaluator + admin helper checks
```

## Development

Plain PHP WordPress plugin: no build step, Composer, or npm install is required. Targets
the [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards).

Regression checks use the PHP and Node.js runtimes directly:

```text
php tests/php-regression.php
node --test tests/frontend-evaluator.test.js
```

## License

GPL-2.0-or-later.
