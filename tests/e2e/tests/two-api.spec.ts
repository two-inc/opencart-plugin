import { expect, test } from "@playwright/test";

import { STUB_API_KEY } from "../config";
import { addHistory, openOrder } from "../pages/admin";
import { callTwo, twoOrderId } from "../pages/shop";
import { placeOrder } from "../pages/storefront";
import { expectState, requests, stubOrder } from "../pages/stub";

// The stub refuses what Two refuses, so a shop that sends the wrong API key or
// amounts that do not add up fails the suite as it would fail against Two.
// Every refusal has an accepted twin, so it is the change that is refused.

const add = (amount: string, cents: number): string => ((Math.round(Number(amount) * 100) + cents) / 100).toFixed(2);

test("Two refuses a wrong API key, an order whose amounts do not add up and a malformed refund", async ({ page }) => {
  const orderId = await placeOrder(page, 2);
  const twoId = twoOrderId(orderId);
  await openOrder(page, orderId);
  await addHistory(page, "Shipped");
  await expectState(twoId, "FULFILLED");

  expect(callTwo("GET", `/v1/order/${twoId}`, null, STUB_API_KEY).status, "the shop's key reads the order").toBe(200);
  const wrongKey = callTwo("GET", `/v1/order/${twoId}`, null, "not-the-shops-key");
  expect([wrongKey.status, wrongKey.json.error_code], "a wrong key is refused").toEqual([401, "AUTHENTICATION_INVALID"]);

  const created = (await requests("/v1/order")).find((r) => r.path === "/v1/order" && r.method === "POST" && r.body.merchant_order_id === String(orderId))!.body;
  expect(callTwo("POST", "/v1/order", created, STUB_API_KEY).status, "the shop's own order body is accepted").toBe(201);
  const line = created.line_items[0];
  const orders: [string, object, string][] = [
    ["the order's gross is a penny over its net and tax", { gross_amount: add(created.gross_amount, 1) }, "Order invoice total is not equal to tax + net amount"],
    ["the lines' net is a pound short of the order's", { net_amount: add(created.net_amount, 100), gross_amount: add(created.gross_amount, 100) }, "Order net amount is not equal to total line item net amounts"],
    ["a line's gross is a penny over its net and tax", { line_items: [{ ...line, gross_amount: add(line.gross_amount, 1) }, ...created.line_items.slice(1)] }, "Line item gross amount is not equal to net + tax amount"],
  ];
  for (const [what, change, detail] of orders) {
    const refused = callTwo("POST", "/v1/order", { ...created, ...change }, STUB_API_KEY);
    expect([refused.status, refused.json.error_code], what).toEqual([400, "INVALID_ORDER_AMOUNT_DATA"]);
    expect(refused.json.error_details, what).toContain(detail);
  }

  const order = await stubOrder(twoId);
  const item = order.line_items[0] as any;
  const refund = {
    amount: item.gross_amount,
    currency: order.currency,
    line_items: [item],
    tax_subtotals: [{ tax_rate: item.tax_rate, taxable_amount: item.net_amount, tax_amount: item.tax_amount }],
  };
  const refunds: [string, object, string][] = [
    ["the amount is a penny over its lines", { ...refund, amount: add(refund.amount, 1) }, "REFUND_AMOUNT_INCONSISTENT"],
    ["the currency is not the order's", { ...refund, currency: order.currency === "EUR" ? "GBP" : "EUR" }, "REFUND_CURRENCY_ERROR"],
    // Two allows a refund line two pence either way.
    ["a line's gross is a pound over its net and tax", { ...refund, line_items: [{ ...item, gross_amount: add(item.gross_amount, 100) }] }, "REFUND_LINE_ITEMS_INVALID"],
    ["a full refund names a currency", { currency: order.currency }, "SCHEMA_ERROR"],
  ];
  for (const [what, body, code] of refunds) {
    const refused = callTwo("POST", `/v1/order/${twoId}/refund`, body, STUB_API_KEY);
    expect([refused.status, refused.json.error_code], what).toEqual([400, code]);
  }
  expect((await stubOrder(twoId)).refunds, "no refused refund is applied").toHaveLength(0);
  expect(callTwo("POST", `/v1/order/${twoId}/refund`, refund, STUB_API_KEY).status, "the well-formed refund is accepted").toBe(201);
});
