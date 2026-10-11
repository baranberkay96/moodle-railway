#!/bin/sh
# Moodle cron runner (started by the entrypoint as www-data).
# Behaves like a system crontab entry "* * * * * php admin/cli/cron.php": a new run starts
# every MOODLE_CRON_INTERVAL seconds, and runs may overlap (each keeps polling for
# MOODLE_CRON_KEEPALIVE seconds, Moodle's own locks prevent duplicate work).
# MOODLE_CRON_MAX_RUNNERS caps concurrent runs to bound memory use.
# Task output is stored by Moodle (Site administration > Server > Tasks > Task logs);
# set MOODLE_CRON_VERBOSE=true to also stream it to the service logs.

INTERVAL="${MOODLE_CRON_INTERVAL:-60}"
MAX_RUNNERS="${MOODLE_CRON_MAX_RUNNERS:-3}"
KEEPALIVE="${MOODLE_CRON_KEEPALIVE:-}"
CRON="/var/www/moodle/admin/cli/cron.php"

case "$INTERVAL" in ''|*[!0-9]*) INTERVAL=60 ;; esac
case "$MAX_RUNNERS" in ''|*[!0-9]*) MAX_RUNNERS=3 ;; esac
[ "$INTERVAL" -lt 30 ] && INTERVAL=30

run_once() {
    if [ -n "$KEEPALIVE" ]; then
        set -- --keep-alive="$KEEPALIVE"
    else
        set --
    fi
    if [ "${MOODLE_CRON_VERBOSE:-false}" = "true" ]; then
        php "$CRON" "$@"
    else
        php "$CRON" "$@" >/dev/null 2>&1
    fi
    status=$?
    [ "$status" -ne 0 ] && echo "[moodle-cron] cron.php exited with status ${status}"
    return 0
}

echo "[moodle-cron] starting: every ${INTERVAL}s, up to ${MAX_RUNNERS} concurrent runs"
count_runners() {
    # Count live cron.php processes via /proc (no procps in the image). The bracket in the
    # pattern keeps grep from matching its own command line.
    grep -las 'admin/cli/cro[n][.]php' /proc/[0-9]*/cmdline 2>/dev/null | wc -l
}

while :; do
    if [ "$(count_runners)" -lt "$MAX_RUNNERS" ]; then
        run_once &
    else
        echo "[moodle-cron] ${MAX_RUNNERS} runs still active; skipping this minute"
    fi
    sleep "$INTERVAL"
done
