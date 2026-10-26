// A stand-in for Two's API for the E2E suite, run in its own container by
// tests/e2e/compose.yml. No dependencies: Node's own http and https.
//
// The shop calls https://api.sandbox.two.inc as it would in production: the
// compose network gives this container that name, and the shop trusts the
// throwaway CA tests/e2e/bin/tls.sh made for it. So the extension runs
// unchanged, with no host override.
//
// It listens twice, both over HTTPS:
//   - on 443, inside the compose network only: the shop's API calls.
//   - HTTPS on TWO_API_STUB_PORT, published on the host's loopback: Two's hosted
//     checkout (the order's payment_url, which the browser follows; the
//     extension only redirects to an https URL) and the /__stub/ control
//     routes the specs use. The port has no default, so the published port
//     and the payment_url built from it cannot drift apart. The browser does
//     not trust the throwaway CA, so the suite ignores certificate errors.
//
// It keeps orders in memory and answers with the fields the extension reads:
// state, the totals and lines it was sent, and the refunds it holds. A write
// repeated under the same Idempotency-Key gets the first answer back and
// changes nothing, as Two does. /__stub/fault makes the next matching request
// fail, so the specs can reach the refund-unknown path.
import fs from "node:fs";
import https from "node:https";
import { randomUUID } from "node:crypto";

const PORT = process.env.TWO_API_STUB_PORT;
if (!PORT) {
  console.error("TWO_API_STUB_PORT must be set: the payment_url is built from it.");
  process.exit(1);
}
const PUBLIC_URL = `https://localhost:${PORT}`;
const MERCHANT_ID = "e2e-merchant";

let orders = new Map();
let replies = new Map();
let requests = [];
let faults = [];
let checkoutOutcome = "verify";

const cents = (value) => Math.round(Math.abs(Number(value || 0)) * 100);
const money = (c) => (c / 100).toFixed(2);

function refundedCents(order) {
  return order.refunds.reduce((sum, refund) => sum + cents(refund.total_amount), 0);
}

// What is left per tax rate: the order's subtotals less every refund's.
function remainingSubtotals(order) {
  const buckets = new Map();
  const add = (subtotals, sign) => {
    for (const s of subtotals || []) {
      const b = buckets.get(String(Number(s.tax_rate))) || { net: 0, tax: 0 };
      b.net += sign * cents(s.taxable_amount);
      b.tax += sign * cents(s.tax_amount);
      buckets.set(String(Number(s.tax_rate)), b);
    }
  };
  add(order.tax_subtotals, 1);
  for (const refund of order.refunds) add(refund.tax_subtotals, -1);
  return [...buckets].filter(([, b]) => b.net + b.tax > 0)
    .map(([rate, b]) => ({ tax_rate: rate, taxable_amount: money(b.net), tax_amount: money(b.tax) }));
}

function refund(order, body) {
  const full = !body || Object.keys(body).length === 0;
  const remaining = cents(order.gross_amount) - refundedCents(order);
  const amount = full ? remaining : cents(body.amount);
  if (amount <= 0 || amount > remaining) {
    return [400, { error_code: "INVALID_REFUND", error_message: `Refund of ${money(amount)} exceeds the ${money(remaining)} left.` }];
  }
  const item = {
    id: randomUUID(),
    amount: money(amount),
    total_amount: `-${money(amount)}`,
    currency: order.currency,
    tax_subtotals: full ? remainingSubtotals(order) : body.tax_subtotals || [],
    line_items: full ? [] : body.line_items || [],
  };
  order.refunds.push(item);
  order.state = refundedCents(order) >= cents(order.gross_amount) ? "REFUNDED" : order.state;
  return [201, item];
}

// Two gives every order line an id, which refunds then reference.
const withLineIds = (lines) => (lines || []).map((line) => ({ ...line, id: randomUUID() }));

const STATES_FOR = {
  confirm: ["VERIFIED"],
  fulfillments: ["CONFIRMED", "VERIFIED"],
  cancel: ["UNVERIFIED", "VERIFIED", "CONFIRMED"],
  refund: ["FULFILLED", "DELIVERED", "REFUNDED"],
  update: ["VERIFIED", "CONFIRMED"],
};

function orderAction(order, action, body) {
  if (!STATES_FOR[action].includes(order.state)) {
    return [400, { error_code: "INVALID_STATE", error_message: `Order is ${order.state}.` }];
  }
  switch (action) {
    case "confirm": order.state = "CONFIRMED"; break;
    case "fulfillments": order.state = "FULFILLED"; break;
    case "cancel": order.state = "CANCELLED"; break;
    case "refund": return refund(order, body);
    case "update": {
      const { merchant_urls: _ignored, ...edit } = body || {};
      Object.assign(order, edit, { line_items: withLineIds(edit.line_items) });
      break;
    }
  }
  return [200, order];
}

