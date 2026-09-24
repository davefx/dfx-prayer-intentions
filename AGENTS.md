# Working on DFX Prayer Intentions

Guide for anyone (person or coding agent) changing this plugin. `README.md`
has the layout and commands; this file is about how work is done here and
what must not break.

## Ground rules

1. **All code in English**: identifiers, comments, docblocks and source
   strings. Spanish is the users' language and ships as a complete
   translation (`languages/…-es_ES.po`), never as source strings.
2. **Object-oriented, one singleton per class.** Every class under
   `includes/`, `includes/channels/` and `admin/` uses the
   `DFXPRAY_Singleton` trait and is reached through `::get_instance()`.
   `tests/unit/SingletonTest.php` discovers the class files and fails if a
   new one is not a singleton. The autoloader is the only exception.
3. **Test first, at every layer, E2E included.** Write the failing test, run
   it and see it fail for the right reason, then write the code. A feature
   that a user can see gets an E2E test in `tests/e2e/specs/` as well as its
   PHPUnit tests. A bug fix starts with a test that reproduces the bug.
4. **Everything green before you stop**: PHPUnit, PHPCS, Playwright, and
   PHP 8.1 through `tests/bin/php-matrix.sh` when you touched PHP syntax or
   library calls.
5. **Commits** carry no trailer or header saying they were written with AI
   or by an assistant. Plain, descriptive messages.

## The loop

```sh
tests/bin/start-db.sh                       # once per boot: MySQL in Docker, port 3310
vendor/bin/phpunit --filter SomethingTest   # red, then green
composer test                               # all PHPUnit
composer lint                               # PHPCS (WordPress + PHPCompatibility 8.1)
npx playwright test tests/e2e/specs/x.spec.js
npx playwright test                         # full E2E, rebuilds the site (~10 min)
DFXPRAY_E2E_SKIP_SETUP=1 npx playwright test …   # reuse the built site
tests/bin/php-matrix.sh 8.1                 # the declared PHP floor
```

The database runs in Docker because AppArmor stops MariaDB from using a data
directory inside the project. The E2E site lives in `tests/.wp/e2e-site`,
served by `php -S 127.0.0.1:8890`, with the plugin symlinked in.

## Where things go

| Change | Place |
| --- | --- |
| Business rule (lifecycle, discretion, validation) | the service in `includes/` (`DFXPRAY_Intentions`, `DFXPRAY_Lifecycle`, `DFXPRAY_Discretion`…) |
| Anything a leader does in the panel | a `do_<action>()` in `admin/class-admin-actions.php`, registered in `ACTIONS` with its permission scope; the view only renders a form through `DFXPRAY_Admin::form_open()` |
| A task on a group (moderation, index…) | a tab: `admin/views/<name>.php`, registered in `DFXPRAY_Admin::TABS` and `tab_titles()`; link to it with `DFXPRAY_Admin::group_url( $group_id, $tab )` |
| A site-wide screen | `DFXPRAY_Admin::render_page()` and `register_menu()`; the menu only holds "Groups", "Global settings" and one entry per group |
| Text shared with the group (index, digest, alerts) | `DFXPRAY_Formatter`, `DFXPRAY_Index_Composer`, `DFXPRAY_Digest_Composer`, templates in `templates/` |
| A setting | default and type in `DFXPRAY_Settings::defaults()`; add to `GLOBAL_ONLY` if leaders must not override it; field in `DFXPRAY_Admin::setting_fields()` and `setting_sections()` |
| A delivery channel | a class implementing `DFXPRAY_Channel`, added in `DFXPRAY_Channels::all()` (third parties use the `dfxpray_channels` filter) |
| Scheduled work | `DFXPRAY_Scheduler::tick()` (every five minutes) or `daily()` |
| Schema change | `DFXPRAY_Installer::get_schema()`, and bump `DB_VERSION` so `maybe_upgrade()` runs |

## Invariants the tests guard

- **No data crosses groups, and the group is always explicit.** A group's
  work lives on its own page (`page=dfxpray-group-<id>&tab=…`); there is no
  implicit "current group". Every panel query is scoped to a group the
  current user may manage (`DFXPRAY_Permissions`). Every action checks the
  group of its target, including second targets (merge, transfer). Add the
  new action to the IDOR data provider in `AdminActionsTest`.
- **The full text of a request is never published.** Only the summary a
  leader wrote reaches the index, the shareable digest and non-private
  channels. New-request notices carry the text by email only.
- **Discretion only hardens.** Leaders may make it stricter, never more open;
  visible names may not reveal more than the level allows.
- **Intentions stay out of WordPress' public surfaces**: custom tables only,
  no REST routes, no posts. The form page sends `noindex`, `nofollow` and
  no-cache headers and defines `DONOTCACHEPAGE`; it is never added to
  `robots.txt`.
- **No timed event is left without notice.** Alerts are never sent in quiet
  hours; the previous digest lists them in "Without an hour alert", and if no
  digest did, an early alert goes out just before quiet hours.
- **Time comes from `DFXPRAY_Clock`**, in the site timezone. Never call
  `time()`, `date()` or `current_time()` for anything the plugin reasons
  about (the only exception is handing WP-Cron its real timestamps); tests
  freeze the clock (`freeze_time()` in PHPUnit, `freeze()` in E2E).
- **No PHP notices.** The E2E setup fails if the plugin raises any while the
  site is built, and every E2E test fails if the plugin logs one.

## Code conventions

- WordPress Coding Standards; the `DFXPRAY_`/`dfxpray_` prefix everywhere
  (classes, options, hooks, capabilities, tables, shortcodes).
- SQL through `$wpdb->prepare()` with `%i` for table names (WordPress 6.5+).
- Escape on output, sanitise on input, nonce on every form.
- Hooks are registered in each class's `register_hooks()`, called from
  `DFXPRAY_Plugin::run()`; constructors do not add hooks.
- Strings: text domain `dfx-prayer-intentions`, translator comments for every
  placeholder, `_x()` when the same English string needs two meanings.

## Translations

After adding or changing strings:

```sh
wp i18n make-pot . languages/dfx-prayer-intentions.pot --exclude=tests,vendor,node_modules,dist
# add the Spanish for each new string to bin/es_ES.py
python3 bin/build-translations.py
wp i18n make-mo languages && wp i18n make-json languages --no-purge
```

`TranslationTest` fails while any string is untranslated, and checks the
specification's own index example, character for character, in Spanish.

## Releasing

1. Bump the version in `dfx-prayer-intentions.php` (header and
   `DFXPRAY_VERSION`), `blocks/form/block.json`, `blocks/form/editor.asset.php`
   and `readme.txt` (Stable tag and changelog). Versions follow the DFX
   family: `YY.MM.DD`.
2. Everything green (see ground rule 4).
3. `bin/build-zip.sh`, then run Plugin Check on the unpacked zip in the E2E
   site (`wp plugin install plugin-check`, `wp plugin check dfx-prayer-intentions`).
   It must report no errors.
