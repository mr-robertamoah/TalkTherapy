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

# Re-link public/storage on every boot (--force overwrites safely). config/filesystems.php's
# 'links' resolve via public_path()/storage_path(), which are absolute to wherever the app is
# currently running -- if storage:link was ever run outside this container (or the project
# directory moved), the resulting symlinks point at a host path that doesn't exist in here,
# silently breaking every uploaded file's public URL (uploads still succeed and save fine,
# they just 404 when displayed). Re-running this on every boot keeps it self-healing.
echo "Linking storage..."
php artisan storage:link --force

# Finally, run whatever command was passed to the container (defaults to the Dockerfile's
# CMD ["php-fpm"], but must be respected so services like `queue` can override it via
# docker-compose's `command:` to run supervisord/queue:work instead).
exec "$@"
