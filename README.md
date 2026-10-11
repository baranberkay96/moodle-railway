# Deploy and Host Moodle with Railway

Moodle is the open-source learning management system used by schools, universities and companies to run courses, quizzes, assignments and gradebooks. This community template runs Moodle 5.3 LTS on Railway from the official release, with PostgreSQL, Redis caching, cron every minute and persistent storage for course files.

## About Hosting Moodle

Moodle is a PHP application that needs a web server, a database, a data directory for uploaded files, and a cron job that runs every minute for notifications, backups, grading and clean-up. This template builds Moodle from the official, checksum-verified release on the official PHP 8.4 Apache image, serves only Moodle's public directory, installs the site from the command line on first boot with a generated admin password, upgrades automatically when you change the version, stores sessions and the application cache in Redis, and runs cron inside the same service because cron needs the data directory.

## Common Use Cases

- Running online and blended courses with quizzes, assignments and a gradebook
- Employee onboarding, compliance and certification training with completion tracking
- Teaching platforms for schools and training providers that need their own branding and data control
- Self-paced courses with SCORM packages, H5P activities and badges

## Dependencies for Moodle Hosting

- Moodle 5.3 (official release tarball) on PHP 8.4 with Apache
- PostgreSQL 17 or newer (Railway Postgres 18)
- Redis (sessions and application cache)
- A persistent volume for moodledata
- Optional: an SMTP server for e-mail (Railway Pro)

### Deployment Dependencies

- Moodle downloads and checksums: https://download.moodle.org/releases/latest/
- Moodle 5.3 release notes and requirements: https://moodledev.io/general/releases/5.3
- Installing Moodle: https://docs.moodle.org/503/en/Installing_Moodle
- Configuring the router: https://docs.moodle.org/503/en/Configuring_the_Router
- Cron: https://docs.moodle.org/503/en/Cron
- Redis cache store: https://docs.moodle.org/503/en/Redis_cache_store

### Implementation Details

| Service | Image / source | Role |
|---|---|---|
| Moodle | `services/moodle` (official `php:8.4.26-apache-trixie` + Moodle 5.3 tarball) | Web server, CLI install/upgrade at boot, cron every minute, moodledata volume |
| Postgres | `ghcr.io/railwayapp-templates/postgres-ssl:18` | Moodle database (volume) |
| Redis | `redis:8.2` | Sessions and application cache (volume) |

**First login:** enter your e-mail in `MOODLE_ADMIN_EMAIL` when deploying. The first boot installs Moodle (a few minutes); then open `https://{your-domain}` and sign in as `admin` with the `MOODLE_ADMIN_PASSWORD` value from the Moodle service variables. Change the password, then check Site administration → Notifications and Reports → System status.

**Plugins and themes:** the Moodle code is part of the image, so plugins are added in the template repository under `services/moodle/overlay/` (for example `overlay/public/mod/{plugin}/`) and deployed with a redeploy; the upgrade runs automatically.

**E-mail:** fill `MOODLE_SMTP_HOST` and related variables (outbound SMTP requires Railway Pro), or configure mail later in Site administration → Server → Email.

**Scaling:** moodledata lives on a volume, so Moodle runs as a single service; scale it vertically and raise `APACHE_MAX_REQUEST_WORKERS` together with its memory. Scale Postgres vertically for large sites.

**Versions:** to upgrade, change `MOODLE_VERSION`, `MOODLE_BRANCH` and `MOODLE_SHA256` in `services/moodle/Dockerfile`, back up Postgres and the volume, and redeploy. The boot script runs Moodle's upgrade before the site comes back.

### Why Deploy Moodle on Railway?

Railway runs Moodle, its database and cache as one project with private networking, persistent volumes and usage-based billing. You get a maintained, production-shaped Moodle with working cron and caching, without managing a server, PHP extensions or crontabs yourself.
