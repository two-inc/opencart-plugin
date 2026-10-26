# Agent guide: Two for OpenCart

The Two payment extension for OpenCart 4.1 (floor 4.1.0.0, PHP 8.1+). It is a rewrite in progress: the structure is in place and features land phase by phase. Behaviour and copy follow the sibling plugins (PrestaShop is the reference for behaviour) under the Plugin Build Guide's Parity rule.

## Layout

The repo root is the extension directory: a shop serves it as `extension/two/`.

| Path | What lives there |
|---|---|
| `install.json` | OpenCart 4 extension manifest. Holds the one version string |
| `system/library/opencart/` | OpenCart 4 implementations of the core's ports (`DbSettingsStore`, which upserts; `CurlTransport`; `LogLogger`; `DbOrderRecords`, the `two_order` table; `DbRefundRecords`, the `two_refund` table; `EventSubscribers`, the order postprocessing event) and `Services`, the one place the core graph is built |
| `system/library/core/` | The platform-neutral core: `Client`, `Environment`, `RecordProvider`, `SettingsProvider`, `SettingsSave` (the selective admin save), `Availability`, `OrderBuilder`, `Postprocessing`, `ErrorMapper`, `CustomHeaders`, `Brand`, `ApiKeyVerdict` (the key heartbeat), `OrderFlow` (create, confirm and cancel), `IdempotencyKey` (the key every write carries), `OrderLifecycle` (whole-order fulfil and cancel off the status maps, and the invoice), `OrderRefunds` (refunds and the credit note), `OrderEdit` (order-edit sync) and `OrderRefused`, plus the ports they need (`SettingsStore`, `Transport`, `Logger`, `OrderRecords`, `RefundRecords`, `PostprocessingSubscribers`) and `SettingsOverlay` (read-only typed credentials for the live key check) |
| `admin/`, `catalog/` | Thin OpenCart 4 adapters: controllers, models, language files, templates, and `admin/view/javascript/two_admin.js` (the live API key check). `catalog/controller/cron/two.php` is the daily merchant-record refresh, registered in OpenCart's cron table by install() and `configure.php`; `catalog/controller/event/two.php` is the order lifecycle, registered in OpenCart's event table the same way |
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

CI (`.github/workflows/tests.yml`) runs lint and the suite on PHP 8.1 and 8.4, PHPStan, the install smoke and the package build. All are gates. Jest is deferred to P6, when the checkout capture lands; until then the admin's live key check is covered by the save-time rules it mirrors (`SettingsSaveSpec`) and the smoke, and the Two tab's refund script, which only posts and reloads, by the refund specs and the smoke.

The docker shop mounts the repo read-only, because OpenCart's Installer > Uninstall deletes everything under `extension/two`.

## Staging shops

The staging shops deploy this repo to `extension/two` on every new commit and then run `php <checkout>/dev/configure.php`. So `install.json` must stay at the repo root, and `configure.php` must stay idempotent and safe to run on every commit. The shops also serve the whole repo, so every CLI entry point refuses web requests (`PHP_SAPI` guard) and the root `.htaccess` refuses everything except the runtime paths. The package ships only `install.json`, `admin/`, `catalog/` and `system/`.

## Admin settings

The settings page has PrestaShop's five tabs. P2 fills only what the core flow needs: status, environment, API key and sort order (General), the per-language title (Checkout fields) and the fulfilment, cancel and refund status maps (Order management). Every other tab, and the rest of each tab, says the settings are coming in a later release.

- The save is selective (`SettingsSave`): a refused field keeps its stored value and is named, and the rest are written. The API key and environment are one pair, written together or not at all. A rejected key is never stored, and a key Two could not judge never replaces one that last verified.
- The API key is never sent back to the browser. An empty key field means "keep the stored key".
- Reading the merchant record refreshes it when due (`RecordProvider::refreshIfDue`), so a shop with no crontab still gets one.

Where OpenCart deliberately differs from PrestaShop, the row goes in `docs/divergences.md` until it is filed in the Build Guide's register.

## Checkout and order flow

The buyer picks Two, enters the company in the tile (name, organisation number, country; company search is P6) and confirms. `catalog/controller/payment/two.php` maps the stored order through the model into `OrderFlow::create`, which builds the payload (`OrderBuilder`), runs the order postprocessing event (`Postprocessing`), creates the Two order and answers with Two's payment URL. The OpenCart order stays unplaced (status 0) until Two confirms it. Two then sends the buyer to `two.confirmation` (the order moves to the shop's default order status, then the success page) or `two.cancel` (the order stays unplaced with a history note, and the buyer is back at the cart with a notice).

