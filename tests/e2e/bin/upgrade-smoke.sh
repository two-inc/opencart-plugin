#!/usr/bin/env bash
# upgrade-smoke.sh [RELEASE_TAG]
#
# Installs the last release of the OpenCart 4 extension in the E2E shop,
# places an order on it, then swaps this checkout in over it and re-runs
# dev/configure.php, as the staging shops do on every deploy. Passes when every
# setting the release stored is kept and the checkout spec still places an
# order. Without a tag it upgrades from the newest 2.x release tag; with none
# (before the first release of the rewrite) it skips, saying so. The 1.x tags
# are the OpenCart 3 extension, which has no upgrade path to OpenCart 4.
set -euo pipefail

cd "$(dirname "$0")/../../.."
tag="${1:-}"
if [ -z "$tag" ]; then
  tags=$(git ls-remote --tags --refs origin) || { echo "::error::upgrade smoke: could not list the release tags"; exit 1; }
  tag=$(printf '%s\n' "$tags" | sed -n 's#.*refs/tags/\(v\{0,1\}2\.[0-9]\{1,\}\.[0-9]\{1,\}\)$#\1#p' \
    | sort -V | tail -1)
fi
if [ -z "$tag" ]; then
  echo "::notice::upgrade smoke: skipped. There is no earlier release of the OpenCart 4 extension (a 2.x tag) to upgrade from yet."
  exit 0
fi

# Inside the checkout (gitignored): the docker daemon may not see the host's /tmp.
work="$PWD/tests/e2e/.upgrade"
rm -rf "$work" && mkdir -p "$work"
git fetch -q --depth=1 origin "refs/tags/${tag}:refs/tags/${tag}" 2>/dev/null || true
git archive "$tag" | tar -x -C "$work"
export E2E_EXTENSION_DIR="$work"
echo "upgrade smoke: installing ${tag}"
make e2e-up

settings() {
  docker compose -f tests/e2e/compose.yml exec -T db mariadb -uroot -padmin opencart -N -B -e \
    "SELECT \`key\`, value FROM oc_setting WHERE code IN ('payment_two', 'total_two_fee') ORDER BY \`key\`"
}
(cd tests/e2e && npm ci --no-audit --no-fund >/dev/null && npx playwright install ${CI:+--with-deps} chromium >/dev/null \
  && npx playwright test tests/checkout.spec.ts)
settings > "$work.before"

echo "upgrade smoke: swapping in $(git rev-parse --short HEAD)"
find "$work" -mindepth 1 -delete
git ls-files -z | tar --null -cf - -T - | tar -xf - -C "$work"
docker compose -f tests/e2e/compose.yml exec -T -u www-data -e OPENCART_DIR=/var/www/html -e TWO_ENVIRONMENT= opencart \
  php /var/www/html/extension/two/dev/configure.php
settings > "$work.after"

# Every setting the release stored keeps its value; HEAD may add settings.
lost=$(comm -23 <(sort "$work.before") <(sort "$work.after"))
rm -f "$work.before" "$work.after"
if [ -n "$lost" ]; then
  echo "::error::upgrade smoke: settings changed or lost by the upgrade from ${tag}:"
  echo "$lost"
  exit 1
fi
(cd tests/e2e && npx playwright test tests/checkout.spec.ts)
echo "upgrade smoke: ${tag} to HEAD kept every setting and places an order"
