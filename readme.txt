=== ITBoffins Live Opening Hours Banner & Table ===
Contributors: itboffins
Tags: opening hours, business hours, store hours, open closed, live status
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.2
Stable tag: 1.1.13
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A live open/closed banner and opening-hours table powered by one private WordPress schedule.

== Description ==

**ITBoffins Live Opening Hours Banner & Table** helps small businesses show dynamic opening hours on a page, plus a friendly *We're open / Sorry, we're closed* notice bar.

The opening-hours table and live banner both work from the same schedule you save in WordPress. They update in the visitor's browser, so customers see the right opening status even when your site is cached for speed.

= Why you'll like it =

* **One schedule, two customer views.** Power a dynamic opening-hours table and live banner from the same WordPress settings.
* **Dynamic opening-hours table.** Show your regular week and upcoming changes on any page with a block or shortcode.
* **Live open/closed banner.** Tell customers if you are open, closed, opening soon, or closing soon.
* **Flexible banner layout.** Keep the notice sticky or let it scroll away, and choose whether a top notice makes room or sits over the page.
* **Easy weekly hours.** Set your regular week with simple time pickers, lunch breaks, and one-click weekday copying.
* **Holidays & one-off changes.** Add bank holidays, Christmas closures, early finishes, special Saturdays, or appointment-only dates.
* **UK bank-holiday helper.** Add the standard England & Wales, Scotland, or Northern Ireland bank holidays as closed dates for the year.
* **Upcoming changes.** Tell customers about future closures or special hours before the day arrives.
* **Separate bank-holiday notices.** Show bank holidays only within 7 days, show all future bank holidays, or keep them out of upcoming notices while manual special dates stay visible.
* **Cleaner notice layout.** Multiple upcoming dates wrap as separate date/time items, with the date in bold and the hours in normal weight.
* **Automatic cleanup.** Past special dates drop out of the admin list, preview, banner data, and the hours table.
* **Special-dates-only mode.** Turn your regular week off entirely for pop-ups, events, or by-appointment businesses.
* **Works with fast/cached sites.** The page hours and live banner stay fresh even when WordPress or your host caches the page.
* **Uses your WordPress timezone.** Handles daylight saving automatically.
* **Overnight hours.** Late-night venues are handled, such as 22:00-02:00.
* **Genuinely free & private.** No external API, no account, no tracking, and nothing sent off-site.

== Installation ==

1. Upload the `itboffins-live-opening-hours` folder to `/wp-content/plugins/`, or install through the Plugins screen.
2. Activate the plugin.
3. Go to **Live Opening Hours** in the admin menu, set your weekly hours, and save.
4. Optionally add the *Live Opening Hours* block or the `[iboh_opening_hours]` shortcode to a page.

== Frequently Asked Questions ==

= What makes it live? =
The page table and notice bar use your saved schedule in the visitor's browser. That lets the open/closed message and today's row stay current without you editing the page.

= Does it still work if my site is cached or uses WP Rocket? =
Yes. If your site uses caching to stay fast, the opening-hours table and open/closed banner still update from the hours you set.

= Which timezone is used? =
Your site's WordPress timezone (Settings -> General). If you use a named timezone, such as Europe/London, daylight saving is handled automatically.

= Can I quickly add bank holidays? =
Yes. In **Live Opening Hours**, use the bank-holiday helper inside *Holidays & one-off changes*. Choose England & Wales, Scotland, or Northern Ireland, pick the year, and add the standard dates as closed special dates. One-off public holidays can still be added manually.

= Can I set a lunch break or split hours? =
Yes. Add more than one time range to a day, for example 09:00-12:00 and 13:00-17:00.

= Can I open past midnight? =
Yes. Set a closing time earlier than the opening time, for example 22:00-02:00.

= How do I show hours on a page? =
Use the *Live Opening Hours* block in the editor, or paste one of these shortcodes into a page: `[iboh_opening_hours show="table"]`, `[iboh_opening_hours show="status"]`, or `[iboh_opening_hours show="both"]`.

= Does it send any data anywhere? =
No. There is no external API and no tracking. Everything runs on your own site.

== Privacy ==

ITBoffins Live Opening Hours Banner & Table stores the schedule and notice-bar settings in your WordPress database. It does not connect to external services, set tracking cookies, or send visitor data off-site. If a customer closes the notice bar, that preference is remembered only in their browser.

== License ==

This plugin and its bundled assets, including logo.png, are distributed under the GPLv2 or later.

== Screenshots ==

1. The simple weekly-hours editor with a customer preview.
2. The open/closed notice bar on the front end.
3. The dynamic opening-hours table from the block or shortcode.

== Changelog ==

= 1.1.13 =
* New: past special dates are now automatically removed from saved/admin opening-hours data while today's date remains active.