// The Two API: what the shop calls.
function api(method, path, body) {
  if (method === "GET" && path === "/v1/merchant/verify_api_key") {
    return [200, { id: MERCHANT_ID, short_name: "e2e" }];
  }
  if (method === "GET" && path === `/v1/merchant/${MERCHANT_ID}`) {
    return [200, { id: MERCHANT_ID, short_name: "e2e", available_terms: [30, 60], due_in_days: 30, invoice_distributed_by_merchant: false }];
  }
  if (method === "GET" && path === "/refdata/v1/fx-rates") {
    return [200, { base: "EUR", as_of: new Date().toISOString().slice(0, 10), rates: { EUR: 1, GBP: 0.85, USD: 1.08, NOK: 11.6, SEK: 11.3, DKK: 7.46 } }];
  }
  if (method === "POST" && path === "/v1/pricing/order/fee") {
    return [200, { buyer_fee_share: "0.00", currency: body?.currency }];
  }
  if (method === "GET" && path === "/companies/v2/company") {
    return [200, { items: [{ name: "E2E Trading Ltd", national_identifier: { id: "12345678" }, highlight: "", lookup_id: "e2e-1" }] }];
  }
  if (method === "GET" && path === "/companies/v2/supported-countries") {
    return [200, { supported_countries: ["GB", "NO", "SE", "NL", "ES"] }];
  }
  if (method === "GET" && path.startsWith("/registry/v1/supported-company-types/")) {
    return [200, { supported_company_types: ["LIMITED_COMPANY"] }];
  }
  if (method === "POST" && path === "/v1/order_intent") {
    return [200, { approved: true }];
  }
  if (method === "POST" && path === "/v1/order") {
    const id = randomUUID();
    const order = { ...body, line_items: withLineIds(body?.line_items), id, state: "UNVERIFIED", status: "APPROVED", refunds: [], payment_url: `${PUBLIC_URL}/checkout/${id}` };
    orders.set(id, order);
    return [201, order];
  }
  const m = path.match(/^\/v1\/order\/([0-9a-f-]{36})(?:\/(confirm|fulfillments|cancel|refund))?$/);
  const order = m && orders.get(m[1]);
  if (m && !order) {
    return [404, { error_code: "NOT_FOUND", error_message: "No such order." }];
  }
  if (order && method === "GET" && !m[2]) return [200, order];
  if (order && method === "PUT" && !m[2]) return orderAction(order, "update", body);
  if (order && method === "POST" && m[2]) return orderAction(order, m[2], body);
  return [404, { error_code: "NOT_STUBBED", error_message: `${method} ${path} is not stubbed.` }];
}

// Two's hosted checkout: the buyer passes it at once, or leaves (cancel).
function hostedCheckout(id, res) {
  const order = orders.get(id);
  if (!order) return send(res, 404, { error: "no such order" });
  if (checkoutOutcome === "cancel") {
    res.writeHead(302, { Location: order.merchant_urls.merchant_cancel_order_url }).end();
    return;
  }
  order.state = "VERIFIED";
  res.writeHead(302, { Location: order.merchant_urls.merchant_confirmation_url }).end();
}

function control(method, path, body, query, res) {
  if (method === "POST" && path === "/__stub/fault") {
    faults.push({ method: body.method, path: new RegExp(body.path), status: body.status ?? 503 });
    return send(res, 200, { faults: faults.length });
  }
  if (method === "POST" && path === "/__stub/checkout") {
    checkoutOutcome = body.outcome === "cancel" ? "cancel" : "verify";
    return send(res, 200, { checkout: checkoutOutcome });
  }
  if (method === "GET" && path === "/__stub/requests") {
    const prefix = query.get("path") || "";
    return send(res, 200, requests.filter((r) => r.path.startsWith(prefix)));
  }
  const m = path.match(/^\/__stub\/orders\/([0-9a-f-]{36})$/);
  if (method === "GET" && m) {
    return orders.has(m[1]) ? send(res, 200, orders.get(m[1])) : send(res, 404, {});
  }
  return send(res, 404, { error: `no control route ${method} ${path}` });
}

function send(res, status, body) {
  res.writeHead(status, { "Content-Type": "application/json" }).end(JSON.stringify(body));
}

function handler(isPublic) {
  return (req, res) => {
    let raw = "";
    req.on("data", (chunk) => (raw += chunk));
    req.on("end", () => {
      const url = new URL(req.url, "http://stub");
      const path = url.pathname;
      let body = null;
      try {
        body = raw ? JSON.parse(raw) : null;
      } catch {
        return send(res, 400, { error_code: "BAD_JSON" });
      }
      if (isPublic) {
        const checkout = path.match(/^\/checkout\/([0-9a-f-]{36})$/);
        if (checkout) return hostedCheckout(checkout[1], res);
        if (path.startsWith("/__stub/")) return control(req.method, path, body || {}, url.searchParams, res);
        return send(res, 404, {});
      }
      const key = req.headers["idempotency-key"] || null;
      requests.push({ method: req.method, path, key, body, at: new Date().toISOString() });
      const fault = faults.findIndex((f) => f.method === req.method && f.path.test(path));
      if (fault !== -1) {
        const [{ status }] = faults.splice(fault, 1);
        return send(res, status, { error_code: "STUB_FAULT", error_message: "Injected by the E2E suite." });
      }
      if (key && req.method !== "GET" && replies.has(key)) {
        const [status, reply] = replies.get(key);
        return send(res, status, reply);
      }
      const [status, reply] = api(req.method, path, body);
      if (key && req.method !== "GET" && status < 500) replies.set(key, [status, structuredClone(reply)]);
      return send(res, status, reply);
    });
  };
}

const tls = { key: fs.readFileSync(process.env.TLS_KEY), cert: fs.readFileSync(process.env.TLS_CERT) };
https.createServer(tls, handler(false)).listen(443, () => console.log("two-api stub: API on 443"));
https.createServer(tls, handler(true)).listen(Number(PORT), () => console.log(`two-api stub: hosted checkout and control on ${PORT}`));