- Tax: the plugin reports the shop's stored nets, taxes and rates and never computes or adjusts a tax. Where OpenCart keeps no per-line tax (shipping and other charge totals) the shop's own tax engine gives it, and the default handler refuses the order unless the lines add up to what the shop charged. A discount's tax is what the stored tax total leaves; at mixed rates, or with a fixed-amount tax rate, the order is refused rather than guessed.
- Every write request to Two (POST, PUT, PATCH, DELETE) carries an `Idempotency-Key` built by `IdempotencyKey`, whether or not the endpoint reads it today, and `Client` refuses one without. The key names one operation from durable ids: `create_<shop order id>_<start of the attempt's token hash>` (one per checkout attempt, so a new attempt on the same cart never shares one), `confirm_`, `fulfil_` and `cancel_` plus the Two order id, `edit_<two order id>_<hash>`, `refund_<two order id>_<refund row id>` and `refund_remainder_<two order id>_<hash>` (see Order lifecycle). It is logged with the request.
- Every order request goes through `Postprocessing::apply` in `OrderFlow`; `tests/CallSiteAllowlistSpec.php` fails a request that bypasses it. The README carries the merchant contract.
- Confirm checks the order against the Two order: create stores the sent payload's hash, and confirm rebuilds it from the stored order (hook included) and refuses, cancelling the Two order, on any difference. OpenCart rewrites an unplaced order on every checkout render, so the cart can change after create.
- Rates come from OpenCart's own tax engine set up for the stored order (`OrderTax`: its customer group and addresses and its store's settings over the default store's), never from the request's session, so a callback without the cookie and the admin rebuild alike. The smoke checks a non-default group's rate through the real engine (`dev/ci/order-tax-probe.php`).
- Callbacks for one order are serialised with a MySQL named lock, so the status read before `addHistory` cannot go stale. A guarded UPDATE would not do: `addHistory` keys the order mail and stock off the old status. The lock is released before every redirect (`Response::redirect` exits, so a finally would not run). A hit that waits out the lock (30 s) goes to the success page if the order is placed by then, otherwise to a neutral still-confirming notice, never the failure sentence.
- Unplaced means status 0 or the shop's void status, which OpenCart 4.1.0.0 sets on every checkout edit. A second callback hit on a placed order writes nothing; the status is read again before any write (`OrderFlow::afterCallback`).
- install() and `configure.php` rename a `two_order` table of another shape (the pre-rewrite extension's) aside before creating the table, and an order create is refused unless its token row was stored.
- The callbacks are guarded by a per-order token (its hash in `two_order`). OpenCart's session cookie is SameSite=Strict by default, so a callback arriving without it bounces once through a same-site page rebuilt from the route and the validated id and token.
- Refusals: the buyer gets `ErrorMapper`'s copy (section 6.4), the merchant's order history and the log get the reason.

## Order lifecycle

Once placed, the order's Two order follows the merchant's status maps. One catalog event, `catalog/model/checkout/order.addHistory/after` (`Services::EVENT_*`), runs `catalog/controller/event/two.php`, which hands the shop to `OrderStatusEvent` (`system/library/opencart/`, specced offline) after every status change: the admin's (OpenCart runs the catalog order model in a store instance, events included), the storefront's and the extension's own. OpenCart's order edit writes the edited rows and then adds a history line, so the same event sees edits. Orders paid with another method, and unplaced orders (status 0 or the shop's void status, so a refused confirm's note can never cancel a live Two order), are left alone.

- Each event first syncs any edit (below), then acts on the status: a mapped fulfilment status fulfils the whole Two order (`POST /v1/order/{id}/fulfillments`, through the hook as `capture`), the mapped cancel status cancels it, the mapped refund status refunds what is left. A status in more than one map takes the first of fulfil, cancel, refund.
- Idempotent: the stored Two state, then Two's live state, decide whether anything is sent, so a repeated event or a re-save sends nothing. A fulfil marks the order FULFILLING before reading it back. FULFILLING, FULFILMENT_NEEDS_MANUAL_RESOLUTION, FULFILLED, DELIVERED and REFUNDED all count as fulfilment asked for. Every write also carries an `Idempotency-Key` (`fulfil_<two order id>`, `cancel_<two order id>`), though Two honours it today only on partial fulfilments and refunds. Empty bodies go as `{}`: Two refuses a JSON list.
- Before fulfilling, the order's own rows (products with price, tax and quantity, totals, and the billing and shipping addresses as stored on the order) must hash to `rows_hash`, kept when Two confirmed the order and moved on by every edit Two accepts. Nothing from the catalogue or the tax setup is used, so a deleted product or a rate change never blocks a fulfil. On a difference (an edit Two refused or never got) nothing is sent and the history says so. An order with no `rows_hash` (placed before it was kept) is refused with a pointer to the Two merchant portal, never fulfilled unchecked. `rows_hash` and `edit_key` are added in place by install() and `configure.php`.
- The status change is never undone. Every outcome except "nothing to do" goes in the order history at the status the merchant chose (a second `addHistory`, guarded so it does not re-enter the handler), and every refusal in the log. A fulfilled order is not cancelled: the note says it needs a refund.
- Order-edit sync (`OrderEdit`): when the rows no longer hash to `rows_hash`, the order is rebuilt and its create payload hash compared with `payload_hash`; on a difference the edit goes as `PUT /v1/order/{id}` through the hook as `order_update`, while Two holds the order VERIFIED or CONFIRMED. The key is `edit_<two order id>_<hash of the last accepted edit's key (the create key for the first edit) and this payload>`; each accepted edit stores its key, payload hash and rows hash. An unknown outcome (no answer, a 5xx) clears `payload_hash`: the next save then sends the order as it stands whatever its rows (even edited back to what Two last accepted, since Two may hold the lost edit), under the same key while the order is unchanged, and fulfil refuses until Two has accepted it. An edit Two refuses as such (400 or 422) keeps its rows hash (`edit_refused_hash`), so the same edit is not resent or noted on every save; a different edit is. A 408 or 429 counts as no answer, and another 4xx (a key being changed, a conflict) records nothing, so the next save resends. A fulfil blocked this way says to fulfil in the Two merchant portal if the edit cannot be sent. After fulfilment (or once Two reports the PARTIAL status) an edit is refused with one note, never sent.
- Refunds (`OrderRefunds`): Two refunds an order it holds as FULFILLED, DELIVERED or REFUNDED. The refund status refunds what Two's order total leaves after the refunds Two already holds: an empty body when there are none, else one line per tax rate from Two's tax subtotals less its refunds'. Its key is `refund_remainder_<two order id>_<hash of Two's prior refund ids and the amount>`. The Two tab's form refunds picked quantities of the Two order's lines (each the line's own net and tax share, referenced by its id) and an amount line (net and tax typed, at one of the order's rates). Each form refund is a `two_refund` row created before the call, keyed `refund_<two order id>_<row id>`. A line's quantity is capped at what earlier rows (done, unknown or never settled) left of it, and the last of a line takes the net and tax they left. Refunds on one order are serialised with a MySQL named lock (`RefundRecords::lock`, named from the order id and a hash of the database and table prefix), so a status-map refund and a form refund never both pass the checks. Section 12.2.1: a line with a net amount but no tax at a taxed rate is refused, and nothing more than remains is sent.
- After every refund Two's refunded total is read back. A rise of at least the amount sent is done; a 2xx with a smaller rise means Two answered with an earlier refund under the key and nothing was applied; no answer (a timeout, a network error, a 5xx) with no rise marks the row unknown. While a row is unknown (or never settled), every other refund, the status map's included, is refused until the row is retried from the Two tab: the retry sends the stored payload under the stored key, so Two applies it at most once, and counts the earlier attempt as applied when Two answers with a refund it already held, or when Two answers the retry and its refunded total has risen by the amount since the first attempt (kept on the row as `refunded_before`); a rise with no answer leaves the row unknown, since it could be a refund made in the portal. Every outcome goes in the order history, and a refund Two applied is logged with its key at info level.
- The admin order page has a Two tab (the payment extension's `order()`, read with the order's own store settings): the Two order id and state, Two's refunded total and what is left, the refund form, the refunds sent from the shop (with Retry on an unknown one), and the invoice and credit-note downloads. The refund and retry forms post through a small inline script in `two_order.twig`, which shows the outcome and reloads the tab (`two.panel`) whatever it was, so the form and figures are never stale. The downloads (`two.invoice`, `two.creditNote`) sit behind the admin login and its user_token and also need access to Sales > Orders and the extension; the refund and retry actions need modify rights on both. The invoice is asked for as the original streamed by Two (`?v=original&proxy=true`), since Two may otherwise answer with a redirect and the transport does not follow redirects; on Two's `ORDER_NOT_FULFILLED` it reads the order's state: still fulfilling says try later, another state is named, and a just-fulfilled order is asked once more. The credit note is the latest, streamed by Two (`/v1/credit_note/{id}/pdf?proxy=true`). Only a real PDF is served.
- Partial fulfilment is not offered from the shop, as in PrestaShop: the merchant fulfils part of an order in the Two merchant portal. OpenCart has no shipment or invoice record to key one on, and Two splits a partly fulfilled order into child orders that refunds and invoices would then have to follow. The plugin reads Two's PARTIAL status and refuses edits once it is set.
- A re-create (the buyer confirms the same OpenCart order again) cancels the earlier Two order before its id is overwritten, and refuses the new one if Two still holds the earlier one open.

Later-phase items, not built yet:
- The Diagnostics tab: SSL bypass, debug logging, refresh merchant profile, scheduled refresh URL.
- The error log view (last 100 lines).
- The custom request-header table, with halt-and-show on save (`CustomHeaders::validate` is built and tested) and a `send_from_browser` flag per row.
- P9's E2E must include a 4.1.0.0 CI leg that places a whole order: the floor voids an unplaced order on every checkout edit, and the CI shop otherwise runs 4.1.0.4.
- The availability chain at checkout (P6): the tile is offered whenever the extension is enabled with an API key.
- A plugin status for confirmed orders (the shop's default order status is used).
- The rest of the PrestaShop field set: vendor name, subtitle, the checkout field toggles, payment terms, and the remaining status maps.

## Conventions

- Branches go into `staging`; `main` is the release branch.
- Commit subjects: `<TICKET>/<type>: <description>`.
- Tests are parameterised tables with a description per case, used in the assertion message.
- The PR template carries the Parity checklist. Fill it in.
- No em-dashes in code, comments, docs, commits or PRs.
- This repo is public. Never name a partner, merchant or any brand other than Two, and do not reproduce other repos' internals.