= 1.1.12 =
* Review: the no-JavaScript banner fallback styling moved from an inline `<style>` tag into the enqueued stylesheet, gated by a small enqueued head script.
* Review: the shortcode is now `[iboh_opening_hours]` and the bootstrap class is `IBOH_Plugin`, so every public name carries the plugin prefix.
* Removed an unused legacy placeholder file.
* Fix: front-end bootstrap now runs at `init`, so translated defaults no longer trigger the WordPress 6.7+ early-textdomain notice.

= 1.1.11 =
* Branding: submission title and short description now emphasise the linked live banner and opening-hours table USP.
* New: bank-holiday notices can now be controlled separately from manual special dates: within seven days, all future, or hidden from upcoming notices.
* UX: upcoming notice entries now wrap as separate date/time items, with bold dates and normal-weight hours.

= 1.1.10 =
* Fix: the notice bar now shows a subtle loading skeleton until the live browser status is ready, avoiding stale text flicker on page load.

= 1.1.9 =
* Fix: bank holidays now replace same-date manual special dates, and only appear in banner/table upcoming notices when they are within seven days.

= 1.1.8 =
* Fix: front-end banner, status, and opening-hours table now inherit the site's body/content typography instead of using fixed plugin text sizes.

= 1.1.7 =
* New: added a UK bank-holiday helper to insert standard closed dates for England & Wales, Scotland, or Northern Ireland.

= 1.1.6 =
* New: added notice-bar layout controls for sticky/non-sticky display and top-banner spacing/overlay behaviour.

= 1.1.5 =
* Branding: renamed for WordPress.org submission as ITBoffins Live Opening Hours, with the `itboffins-live-opening-hours` text domain and package slug.
* UX: friendlier admin wording and softer IT Boffins styling for small business owners.
* Security: hardened custom wording placeholders so invalid admin-entered formats cannot break public rendering.
* Readiness: refreshed WordPress.org metadata for the current tested release and clarified bundled asset licensing/privacy.

= 1.1.4 =
* Fix: admin header now uses the packaged logo.png asset instead of the inline SVG mark.

= 1.1.3 =
* Fix: upcoming banner text now preserves chronological order when closed dates and special-hours dates are mixed.

= 1.1.2 =
* Fix: upcoming banner text now includes closed special dates without labels and groups multiple closed dates into one ordered phrase.

= 1.1.1 =
* Fix: special-date rows now default to closed, require a date before saving, and date-only rows are preserved instead of disappearing.
* Fix: invalid special-date rows now show an admin error instead of being silently discarded.

= 1.1.0 =
* New: optionally show all upcoming special dates in advance - in the banner and the hours table - instead of only on the day itself. Past dates are hidden automatically.
* New: make the weekly schedule optional ("special dates only" mode) for events, pop-ups and by-appointment venues. When weekly hours are off and there is nothing to show on a given day, the banner hides itself.
* New: editable wording for the upcoming-dates list, plus an "Open 24 hours" label.
* The new behaviour keeps the public message fresh on fast or cached sites.

= 1.0.0 =
* Initial release: weekly schedule editor, split hours, holidays/special dates, customer notice bar, hours table block, and shortcode.

== Upgrade Notice ==

= 1.1.13 =
Past special dates are cleaned out automatically, keeping the one-off changes list current.

= 1.1.12 =
WordPress.org review fixes: proper style enqueueing and prefixed shortcode/class names. The shortcode is now [iboh_opening_hours].

= 1.1.11 =
Submission copy now makes the linked live banner and opening-hours table clearer. Upcoming notices are easier to scan, and bank-holiday notice visibility is now separate from manual special dates.

= 1.1.10 =
The notice bar now avoids page-load text flicker by showing a brief loading state before the live status appears.

= 1.1.9 =
Bank-holiday notices are quieter and same-date bank holidays now take priority over manual special dates.

= 1.1.8 =
Front-end opening-hours text now follows the site's own typography.

= 1.1.7 =
Adds a helper to insert standard UK bank holidays as closed special dates.

= 1.1.6 =
Adds simple controls for sticky notices and top-banner spacing.

= 1.1.5 =
Renames the plugin for WordPress.org submission, adds friendlier small-business wording, hardens custom wording output, and refreshes release metadata.

= 1.1.4 =
Admin header logo now uses the packaged PNG asset.

= 1.1.3 =
Mixed upcoming closed dates and special-hours dates are shown in chronological order.

= 1.1.2 =
Upcoming closed dates without labels are now included in grouped banner text.

= 1.1.1 =
Special dates are saved more defensively and the admin script is cache-busted.

= 1.1.0 =
Show upcoming special dates ahead of time, and optionally run in special-dates-only mode with no weekly hours. Existing settings are preserved.

= 1.0.0 =
First release.
