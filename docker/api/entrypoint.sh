#!/bin/sh
set -eu

# Named volumes keep their original ownership across image rebuilds.
chown -R app:app /app/runtime
chown app:app /app/uploads

exec setpriv --reuid=app --regid=app --init-groups docker-php-entrypoint "$@"
