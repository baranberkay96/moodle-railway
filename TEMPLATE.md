# Moodle 5.3 LMS Online Learning Platform — Railway template spec

## Listing

| Field | Value |
|---|---|
| Name | **Moodle 5.3 LMS Online Learning Platform** (version label `5.3`: Moodle numbers its major releases X.Y — 5.0, 5.1, 5.2, 5.3 are separate major releases with different requirements and layouts; 5.3 is the current LTS. "Moodle 5" would not identify what is deployed.) |
| Description | Courses, quizzes and grades for schools and teams, with cron and caching. (73 chars, unchanged from LISTINGS_ROUND2.md) |
| Category | Other |
| Tags | moodle, lms, e-learning, education, php, postgres, redis |
| User input at deploy | **`MOODLE_ADMIN_EMAIL`**. Everything else is generated. Optional later: SMTP, site name/language. |

The description is delivered as written: real cron every minute and Redis for sessions and the application cache.

## Base market template

- **`moodle-1` "moodle"** (62 active / 17 recent projects) — chosen as base: Railway Postgres + one Moodle service with a moodledata volume and an Apache MPM fix in the start command. Kept: Postgres, volume at the moodledata path, `MOODLE_URL` from the public domain, the single-MPM fix.
  Fixed: third-party image `ellakcy/moodle:postgresql_apache_500_php8.4` (Moodle 5.0, community-maintained), start command that only launches Apache (**no cron**, which Moodle requires every minute), no Redis, `MOODLE_ADMIN_EMAIL` defaulting to a fake address, `MOODLE_REVERSE_LB`/`MOODLE_SSL` toggles instead of a fixed proxy setup, public TCP proxy on Postgres, Postgres 16 (Moodle 5.3 requires 17+), no healthcheck.
- **`moodle`** (25 active / 14 recent) — `bitnamilegacy/moodle:5.0.2` (Bitnami stopped publishing free images; the `bitnamilegacy` repository receives no updates) with `bitnami/mariadb:latest`, empty required admin username/password, root containers. Nothing taken.
- `chamilo` — a different LMS (Chamilo), not used.
- No `_upstream` repos exist for this app. Market templates carry no license → reference only.

**Approach chosen:** a thin Dockerfile on the **official `php:8.4.26-apache-trixie` image** with the **official Moodle 5.3 release tarball** (SHA-256 verified) and its Composer dependencies. No third-party Moodle image is involved, and upgrades are a version/checksum change.

## Pinned versions

| Component | Source | Version | Release date | Checked |
|---|---|---|---|---|
| Moodle | `download.moodle.org/.../stable503/moodle-5.3.tgz`, SHA-256 `7e5edf110555956571f40e42acffde0eb23681ebebd212a2fec0fe7d795dd511` | 5.3 (Build: 20261005, `$version` 2026100500.00, tag `v5.3.0`), LTS | 2026-10-05 | 2026-10-11 |
| PHP + Apache | `php` | `8.4.26-apache-trixie` | 2026-10-07 | 2026-10-11 |
| Composer (build only) | `composer` | `2.10.3` | 2026-09-30 | 2026-10-11 |
| phpredis | PECL `redis` | `6.3.0` (stable) | — | 2026-10-11 |
| Postgres | `ghcr.io/railwayapp-templates/postgres-ssl` | `18` (Moodle 5.3 minimum: 17) | Railway base | 2026-10-11 |
| Redis | `redis` | `8.2` | Railway base | 2026-10-11 |

