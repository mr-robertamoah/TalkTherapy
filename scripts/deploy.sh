#!/usr/bin/env bash
#
# SCRUM-342: deploys TalkTherapy on the EC2 instance. Run as root by AWS SSM -- see
# .github/workflows/main.yml, which extracts this file from the commit being deployed and runs it
# with DEPLOY_SHA set to that commit.
#
# Who runs what:
#   - root: only filesystem ownership (mkdir / chown -R / chmod -R, none of which follow symlinks)
#     and removing stale storage symlinks. Root never runs PHP: storage/ and bootstrap/cache are
#     writable by the web user, so root running artisan would hand that user root.
#   - the deploy user: git, npm, composer.
#   - the web server user: every artisan command, including the migrations -- it already owns the
#     uploads the private-storage data migrations move, so it creates the private copies as itself.
#
# Override APP_DIR / DEPLOY_USER / WEB_USER / WEB_GROUP from the environment if the box differs.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/TalkTherapy}"
DEPLOY_USER="${DEPLOY_USER:-ubuntu}"
DEPLOY_SHA="${DEPLOY_SHA:-}"

die() {
    echo "deploy: $*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || die "must run as root (it sets ownership of private storage)."
[[ "$DEPLOY_SHA" =~ ^[0-9a-f]{40}$ ]] || die "DEPLOY_SHA must be the full commit sha to deploy."

cd "$APP_DIR"

# The web server user is whoever php-fpm's workers run as (never its root master process). Refuse
# to guess further: getting this wrong makes every private document unreadable.
WEB_USER_SOURCE="set explicitly"
if [ -z "${WEB_USER:-}" ]; then
    WEB_USER="$(ps -eo user=,comm= | awk '$2 ~ /^php-fpm/ && $1 != "root" { print $1; exit }')"
    WEB_USER_SOURCE="detected from php-fpm"
    [ -n "$WEB_USER" ] || die "could not find php-fpm worker processes -- set WEB_USER."
    [ "$WEB_USER" != "$DEPLOY_USER" ] || die "php-fpm runs as ${DEPLOY_USER} -- set WEB_USER explicitly to confirm."
fi
[ "$WEB_USER" != "root" ] || die "refusing to use root as the web server user."
WEB_GROUP="${WEB_GROUP:-$(id -gn "$WEB_USER")}"

as_deploy() {
    sudo -u "$DEPLOY_USER" -H -- "$@"
}

artisan() {
    sudo -u "$WEB_USER" -- php "$APP_DIR/artisan" "$@"
}

PREVIOUS_SHA="$(as_deploy git -C "$APP_DIR" rev-parse HEAD)"
echo "Deploying ${DEPLOY_SHA} (previously ${PREVIOUS_SHA}); deploy user ${DEPLOY_USER}, web user ${WEB_USER}:${WEB_GROUP} (${WEB_USER_SOURCE})."

# Maintenance mode for the whole deploy, so no request runs new code before its migrations have run
# (SCRUM-341 relies on this). Fatal if it can't be switched on. If the deploy fails after the code
# changed, the site stays down rather than serving half-deployed code -- the job fails, so someone
# looks. Before that point, it comes back up on the old code.
artisan down --retry=60
CODE_CHANGED=0
on_exit() {
    local rc=$?
    if [ "$rc" -eq 0 ] || [ "$CODE_CHANGED" -eq 0 ]; then
        artisan up || true
    else
        echo "deploy: failed after the code changed -- the site stays in maintenance mode. Fix the cause and re-run (README > Deployment)." >&2
    fi
}
trap on_exit EXIT

as_deploy git -C "$APP_DIR" stash
as_deploy git -C "$APP_DIR" fetch --quiet origin
as_deploy git -C "$APP_DIR" merge-base --is-ancestor "$DEPLOY_SHA" origin/main \
    || die "${DEPLOY_SHA} is not on origin/main."

CODE_CHANGED=1
as_deploy git -C "$APP_DIR" checkout --quiet --detach "$DEPLOY_SHA"
as_deploy npm --prefix "$APP_DIR" install --no-audit --no-fund --loglevel=error
as_deploy npm --prefix "$APP_DIR" run build --silent
as_deploy composer --working-dir="$APP_DIR" install --no-interaction --quiet

# Private storage (identity, license, chat-attachment and report-evidence disks -- config/filesystems.php): owned by
# the web server user, group-shared, never world-readable.
mkdir -p storage/app/private/identity-documents storage/app/private/license-documents storage/app/private/message-attachments storage/app/private/report-evidence
chown -R "${WEB_USER}:${WEB_GROUP}" storage/app/private
chmod -R ug+rwX,o-rwx storage/app/private
# setgid so later files inherit the group -- as the tree's owner, never as root through a path the
# web user could swap for a symlink.
sudo -u "$WEB_USER" -- find "$APP_DIR/storage/app/private" -type d -exec chmod g+s {} +

artisan migrate --force

# The data migrations try to remove these public symlinks themselves, but public/storage belongs to
# the deploy user. rm on a symlink removes the link, never its target.
for link in public/storage/licenses public/storage/messages; do
    if [ -L "$link" ]; then
        rm -f "$link"
    fi
done

artisan config:clear
artisan cache:clear
# Best-effort: the code is deployed and migrated by now, so a box without (say) Reverb running must
# not leave the site in maintenance mode.
artisan queue:restart || echo "deploy: queue:restart failed -- restart the queue workers by hand." >&2
artisan reverb:restart || echo "deploy: reverb:restart failed -- restart Reverb by hand if it runs here." >&2

# How the workers are supervised on this box isn't in the repo: queue:restart and reverb:restart
# only ask them to exit, and they come back only under a process manager. Show what's running.
{ supervisorctl status 2>/dev/null || true; systemctl list-units --type=service --no-pager 2>/dev/null | grep -Ei 'queue|reverb|horizon' || true; } | sed 's/^/workers: /'

for leftover in storage/app/public/licenses storage/app/public/messages; do
    if [ -d "$leftover" ] && [ -n "$(ls -A "$leftover")" ]; then
        echo "deploy: WARNING: $leftover still has files after the private-storage migrations -- check laravel.log." >&2
    fi
done

echo "Deploy of ${DEPLOY_SHA} finished."
