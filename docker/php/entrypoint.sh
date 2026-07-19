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

# Check if composer deps need update
if [ ! -f /var/www/html/vendor/autoload.php ]; then
  echo "Installing Composer dependencies..."
  composer install --no-interaction --optimize-autoloader
fi

# Set permissions
chmod -R 755 /var/www/html/storage 2>/dev/null || true
chmod -R 755 /var/www/html/bootstrap/cache 2>/dev/null || true

# Generate key if not exists
if [ -z "$APP_KEY" ]; then
  php artisan key:generate
fi

# Run migrations and seed only if not already done
if [ ! -f /var/www/.seeded ]; then
  echo "Running migrations and seeding..."
  php artisan migrate --force
  php artisan db:seed --force
  touch /var/www/.seeded
else
  echo "App already seeded. Skipping..."
fi

# Finally, run php-fpm
exec php-fpm
