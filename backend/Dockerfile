# =============================================================================
# FleetView backend — production PHP-FPM image (Laravel 12, PHP 8.2)
#
# Slim Alpine runtime. Deliberately NOT shipping:
#   - ffmpeg + its codec/LLVM stack  (no server-side video transcoding is used;
#     repair videos are stored as-is, php-ffmpeg is not a dependency)
#   - a C build toolchain (gcc/g++/make/autoconf): install-php-extensions pulls
#     its build deps as a throwaway virtual package and removes them, leaving
#     only the runtime shared libraries the extensions actually need.
#   - the full google/apiclient-services catalog: trimmed to Sheets only (the
#     one Google API this app calls) via Google\Task\Composer::cleanup.
#
# Built once, reused by the app, scheduler and (optional) queue containers.
# =============================================================================

FROM php:8.2-fpm-alpine AS runtime

# PHP extensions. install-php-extensions handles the correct build flags and,
# on Alpine, installs compilers as a virtual .build-deps package that it removes
# afterwards — so the final image carries no gcc/g++/make/autoconf.
# NOTE: mbstring + opcache ship (and are enabled) in php:8.2-fpm-alpine already,
# so they are intentionally NOT listed here — re-listing them only emits a
# "Module already installed" warning. OPcache is tuned via docker/opcache.ini.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        pdo_mysql \
        bcmath \
        gd \
        exif \
        pcntl \
        zip \
        intl

WORKDIR /var/www/html

# Production PHP config: OPcache tuning + upload limits (large repair videos)
# + php-fpm pool that keeps env_file variables visible to the app.
COPY docker/opcache.ini  /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/uploads.ini  /usr/local/etc/php/conf.d/zz-uploads.ini
COPY docker/www.conf     /usr/local/etc/php-fpm.d/zz-www.conf

# Composer binary from the official image (no package-manager install).
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Application source (respects .dockerignore — no .env, vendor, node_modules).
COPY . /var/www/html

# Install prod deps, trim unused Google services, and dump the optimized
# autoloader — ALL in one layer. Doing it here (rather than a separate vendor
# stage + COPY) means the files removed by the Google cleanup (pre-autoload-dump)
# and the composer cache never linger in an earlier, un-shrinkable layer.
# Platform reqs are satisfied natively now (ext-exif etc. installed above), so
# no --ignore-platform-reqs is needed — composer validates for real.
RUN composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
    && composer clear-cache \
    && rm -rf /root/.composer /tmp/*

# Laravel needs write access to storage/ and bootstrap/cache/ as the runtime
# user. php:fpm runs as www-data. (No brace expansion — Alpine's ash sh does
# not support it, so the dirs are listed explicitly.)
RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Entrypoint: waits for DB, runs migrate/cache (app role only), then execs the
# container command (php-fpm / scheduler / queue).
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh

USER www-data

EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
