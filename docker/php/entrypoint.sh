#!/bin/bash

# SCRUM-379: readiness marker for the php service's healthcheck (docker-compose.yml). queue and reverb
# wait for it, so migrations, the seed and storage permissions are done before they start writing.
# Removed first, so a restart isn't reported ready before this run has finished.
READY_MARKER=/tmp/talktherapy-entrypoint-ready
rm -f "$READY_MARKER"

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

# Set permissions. `storage` is bind-mounted from the host, so a directory created there by a host
# process (or a root `docker compose exec`) keeps that ownership, and www-data (php-fpm's user, see
# docker/php/www.conf) can be left unable to write it -- confirmed via TT-4.11b/SCRUM-303, where
# identity-document uploads 500'd. SCRUM-376: the rules live in one script shared with queue and reverb.
# SCRUM-378: only the php-fpm container runs it. The queue (same image and entrypoint, other command)
# and reverb (depends_on php) share the same bind mount, so a second and third recursive pass at the
# same moment only repeated the work.
if [ "$1" = "php-fpm" ]; then
  bash /var/www/html/docker/php/storage-permissions.sh
fi

# Generate key if not exists
if [ -z "$APP_KEY" ]; then
  php artisan key:generate
fi

# Migrations on every boot (idempotent); the seed only once (SCRUM-373). The marker lives on the
# bind-mounted storage/ (it used to be /var/www/.seeded, inside each container, so every recreate
# re-seeded), and flock makes the php and queue containers -- which both run this script -- take turns.
# An existing database that already has users is never seeded again, marker or not.
# Remove storage/framework/.seeded (and empty the database) to seed again.
SEED_MARKER=/var/www/html/storage/framework/.seeded
(
  flock 9
  if ! php artisan migrate --force; then
    echo "Migrations failed: not seeding." >&2
    exit 0
  fi
  # Never seed production: the seed creates demo accounts with known passwords (security review).
  if [ "$APP_ENV" = "production" ]; then
    echo "APP_ENV=production: not seeding."
  elif [ ! -f "$SEED_MARKER" ]; then
    # 0 = the database already has users, 3 = empty, anything else = couldn't tell (don't seed).
    php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(Illuminate\Support\Facades\DB::table("users")->exists() ? 0 : 3);'
    PROBE=$?
    if [ "$PROBE" -eq 0 ]; then
      echo "Database already has users. Not seeding; writing the marker."
      touch "$SEED_MARKER"
    elif [ "$PROBE" -eq 3 ]; then
      echo "Seeding..."
      php artisan db:seed --force && touch "$SEED_MARKER"
    else
      echo "Could not check the database: not seeding this boot." >&2
    fi
  else
    echo "App already seeded. Skipping seed."
  fi
) 9>/var/www/html/storage/framework/.setup.lock

# Re-link public/storage on every boot (--force overwrites safely). config/filesystems.php's
# 'links' resolve via public_path()/storage_path(), which are absolute to wherever the app is
# currently running -- if storage:link was ever run outside this container (or the project
# directory moved), the resulting symlinks point at a host path that doesn't exist in here,
# silently breaking every uploaded file's public URL (uploads still succeed and save fine,
# they just 404 when displayed). Re-running this on every boot keeps it self-healing.
echo "Linking storage..."
php artisan storage:link --force

touch "$READY_MARKER"

# Finally, run whatever command was passed to the container (defaults to the Dockerfile's
# CMD ["php-fpm"], but must be respected so services like `queue` can override it via
# docker-compose's `command:` to run supervisord/queue:work instead).
exec "$@"
