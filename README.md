# DFX Prayer Intentions

How to work on the plugin (rules, invariants, where things go): see `AGENTS.md`.

User manual (for administrators and group leaders, with the leaders' app): [English](docs/manual/en/README.md) and [Spanish](docs/manual/es/README.md).

WordPress plugin for prayer groups: a discreet public request form, moderation,
a lifecycle by type, a WhatsApp-ready index and a daily digest. The user-facing
description is in `readme.txt`; this file is for developers.

## Layout

```
dfx-prayer-intentions.php   bootstrap: constants, autoloader, activation hooks
includes/                   domain services, one singleton class per file
  channels/                 DFXPRAY_Channel interface + email, Telegram, webhook
admin/                      panel: DFXPRAY_Admin (screens), DFXPRAY_Admin_Actions (every POST)
  views/                    screen templates
templates/                  index, digest and form templates (theme-overridable)
assets/js/app.js            the leaders' app (PWA, no build step); served by DFXPRAY_App, data from DFXPRAY_App_API
blocks/form/                the dfxpray/form block (no build step)
languages/                  POT and the es_ES translation
```

Every plugin class uses the `DFXPRAY_Singleton` trait (`::get_instance()`);
`tests/unit/SingletonTest.php` discovers the class files and fails if one
does not. The only exception is the autoloader, which is static by necessity.

Time always comes from `DFXPRAY_Clock`, in the site timezone, so tests can
freeze it. On a site that defines `DFXPRAY_ALLOW_FROZEN_CLOCK`, the
`dfxpray_frozen_now` option freezes it too (the E2E suite does this).

## The leaders' app

Leaders can install a small app on their phones at `/<url_base>-app/` (by
default `/prayer-requests-app/`): today's message ready to share, the
intentions with their follow-up, a quick form to add one, and moderation and
reviews. It is a PWA (manifest and service worker under that address) and
talks only to `DFXPRAY_App_API` through admin-ajax, for logged-in leaders,
with the panel's own permission checks. Without connection it shows the last
message and lists saved on the device and queues changes until it is back.

## Tests

Test-first throughout. Three layers, all against real WordPress:

| Layer | Where | Runs with |
| --- | --- | --- |
| Unit (pure logic: lifecycle, discretion, formatting) | `tests/unit` | PHPUnit |
| Integration (database, hooks, permissions, channels, panel) | `tests/integration` | PHPUnit |
| End-to-end (browser, served site, WP-CLI fixtures) | `tests/e2e` | Playwright |

One-time setup:

```sh
composer install
npm install && npx playwright install chromium
tests/bin/start-db.sh     # throwaway MySQL 8 in Docker on 127.0.0.1:3310
tests/bin/install.sh      # WordPress + test library into tests/.wp/
```

Then:

```sh
composer test             # PHPUnit, both suites
tests/bin/php-matrix.sh   # PHPUnit on PHP 8.1 (the floor) and 8.3, in Docker
composer lint             # PHPCS, WordPress standard + PHPCompatibility 8.1
npx playwright test       # E2E: rebuilds tests/.wp/e2e-site, serves it with php -S
```

The E2E setup fails if the plugin raises any PHP notice while the site is
built, and every E2E test fails if the plugin logs a PHP error while it runs.
Set `DFXPRAY_E2E_SKIP_SETUP=1` to reuse the site between runs.

MariaDB cannot run from the project directory under the system's AppArmor
profile, which is why the test database lives in Docker.

## Translations

Source strings are English; Spanish (the plugin's base language for its
users) ships complete. After changing strings:

```sh
wp i18n make-pot . languages/dfx-prayer-intentions.pot --exclude=tests,vendor,node_modules,dist
# add the new strings to bin/es_ES.py
python3 bin/build-translations.py
wp i18n make-mo languages && wp i18n make-json languages --no-purge
```

`tests/integration/TranslationTest.php` fails while any string is untranslated,
and checks the specification's index example character for character in Spanish.

## Release

```sh
bin/build-zip.sh          # dist/dfx-prayer-intentions-<version>.zip, per .distignore
```

Run Plugin Check on the zip before submitting (it is clean as of 26.09.24).
