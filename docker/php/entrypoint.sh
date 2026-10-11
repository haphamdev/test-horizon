#!/usr/bin/env sh
set -e

# The repo is bind-mounted, so vendor/ usually already exists (installed on the
# host). On a fresh clone it may be missing — install it once inside the container
# so the image works without a prior host `composer install`.
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ missing — running composer install..."
    composer install --no-interaction --prefer-dist --no-progress
fi

exec "$@"
