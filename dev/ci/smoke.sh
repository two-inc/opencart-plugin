#!/usr/bin/env bash
# Install smoke against the running docker shop (`make install` first, then
# `make smoke`). Catches what the offline suite cannot: a wrong admin
# namespace, route, template or language path, a broken install() or
# configure.php, an unguarded callback, and any PHP error OpenCart logs
# while serving them. Placing a whole order against a Two API stub is P9.
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
store() { docker exec "$db" mariadb -uroot -padmin opencart -e "UPDATE oc_setting SET value = '$2' WHERE store_id = 0 AND code = 'payment_two' AND \`key\` = 'payment_two_$1'"; }

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
for tab in 'Checkout fields' 'Payment terms' 'Order management' 'Diagnostics' 'coming in a later release'; do contains "settings tab: ${tab}" "$page" "$tab"; done
[ "$(status extension/two/admin/view/javascript/two_admin.js)" = 200 ] || fail "the admin script is not served"
ok "admin script served"
# The environment dropdown: production and sandbox always, staging only on an internal shop.
contains "environment: production" "$page" '<option value="production"'
contains "environment: sandbox" "$page" '<option value="sandbox"'
contains "environment: the stored one is selected" "$page" "<option value=\"$(setting environment)\" selected"
if [ "$(docker exec "$app" printenv TWO_INTERNAL_ENVIRONMENTS || true)" = 1 ]; then
  contains "environment: staging on an internal shop" "$page" '<option value="staging"'
elif grep -qF '<option value="staging"' <<<"$page"; then
  fail "staging is offered on a shop without TWO_INTERNAL_ENVIRONMENTS=1"
else
  ok "environment: no staging without the flag"
fi
# The rendered heading, from our language file: a broken language path renders the key instead.
contains "payments list heading" "$(admin extension/payment)" '<td>Two</td>'
# Drop the sort_order row first: a save must insert a key that has no row (upgraded installs), not only update.
docker exec "$db" mariadb -uroot -padmin opencart -e "DELETE FROM oc_setting WHERE store_id = 0 AND code = 'payment_two' AND \`key\` = 'payment_two_sort_order'"
contains "save" "$(admin extension/two/payment/two.save -X POST -d payment_two_status=1 -d payment_two_sort_order=2)" '"success"'
[ "$(setting sort_order)" = 2 ] || fail "save did not insert the missing sort_order row"
ok "save inserted a key that had no row"

# Selective save: a malformed key is refused and the stored key kept, while the other fields save.
# The key never reaches Two (it is refused before the wire), so this needs no network.
store api_key smoke-good-key-1234
saved=$(admin extension/two/payment/two.save -X POST -d payment_two_sort_order=5 -d payment_two_environment=sandbox --data-urlencode 'payment_two_api_key=bad key')
contains "save refuses a malformed key" "$saved" 'not a valid API key'
contains "save reports the other fields saved" "$saved" 'The other settings were saved.'
[ "$(setting api_key)" = smoke-good-key-1234 ] || fail "a refused key overwrote the stored key (got '$(setting api_key)')"
[ "$(setting sort_order)" = 5 ] || fail "a refused key stopped the other fields saving"
ok "refused key kept the stored key, other fields saved"
store api_key ''
# OpenCart escapes every posted value; the core stores the title as typed.
admin extension/two/payment/two.save -X POST --data-urlencode 'payment_two_title[1]=Pay & go <now>' > /dev/null
[ "$(setting title)" = '{"1":"Pay & go <now>"}' ] || fail "the title was not stored as typed (got '$(setting title)')"
ok "title stored as typed"
contains "a bad environment is named on its field" "$(admin extension/two/payment/two.save -X POST -d payment_two_environment=bogus)" '"environment":"Please select a valid environment."'

# Extensions > Payments: uninstall, then install (runs install(), which loads the core in the admin).
contains "payments uninstall" "$(admin 'extension/payment.uninstall&extension=two&code=two')" '"success"'
contains "payments install" "$(admin 'extension/payment.install&extension=two&code=two')" '"success"'
[ "$(setting environment)" = sandbox ] || fail "install() did not seed the core defaults"
ok "install() seeded the core defaults"
shipped=$(docker exec "$db" mariadb -uroot -padmin opencart -N -e "SELECT order_status_id FROM oc_order_status WHERE name = 'Shipped' ORDER BY language_id LIMIT 1")
[ "$(setting fulfil_status_ids)" = "[${shipped}]" ] || fail "install() did not map fulfilment to Shipped (got '$(setting fulfil_status_ids)')"
ok "install() mapped fulfilment to Shipped"
cron() { docker exec "$db" mariadb -uroot -padmin opencart -N -e "SELECT CONCAT(action, '|', cycle, '|', status) FROM oc_cron WHERE code = 'two_record_refresh'"; }
[ "$(cron)" = 'extension/two/cron/two|day|1' ] || fail "install() did not register the record refresh cron (got '$(cron)')"
ok "install() registered the record refresh cron"

