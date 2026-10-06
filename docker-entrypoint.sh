#!/bin/sh
set -eu

PORT="${PORT:-8080}"

# Keep Apache's Listen directive and the default virtual host aligned with the
# platform-provided port. HostForge can inject PORT at runtime.
sed -i -E "s/^Listen [0-9]+$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i -E "s#<VirtualHost \*:[0-9]+>#<VirtualHost *:${PORT}>#" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
