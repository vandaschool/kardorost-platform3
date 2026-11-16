#!/bin/sh
set -eu
umask 0027
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
if [ "${KARDOROST_ROLE:-app}" = app ]; then
    php artisan view:cache --no-interaction
fi
exec "$@"