# configure.php: puts the extension back, is idempotent, refuses a bad environment.
configure > /dev/null
[ "$(setting status)" = 1 ] || fail "configure.php did not enable the extension"
[ "$(configure | grep -c '^\[configure\] set')" = 0 ] || fail "a second configure.php run changed settings"
ok "configure.php enables and is idempotent"
docker exec "$db" mariadb -uroot -padmin opencart -e "DELETE FROM oc_cron WHERE code = 'two_record_refresh'"
configure > /dev/null
[ "$(cron)" = 'extension/two/cron/two|day|1' ] || fail "configure.php did not register the record refresh cron"
ok "configure.php registers the record refresh cron"
if configure -e TWO_ENVIRONMENT=bogus > /dev/null 2>&1; then fail "configure.php accepted TWO_ENVIRONMENT=bogus"; fi
ok "configure.php refuses an unknown environment"

# The order table: install() and configure.php create it, InnoDB.
table() { docker exec "$db" mariadb -uroot -padmin opencart -N -e "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'opencart' AND TABLE_NAME = 'oc_two_order'"; }
[ "$(table)" = InnoDB ] || fail "the two_order table is missing or not InnoDB (got '$(table)')"
ok "two_order table (InnoDB)"
sql() { docker exec "$db" mariadb -uroot -padmin opencart -N -e "$1"; }
sql "DROP TABLE oc_two_order; CREATE TABLE oc_two_order (two_order_id INT NOT NULL, order_id INT NOT NULL, PRIMARY KEY (two_order_id)) ENGINE=MyISAM"
configure > /dev/null
[ "$(sql "SHOW COLUMNS FROM oc_two_order LIKE 'token_hash'" | wc -l)" = 1 ] || fail "configure.php did not replace a legacy two_order table"
[ "$(sql "SHOW TABLES LIKE 'oc\_two\_order\_legacy\_%'" | wc -l)" = 1 ] || fail "the legacy two_order table was not kept aside"
ok "configure.php renames a legacy two_order table aside"

# Storefront: the tile template and language load, and the callbacks from Two are guarded.
[ "$(status 'index.php?route=extension/two/payment/two&language=en-gb')" = 200 ] || fail "the tile did not render"
ok "tile renders"
contains "placing an order with no order in the session is refused" "$(curl -s -X POST "$base/index.php?route=extension/two/payment/two.confirm&language=en-gb")" '"error"'
cb="index.php?route=extension/two/payment/two.confirmation&language=en-gb&order_id=1"
[ "$(status "${cb}&token=not-a-token")" = 302 ] || fail "a malformed callback token was not sent back to the cart"
ok "a malformed callback token is refused"
token=$(printf 'a%.0s' $(seq 48))
bounce=$(curl -s -D - "$base/${cb}&token=${token}")
contains "a callback without the session cookie bounces" "$bounce" 'two_bounce=1'
if grep -qi '^set-cookie: OCSESSID' <<<"$bounce"; then fail "the bounce replaced the session cookie"; fi
ok "the bounce leaves the session cookie alone"
grep -o 'url=[^"]*' <<<"$bounce" | grep -q "^url=${base}/" || fail "the bounce target is not the shop itself"
ok "the bounce stays on the shop"
location=$(curl -s -o /dev/null -w '%{redirect_url}' "$base/${cb}&token=${token}&two_bounce=1")
grep -q 'checkout/cart' <<<"$location" || fail "a callback for an unknown order did not go back to the cart (got '${location}')"
ok "a callback for an unknown order goes back to the cart"

# The Installer's Uninstall deletes extension/two; the checkout mount must not allow it.
if docker exec "$app" touch /var/www/html/extension/two/.smoke-probe 2> /dev/null; then fail "extension/two is writable from the shop"; fi
ok "extension mount is read-only"

# Nothing logged while serving all of the above.
log=$(docker exec "$app" sh -c 'cat /var/www/html/system/storage/logs/error.log 2> /dev/null || true')
[ -z "$log" ] || fail "OpenCart error log is not empty: ${log}"
if docker logs "$app" 2>&1 | grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)'; then fail "PHP errors in the container log"; fi
ok "no PHP errors logged"
