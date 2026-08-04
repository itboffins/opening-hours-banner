# ITBoffins Live Opening Hours Banner & Table

Show a dynamic opening-hours table and a friendly **We're open / Sorry, we're closed** banner from the same locally stored WordPress schedule. It is free, self-hosted, and works on ordinary WordPress hosting.

> **Note on this repository.** This plugin is fully self-contained and is intended to live in its own repository (`itboffins/itboffins-live-opening-hours`). It was scaffolded alongside *Local Image Optimiser* for convenience; the `itboffins-live-opening-hours/` folder can be moved into a standalone repo as-is.

## Why

Customers should not have to dig around to find out whether a business is open. This plugin gives small business owners a simple place to set regular hours, holidays, early finishes, late openings, and one-off changes.

The main USP is that both the opening-hours table and the notice bar stay live from the saved schedule. A cached page is still fast, but the customer-facing status can update in the browser without a manual edit or page rebuild.

## Features

- **Dynamic opening-hours table** for regular hours and upcoming changes.
- **Friendly live notice bar** for open, closed, opening soon, and closing soon messages.
- **Loading-state notice bar** so cached pages do not flash stale text before the live status is ready.
- **Flexible banner layout** with sticky/non-sticky and top spacing/overlay controls.
- **Simple weekly-hours editor** with time pickers, lunch breaks, and one-click weekday copying.
- **Holidays and one-off changes** for bank holidays, Christmas, special Saturdays, early closes, and appointment-only dates.
- **UK bank-holiday helper** to add standard England & Wales, Scotland, or Northern Ireland bank holidays as closed dates for a selected year.
- **Bank-holiday priority** so saved bank holidays override same-date manual special dates.
- **Separate bank-holiday notice controls** to show bank holidays within 7 days, all future bank holidays, or no bank holidays in upcoming notices.
- **Upcoming changes** so customers can see future closures or special hours in advance.
- **Cleaner notice layout** with upcoming dates and times shown as separate, wrapped date/detail items.
- **Automatic cleanup** so expired special dates drop out after any overnight opening has finished.
- **Special-dates-only mode** for pop-ups, events, seasonal venues, and appointment-led businesses.
- **Timezone-aware** using the site's WordPress timezone, including daylight saving changes.
- **Overnight hours** for late-night venues, such as 22:00-02:00.
- **Block and shortcode** to show hours or status anywhere on the site.
- **Theme-friendly typography** so public banner and table text inherit the site's body/content font.
- **No accounts or tracking**. No external API, no API keys, and nothing sent off-site.

The schedule is evaluated in each visitor's browser so cached pages stay current. Its
hours, dates, labels, and public notes are therefore included in the page data. Do not
put confidential information in a schedule note.

## How it stays fresh on fast sites

When the public page loads, the browser uses the saved schedule to decide what customers should see. It checks again every 30 seconds and when a visitor returns to the tab, so the message can change without a page reload. The site timezone is used, so daylight saving follows the WordPress settings. A PHP fallback keeps the page sensible if JavaScript is unavailable.

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
includes/
  class-iboh-settings.php     Options array: defaults / all / get / sanitize
  class-iboh-timezone.php     Site timezone -> IANA name + DST-aware offset
  class-iboh-config.php       Canonical config shared by PHP + JS
  class-iboh-evaluator.php    Server-side open/closed (no-JS fallback)
  class-iboh-frontend.php     Asset enqueue, localize, banner injection
  class-iboh-shortcode.php    [iboh_opening_hours] + table/status renderers
  class-iboh-block.php        Server-rendered block (reuses the shortcode)
  class-iboh-admin.php        Settings page + schedule editor
assets/
  admin.css / admin.js        Schedule editor + customer preview
  frontend.css / frontend.js  Notice bar + live status logic
  editor.js                   Block editor (no build step)
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
