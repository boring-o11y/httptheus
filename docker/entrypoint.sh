#!/bin/sh
set -e

# vendor/ lives on the bind mount, so it survives between runs. composer.lock is
# gitignored (this is a library), so dependencies resolve fresh on first install.
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --no-progress
fi

exec "$@"
