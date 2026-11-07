import assert from "node:assert/strict";
import test from "node:test";
import { stockChoices, supplyTotal, validateStockLines, validateUsages, validateWorkflowPayment, validateSupportAmounts } from "../src/lib/production-workflow.ts";

test("stock choices aggregate lots precisely and respect the selected source", () => {
  const context = { materials: [{ id: 1, material_id: 4, location_id: 1, name: "Wool", warehouse_quantity: "0.001", held_quantity: "2.000" }, { id: 2, material_id: 4, location_id: 1, name: "Wool", warehouse_quantity: "0.009", held_quantity: "1.125" }, { id: 3, material_id: 4, location_id: 2, name: "Wool", warehouse_quantity: "100", held_quantity: "0" }], tools: [], defaults: null, washing_items: [] };
  assert.equal(stockChoices(context, false, false, "1")[0].quantity, "0.010");
  assert.equal(stockChoices(context, false, true)[0].quantity, "3.125");
});
test("give and return forms reject over-issue, duplicate items and fractional tools", () => {
  const choices = [{ id: 1, quantity: "10.125" }];
  assert.deepEqual(validateStockLines([{ item_id: "1", quantity: "10.125" }], choices, false), {});
  assert.ok(validateStockLines([{ item_id: "1", quantity: "10.126" }], choices, false)["0.quantity"]);
  assert.ok(validateStockLines([{ item_id: "1", quantity: "1.5" }], choices, true)["0.quantity"]);
  assert.ok(validateStockLines([{ item_id: "1", quantity: "1" }, { item_id: "1", quantity: "2" }], choices, false).items);
});
test("actual material usage includes waste and cannot exceed manager custody", () => {
  const choices = [{ id: 1, quantity: "1.125" }];
  assert.ok(validateUsages([{ material_id: "1", used_quantity: "1", waste_quantity: ".125" }], choices)["usages.0"]);
  assert.deepEqual(validateUsages([{ material_id: "1", used_quantity: "1", waste_quantity: "0.125" }], choices), {});
  for (const row of [{ used_quantity: "1.001", waste_quantity: "0.125" }, { used_quantity: "0.0001", waste_quantity: "0" }, { used_quantity: "-1", waste_quantity: "0" }]) assert.ok(validateUsages([{ material_id: "1", ...row }], choices)["usages.0"]);
});
test("supply totals round each receipt with decimal arithmetic", () => {
  assert.equal(supplyTotal([{ quantity: "0.005", unit_cost: "1.00" }, { quantity: "0.005", unit_cost: "1.00" }], 2), "0.02");
  assert.equal(supplyTotal([{ quantity: "20", unit_cost: "400" }, { quantity: "2", unit_cost: "750" }], 2), "9500.00");
  assert.equal(supplyTotal([{ quantity: "1.125", unit_cost: "10" }], 0), "11.00");
  assert.equal(supplyTotal([{ quantity: "1.495", unit_cost: "1" }], 0), "1.00");
  assert.equal(supplyTotal([{ quantity: "1.245", unit_cost: "1" }], 1), "1.20");
});

test("support handover is limited to the funded document amount and validates one-decimal currencies", () => {
  const lookups = { currencies: [{ id: 1, decimal_places: 1 }] };
  const payment = { amount: "100", units_per_payment_unit: "1.5" };
  assert.ok(validateSupportAmounts("300", 1, lookups, payment, { amount: "250" })["delivery.amount"]);
  assert.deepEqual(validateSupportAmounts("300", 1, lookups, payment, { amount: "150" }), {});
  assert.ok(validateSupportAmounts("300.01", 1, lookups).amount);
  assert.ok(validateSupportAmounts("300", 1, lookups, { ...payment, amount: "201" })["payment.amount"]);
  assert.ok(validateSupportAmounts("300", 1, lookups, payment, { amount: "1.01" })["delivery.amount"]);
});
test("payments validate account scope, balance, currency precision, rates and dates", () => {
  const lookups = { accounts: [{ id: 1, currency_id: 1, balance: "100.00" }], currencies: [{ id: 1, decimal_places: 0 }], company: { base_currency_id: 1 } };
  const payment = { account_id: "1", amount: "100", rate_to_base: "1", units_per_payment_unit: "1", payment_on: "2026-10-24", reason: "Production payment" };
  assert.deepEqual(validateWorkflowPayment(payment, lookups, 1, "2026-10-24"), {});
  for (const [key, value] of Object.entries({ account_id: "2", amount: "100.01", rate_to_base: "2", units_per_payment_unit: "2", payment_on: "2026-10-25", reason: "" })) assert.ok(validateWorkflowPayment({ ...payment, [key]: value }, lookups, 1, "2026-10-24")[key]);
});
