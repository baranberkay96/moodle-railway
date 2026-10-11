#!/bin/sh
# Boot script for Moodle on Railway (runs as root; Moodle CLI steps run as www-data).
#  1. Exactly one Apache MPM (guards against "AH00534: More than one MPM loaded").
#  2. Prepares moodledata on the volume and the local cache directory.
#  3. Waits for Postgres.
#  4. First boot: non-interactive CLI install with the admin account from variables.
#     Later boots: runs the upgrade when the image carries a newer Moodle or plugin version.
#  5. Points the application cache at Redis and purges caches (code may have changed).
#  6. Starts the built-in cron loop (every minute) in the background.
#  7. Hands over to Apache.
set -eu

MOODLE_DIR=/var/www/moodle
DATAROOT="${MOODLE_DATAROOT:-/var/moodledata}"
SCRIPTS=/usr/local/share/railway/scripts

log() { printf '[railway-entrypoint] %s\n' "$*"; }
as_www() { setpriv --reuid=www-data --regid=www-data --init-groups -- "$@"; }

# Only the default command gets the boot sequence; anything else (a debug shell) runs as-is.
if [ "$*" != "apache2-foreground" ]; then
    exec "$@"
fi

# 1. Single MPM guard.
mpm_count="$(find /etc/apache2/mods-enabled -maxdepth 1 -name 'mpm_*.load' | wc -l)"
if [ "$mpm_count" != "1" ] || [ ! -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    log "found ${mpm_count} enabled MPM modules; enabling mpm_prefork only"
    rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
    a2enmod -q mpm_prefork
fi

# Required settings.
: "${MOODLE_URL:?MOODLE_URL is required (public https URL of the site)}"
: "${MOODLE_DB_HOST:?MOODLE_DB_HOST is required}"

# 2. Directories. Railway mounts the volume owned by root.
mkdir -p "$DATAROOT" /var/cache/moodle
rmdir "$DATAROOT/lost+found" 2>/dev/null || true
if [ "$(stat -c '%U' "$DATAROOT")" != "www-data" ]; then
    log "setting ownership of ${DATAROOT} to www-data (first boot on this volume)"
    chown -R www-data:www-data "$DATAROOT"
fi
chmod 2770 "$DATAROOT"
chown www-data:www-data /var/cache/moodle

# 3. Database.
as_www php "$SCRIPTS/railway-db.php" wait

# 4. Install or upgrade.
set +e
as_www php "$SCRIPTS/railway-db.php" installed
installed=$?
set -e
case "$installed" in
    0)
        set +e
        as_www php "$MOODLE_DIR/admin/cli/upgrade.php" --is-pending >/dev/null 2>&1
        pending=$?
        set -e
        if [ "$pending" = "2" ]; then
            log "upgrade pending: running admin/cli/upgrade.php"
            as_www php "$MOODLE_DIR/admin/cli/upgrade.php" --non-interactive
        else
            log "Moodle is installed and up to date"
        fi
        ;;
    3)
        : "${MOODLE_ADMIN_PASSWORD:?MOODLE_ADMIN_PASSWORD is required for the first install}"
        : "${MOODLE_ADMIN_EMAIL:?MOODLE_ADMIN_EMAIL is required for the first install}"
        log "empty database: installing Moodle (this takes a few minutes)"
        as_www php "$MOODLE_DIR/admin/cli/install_database.php" \
            --agree-license \
            --lang="${MOODLE_LANG:-en}" \
            --adminuser="${MOODLE_ADMIN_USER:-admin}" \
            --adminpass="$MOODLE_ADMIN_PASSWORD" \
            --adminemail="$MOODLE_ADMIN_EMAIL" \
            --fullname="${MOODLE_SITE_NAME:-Moodle}" \
            --shortname="${MOODLE_SITE_SHORTNAME:-moodle}" \
            --supportemail="$MOODLE_ADMIN_EMAIL"
        log "installation finished; sign in as ${MOODLE_ADMIN_USER:-admin}"
        ;;
    *)
        log "could not determine the installation state"
        exit 1
        ;;
esac

# 5. Redis application cache + cache purge.
as_www php "$SCRIPTS/configure-redis-cache.php"
as_www php "$MOODLE_DIR/admin/cli/purge_caches.php" >/dev/null 2>&1 || log "cache purge reported a problem"

# 6. Cron loop (Railway cron services cannot share the moodledata volume and run at most
#    every 5 minutes, so cron runs here, every minute, as Moodle recommends).
if [ "${MOODLE_CRON_ENABLED:-true}" = "true" ]; then
    as_www /usr/local/bin/moodle-cron-loop.sh &
    log "cron loop started (every ${MOODLE_CRON_INTERVAL:-60}s)"
else
    log "MOODLE_CRON_ENABLED=false: cron loop not started"
fi

# 7. Apache in the foreground.
exec docker-php-entrypoint "$@"
