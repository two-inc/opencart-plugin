# Two for OpenCart

The Two payment extension for OpenCart 4.1: pay by invoice for business buyers.

**Status: being rewritten.** This branch holds the new OpenCart 4.1 foundation. It installs and appears under Extensions > Payments, but does not offer Two at checkout yet. The 1.x releases for OpenCart 3 call API hosts that no longer exist, so they cannot reach Two.

## Requirements

- OpenCart 4.1.0.0 or later
- PHP 8.1 or later

## Install

Upload `two.ocmod.zip` in Extensions > Installer, install it, then install and enable Two in Extensions > Extensions > Payments.

## Development

Needs Docker and PHP 8.1+ on the host.

```sh
cp .env.example .env   # optional: API key and environment
make install           # OpenCart 4.1.0.4 on http://localhost:1238/ with the extension enabled
make test              # offline test suite
make phpstan           # static analysis
make archive           # dist/two.ocmod.zip
```

`make help` lists every target. See [AGENTS.md](AGENTS.md) for the layout and conventions.

## Stable extension contract: order postprocessing

Every order request the extension sends to Two (order create, confirm, cancel and capture, that is fulfil, today; intent, update and refund as they land) passes through one OpenCart event just before it is sent, so your code can change the payload. Subscribe the way any OpenCart extension subscribes to an event: an `event` row (Extensions > Events) with the trigger `catalog/extension/two/order_postprocessing` and an action whose method takes the payload by reference:

```php
public function handle(array &$payload, array $context): void
```

- `context` carries `request_type` (`order_intent`, `order_create`, `order_update`, `order_confirm`, `capture`, `refund`, `cancel`), `trigger`, `endpoint`, `order_id`, the shop's `order`, `shipping_tax_rate`, `fallback_shipping_tax_rate` and `contract_version` (1). Confirm, cancel and capture carry an empty payload; what your handler returns is what is sent.
- Handlers run in the event's sort order. The extension never recomputes after them: a handler that edits a line also updates the totals it affects, for example with the totals helper `\Opencart\System\Library\Extension\Two\Core\Postprocessing::recomputeTotals($payload)`.
- With no handler registered, the extension refuses an order whose lines do not add up to the total and tax the shop charged. With one registered and enabled, even one that changes nothing, those shop-match checks are yours, and the log says so once per request. To run them yourself: `\Opencart\System\Library\Extension\Two\Core\OrderBuilder::shopMatchFailures($payload, $order)`.
- A handler that throws, or leaves the payload as something that is not an encodable array, refuses the request. The buyer sees the generic refusal and the log names `TWO_ORDER_POSTPROCESSING_HOOK_FAILED`, the exception class and where it was thrown.
- The same input must give the same output: no clock, no randomness. When the buyer comes back from Two, the extension rebuilds the order create payload from the stored order and runs your handlers on it again (`request_type` `order_create`, `trigger` `snapshot_hash`, nothing sent), then compares its hash with the payload Two was sent. On any difference (the cart, an amount or an address changed, or a handler gave a different answer) the order is not confirmed: the Two order is cancelled, the reason goes to the order history and the buyer is asked to review the cart. The same check runs before the order is fulfilled: on a difference it is not fulfilled, and the order history says why.
- The extension makes no determination about how tax is levied: it sends the nets, taxes and rates your shop recorded. If your shop records an amount differently from your books (for example untaxed shipping with VAT inside it), this event is where you correct it.

This contract is permanent: the event name, the v1 context keys and request types, and the totals helper are never removed or renamed. Changes are additive only.
