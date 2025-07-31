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

# Run migrations and seed only if not already done
if [ ! -f /var/www/.seeded ]; then
  echo "Running migrations and seeding..."
  php artisan migrate --force
  php artisan db:seed --force
  touch /var/www/.seeded
else
  echo "App already seeded. Skipping..."
fi

# Create log directory and set permissions
mkdir -p /var/log
chmod -R 777 /var/www/html/storage

# Start supervisor (which will start Reverb)
echo "Starting Laravel Reverb via Supervisor..."
exec supervisord -c /etc/supervisord.conf
