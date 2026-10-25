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
lifecycle() { docker exec "$db" mariadb -uroot -padmin opencart -N -e "SELECT CONCAT(\`trigger\`, '|', action, '|', status) FROM oc_event WHERE code = 'two_order_lifecycle'"; }
[ "$(lifecycle)" = 'catalog/model/checkout/order.addHistory/after|extension/two/event/two.history|1' ] || fail "install() did not register the order lifecycle event (got '$(lifecycle)')"
ok "install() registered the order lifecycle event"

# configure.php: puts the extension back, is idempotent, refuses a bad environment.
configure > /dev/null
[ "$(setting status)" = 1 ] || fail "configure.php did not enable the extension"
[ "$(configure | grep -c '^\[configure\] set')" = 0 ] || fail "a second configure.php run changed settings"
ok "configure.php enables and is idempotent"
docker exec "$db" mariadb -uroot -padmin opencart -e "DELETE FROM oc_cron WHERE code = 'two_record_refresh'"
configure > /dev/null
[ "$(cron)" = 'extension/two/cron/two|day|1' ] || fail "configure.php did not register the record refresh cron"
ok "configure.php registers the record refresh cron"
docker exec "$db" mariadb -uroot -padmin opencart -e "DELETE FROM oc_event WHERE code = 'two_order_lifecycle'"
configure > /dev/null
[ "$(lifecycle)" = 'catalog/model/checkout/order.addHistory/after|extension/two/event/two.history|1' ] || fail "configure.php did not register the order lifecycle event"
ok "configure.php registers the order lifecycle event"
if configure -e TWO_ENVIRONMENT=bogus > /dev/null 2>&1; then fail "configure.php accepted TWO_ENVIRONMENT=bogus"; fi
ok "configure.php refuses an unknown environment"

# The order table: install() and configure.php create it, InnoDB.
table() { docker exec "$db" mariadb -uroot -padmin opencart -N -e "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'opencart' AND TABLE_NAME = 'oc_two_order'"; }
[ "$(table)" = InnoDB ] || fail "the two_order table is missing or not InnoDB (got '$(table)')"
ok "two_order table (InnoDB)"
sql() { docker exec "$db" mariadb -uroot -padmin opencart -N -e "$1"; }
legacy() { sql "SHOW TABLES LIKE 'oc\_two\_order\_legacy\_%'" | wc -l; }
before=$(legacy)
sql "DROP TABLE oc_two_order; CREATE TABLE oc_two_order (two_order_id INT NOT NULL, order_id INT NOT NULL, PRIMARY KEY (two_order_id)) ENGINE=MyISAM"
configure > /dev/null
[ "$(sql "SHOW COLUMNS FROM oc_two_order LIKE 'token_hash'" | wc -l)" = 1 ] || fail "configure.php did not replace a legacy two_order table"
[ "$(legacy)" = $((before + 1)) ] || fail "the legacy two_order table was not kept aside"
ok "configure.php renames a legacy two_order table aside"
# A table from before rows_hash (the first rewrite release) keeps its rows and gains the column.
sql "INSERT INTO oc_two_order SET order_id = 9002, two_order_id = 'kept', date_added = NOW(), date_modified = NOW(); ALTER TABLE oc_two_order DROP COLUMN rows_hash"
before=$(legacy)
configure > /dev/null
[ "$(sql "SHOW COLUMNS FROM oc_two_order LIKE 'rows_hash'" | wc -l)" = 1 ] || fail "configure.php did not add rows_hash to the two_order table"
[ "$(sql "SELECT two_order_id FROM oc_two_order WHERE order_id = 9002")" = kept ] && [ "$(legacy)" = "$before" ] || fail "configure.php renamed a current two_order table aside instead of adding the column"
sql "DELETE FROM oc_two_order WHERE order_id = 9002"
ok "configure.php adds rows_hash in place, keeping the rows"

