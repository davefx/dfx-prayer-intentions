=== DFX Prayer Intentions – Prayer Requests for Small Groups ===
Contributors: DaveFX
Tags: prayer, church, parish, prayer requests, whatsapp
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.2611210
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
* A daily digest at the time each group chooses, formatted for WhatsApp with a Copy button: the shareable version is the message of the leaders' app, and the leaders' version is that same message saying who asks for each intention, followed by today's appointments, events without an hour alert, what waits in the panel, whom to ask and the news of the last day.
* A monthly digest with the monthly list, to read at the monthly adoration, in both versions.
* Hour alerts before timed events, so the group can pray at that very moment. Nothing is sent in quiet hours: the leaders' version of the digest before them lists those events, and if none did, an early alert goes out just before quiet hours begin.
* Delivery by email, Telegram or a signed webhook (Slack, Discord, n8n…), besides copy and paste to WhatsApp.
* Internal comments for calls, visits and contacts, which never leave the panel, and updates that reach the group as news.
* Several independent groups in one site, each with its own leaders, form, index, digest and settings. With a single group, the plugin behaves as if groups did not exist.
* Leaders invite new leaders of their group by email, from the group settings.

**Privacy by design**

Prayer requests often carry health and religious information about third parties. The plugin keeps intentions in its own tables, out of REST, feeds, search, sitemaps and the WordPress exporter; applies the discretion chosen by the requester (leaders can make it stricter, never more open); deletes closed intentions after 90 days (configurable); integrates with WordPress' personal data export and erasure tools; and suggests a text for your privacy policy. This is not legal advice: check your case with a professional.

**Roles**

The plugin adds a *Prayer leader* role whose only capability is access to the panel. Leaders only ever see the groups they are assigned to, and so do administrators: they create, edit, archive and delete every group and assign its leaders, keep the global settings and answer requests about personal data, and can give panel access to other roles, but they only see the requests of the groups they lead. A leader can invite someone by email to lead their group, and an administrator to any group, from the groups page: the invitation is valid for seven days, and on accepting it the person becomes a leader at once, with an account if they had none. Only administrators remove leaders.

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
3. The daily digest, ready to paste into WhatsApp.
4. The monthly digest.

== Changelog ==

= 1.0.2611210 =
* "Mark as sent to the group", in the "Daily digest" tab and on the app's "Today" screen (with "Undo"): leaders mark the day's message once they have shared it. Thanksgivings now go in the next message shared after they are written down, so one written down after the day's message was shared is no longer lost; the digest's schedule plays no part. Routines can mark it too (mark_shared).

= 1.0.2611203 =
* Easier start for invited leaders without an account: they choose their password on the invitation page itself (told that their email is their user), and on accepting they go straight into the group, already logged in, instead of WordPress' password reset screen. A welcome email tells them how to come back: log in with their email, the leaders' app, and where to choose a new password if they forget it.