Moodle 5.3 supports PHP 8.3 and 8.4 (8.4 chosen). **Upgrade:** set `MOODLE_VERSION`, `MOODLE_BRANCH` and `MOODLE_SHA256` in `services/moodle/Dockerfile` (the checksum is published next to each tarball as `.sha256`), back up Postgres and the volume, redeploy. The boot script detects the pending upgrade and runs `admin/cli/upgrade.php --non-interactive` (Moodle's maintenance mode is enabled automatically during the upgrade).

## Architecture

```
Learners / teachers ──HTTPS──▶ Moodle (Apache prefork + mod_php 8.4, :80, public)
                                 │  webroot = moodle/public (5.1+ layout), router via r.php
                                 │  boot: wait for DB → CLI install (first boot) or upgrade
                                 │        → Redis MUC store → purge caches → cron loop → Apache
                                 │  cron loop: php admin/cli/cron.php every 60 s (same container)
                                 ├─▶ Postgres 18   (all Moodle data)
                                 └─▶ Redis 8.2     (sessions + application cache)
volume /var/moodledata: uploaded files (filedir), backups, temp, language packs, MUC config
```

### Design decisions (and why)

- **Cron inside the Moodle container.** Moodle wants `admin/cli/cron.php` every minute, and cron tasks read and write moodledata (files, temp, backups). Railway volumes attach to exactly one service and Railway cron services run at most every 5 minutes, so a separate Railway cron service could neither see moodledata nor run often enough. The entrypoint starts a small loop as `www-data` that launches `cron.php` every 60 s, allowing up to 3 overlapping runs (each keeps polling for its keep-alive time; Moodle's locks prevent duplicate work) — the same behaviour as a system crontab. Verified locally: 154 task-log entries after the first minutes.
- **Install/upgrade at boot, not in pre-deploy.** Railway pre-deploy containers have no volume, while Moodle's installer and upgrader write to moodledata (language packs, MUC config, file pool). The boot script runs them before Apache starts; the healthcheck (`/healthz`, Apache-only) therefore turns green only when Moodle is ready, with a 1200 s window for the first install. The service has a volume, so there is exactly one replica and no concurrent upgrades.
- **Immutable code, mutable data.** Moodle code lives in the image (root-owned, read-only for `www-data`); only `/var/moodledata` is persistent. `$CFG->disableupdateautodeploy` and `$CFG->preventexecpath` are set. Add plugins or themes by placing them under `services/moodle/overlay/` (e.g. `overlay/public/mod/<plugin>/`) and redeploying; the upgrade runs automatically.
- **Composer dependencies.** Since Moodle 5.1 some libraries (e.g. the REST API's `league/oauth2-server`) are installed with Composer and not shipped in the tarball; the build runs `composer install --no-dev --classmap-authoritative` as Moodle's docs require. Found during local testing: without it `/api/rest/v2/openapi.json` fails with a missing class.
- **Router.** `$CFG->routerconfigured = true`; Apache sends any path that is not a file or directory to `r.php` (rewrite rule from Moodle's router guide, plus `FallbackResource`). Verified locally against Moodle's five router self-test URLs (`/core/check/controller/test` 200, `/api/rest/v2/openapi.json` 200, `/not/a/valid/request` 404, both shim routes 302).
- **Redis.** Sessions use Moodle's Redis session handler (`$CFG->session_handler_class = '\core\session\redis'` from `config.php`); the application cache is pointed at a Redis store through Moodle's own cache API (`core_cache\config_writer`) on each boot (idempotent). Verified locally: `mdl_sess_*` and `mdl_muc_*` keys in Redis.
- **TLS at Railway's edge.** `$CFG->sslproxy` is set automatically when `MOODLE_URL` is https.
- **Single Apache MPM** enforced at build and boot (guards against `AH00534: More than one MPM loaded`, reported for stock PHP/Apache images on Railway and handled the same way in the round-1 WordPress template).
- **PDF annotation:** Ghostscript and poppler (`pdftoppm`) installed, paths fixed in `config.php`.

## Services

All services are created together from the template; references resolve at deploy time. Empty values are optional.

### Moodle

- **Source:** repo `baranberkay96/moodle-railway`, root directory `/services/moodle` (Dockerfile build)
- **Start command:** image default
- **Healthcheck:** `/healthz` (timeout 1200 s)
- **Public domain:** yes → target port `80`
- **TCP proxy:** none
- **Volume:** `/var/moodledata`
- **Restart policy:** ON_FAILURE (max 10 retries)
- **Replicas:** 1

| Variable | Value | Description |
|---|---|---|
| `PORT` | `80` | Port Apache listens on; Railway routes traffic and healthchecks here. |
| `MOODLE_URL` | `https://${{RAILWAY_PUBLIC_DOMAIN}}` | Site URL ($CFG->wwwroot). Change it when you add a custom domain; Moodle redirects every request to this host. |
| `MOODLE_DB_TYPE` | `pgsql` | Database driver (Railway Postgres). |
| `MOODLE_DB_HOST` | `${{Postgres.RAILWAY_PRIVATE_DOMAIN}}` | Postgres host on the private network. |
| `MOODLE_DB_PORT` | `5432` | Postgres port. |
| `MOODLE_DB_NAME` | `${{Postgres.PGDATABASE}}` | Database name. |
| `MOODLE_DB_USER` | `${{Postgres.PGUSER}}` | Database user. |
| `MOODLE_DB_PASSWORD` | `${{Postgres.PGPASSWORD}}` | Database password. |
| `MOODLE_DB_PREFIX` | `mdl_` | Table prefix; do not change after installation. |
| `MOODLE_REDIS_HOST` | `${{Redis.RAILWAY_PRIVATE_DOMAIN}}` | Redis for sessions and the application cache. Empty = database sessions and file cache. |
| `MOODLE_REDIS_PORT` | `6379` | Redis port. |
| `MOODLE_REDIS_PASSWORD` | `${{Redis.REDIS_PASSWORD}}` | Redis password. |
| `MOODLE_ADMIN_USER` | `admin` | Username of the site administrator created at installation. |
| `MOODLE_ADMIN_PASSWORD` | `${{secret(24)}}` | Password of the site administrator (used once at installation; change it in your profile afterwards). |
| `MOODLE_ADMIN_EMAIL` | *(empty)* | E-mail of the site administrator (also the support contact). **Required input.** |
| `MOODLE_SITE_NAME` | `Moodle` | Full site name set at installation (can be changed later in Site administration). |
| `MOODLE_SITE_SHORTNAME` | `moodle` | Short site name set at installation. |
| `MOODLE_LANG` | `en` | Installation language code, e.g. en, de, es, tr (the language pack is downloaded during install). |
| `MOODLE_NOREPLY_ADDRESS` | *(empty)* | No-reply sender address for notifications, e.g. noreply@yourschool.org. Optional. |
| `MOODLE_SMTP_HOST` | *(empty)* | SMTP host for outgoing mail (outbound SMTP needs Railway Pro). Empty = configure later in Site administration. Optional. |
| `MOODLE_SMTP_PORT` | `587` | SMTP port. |
| `MOODLE_SMTP_SECURE` | `tls` | SMTP security: tls (STARTTLS), ssl, or empty. |
| `MOODLE_SMTP_USER` | *(empty)* | SMTP username. Optional. |
| `MOODLE_SMTP_PASSWORD` | *(empty)* | SMTP password. Optional. |
| `MOODLE_CRON_ENABLED` | `true` | Run Moodle cron inside this service every minute (needs moodledata, so it cannot be a separate Railway cron service). |
| `MOODLE_CRON_INTERVAL` | `60` | Seconds between cron starts (Moodle recommends every minute). |
| `MOODLE_CRON_MAX_RUNNERS` | `3` | Maximum overlapping cron runs (each keeps polling for tasks for a few minutes). |
| `MOODLE_CRON_VERBOSE` | `false` | Stream cron output to the logs. Task logs are always available in Site administration > Server > Tasks > Task logs. |
| `PHP_MEMORY_LIMIT` | `256M` | PHP memory per web request. |
| `PHP_UPLOAD_MAX_FILESIZE` | `128M` | Largest single upload; Moodle's own course/site limits apply on top. |
| `PHP_POST_MAX_SIZE` | `130M` | Largest request body (keep slightly above PHP_UPLOAD_MAX_FILESIZE). |
| `PHP_TIMEZONE` | `UTC` | PHP default timezone (Moodle's site timezone is set in Site administration). |
| `APACHE_MAX_REQUEST_WORKERS` | `25` | Maximum concurrent PHP requests. Peak RAM is roughly this x 60-100 MB; raise with the service memory. |
| `MOODLE_CONFIG_EXTRA` | *(empty)* | Optional raw PHP appended to config.php (e.g. $CFG->theme = 'boost';). Evaluated last. Advanced. |

### Postgres

- **Source:** Docker image `ghcr.io/railwayapp-templates/postgres-ssl:18`
- **Start command:** image default
- **Healthcheck:** none
- **Public domain:** no (private network only)
- **TCP proxy:** none
- **Volume:** `/var/lib/postgresql/data`
- **Restart policy:** ON_FAILURE (max 10 retries)
- **Replicas:** 1

| Variable | Value | Description |
|---|---|---|
| `PGDATA` | `/var/lib/postgresql/data/pgdata` | Location where the database will be initialized. |
| `PGHOST` | `${{RAILWAY_PRIVATE_DOMAIN}}` | Railway Private Domain Name. |
| `PGPORT` | `5432` | Port to connect to Postgres. |
| `PGUSER` | `${{ POSTGRES_USER }}` | Required variable for Data panel. |
| `PGDATABASE` | `${{POSTGRES_DB}}` | Required variable for the data panel. |
| `PGPASSWORD` | `${{POSTGRES_PASSWORD}}` | Required variable for Data panel. |
| `POSTGRES_DB` | `railway` | Default database created when image is started. |
| `DATABASE_URL` | `postgresql://${{PGUSER}}:${{POSTGRES_PASSWORD}}@${{RAILWAY_PRIVATE_DOMAIN}}:5432/${{PGDATABASE}}` | URL to connect to Postgres database. |
| `POSTGRES_USER` | `postgres` | User to connect to Postgres DB. |
| `SSL_CERT_DAYS` | `820` | SSL certificate expiry in days. |
| `POSTGRES_PASSWORD` | `${{ secret(32, "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ") }}` | Password to connect to DB. |
| `RAILWAY_DEPLOYMENT_DRAINING_SECONDS` | `60` | Allow Postgres to cleanly shut down. |

### Redis

- **Source:** Docker image `redis:8.2`
- **Start command:** `/bin/sh -c "rm -rf $RAILWAY_VOLUME_MOUNT_PATH/lost+found/ && exec docker-entrypoint.sh redis-server --requirepass $REDIS_PASSWORD --save 60 1 --dir $RAILWAY_VOLUME_MOUNT_PATH"`
- **Healthcheck:** none
- **Public domain:** no (private network only)
- **TCP proxy:** none
- **Volume:** `/data`
- **Restart policy:** ON_FAILURE (max 10 retries)
- **Replicas:** 1

| Variable | Value | Description |
|---|---|---|
| `REDISHOST` | `${{RAILWAY_PRIVATE_DOMAIN}}` | Private network hostname of the Redis service, only resolvable from services in the same environment. |
| `REDISPORT` | `6379` | Port that Redis listens on. |
| `REDISUSER` | `default` | Username for authenticating with Redis. |
| `REDIS_URL` | `redis://${{ REDISUSER }}:${{ REDIS_PASSWORD }}@${{ REDISHOST }}:${{ REDISPORT }}` | Connection string for connecting to Redis using the private network. |
| `REDISPASSWORD` | `${{REDIS_PASSWORD}}` | Alias of REDIS_PASSWORD for clients that expect the unseparated name. |
| `REDIS_PASSWORD` | `${{ secret(32, "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ") }}` | Randomly generated password for authenticating with Redis. |


## Improvements over market templates

| # | Change | Why |
|---|---|---|
| 1 | Moodle 5.3 LTS from the official tarball on official PHP 8.4 (base: third-party image with Moodle 5.0; other: frozen Bitnami legacy image) | Current LTS, maintained supply chain, checksum-verified. |
| 2 | Cron every minute inside the service | Without cron Moodle does not send notifications, run backups, grade queues, clean up or process ad-hoc tasks. |
| 3 | Redis sessions + Redis application cache, configured automatically | Faster pages; required for good performance under load. |
| 4 | Non-interactive CLI install with generated admin password on first boot | No install wizard exposed on a public URL; first login works immediately. |
| 5 | Automatic upgrade on version change | Redeploy = upgrade, with Moodle's maintenance mode. |
| 6 | Postgres 18 (Railway base), private only | Meets Moodle 5.3's PostgreSQL 17+ requirement; no public DB proxy. |
| 7 | 5.1+ public/ webroot, router enabled, Composer deps installed | Config, CLI scripts and libraries are not web-accessible; REST API and router work. |
| 8 | Tuned PHP (max_input_vars 5000, OPcache), bounded Apache workers, Ghostscript/poppler | Passes Moodle's environment checks; predictable memory; PDF annotation works. |
| 9 | Healthcheck `/healthz` | Railway only routes to a ready site. |

## First login

1. Wait for the deploy log line `installation finished; sign in as admin` (first boot takes a few minutes; later boots seconds).
2. Open the public URL, sign in as `admin` (`MOODLE_ADMIN_USER`) with `MOODLE_ADMIN_PASSWORD` from the Moodle service variables, then change the password.
3. Site administration → Notifications should show no pending upgrades; Site administration → Reports → System status shows the router and cron as OK.
4. Mail: on Railway Pro set `MOODLE_SMTP_*`; otherwise configure an SMTP relay later under Site administration → Server → Email.
5. Custom domain: add it to the service, set `MOODLE_URL` to the new https URL, redeploy. Moodle builds its links from `MOODLE_URL`; links typed by hand into course content keep the old host.

## Scaling

- **Moodle:** has a volume (moodledata) → one replica; scale vertically and raise `APACHE_MAX_REQUEST_WORKERS` together with memory (≈ 60–100 MB per worker). Horizontal scaling would require moving files to shared storage (not supported by Railway volumes).
- **Postgres:** vertical first; HA conversion available. **Redis:** vertical.

## Resource estimate

Railway prices: RAM $10/GB-month, vCPU $20/vCPU-month, volume $0.15/GB-month. Idle figures measured locally (2026-10-11) right after installation.

| Service | Idle RAM | Idle CPU | Typical RAM (≈100 active learners) | Typical CPU |
|---|---|---|---|---|
| Moodle (Apache + PHP + cron) | 210–300 MB | 0.02 vCPU (cron polling) | 1.2 GB | 0.3 vCPU |
| Postgres | 100–150 MB | 0.01 vCPU | 400 MB | 0.05 vCPU |
| Redis | 12 MB | <0.01 vCPU | 100 MB | 0.01 vCPU |
| **Total** | **≈0.4 GB** | **≈0.03 vCPU** | **≈1.7 GB** | **≈0.36 vCPU** |

- **Idle:** ≈ $4 RAM + $0.6 CPU + $0.2 volumes ≈ **$5/month**.
- **Typical:** ≈ $17 RAM + $7 CPU + $1.5 volumes (≈10 GB of course files and backups) ≈ **$25/month**. Exams with many simultaneous users spike CPU and workers.
- **Minimum plan:** Hobby (the first install and upgrades need more than Trial's limits comfortably allow; moodledata grows past small volumes with course backups).

## Verification checklist

- [ ] Moodle log: `database reachable` → `empty database: installing Moodle` → `installation finished` → `application cache mapped to Redis` → `cron loop started`; healthy on `/healthz`.
- [ ] Sign in as admin; System status page: router OK, cron ran within the last minutes.
- [ ] Create a course, upload a file and a PDF assignment submission; annotate the PDF (Ghostscript/poppler).
- [ ] Redis contains `mdl_sess_*` and `mdl_muc_*` keys (Railway → Redis → Data).
- [ ] Redeploy: log shows `Moodle is installed and up to date`; course and file still there.
- [ ] Site administration → Server → Tasks → Task logs shows recent runs every minute.

## Open questions

- **First-install duration on Railway** was 45 s on a fast local machine; on Railway's shared CPUs expect a few minutes. The healthcheck window is 1200 s.
- **Language packs:** a non-English `MOODLE_LANG` downloads the pack from download.moodle.org during install (needs outbound HTTPS, available on Railway).
- **Router self-test from the admin UI** calls the site's own public URL from inside the container; on Railway this is the public domain and works. In the local test it could only be checked from outside the container (all five URLs returned the expected codes).
- **Interrupted first install:** the boot script treats the site as installed once Moodle's `config` table exists. If the very first installation is killed midway (e.g. out of memory), empty the Postgres database (or recreate the Postgres service) and redeploy; later upgrades are not affected.
- **Large uploads:** `PHP_UPLOAD_MAX_FILESIZE` is 128 MB; Railway's edge request limits for very large course backups were not tested.
- **Local functional test ran on the arm64 build** of the same Dockerfile; the amd64 build completes and passes the same build-time checks (extensions present, `apache2ctl -t`, `php -l`).
