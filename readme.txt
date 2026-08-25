=== PressHangar Draft Pacer ===
Contributors: presshangar
Tags: schedule, drip, drafts, auto publish, missed schedule
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Drip-publish your WordPress drafts at a natural, human-like pace, with a watchdog that catches missed schedules.

== Description ==

PressHangar Draft Pacer is a pacing and reliability layer for WordPress content distribution. Whether your drafts come from AI tools, bulk imports, or human writers, this plugin publishes them at a measured, natural cadence, while a built-in watchdog helps ensure schedules are not missed. It does not generate content, optimize SEO, or make any guarantee about search rankings or penalties.

PressHangar Draft Pacer is developed by PressHangar, a brand of Musubiemu LLC.

**Core Features:**

* **Natural Cadence** — Randomly distribute posts throughout the day (configurable range, e.g., 3-5 posts daily) at random times between your chosen hours (e.g., 9 AM–9 PM). Every post gets a unique timestamp down to the second.

* **Source-Agnostic** — Works seamlessly with drafts from any origin: AI content generators, RSS imports, bulk uploads, or your team's writers. PressHangar Draft Pacer doesn't care where they came from.

* **Watchdog Recovery** — A built-in recovery system runs every 15 minutes, catching any scheduled posts that somehow missed their publish window (within a 5-minute grace period) and publishes them immediately. Your content always goes live—no surprises.

* **WP-Cron Health Monitoring** — Automatically detects if your WordPress cron is functioning, and alerts you with copy-paste setup instructions for external cron if needed. Includes detection of `DISABLE_WP_CRON` configuration.

* **Smart Scheduling** — Prevents overlapping schedules, maintains minimum spacing between posts (with auto-adjustment if needed), and optionally filters drafts by post type and category.

* **Admin Dashboard** — Real-time status card showing active drafts, scheduled queue, next publish date, cron health, and recovery statistics. One-click manual scheduling and queue clearing.

Perfect for content teams, SEO strategists, and anyone publishing at scale who wants control, reliability, and natural pacing.

== Installation ==

1. Upload the `presshangar-draft-pacer` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **Settings > PressHangar Draft Pacer** to configure your preferences.
4. Enable drip publishing, set your post frequency and time window, then click **Schedule Now**.

**For production sites:** We recommend disabling WordPress's built-in cron and running an external cron job. Configuration instructions are available in the plugin's settings page.

== Frequently Asked Questions ==

= Why aren't my scheduled posts publishing? =

Several reasons could cause missed schedules:

1. **WordPress Cron is not running** — WordPress relies on site traffic to trigger its scheduler. Low-traffic sites may skip runs. Check the plugin's status card for a cron health warning, and if present, set up an external cron job (instructions provided in settings).

2. **Conflicting plugins** — Other scheduling or automation plugins may interfere. Try deactivating conflicting plugins and re-running the scheduler.

3. **Server time mismatch** — Verify your server's timezone is correctly configured in WordPress (**Settings > General**). PressHangar Draft Pacer uses your site's configured timezone.

4. **Post date in the past** — If a scheduled post's date falls before the current time, WordPress may not publish it. The watchdog will catch these within 5 minutes, but ensure your schedule window includes future times.

If issues persist, check your site's error logs and enable WordPress debugging for more details.

= Does it work with AI-generated drafts? =

Yes, absolutely. PressHangar Draft Pacer is completely source-agnostic. It works with:

* Drafts from AI content generators (ChatGPT, Anthropic, etc.)
* Bulk imports from RSS feeds or external platforms
* Drafts created via REST API or WordPress Importer
* Manually written drafts

The plugin treats all drafts the same way—it simply applies your pacing and scheduling rules, regardless of origin. If you're concerned about content quality, pair this plugin with a review workflow to approve drafts before they're drip-published.

= What if my host has no cron settings? =

Some hosts don't offer cron at all. If that's the case for you, you can register the external cron URL shown in the plugin's settings page with a free external ping service such as cron-job.org — or simply skip external cron entirely. It's only an extra safety net: the plugin works with the normal WordPress cron, and the built-in watchdog rescues any missed posts.

= Why does the status card show 0 scheduled posts when I have many? =

PressHangar Draft Pacer's "Scheduled" count normally shows only the posts it manages itself — those it either scheduled through its drip logic or that you've explicitly adopted. If you scheduled posts another way (an older version of this plugin, a REST API script, another scheduling tool, or by hand in the editor), those posts are still `future` posts on your site, but PressHangar Draft Pacer doesn't know about them yet, so it can't include them in its counts or protect them with its watchdog recovery.

To make this visible, the status card shows two numbers: **Scheduled (PressHangar Draft Pacer-managed)** and **Scheduled (site-wide)**. If site-wide is higher than PressHangar Draft Pacer-managed, a notice appears with an **Adopt Existing Scheduled Posts** button. Clicking it tags every outside `future` post (matching your configured post types) with PressHangar Draft Pacer's internal metadata, without changing any post's date or status — it simply brings them under management so they count correctly and are covered by the watchdog's failure recovery going forward. Large backlogs are processed automatically in batches.

= Will this help avoid Google penalties? =

PressHangar Draft Pacer provides a **reliable pacing layer** that distributes content naturally throughout your day. Spacing out publication is often suggested as sensible editorial practice, but we make no guarantees about search rankings or penalties.

**What it does:**
* Spaces out post publication to mimic natural editorial cadence
* Avoids sudden, machine-like publication spikes

**What it doesn't do:**
* Generate content or improve quality
* Provide SEO optimization
* Guarantee penalty immunity

Always follow Google's content policies, prioritize quality over quantity, and use this plugin as part of a larger, thoughtful content strategy. If you're already in penalty, consult Google Search Console and a professional SEO advisor.

== Changelog ==

= 0.2.9 =
* Add: Official plugin icon and banner shown on the WordPress.org plugin page.

= 0.2.8 =
* Add: A state-aware "Getting started" panel at the top of the settings page that guides first-time setup as a live checklist.
* Add: Bundled translations for French, Spanish, German, Brazilian Portuguese, and Italian, so the whole interface is localized out of the box in seven languages alongside English and Japanese.

= 0.2.7 =
* Add: Japanese translation (bundled) and proper text-domain loading (Domain Path: /languages).

= 0.2.6 =
* readme: clarified wording so the description no longer implies SEO/penalty safety; consistent with the FAQ (no ranking or penalty guarantees)

= 0.2.4 =
* Add: adopt existing scheduled posts into PressHangar Draft Pacer management; status card now shows site-wide scheduled count

= 0.2.3 =
* Fix: status card scheduled count and next-publish now respect the category filter

= 0.2.2 =
* WordPress.org submission fixes: slug/textdomain alignment, tested up to 7.0

= 0.2.1 =
* Plugin Check compliance fixes; author update

= 0.2.0 =
* Renamed to PressHangar Draft Pacer; now part of PressHangar (presshangar.com)

= 0.1.1 =
* Simplified external cron guide: universal instructions for shared hosting, VPS, and home servers

= 0.1.0 =
* Initial release
* Drip scheduling with configurable frequency and time windows
* Watchdog recovery for missed scheduled posts
* WP-Cron health monitoring with external cron setup guidance
* Admin dashboard with real-time status and manual controls
* Support for WordPress 6.0+ and PHP 7.4+
