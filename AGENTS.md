# Agent guide: Two for OpenCart

The Two payment extension for OpenCart 4.1 (floor 4.1.0.0, PHP 8.1+). It is a rewrite in progress: the structure is in place and features land phase by phase. Behaviour and copy follow the sibling plugins (PrestaShop is the reference for behaviour) under the Plugin Build Guide's Parity rule.

## Layout

The repo root is the extension directory: a shop serves it as `extension/two/`.

| Path | What lives there |
|---|---|
| `install.json` | OpenCart 4 extension manifest. Holds the one version string |
| `system/library/opencart/` | OpenCart 4 implementations of the core's ports (`DbSettingsStore`, which upserts; `CurlTransport`; `LogLogger`; `DbOrderRecords`, the `two_order` table; `EventSubscribers`, the order postprocessing event) and `Services`, the one place the core graph is built |
| `system/library/core/` | The platform-neutral core: `Client`, `Environment`, `RecordProvider`, `SettingsProvider`, `SettingsSave` (the selective admin save), `Availability`, `OrderBuilder`, `Postprocessing`, `ErrorMapper`, `CustomHeaders`, `Brand`, `ApiKeyVerdict` (the key heartbeat), `OrderFlow` (create, confirm and cancel) and `OrderRefused`, plus the ports they need (`SettingsStore`, `Transport`, `Logger`, `OrderRecords`, `PostprocessingSubscribers`) and `SettingsOverlay` (read-only typed credentials for the live key check) |
| `admin/`, `catalog/` | Thin OpenCart 4 adapters: controllers, models, language files, templates, and `admin/view/javascript/two_admin.js` (the live API key check). `catalog/controller/cron/two.php` is the daily merchant-record refresh, registered in OpenCart's cron table by install() and `configure.php` |
| `dev/` | `configure.php` (installs and enables the extension), the docker image, CI scripts (`ci/smoke.sh`), packaging |
| `tests/` | Offline PHP suite: `tests/*Spec.php`, run by `tests/run.php` |

## The core boundary

Core classes hold the rules and never call OpenCart: no registry (`$this->load`, `$this->db` and so on), no OpenCart classes or config constants, no database, network or file I/O, no `getenv` or request globals. Adapters read the shop and pass plain values in; anything the core needs from outside goes through a port interface the adapter implements. `tests/CoreBoundarySpec.php` enforces this, so a new core file must also be added to its type list.

OpenCart 4 autoloads `Opencart\System\Library\Extension\Two\Core\FooBar` from `system/library/core/foo_bar.php` (lower case, CamelCase split by underscores). `dev/autoload.php` applies the same rule offline.

Stubs throw `NotImplemented::inPhase(...)` naming the phase that implements them. Never replace one with a guessed return value.

## Commands

