ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

RUN apt-get update -qq \
    && apt-get install -y -qq --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install -j"$(nproc)" zip \
    && pecl install apcu redis \
    && docker-php-ext-enable apcu redis \
    && rm -rf /var/lib/apt/lists/*

# apc.enable_cli: APCu is off for CLI by default, and both the storage tests and
# the demo traffic generator are CLI processes — without this the tests that
# cover the APCu adapter skip themselves and never catch a broken adapter.
#
# memory_limit: phpstan's parallel workers blow past the 128M default; setup-php
# runs the CI job with no limit, so match that rather than tuning phpstan.neon.
RUN printf 'apc.enable_cli = 1\nmemory_limit = -1\n' > /usr/local/etc/php/conf.d/zz-httptheus.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
CMD ["vendor/bin/phpunit"]
