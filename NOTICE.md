# NOTICE — Moodle 5.3 LMS Online Learning Platform

## Software deployed

| Component | Source | License |
|---|---|---|
| Moodle 5.3 | Official release tarball `https://download.moodle.org/download.php/direct/stable503/moodle-5.3.tgz` (SHA-256 `7e5edf110555956571f40e42acffde0eb23681ebebd212a2fec0fe7d795dd511`), downloaded at build time | GPL-3.0-or-later (Moodle `COPYING.txt`); bundled third-party libraries under their own licenses (`thirdpartylibs.xml` files) |
| Moodle Composer dependencies | Installed at build time from Moodle's `composer.lock` (`composer install --no-dev`) | Each package's own license (MIT, Apache-2.0, BSD, LGPL; listed in `vendor/composer/installed.json`) |
| PHP + Apache | Docker official image `php:8.4.26-apache-trixie` | PHP License 3.01, Apache-2.0, Debian packages under their own licenses |
| Composer | `composer:2.10.3` (binary copied at build time) | MIT |
| phpredis | PECL `redis` 6.3.0 | PHP License 3.01 |
| Ghostscript, poppler-utils | Debian packages | AGPL-3.0 (Ghostscript), GPL-2.0 (poppler) |
| PostgreSQL | Railway base image `ghcr.io/railwayapp-templates/postgres-ssl:18` | PostgreSQL License |
| Redis | `redis:8.2` (Railway base definition) | Redis 8 tri-license (RSALv2 / SSPLv1 / AGPLv3) |

Moodle is downloaded and built into the image by the Dockerfile; no Moodle source is stored in this repository. "Moodle" is a registered trademark of Moodle Pty Ltd; this is a community template, not affiliated with or endorsed by Moodle.

The files in `services/moodle/` (`Dockerfile`, `config/*`, `scripts/*`) are original work for this template. `config/config.php` follows the setting names documented in Moodle's `config-dist.php` (GPL-3.0); the Apache router rule follows Moodle's router configuration guide. The Apache prefork/MPM approach mirrors this project's round-1 WordPress template.

## Upstream files checked (2026-10-11, Moodle 5.3 / tag `v5.3.0`)

- `INSTALL.txt`, `config-dist.php` — database, wwwroot, dataroot, `routerconfigured`, Redis session handler, `localcachedir`, `disableupdateautodeploy`, `preventexecpath`.
- `public/admin/environment.xml` — PHP ≥ 8.3, PostgreSQL ≥ 17, required extensions, `max_input_vars` ≥ 5000 (`public/lib/upgradelib.php`), OPcache.
- `admin/cli/install_database.php`, `admin/cli/upgrade.php`, `admin/cli/cron.php`, `admin/cli/purge_caches.php` — CLI options used by the boot script; `public/lib/classes/cron.php` (default keep-alive 3 minutes).
- `public/cache/classes/config_writer.php`, `public/cache/classes/config.php`, `public/cache/stores/redis/lib.php` — cache store API used to map the application cache to Redis.
- `public/lib/classes/component.php`, `public/lib/classes/environment.php`, `composer.json`, `composer.lock` — Composer autoload and dependency check.
- Docs: Moodle 5.3 release notes (moodledev.io), "Configuring the Router" and "Composer vendor directory not found" (docs.moodle.org).

## Reused material

- **Railway base definitions** `templates/_railway-base/postgres.json` and `redis.json` (this repository): copied unchanged as instructed by STANDARD.md.
- **Market templates** `moodle-1`, `moodle`, `chamilo` (Railway marketplace, no license): reviewed for structure only; no text or files copied. All prose in this package is original.
- No `_upstream` repositories exist for this app.
