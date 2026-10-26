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

export function twoOrderId(orderId: number): string {
  return sql(`SELECT two_order_id FROM oc_two_order WHERE order_id = ${orderId}`)[0]?.[0] ?? "";
}