# An order's tax rates come from its own customer group, never the request's session: the
# probe runs OpenCart's tax engine as the plugin sets it up, with no session at all.
# A Norway zone with one rate per customer group, on the Taxable Goods class (9).
sql "REPLACE INTO oc_customer_group SET customer_group_id = 9001, approval = 0, sort_order = 9; REPLACE INTO oc_geo_zone SET geo_zone_id = 9001, name = 'Smoke NO', description = 'Smoke NO'; REPLACE INTO oc_zone_to_geo_zone SET zone_to_geo_zone_id = 9001, geo_zone_id = 9001, country_id = 160, zone_id = 0; REPLACE INTO oc_tax_rate SET tax_rate_id = 9001, geo_zone_id = 9001, name = 'Smoke 15', rate = 15, type = 'P'; REPLACE INTO oc_tax_rate SET tax_rate_id = 9002, geo_zone_id = 9001, name = 'Smoke 25', rate = 25, type = 'P'; DELETE FROM oc_tax_rate_to_customer_group WHERE tax_rate_id IN (9001, 9002); INSERT INTO oc_tax_rate_to_customer_group SET tax_rate_id = 9001, customer_group_id = 1; INSERT INTO oc_tax_rate_to_customer_group SET tax_rate_id = 9002, customer_group_id = 9001; REPLACE INTO oc_tax_rule SET tax_rule_id = 9001, tax_class_id = 9, tax_rate_id = 9001, based = 'shipping', priority = 1; REPLACE INTO oc_tax_rule SET tax_rule_id = 9002, tax_class_id = 9, tax_rate_id = 9002, based = 'shipping', priority = 1"
probe() { docker exec -u www-data -e OPENCART_DIR=/var/www/html "$app" php /var/www/html/extension/two/dev/ci/order-tax-probe.php "{\"customer_group_id\":$1,\"shipping_country_id\":160,\"shipping_zone_id\":0,\"payment_country_id\":160,\"payment_zone_id\":0,\"store_id\":0}" 9; }
[ "$(probe 9001)" = '{"rate":0.25,"fixed_tax":false}' ] || fail "an order from another customer group did not get its group's rate (got '$(probe 9001)')"
ok "an order's own customer group gives its rate, with no session"
[ "$(probe 1)" = '{"rate":0.15,"fixed_tax":false}' ] || fail "an order from the default group did not get its rate (got '$(probe 1)')"
ok "the default group gives its own rate"

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

# Order lifecycle: an admin status change runs the catalog event in the store
# instance. A Two order with no Two order linked moves to Shipped and to
# Canceled: each refusal is noted at the merchant's status, never undone,
# and an unmapped status (Pending) writes nothing. The Two tab renders.
sql "DELETE FROM oc_order WHERE order_id = 9001; DELETE FROM oc_order_history WHERE order_id = 9001; INSERT INTO oc_order SET order_id = 9001, store_id = 0, store_name = 'Smoke', store_url = '${base}/', customer_group_id = 1, firstname = 'Kari', lastname = 'Nordmann', email = 'buyer@example.com', telephone = '', comment = '', payment_method = '{\"name\":\"Two\",\"code\":\"two.two\"}', shipping_method = '', total = 100, order_status_id = 1, language_id = 1, currency_id = 1, currency_code = 'GBP', currency_value = 1, date_added = NOW(), date_modified = NOW()"
history() { contains "status change to $1" "$(curl -s -b "$jar" -X POST -d "order_id=9001&order_status_id=$1&comment=&notify=0&override=0" "$base/admin/index.php?route=sale/order.call&call=history_add&store_id=0&language=en-gb&${user_token}")" '"success"'; }
canceled=$(sql "SELECT order_status_id FROM oc_order_status WHERE name = 'Canceled' ORDER BY language_id LIMIT 1")
history "$shipped"
history 1
history "$canceled"
notes=$(sql "SELECT CONCAT(order_status_id, '|', comment) FROM oc_order_history WHERE order_id = 9001 ORDER BY order_history_id")
expected=$(printf '%s\n' "${shipped}|" "${shipped}|Two: Not fulfilled: no Two order is linked to this order." "1|" "${canceled}|" "${canceled}|Two: Not cancelled: no Two order is linked to this order.")
[ "$notes" = "$expected" ] || fail "the order lifecycle did not note its refusals at the merchant's status (got '${notes}')"
ok "an admin status change runs the order lifecycle and notes the outcome"
contains "the Two tab on the admin order page" "$(admin 'sale/order.info&order_id=9001')" 'No Two order is linked to this order.'
# Refunds: the table and the edit key column exist, and the refund action refuses an order with no Two order.
[ "$(sql "SHOW TABLES LIKE 'oc_two_refund'")" = "oc_two_refund" ] || fail "install did not create the two_refund table"
[ "$(sql "SHOW COLUMNS FROM oc_two_order LIKE 'edit_key'" | cut -f1)" = "edit_key" ] || fail "install did not add the edit_key column"
contains "the refund action refuses an order with no Two order" "$(curl -s -b "$jar" -X POST -d "two_refund_net=10.00&two_refund_tax=2.50&two_refund_rate=0.25" "$base/admin/index.php?route=extension/two/payment/two.refund&order_id=9001&${user_token}")" 'Not refunded: no Two order is linked to this order.'
# The two refusals are expected in the log; anything else still fails the check below.
docker exec "$app" sed -i '/Two error: Order 9001: Not \(fulfilled\|cancelled\|refunded\): no Two order is linked/d' /var/www/html/system/storage/logs/error.log

# The Installer's Uninstall deletes extension/two; the checkout mount must not allow it.
if docker exec "$app" touch /var/www/html/extension/two/.smoke-probe 2> /dev/null; then fail "extension/two is writable from the shop"; fi
ok "extension mount is read-only"

# Nothing logged while serving all of the above.
log=$(docker exec "$app" sh -c 'cat /var/www/html/system/storage/logs/error.log 2> /dev/null || true')
[ -z "$log" ] || fail "OpenCart error log is not empty: ${log}"
if docker logs "$app" 2>&1 | grep -E 'PHP (Fatal|Parse|Warning|Notice|Deprecated)'; then fail "PHP errors in the container log"; fi
ok "no PHP errors logged"
