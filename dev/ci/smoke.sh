#!/usr/bin/env bash
# Install smoke against the running docker shop (`make install` first, then
# `make smoke`). Catches what the offline suite cannot: a wrong admin
# namespace, route, template or language path, a broken install() or
# configure.php, and any PHP error OpenCart logs while serving them.
set -euo pipefail

base="http://localhost:${PORT:-1238}"
app=two-opencart
db=two-opencart-db
jar=$(mktemp)
trap 'rm -f "$jar"' EXIT

fail() { echo "smoke: FAIL $*" >&2; exit 1; }
ok() { echo "smoke: ok   $*"; }
status() { curl -s -o /dev/null -w '%{http_code}' "$base/$1"; }
contains() { grep -qF -- "$3" <<<"$2" || fail "$1: no '$3' in: ${2:0:300}"; ok "$1"; }
configure() { docker exec -u www-data -e OPENCART_DIR=/var/www/html "$@" "$app" php /var/www/html/extension/two/dev/configure.php; }
setting() { docker exec "$db" mariadb -uroot -padmin opencart -N -e "SELECT value FROM oc_setting WHERE store_id = 0 AND code = 'payment_two' AND \`key\` = 'payment_two_$1'"; }

# Storefront, and what the shop serves from the extension directory.
[ "$(status '')" = 200 ] || fail "storefront did not return 200"
ok "storefront 200"
[ "$(status extension/two/install.json)" = 200 ] || fail "install.json is not served"
ok "install.json served"
for path in dev/configure.php tests/run.php Makefile .env.example .git/HEAD; do
  code=$(status "extension/two/${path}")
  [ "$code" = 403 ] || [ "$code" = 404 ] || fail "extension/two/${path} returned ${code}, expected 403"
done
ok "dev/, tests/ and repo tooling refused"

# Admin: log in, then the Two page, the payments list and a save.
login_token=$(curl -s -c "$jar" -b "$jar" "$base/admin/index.php?route=common/login" | grep -o 'login_token=[a-z0-9]*' | head -1)
user_token=$(curl -s -c "$jar" -b "$jar" -X POST \
  --data-urlencode "username=${ADMIN_USER:-admin}" --data-urlencode "password=${ADMIN_PASSWD:-examplepassword123}" \
  "$base/admin/index.php?route=common/login.login&${login_token}" | grep -o 'user_token=[a-z0-9]*' | head -1)
[ -n "$user_token" ] || fail "admin login"
ok "admin login"
admin() { local route="$1"; shift; curl -s -b "$jar" "$@" "$base/admin/index.php?route=${route}&${user_token}"; }

page=$(admin extension/two/payment/two)
contains "settings page" "$page" 'Edit Two settings'
contains "settings page version" "$page" "Version $(php -r 'echo json_decode(file_get_contents("install.json"), true)["version"];')"
contains "payments list" "$(admin extension/payment)" 'extension/two/payment/two'
# Drop the sort_order row first: a save must insert a key that has no row (upgraded installs), not only update.
docker exec "$db" mariadb -uroot -padmin opencart -e "DELETE FROM oc_setting WHERE store_id = 0 AND code = 'payment_two' AND \`key\` = 'payment_two_sort_order'"
contains "save" "$(admin extension/two/payment/two.save -X POST -d payment_two_status=1 -d payment_two_sort_order=2)" '"success"'
[ "$(setting sort_order)" = 2 ] || fail "save did not insert the missing sort_order row"
ok "save inserted a key that had no row"

# Extensions > Payments: uninstall, then install (runs install(), which loads the core in the admin).
contains "payments uninstall" "$(admin 'extension/payment.uninstall&extension=two&code=two')" '"success"'
contains "payments install" "$(admin 'extension/payment.install&extension=two&code=two')" '"success"'
[ "$(setting environment)" = sandbox ] || fail "install() did not seed the core defaults"
ok "install() seeded the core defaults"

# configure.php: puts the extension back, is idempotent, refuses a bad environment.
configure > /dev/null
[ "$(setting status)" = 1 ] || fail "configure.php did not enable the extension"
[ "$(configure | grep -c '^\[configure\] set')" = 0 ] || fail "a second configure.php run changed settings"
ok "configure.php enables and is idempotent"
if configure -e TWO_ENVIRONMENT=bogus > /dev/null 2>&1; then fail "configure.php accepted TWO_ENVIRONMENT=bogus"; fi
ok "configure.php refuses an unknown environment"

# The Installer's Uninstall deletes extension/two; the checkout mount must not allow it.
if docker exec "$app" touch /var/www/html/extension/two/.smoke-probe 2> /dev/null; then fail "extension/two is writable from the shop"; fi
ok "extension mount is read-only"

# Nothing logged while serving all of the above.
log=$(docker exec "$app" sh -c 'cat /var/www/html/system/storage/logs/error.log 2> /dev/null || true')
[ -z "$log" ] || fail "OpenCart error log is not empty: ${log}"
if docker logs "$app" 2>&1 | grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)'; then fail "PHP errors in the container log"; fi
ok "no PHP errors logged"
