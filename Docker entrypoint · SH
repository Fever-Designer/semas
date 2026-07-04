#!/bin/sh
set -e

# Render injects $PORT (defaults to 10000). Apache's default config listens on 80.
PORT="${PORT:-10000}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/*.conf

exec "$@"