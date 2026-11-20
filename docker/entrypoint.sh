#!/bin/sh
# Creates / upgrades the database on every start (safe to repeat), then starts Apache.
set -e
if [ "${RUN_INSTALL:-1}" = "1" ]; then
  php /var/www/html/bin/install.php --wait || { echo "Database setup failed - not starting. See the messages above."; exit 1; }
fi
exec docker-php-entrypoint "$@"