| Command | What it does |
|---|---|
| `make test` | Offline suite |
| `make lint` | `php -l` over every tracked PHP file |
| `make phpstan` | PHPStan level 6 against the OpenCart 4.1.0.0 sources (pinned, checksum-verified), then the core alone with no OpenCart symbols, then `tests/` (apart, since its DB double shares OpenCart's class name) |
| `make install` | Fresh docker shop (MariaDB plus OpenCart 4.1.0.4 from the upstream zip, installed with `cli_install.php`), extension installed and enabled |
| `make configure` | Re-run `dev/configure.php` in the running shop |
| `make smoke` | Install smoke against the running shop: admin page, save, install, `configure.php`, empty error log |
| `make archive` | `dist/two.ocmod.zip` from HEAD |
| `make patch` / `minor` / `major` | bumpver, commits, no tag or push |

Settings come from `.env` only (see `.env.example`). `TWO_INTERNAL_ENVIRONMENTS` passes through to the shop.

CI (`.github/workflows/tests.yml`) runs lint and the suite on PHP 8.1 and 8.4, PHPStan, the install smoke and the package build. All are gates. Jest is deferred to P6, when the checkout capture lands; until then the one script, the admin's live key check, is covered by the save-time rules it mirrors (`SettingsSaveSpec`) and the smoke.

The docker shop mounts the repo read-only, because OpenCart's Installer > Uninstall deletes everything under `extension/two`.

## Staging shops

The staging shops deploy this repo to `extension/two` on every new commit and then run `php <checkout>/dev/configure.php`. So `install.json` must stay at the repo root, and `configure.php` must stay idempotent and safe to run on every commit. The shops also serve the whole repo, so every CLI entry point refuses web requests (`PHP_SAPI` guard) and the root `.htaccess` refuses everything except the runtime paths. The package ships only `install.json`, `admin/`, `catalog/` and `system/`.

## Admin settings

The settings page has PrestaShop's five tabs. P2 fills only what the core flow needs: status, environment, API key and sort order (General), the per-language title (Checkout fields) and the fulfilment, cancel and refund status maps (Order management; refunds are sent from P5). Every other tab, and the rest of each tab, says the settings are coming in a later release.

- The save is selective (`SettingsSave`): a refused field keeps its stored value and is named, and the rest are written. The API key and environment are one pair, written together or not at all. A rejected key is never stored, and a key Two could not judge never replaces one that last verified.
- The API key is never sent back to the browser. An empty key field means "keep the stored key".
- Reading the merchant record refreshes it when due (`RecordProvider::refreshIfDue`), so a shop with no crontab still gets one.

Where OpenCart deliberately differs from PrestaShop, the row goes in `docs/divergences.md` until it is filed in the Build Guide's register.

## Checkout and order flow

The buyer picks Two, enters the company in the tile (name, organisation number, country; company search is P6) and confirms. `catalog/controller/payment/two.php` maps the stored order through the model into `OrderFlow::create`, which builds the payload (`OrderBuilder`), runs the order postprocessing event (`Postprocessing`), creates the Two order and answers with Two's payment URL. The OpenCart order stays unplaced (status 0) until Two confirms it. Two then sends the buyer to `two.confirmation` (the order moves to the shop's default order status, then the success page) or `two.cancel` (the order stays unplaced with a history note, and the buyer is back at the cart with a notice).

- Tax: the plugin reports the shop's stored nets, taxes and rates and never computes or adjusts a tax. Where OpenCart keeps no per-line tax (shipping and other charge totals) the shop's own tax engine gives it, and the default handler refuses the order unless the lines add up to what the shop charged. A discount's tax is what the stored tax total leaves; at mixed rates, or with a fixed-amount tax rate, the order is refused rather than guessed.
- Every order request goes through `Postprocessing::apply` in `OrderFlow`; `tests/CallSiteAllowlistSpec.php` fails a request that bypasses it. The README carries the merchant contract.
- The callbacks are guarded by a per-order token (its hash in `two_order`). OpenCart's session cookie is SameSite=Strict by default, so a callback arriving without it bounces once through a same-site page rebuilt from the route and the validated id and token.
- Refusals: the buyer gets `ErrorMapper`'s copy (section 6.4), the merchant's order history and the log get the reason.

Later-phase items, not built yet:
- The Diagnostics tab: SSL bypass, debug logging, refresh merchant profile, scheduled refresh URL.
- The error log view (last 100 lines).
- The custom request-header table, with halt-and-show on save (`CustomHeaders::validate` is built and tested) and a `send_from_browser` flag per row.
- The availability chain at checkout (P6): the tile is offered whenever the extension is enabled with an API key.
- A plugin status for confirmed orders (the shop's default order status is used) and the order lifecycle off the status maps (P5).
- The rest of the PrestaShop field set: vendor name, subtitle, the checkout field toggles, payment terms, and the remaining status maps.

## Conventions

- Branches go into `staging`; `main` is the release branch.
- Commit subjects: `<TICKET>/<type>: <description>`.
- Tests are parameterised tables with a description per case, used in the assertion message.
- The PR template carries the Parity checklist. Fill it in.
- No em-dashes in code, comments, docs, commits or PRs.
- This repo is public. Never name a partner, merchant or any brand other than Two, and do not reproduce other repos' internals.
