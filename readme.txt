=== DFX Prayer Intentions – Prayer Requests for Small Groups ===
Contributors: DaveFX
Tags: prayer, church, parish, prayer requests, whatsapp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 26.09.24
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Prayer requests for small intercession groups: a discreet public form, moderation, a WhatsApp-ready index and a daily digest.

== Description ==

Prayer groups receive intentions by word of mouth, by message, on scraps of paper. Some are for tomorrow's surgery, some for someone who died last week, some for an illness that goes on for months. Keeping track of them, and of who may be named and how, is a job in itself.

DFX Prayer Intentions gives the leaders of each group a private panel for that job, and gives everybody else a single thing: a form to ask for prayer.

**What visitors see**

* A simple form at a secret address of each group (for example `/prayer-requests-7f3k9q2x/`), or on any page with the *Prayer request form* block or the `[dfxpray_form]` shortcode.
* They choose, in plain words, what the intention is about: *a moment in particular*, *a death*, *a situation that goes on*, *giving thanks*. The date is only asked when it matters, and the time for one-off events, "so we pray at that moment".
* They choose how names may appear: full name, first name, initials or anonymous.
* Nothing is ever published: the form's page is kept out of search engines, sitemaps, site search and menus.

**What leaders get**

* A moderation inbox: approve with a short summary written by a leader (the only text the group will ever see), discard spam, or merge duplicates.
* A lifecycle by type. One-off events go to review two days after the date; the departed after 30 days; ongoing situations are reviewed every 30 days; thanksgivings last a week. Intentions in review stay in the index until a leader decides, so nobody stops praying for an administrative oversight. All deadlines are configurable.
* The index of active intentions, formatted for WhatsApp, with a Copy button.
* A daily digest at the time each group chooses: today's events and key dates (funerals, month's mind Masses, appointments), news, high priority, tomorrow, the recently departed, and a leaders-only section.
* Hour alerts before timed events, so the group can pray at that very moment. Nothing is sent in quiet hours: the digest before them lists those events, and if none did, an early alert goes out just before quiet hours begin.
* Delivery by email, Telegram or a signed webhook (Slack, Discord, n8n…), besides copy and paste to WhatsApp.
* Internal comments for calls, visits and contacts, which never leave the panel, and updates that reach the group as news.
* Several independent groups in one site, each with its own leaders, form, index, digest and settings. With a single group, the plugin behaves as if groups did not exist.

**Privacy by design**

Prayer requests often carry health and religious information about third parties. The plugin keeps intentions in its own tables, out of REST, feeds, search, sitemaps and the WordPress exporter; applies the discretion chosen by the requester (leaders can make it stricter, never more open); deletes closed intentions after 90 days (configurable); integrates with WordPress' personal data export and erasure tools; and suggests a text for your privacy policy. This is not legal advice: check your case with a professional.

**Roles**

The plugin adds a *Prayer leader* role whose only capability is access to the panel. Leaders only ever see the groups they are assigned to. Administrators manage groups, leaders and global settings, and can give panel access to other roles.

== External services ==

The plugin works without any external service. Three are optional, and only contacted when an administrator or leader configures them:

* **Cloudflare Turnstile**, to keep bots away from the request form. When both Turnstile keys are set in the global settings, the form page loads Cloudflare's script from `challenges.cloudflare.com`, and each submission sends the widget's token, the secret key and the visitor's IP address to `https://challenges.cloudflare.com/turnstile/v0/siteverify` to check it. [Terms of use](https://www.cloudflare.com/website-terms/), [privacy policy](https://www.cloudflare.com/privacypolicy/).
* **Telegram Bot API**, to deliver digests, hour alerts and new-request notices to a chat. When a group's Telegram channel is configured, the message text and chat ID are sent to `https://api.telegram.org`. New-request notices never include the text of the request. [Terms of service](https://telegram.org/tos), [privacy policy](https://telegram.org/privacy).
* **A webhook URL of your choice** (Slack, Discord, n8n or any other service), which receives the same messages as signed JSON. What happens to them there depends on that service's own terms.

== Installation ==

1. Install and activate the plugin.
2. In *Prayer intentions → Groups*, create a group and choose its leaders (users with the *Prayer leader* role).
3. Share the group's form address, shown in *Group settings*, with the people who should send requests.
4. The request form tells page caches to skip it (`DONOTCACHEPAGE` and no-cache headers), since each form carries a security token that expires. If your cache ignores both, exclude the form pages by hand.
5. For punctual digests and hour alerts, set a real system cron that calls `wp-cron.php` every five minutes. With WordPress' built-in cron, scheduled work only runs when someone visits the site.

== Frequently Asked Questions ==

= Can it post to our WhatsApp group automatically? =

No. As far as we know, WhatsApp's official API cannot post to an existing group created by individuals, and unofficial ways risk getting the number banned. The panel gives you the index, the digest and each hour alert ready to copy and paste.

= Can a theme change the texts? =

Yes. Copy `templates/index.php`, `templates/digest.php`, `templates/form.php` or `templates/form-page.php` to `your-theme/dfx-prayer-intentions/`. Every section of the index and the digest also has a filter (`dfxpray_index_section_{key}`, `dfxpray_digest_section_{key}`).

= How do I add a delivery channel? =

Implement the `DFXPRAY_Channel` interface and add your object through the `dfxpray_channels` filter. The core does not need to change.

= How is the webhook signed? =

Each request carries an `X-DFXPray-Signature` header: `sha256=` followed by the HMAC-SHA256 of the raw body, keyed with the secret you set.

== Screenshots ==

1. The public form.
2. The moderation inbox.
3. The index, ready to paste into WhatsApp.
4. The daily digest.

== Changelog ==

= 26.09.24 =
* First release.
