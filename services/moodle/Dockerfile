# Moodle LMS for Railway: official php:apache image + the official Moodle release tarball.
#
#  - PHP extensions Moodle 5.3 needs (pgsql, intl, zip, gd, soap, exif, opcache, phpredis)
#  - Ghostscript + poppler-utils for PDF annotation in assignments
#  - Apache with the webroot on Moodle's public/ directory and the router enabled
#  - Boot script: wait for Postgres, CLI install (first boot) or upgrade, Redis cache store,
#    built-in cron loop (every minute, in this container because cron needs moodledata)
#
# Upgrade: change MOODLE_VERSION / MOODLE_BRANCH / MOODLE_SHA256 (values published next to
# each tarball on download.moodle.org) and redeploy. The boot script runs the upgrade.
ARG PHP_IMAGE_TAG=8.4.26-apache-trixie
ARG COMPOSER_IMAGE_TAG=2.10.3
FROM composer:${COMPOSER_IMAGE_TAG} AS composer

FROM php:${PHP_IMAGE_TAG}

ARG MOODLE_VERSION=5.3
ARG MOODLE_BRANCH=503
ARG MOODLE_SHA256=7e5edf110555956571f40e42acffde0eb23681ebebd212a2fec0fe7d795dd511
ARG PHPREDIS_VERSION=6.3.0

# 1) Runtime tools, PHP extensions (build deps removed afterwards), locales.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ghostscript poppler-utils locales; \
    sed -i -e 's/^# *\(en_AU.UTF-8\)/\1/' -e 's/^# *\(en_US.UTF-8\)/\1/' /etc/locale.gen; \
    locale-gen; \
    saved_apt_mark="$(apt-mark showmanual)"; \
    apt-get install -y --no-install-recommends \
        libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libwebp-dev libxml2-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pgsql intl zip gd soap exif opcache; \
    pecl install "redis-${PHPREDIS_VERSION}"; \
    docker-php-ext-enable redis; \
    rm -rf /tmp/pear; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $saved_apt_mark > /dev/null; \
    find /usr/local/lib/php/extensions -type f -name '*.so' -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    for ext in pgsql intl zip gd soap exif redis sodium "Zend OPcache"; do php -m | grep -qx "$ext"; done

# 2) Moodle release tarball, checksum-verified, plus its Composer dependencies (Moodle 5.1+
#    ships some libraries, e.g. the REST API's OAuth2 server, only via composer.lock).
#    Code is read-only for the web server user.
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN set -eux; \
    curl -fsSL -o /tmp/moodle.tgz \
        "https://download.moodle.org/download.php/direct/stable${MOODLE_BRANCH}/moodle-${MOODLE_VERSION}.tgz"; \
    echo "${MOODLE_SHA256}  /tmp/moodle.tgz" | sha256sum -c -; \
    tar -xzf /tmp/moodle.tgz -C /var/www; \
    rm /tmp/moodle.tgz; \
    test -f /var/www/moodle/public/version.php; \
    cd /var/www/moodle; \
    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer composer install \
        --no-dev --classmap-authoritative --no-interaction --no-progress; \
    rm -rf /tmp/composer; \
    test -f /var/www/moodle/vendor/autoload.php; \
    mkdir -p /var/moodledata /var/cache/moodle; \
    chown www-data:www-data /var/moodledata /var/cache/moodle

# 3) Tuning defaults (override any of them with Railway service variables).
ENV PHP_MEMORY_LIMIT=256M \
    PHP_UPLOAD_MAX_FILESIZE=128M \
    PHP_POST_MAX_SIZE=130M \
    PHP_MAX_EXECUTION_TIME=300 \
    PHP_MAX_INPUT_VARS=5000 \
    PHP_OPCACHE_MEMORY=256 \
    PHP_TIMEZONE=UTC \
    APACHE_MAX_REQUEST_WORKERS=25 \
    APACHE_MAX_CONNECTIONS_PER_CHILD=500 \
    MOODLE_DATAROOT=/var/moodledata

COPY config/zz-moodle.ini            /usr/local/etc/php/conf.d/zz-moodle.ini
COPY config/moodle-vhost.conf        /etc/apache2/sites-available/000-default.conf
COPY config/mpm_prefork.conf         /etc/apache2/mods-available/mpm_prefork.conf
COPY config/healthz.php              /usr/local/share/railway/health/healthz.php
COPY config/config.php               /var/www/moodle/config.php
COPY scripts/                        /usr/local/share/railway/scripts/
# Optional plugins/themes: files under overlay/ are copied over the Moodle tree
# (e.g. overlay/public/mod/<plugin>/). Code is immutable at runtime, so add plugins here.
COPY overlay/                        /var/www/moodle/

# 4) Exactly one Apache MPM (prefork, required by mod_php), validate everything at build time.
RUN set -eux; \
    rm -f /var/www/moodle/.gitkeep; \
    install -m 0755 /usr/local/share/railway/scripts/railway-entrypoint.sh /usr/local/bin/railway-entrypoint.sh; \
    install -m 0755 /usr/local/share/railway/scripts/moodle-cron-loop.sh /usr/local/bin/moodle-cron-loop.sh; \
    rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf; \
    a2enmod -q mpm_prefork expires headers rewrite; \
    test "$(ls /etc/apache2/mods-enabled/ | grep -c '^mpm_.*\.load$')" = "1"; \
    printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/railway.conf; \
    a2enconf -q railway; \
    chown -R root:root /var/www/moodle; \
    chmod -R go-w /var/www/moodle; \
    php -l /var/www/moodle/config.php; \
    for f in /usr/local/share/railway/scripts/*.php; do php -l "$f"; done; \
    sh -n /usr/local/bin/railway-entrypoint.sh; \
    sh -n /usr/local/bin/moodle-cron-loop.sh; \
    apache2ctl -t

WORKDIR /var/www/moodle
EXPOSE 80
ENTRYPOINT ["railway-entrypoint.sh"]
CMD ["apache2-foreground"]
