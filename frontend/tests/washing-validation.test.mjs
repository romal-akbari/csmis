import assert from "node:assert/strict";
import test from "node:test";
import { validateWashing, validWashingDate, washingConverted, washingFormat, washingPayload, washingToday } from "../src/lib/washing.ts";

const lookups = {
  company: { base_currency_id: 1, timezone: "Asia/Kabul" },
  branches: [{ id: 1, name: "Kabul" }, { id: 2, name: "Herat" }],
  facilities: [{ id: 1, name: "Washer" }, { id: 2, name: "Other washer" }],
  facility_locations: [{ id: 1, name: "Washing yard", washing_facility_id: 1 }, { id: 2, name: "Other yard", washing_facility_id: 2 }],
  locations: [{ id: 10, name: "Warehouse", branch_id: 1 }, { id: 20, name: "Other warehouse", branch_id: 2 }],
  currencies: [{ id: 1, code: "USD", decimal_places: 2 }, { id: 2, code: "AFN", decimal_places: 0 }],
  accounts: [{ id: 1, name: "Cash USD", branch_id: 1, currency_id: 1, balance: "100.00" }, { id: 2, name: "Cash AFN", branch_id: 1, currency_id: 2, balance: "7000.00" }, { id: 3, name: "Other branch", branch_id: 2, currency_id: 1, balance: "1000.00" }],
};
const today = washingToday();
const line = (id = 1, amount = "10.00") => ({ id, amount, expected_sequence: 2, label: `CAR-${id}` });
const bill = { expense_no: "WB-01", invoice_no: "FAC-001", branch_id: "1", facility_id: "1", currency_id: "1", rate_to_base: "1", billed_on: today, amount: "20.00" };
const payment = { payment_no: "WP-01", account_id: "1", amount: "10.00", rate_to_base: "1", bill_units_per_payment_unit: "1", payment_on: today, reason: "Cash paid to washer" };
const existingBill = { id: 1, branch_id: 1, currency_id: 1, billed_on: today, remaining_amount: "20.00" };

test("washing dates reject future and impossible calendar dates without throwing", () => {
  assert.equal(validWashingDate("2026-02-30", "2026-10-20"), false);
  assert.equal(validWashingDate("2026-99-99", "2026-10-20"), false);
  assert.equal(validWashingDate("2026-10-21", "2026-10-20"), false);
  assert.equal(validWashingDate("2024-02-29", "2026-10-20"), true);
});
test("dispatch validates facility ownership and rejects duplicate or oversized selections", () => {
  const values = { order_no: "W-01", branch_id: "1", facility_id: "1", facility_location_id: "1", sent_on: today };
  assert.deepEqual(validateWashing("dispatch", values, [line()], lookups), {});
  assert.ok(validateWashing("dispatch", { ...values, facility_location_id: "2" }, [line()], lookups).facility_location_id);
  assert.ok(validateWashing("dispatch", values, [line(), line()], lookups).items);
  assert.ok(validateWashing("dispatch", values, Array.from({ length: 51 }, (_, index) => line(index + 1)), lookups).items);
  assert.ok(validateWashing("dispatch", { ...values, order_no: "lowercase" }, [line()], lookups).order_no);
  assert.ok(validateWashing("dispatch", { ...values, order_no: "W/01" }, [line()], lookups).order_no);
  assert.ok(validateWashing("dispatch", { ...values, notes: "x".repeat(501) }, [line()], lookups).notes);
});
test("partial completion and return preserve visit sequence and require correct stock branch", () => {
  const order = { id: 1, branch_id: 1, sent_on: today };
  assert.deepEqual(validateWashing("complete", { completed_on: today }, [line(5)], lookups, order), {});
  assert.ok(validateWashing("return", { returned_on: today, location_id: "20" }, [line()], lookups, order).location_id);
  assert.ok(validateWashing("return", { returned_on: "2020-01-01", location_id: "10" }, [line()], lookups, order).returned_on);
  assert.deepEqual(washingPayload("return", { returned_on: today, location_id: "10", unused: "ignored" }, [line(5)]), { returned_on: today, location_id: "10", items: [{ washing_order_item_id: 5, expected_sequence: 2 }] });
});
test("bill allocations must exactly equal total and respect each currency's precision", () => {
  assert.deepEqual(validateWashing("bill", bill, [line(1), line(2)], lookups), {});
  assert.ok(validateWashing("bill", bill, [line(1, "9.99"), line(2)], lookups).allocations);
  assert.ok(validateWashing("bill", { ...bill, currency_id: "2", amount: "20.01" }, [line(1, "10.01"), line(2)], lookups).amount);
  assert.ok(validateWashing("bill", { ...bill, currency_id: "2", amount: "20.00" }, [line(1, "10.01"), line(2, "9.99")], lookups)["allocations.0.amount"]);
  assert.ok(validateWashing("bill", { ...bill, rate_to_base: "70" }, [line(1), line(2)], lookups).rate_to_base);
});
test("payment validation prevents overpayment, insufficient cash and precision loss while allowing assigned company accounts", () => {
  assert.deepEqual(validateWashing("payment", payment, [], lookups, existingBill), {});
  assert.ok(validateWashing("payment", { ...payment, amount: "20.01" }, [], lookups, existingBill).amount);
  assert.ok(validateWashing("payment", { ...payment, amount: "101" }, [], lookups, { ...existingBill, remaining_amount: "200" }).amount);
  assert.deepEqual(validateWashing("payment", { ...payment, account_id: "3" }, [], lookups, existingBill), {});
  assert.ok(validateWashing("payment", { ...payment, account_id: "999" }, [], lookups, existingBill).account_id);
  assert.ok(validateWashing("payment", { ...payment, bill_units_per_payment_unit: "2" }, [], lookups, existingBill).bill_units_per_payment_unit);
  assert.ok(validateWashing("payment", { ...payment, account_id: "2", amount: "10.10", rate_to_base: "0.01428571", bill_units_per_payment_unit: "0.01428571" }, [], lookups, existingBill).amount);
});
test("washing conversion uses exact integers for large values and currency rounding", () => {
  assert.equal(washingFormat(washingConverted("9999999999999999.99", "1")), "9999999999999999.99");
  assert.equal(washingFormat(washingConverted("1.01", "70.125", 0)), "71.00");
  assert.equal(washingFormat(washingConverted("0.01", "0.5", 2)), "0.01");
});
test("void and reversal require a reason and command payloads never leak unrelated fields", () => {
  assert.ok(validateWashing("void", { voided_on: today, reason: "" }, [], lookups, existingBill).reason);
  assert.ok(validateWashing("reverse", { reason: "" }, [], lookups).reason);
  assert.ok(validateWashing("reverse", { reason: "x" }, [], lookups).reason);
  assert.ok(validateWashing("payment", { ...payment, reason: "x".repeat(256) }, [], lookups, existingBill).reason);
  assert.deepEqual(washingPayload("reverse", { reason: "Wrong account", amount: "999", branch_id: "99" }, []), { reason: "Wrong account" });
  assert.deepEqual(washingPayload("bill", bill, [line(4, "20")]).allocations, [{ washing_order_item_id: 4, amount: "20" }]);
});
