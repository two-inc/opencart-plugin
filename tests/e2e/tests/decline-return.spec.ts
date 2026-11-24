import { Page, expect, test } from "@playwright/test";

import { STORE_URL } from "../config";
import { historyComments, isUnplaced, twoOrderId } from "../pages/shop";
import { placeWithoutReturn } from "../pages/storefront";
import { leaveAtTwo, requests, setState } from "../pages/stub";

// TWO-27391: Two declines the buyer at its hosted checkout and sends them to
// the shop's cancel URL. The buyer wants to pay for the same basket another
// way: the shop asks Two, closes the declined attempt without sending Two a
// cancel, and sends the buyer back to choose another payment method.
const MESSAGE = "Two could not approve this purchase. Please choose another payment method.";

const cancels = async (twoId: string) => (await requests(`/v1/order/${twoId}/cancel`)).filter((r) => r.method === "POST");
const declinedNotes = (orderId: number) => historyComments(orderId).filter((c) => c.includes("Two declined order"));
const notices = (page: Page) => page.locator(".alert", { hasText: MESSAGE });

/** Places an order whose buyer Two declines; returns it with the cancel URL Two sends the buyer to. */
async function declined(page: Page): Promise<{ orderId: number; twoId: string; cancelUrl: string }> {
  const { orderId, paymentUrl } = await placeWithoutReturn(page);
  const twoId = twoOrderId(orderId);
  await setState(twoId, "UNVERIFIED", "REJECTED");
  return { orderId, twoId, cancelUrl: await leaveAtTwo(paymentUrl) };
}

test("a declined buyer in the same browser is back at the checkout with the basket, Two offered but not chosen", async ({ page }) => {
  const { orderId, twoId, cancelUrl } = await declined(page);

  await page.goto(cancelUrl);
  // OpenCart's checkout renders only with products in the cart: the basket is kept.
  await page.waitForURL(/route=checkout\/checkout/);
  await expect(notices(page), "the message, once").toHaveCount(1);
  expect(isUnplaced(orderId), "the order stays unplaced").toBe(true);
  expect(await cancels(twoId), "no cancel sent for a declined order").toHaveLength(0);
  expect(declinedNotes(orderId), "one note").toHaveLength(1);

  await expect(page.locator("#input-payment-method"), "no payment method chosen").toHaveValue("");
  await page.click("#button-payment-methods");
  const two = page.locator('.modal.show input[value="two.two"]');
  await expect(two, "Two still offered").toBeVisible();
  await expect(two, "but not selected").not.toBeChecked();

  await page.reload();
  await expect(notices(page), "shown once, not on the next visit").toHaveCount(0);

  // A second click on the same link writes nothing more.
  await page.goto(cancelUrl);
  await page.waitForURL(/route=checkout\/checkout/);
  expect(declinedNotes(orderId), "still one note").toHaveLength(1);
  expect(await cancels(twoId)).toHaveLength(0);
});

test("the same link in another browser shows the message on its own page and restores nothing", async ({ page, browser }) => {
  const { orderId, twoId, cancelUrl } = await declined(page);

  const other = await browser.newContext({ baseURL: STORE_URL, ignoreHTTPSErrors: true });
  try {
    const elsewhere = await other.newPage();
    await elsewhere.goto(cancelUrl);
    // OpenCart's cart shows a notice only beside products, so the extension's own page shows it.
    await expect(elsewhere.locator("#two-return-notice h1")).toHaveText("Payment with Two");
    await expect(notices(elsewhere), "the message").toHaveCount(1);
    await elsewhere.goto("/index.php?route=checkout/cart");
    await expect(elsewhere.locator("#content"), "nothing restored").toContainText("Your shopping cart is empty!");
  } finally {
    await other.close();
  }
  expect(isUnplaced(orderId), "the order stays unplaced").toBe(true);
  expect(await cancels(twoId), "no cancel sent for a declined order").toHaveLength(0);
  expect(declinedNotes(orderId), "the housekeeping still done: one note").toHaveLength(1);
});
