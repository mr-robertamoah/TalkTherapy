#!/bin/bash
# SCRUM-376: storage permissions for every container that runs PHP (php-fpm, queue, reverb), in one
# place. storage/ and bootstrap/cache are bind-mounted from the host, so:
#   - owner www-data: php-fpm, the queue worker, the scheduler and Reverb all run as it;
#   - group = the host user's group (read from the project directory, which the host user owns),
#     group-writable at boot, with setgid on directories so new files stay in that group. The host
#     user can create and delete files there (git, host-side artisan); a file www-data creates later
#     is group-read-only (umask 022) until the next boot re-applies this;
#   - never world-writable (the old `chmod -R 777` made private uploads writable by anyone), and
#     neither private uploads (identity, licence, report-evidence documents) nor logs (user ids,
#     emails, exception text) are world-readable.
# `a-x` before `X` clears the stray +x the old `chmod -R 755` left on files; directories keep theirs.
APP=/var/www/html
HOST_GID=$(stat -c %g "$APP" 2>/dev/null || echo 33)

for dir in "$APP/storage" "$APP/bootstrap/cache"; do
  [ -d "$dir" ] || continue
  chown -R "www-data:$HOST_GID" "$dir" 2>/dev/null || true
  chmod -R a-x,u+rwX,g+rwX,o+rX,o-w "$dir" 2>/dev/null || true
  find "$dir" -type d -exec chmod g+s {} + 2>/dev/null || true
done

for dir in "$APP/storage/app/private" "$APP/storage/logs"; do
  [ -d "$dir" ] && chmod -R o-rwx "$dir" 2>/dev/null || true
done
