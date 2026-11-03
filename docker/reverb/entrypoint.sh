#!/bin/bash

# Wait for MySQL to be ready
echo "Waiting for the database..."
until nc -z db 3306; do
  echo "Database not ready yet, waiting..."
  sleep 5
done

# Additional wait to ensure MySQL is fully initialized
sleep 10
echo "Database should be ready now"

# SCRUM-373: no migrating or seeding here -- the php container does it (docker/php/entrypoint.sh).
# Three containers doing it at once logged duplicate-key errors on every recreate.

# Create log directory and set permissions
mkdir -p /var/log
chmod -R 777 /var/www/html/storage

# Start supervisor (which will start Reverb)
echo "Starting Laravel Reverb via Supervisor..."
exec supervisord -c /etc/supervisord.conf
