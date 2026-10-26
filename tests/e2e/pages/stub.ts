import { APIRequestContext, expect, request } from "@playwright/test";

import { STUB_URL } from "../config";

// The stub's control routes (tests/e2e/stub/two-api-stub.mjs).
async function api(): Promise<APIRequestContext> {
  return request.newContext({ baseURL: STUB_URL, ignoreHTTPSErrors: true });
}

export type StubOrder = {
  id: string;
  state: string;
  currency: string;
  gross_amount: string;
  line_items: { id: string; name: string; quantity: number; gross_amount: string }[];
  refunds: { id: string; amount: string }[];
};

export async function stubOrder(id: string): Promise<StubOrder> {
  const res = await (await api()).get(`/__stub/orders/${id}`);
  expect(res.ok(), `the stub holds Two order ${id}`).toBeTruthy();
  return res.json();
}

export async function expectState(id: string, state: string): Promise<void> {
  await expect.poll(async () => (await stubOrder(id)).state, { message: `Two order ${id} reaches ${state}` }).toBe(state);
}

export async function requests(prefix: string): Promise<{ method: string; path: string; key: string | null; body: any }[]> {
  return (await (await api()).get(`/__stub/requests?path=${encodeURIComponent(prefix)}`)).json();
}

/** Makes the next matching request to the stub fail with the status, without applying it. */
export async function failNext(method: string, path: string, status = 503): Promise<void> {
  expect((await (await api()).post("/__stub/fault", { data: { method, path, status } })).ok()).toBeTruthy();
}

export const cents = (amount: string | number): number => Math.round(Math.abs(Number(amount)) * 100);
export const refundedCents = (order: StubOrder): number => order.refunds.reduce((sum, r) => sum + cents(r.amount), 0);
