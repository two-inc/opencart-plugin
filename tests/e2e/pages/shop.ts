import { execFileSync } from "node:child_process";
import path from "node:path";

import { STUB_PORT } from "../config";

// Reads the shop's database through the E2E compose project, so a spec can
// find the order it just placed and the Two order it is linked to.
const COMPOSE = path.resolve(__dirname, "../compose.yml");

export function sql(query: string): string[][] {
  const out = execFileSync(
    "docker",
    ["compose", "-f", COMPOSE, "exec", "-T", "db", "mariadb", "-uroot", "-padmin", "opencart", "-N", "-B", "-e", query],
    { encoding: "utf8", env: { ...process.env, TWO_API_STUB_PORT: STUB_PORT } }
  );
  return out.trim() === "" ? [] : out.trim().split("\n").map((row) => row.split("\t"));
}

export function latestOrderId(): number {
  return Number(sql("SELECT MAX(order_id) FROM oc_order")[0][0]);
}

export function orderStatusId(orderId: number): number {
  return Number(sql(`SELECT order_status_id FROM oc_order WHERE order_id = ${orderId}`)[0][0]);
}

/**
 * Calls the Two API from inside the shop container, as the shop does: so a
 * spec reaches the stub's API, which listens only on the compose network.
 */
export function callTwo(method: string, path: string, body: unknown, apiKey: string): { status: number; json: any } {
  const out = execFileSync(
    "docker",
    ["compose", "-f", COMPOSE, "exec", "-T", "opencart", "curl", "-sS", "-X", method, "-H", `X-API-Key: ${apiKey}`,
      "-H", "Content-Type: application/json", "-H", `Idempotency-Key: e2e-${Date.now()}-${Math.random()}`,
      ...(body === null ? [] : ["--data-binary", "@-"]), "-w", "\n%{http_code}", `https://api.sandbox.two.inc${path}`],
    { encoding: "utf8", input: body === null ? "" : JSON.stringify(body), env: { ...process.env, TWO_API_STUB_PORT: STUB_PORT } }
  );
  const at = out.lastIndexOf("\n");
  return { status: Number(out.slice(at + 1)), json: JSON.parse(out.slice(0, at) || "null") };
}

export function twoOrderId(orderId: number): string {
  return sql(`SELECT two_order_id FROM oc_two_order WHERE order_id = ${orderId}`)[0]?.[0] ?? "";
}
