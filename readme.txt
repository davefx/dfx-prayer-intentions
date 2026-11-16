=== DFX Prayer Intentions – Prayer Requests for Small Groups ===
Contributors: DaveFX
Tags: prayer, church, parish, prayer requests, whatsapp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 26.11.29
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

= 26.11.29 =
* The news of the day before (and of the same morning) goes first in the daily message, in "Especially", the departed too; from the next day on, each goes back to its place (a group setting).

= 26.11.28 =
* In the leaders' app, the phone's back button moves between screens (an intention, the list, "Today") and no longer leaves the app for the login page.
* Leaders who log in from the app go back to the app instead of the panel.

= 26.11.27 =
* Thanksgivings appear in the daily message only the morning after the good news, and close themselves when their days are over (a group setting); the monthly list, read at the monthly adoration, gives thanks again for those of the last month, closed ones included.
* The WhatsApp question greets the requester by their first name only, even when their name to show carries a surname.
* A summary that starts with the person's name no longer repeats it in the line ("Lourdes, …: Lourdes, ya está en casa").
* Clearer names: "Name to show" becomes "How they are called" and takes nicknames and surnames (Toni Alfonso, Javi Cavallé) without changing the full name; "Visible name" becomes "Description instead of the name", for discreet cases, at the end of the form.

= 26.11.26 =
* The WhatsApp question names each person as the requester would: "El hijo de una compañera", "Carolina, hija de unos amigos", "José", instead of a bare name or a name that points back to the requester.

= 26.11.25 =
* The current affairs section of the messages opens with "We also pray for" instead of a label.
* The daily message lists the people whose health we pray for in a section of their own, our brothers first; the monthly list orders the sick the same way.
* "To ask" in the leaders' app: the intentions marked "pending to ask" and those due for review, grouped by requester, with one WhatsApp message asking each of them about all theirs. An update or closing the intention clears the mark.

= 26.11.24 =
* Two messages: the daily one, short (urgent ones, one line for the health of many, current affairs, thanks and the departed of the last week), and the monthly list read at the meeting, with every intention by topic. Each intention says which one it goes in and its topic.
* The requester's phone is kept and offered again for their next requests, with a button to ask them by WhatsApp how it goes.

= 26.11.23 =
* The deceased close themselves when their time in the index is over, instead of waiting in review (a group setting, on by default). Those whose anniversary is remembered are kept, and the anniversary still appears in the digest.

= 26.11.22 =
* The lists of intentions, in the app and in the panel, show the urgent ones first and then the most recent requests.

= 26.11.21 =
* Leaders can set and correct the date of a request, in the panel and in the app: for requests written down later or brought from another list.

= 26.11.20 =
* Every field of an intention can be edited from the leaders' app.
* "Name to show" (formerly "first name") also takes a surname, to tell apart members with the same first name: "Luis Sebastián" appears as "N.H. Luis Sebastián".
* The app shows when each intention was added, its next review and who asked for it, with their full name.
* A warmer design for the app: EB Garamond and Cinzel, shipped with the plugin, cards, status pills and a bottom bar with icons.

= 26.11.19 =
* The praying hands of the icon take the DFX family colours: white hands with a navy outline and maroon cuffs.

= 26.11.18 =
* The plugin and the leaders’ app get the DFX family icon, with praying hands.

= 26.11.17 =
* An installable app for leaders (PWA) at /<url_base>-app/: today’s message ready to share, follow-up of intentions, a quick form to add one, moderation and reviews. It works without connection with the last saved message and lists, and sends changes when the connection comes back.

= 26.11.16 =
* A closed intention can be reopened from its screen, keeping its history (for intentions closed by mistake). Discarded requests stay final.

= 26.11.15 =
* New index layout per group, “prayer proposals”: the urgent intentions first, then the rest, thanksgivings and the recently departed (within the deceased deadline); intentions sharing a summary go in one line.
* Leaders can mark the requester and the beneficiary as members of the group; each group sets the prefix for members (“N.H.”).
* The index header is multi-line and accepts {long_date}.

= 26.11.14 =
* Leaders can set a compound first name (Juan Tomás) for the requester and the beneficiary; with “first name” discretion the index shows it whole.
* Each group has its own page in the panel, with its tasks as tabs.

= 26.09.24 =
* First release.
