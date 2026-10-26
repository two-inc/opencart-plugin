import { expect, test } from "@playwright/test";

import { orderStatusId, twoOrderId } from "../pages/shop";
import { placeOrder } from "../pages/storefront";
import { expectState, requests, stubOrder } from "../pages/stub";

test("a buyer finds their company, places the order with Two and sees the confirmation", async ({ page }) => {
  const orderId = await placeOrder(page);

  const twoId = twoOrderId(orderId);
  expect(twoId, "the shop order is linked to a Two order").not.toBe("");
  await expectState(twoId, "CONFIRMED");
  expect(orderStatusId(orderId), "the order is placed at the shop's default status").toBe(1);

  const created = await stubOrder(twoId);
  expect((created as any).buyer.company.organization_number, "the searched company's number is sent").toBe("12345678");
  const writes = (await requests("/v1/order")).filter((r) => r.method !== "GET");
  expect(writes.every((r) => r.key), "every write carries an Idempotency-Key").toBeTruthy();
});