= 1.0.2611202 =
* Routines (and the app's API) can create and edit the members of their groups with "save_member", with the same per-group checks as the panel; only the fields sent change. Deleting members stays in the panel.

= 1.0.2611201 =
* Leaders can edit the day an intention reached the list ("When it reached the list"), in the panel, the app and routines (listed_on). Moving it back takes a new intention out of "Especially" sooner. It cannot be after today nor before the day it was asked for.

= 1.0.2611200 =
* Closing an intention as answered with a thanksgiving previews, in the panel and in the app, the exact line the thanksgiving will show in the daily and monthly digests, refreshed while its summary is written.
* Once closed, the new thanksgiving opens so it can be edited, and the intention and its thanksgiving link to each other ("Gives thanks for:" / "Its thanksgiving:").

= 1.0.2611197 =
* Administrators see, at the top of "Groups", the statistics of all groups: one row per group (archived ones too) with leaders, members, pending, in the list, received and closed in the last 30 days, answered and total received, and the totals. Figures only: no names or texts of groups they do not lead.

= 1.0.2611196 =
* Changed: clearer examples in the help for descriptions instead of names and for the members' prefix.

= 1.0.2611195 =
* New: the user manual in English, with its own screenshots. The panel shows it to anyone whose language is not Spanish, and each manual links to the other.

= 1.0.2611194 =
* New: the user manual ships with the plugin and opens in the panel, under "Prayer intentions > Manual", in the user's language, with its screenshots. The footer of every screen and the leaders' app link to it.
* New: on phones and tablets, every panel screen shows the leaders' app address with a "Copy link" button and how to install it, and the menu has an "Install the app" entry. Inside the app, an "Install the app" button (or the steps, on iPhone and iPad) stays at the top of "Today" until the app is installed.
* Changed: the app's manifest declares its id and icon purposes, so browsers offer to install it.

= 1.0.2611193 =
* New: administrators invite leaders to any group, including groups they do not lead or groups with no leaders yet, from each group's card in "Groups > Manage groups", where they also see and withdraw its pending invitations.

= 1.0.2611192 =
* New: leaders invite other people to lead their group, by name and email, from "Group settings". The invitation lasts seven days; accepting it (with a button, never just by opening the link) makes the person a leader at once, with a new account if they had none, and tells the site's administrator. Only administrators remove leaders.
* New: the "Leaders" section in "Group settings" shows the group's leaders and pending invitations. Leaders see only their co-leaders' names, never other users of the site, and inviting answers the same whether or not the email has an account.
* Changed: administrators only see and open the groups they lead. They still manage every group (name, leaders, data protection, archive) from "Groups", and answer personal data requests across all groups from "Global settings".
* Changed: invitations are included in personal data export and erasure, and in the suggested privacy policy.

= 1.0.2611191 =
* New: routines. A script can keep a group up to date through one REST route (`dfxpray/v1/<op>`), with the same operations and permissions as the leaders' app. It answers only to application passwords, never anonymously or to a browser session.
* New: service accounts. An administrator marks a user as used only by a routine, in the user's profile: it keeps the permissions of the groups it leads, gets no email, and its application passwords open nothing else in WordPress.
* New: the routine (and the app) can read today's digest and send it with "send_digest_now".
* Changed: the suggested privacy policy mentions that a leader may use a routine.

= 1.0.2611190 =
* New: "Daily digest at" can be left empty, so the digest goes out only when it is sent with "Generate and send now", for example by a routine when it finishes.

= 1.0.2611181 =
* Changed: "Daily message: thanksgivings during (days)" is now the number of daily messages that carry a thanksgiving (1: only one), counted from the day of the good news, or from the day it reaches the list if it is written down later.

= 1.0.2611180 =
* Fixed: the daily message brings back to "Especially" what turns serious again: an intention that gets an update marked as news, or that is reopened, goes there with its summary for the same days as a new one. Whatever reaches the list counts as news from that day, however old the request: approved in moderation, or written down late by a leader.

= 1.0.2611171 =
* Fixed: "To ask", in the leaders' digest, no longer lists the departed and thanksgivings whose review is near when the group closes them automatically: they close by themselves and there is nothing to ask. Those marked "Pending to ask" still appear.

= 1.0.2611170 =
* New version numbers: MAJOR.MINOR.YYMMDDN (SemVer, with the release date and the number of the release that day as the patch). This is the first one; it follows 26.11.41.
* Data protection for sites that serve several parishes or groups: each group has its own data controller, contact and, optionally, its own privacy policy, set by the administrator in the groups page; the global ones are only the default.
* New global settings for the provider of the service (data processor): name, tax ID, address, email and subprocessors. The form then says that the provider offers the tool free of charge to the group's controller as data processor.
* Each group has its data processing agreement (art. 28 of the GDPR) ready to copy, with both parties named, and the date it was accepted. Until a group has its controller and accepted agreement, its panel says so.
* The suggested privacy policy describes the site as a platform run by the provider for each controller, with the subprocessors.

= 26.11.40 =
* Data protection (GDPR): the form gives the basic information (controller, purpose, legal basis, recipients and rights) and asks for three things before sending: explicit consent for health and belief data and for sharing a discreet summary with the group, a statement about the person prayed for, and a minimum age (14 by default).
* New global settings: data controller, contact for data protection and minimum age.
* The suggested privacy policy covers everything the GDPR asks to tell: legal basis, recipients and services, international transfers, retention, the people prayed for, the members of the groups, minors and rights.
* Exporting and erasing personal data finds a person by their phone too, written in any format, and includes the leaders' notes, key dates and the member records. Administrators can look a person up by email or phone in the global settings, hand over what is kept and erase it.
* Each leader acknowledges once their duty of confidentiality, and the members tab reminds what may be written down.
* Two group options: keep the full text out of the email notice, and keep the phones out of the leaders' digest (both on, as before).

= 26.11.39 =
* The "Daily digest" tab always shows the message as it is now, the same as the leaders' app, also after the day's digest went out; it still says when it was sent.

= 26.11.38 =
* The "Daily digest" tab shares the same message as the leaders' app. The leaders' version is that message saying who asks for each intention (with their phone), followed by a leaders-only block: today's appointments, events without an hour alert, what waits in the panel, whom to ask and the news of the last 24 hours.
* New "Monthly digest" tab: the monthly list to share, and the leaders' version with who asks, what waits in the panel and whom to ask.
* The "Index" tab is gone: its message, length warning and link to the app are in the daily digest.
* The anniversaries of the departed whose anniversary is remembered appear in the daily message, with the departed.
* Events without an hour alert during quiet hours are told to the leaders, in their version of the digest.
* Removed the digest header, footer and "recently departed" settings, no longer used.
* Fixed: the intentions list and the reviews linked each intention to a page that no longer exists ("not allowed"); they open its card in the group again.

= 26.11.37 =
* In the daily message, "Especially" keeps its cases apart, with a blank line between them: first the departed (deaths reported the day before and anniversaries of a member's death), then the birthdays, then the serious intentions.
* The package no longer includes the local working folders of the development tools.

= 26.11.36 =
* The six tabs of the app fit in one row on a phone; the day the Members tab opens on is no longer hidden under the header.

= 26.11.35 =
* The app has a Members tab: the year by month and day of their birthdays (and of the anniversaries of those who died), opening on today or the next day with someone, with their notes and a WhatsApp link.

= 26.11.34 =
* The intention form follows five steps, the same in the panel and in the app: who asks, who we pray for, what is happening, how it appears in the message and the follow-up. Fields that do not apply to the kind are hidden.
* In the app, adding and editing share one form, opened from an Edit button, and the line it will show is previewed while it is written.

= 26.11.33 =
* An intention can be prayed for until a day: the day after, it leaves the messages and closes by itself, without going through review.

= 26.11.32 =
* Members celebrating their birthday on the same day share one line, after the news of the day before.
* Members who would show the same name carry their first surname.

= 26.11.31 =
* Members have internal notes (for instance, the retreat they walked and their table).

= 26.11.30 =
* Members: a new tab keeps the members of each group, with how they are called, their phone and their birthday. On their birthday they open "Especially" in the daily message (a group setting). A member who has died is no longer mentioned on his birthday: he opens "Especially" every year on the anniversary of his death.

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
