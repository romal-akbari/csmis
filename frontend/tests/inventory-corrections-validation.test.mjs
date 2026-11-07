import assert from "node:assert/strict";
import test from "node:test";
import {
  formatMinor,
  inventoryToday,
  moneyMinor,
} from "../src/lib/inventory.ts";
import {
  validateDraft,
  validateCorrection,
} from "../src/lib/inventory-corrections.ts";

const lookups = {
  company: { base_currency_id: 1, timezone: "Asia/Kabul" },
  branches: [{ id: 1, name: "Kabul" }],
  locations: [{ id: 1, name: "Shelf", branch_id: 1 }],
  categories: [],
  weavers: [{ id: 1, name: "Weaver" }],
  currencies: [{ id: 1, code: "USD", decimal_places: 2 }],
  accounts: [{ id: 1, name: "Cash", branch_id: 1, currency_id: 1 }],
  expense_categories: [],
};
const today = inventoryToday();
test("credit amounts preserve their sign and cents exactly", () => {
  assert.equal(moneyMinor("-100.05"), BigInt(-10005));
  assert.equal(moneyMinor("-0.05"), BigInt(-5));
  assert.equal(formatMinor(BigInt(-10005)), "-100.05");
});
test("partial drafts require a reference and branch but allow missing weaver and prices", () => {
  assert.deepEqual(
    validateDraft({ purchase_no: "DRAFT-1", branch_id: "1" }, [], lookups),
    {},
  );
  assert.ok(
    validateDraft({ purchase_no: "bad ref", branch_id: "2" }, [], lookups)
      .branch_id,
  );
  assert.deepEqual(
    validateDraft(
      { purchase_no: "DRAFT-1", branch_id: "1" },
      [{ carpet_id: 1, expected_sequence: 0, unit_price: "", label: "C1" }],
      lookups,
    ),
    {},
  );
});
test("stock correction requires reason, dates, branch, location and selected carpets", () => {
  const values = {
    adjustment_no: "A1",
    adjustment_type: "found",
    branch_id: "1",
    location_id: "1",
    adjusted_on: today,
    reason: "Found during stock count",
  };
  assert.ok(validateCorrection("adjustments", values, [], lookups).items);
  const lines = [
    { carpet_id: 1, expected_sequence: 3, unit_price: "", label: "C1" },
  ];
  assert.deepEqual(
    validateCorrection("adjustments", values, lines, lookups),
    {},
  );
  assert.ok(
    validateCorrection(
      "adjustments",
      { ...values, reason: "", adjusted_on: "2099-01-01" },
      lines,
      lookups,
    ).reason,
  );
});
test("return accepts a real source or explicit unreceived items and requires a purchase", () => {
  const values = {
    return_no: "R1",
    from_location_id: "-1",
    returned_on: today,
    reason: "Cancel remaining item",
  };
  const lines = [
    { carpet_id: 1, expected_sequence: 1, unit_price: "100", label: "C1" },
  ];
  assert.ok(
    validateCorrection("returns", values, lines, lookups).weaver_purchase_id,
  );
  assert.deepEqual(
    validateCorrection("returns", values, lines, lookups, { id: 1 }),
    {},
  );
});
test("refund validates exact credit limit, precision, account scope and rates", () => {
  const purchase = {
    id: 1,
    branch_id: 1,
    currency_id: 1,
    remaining_amount: "-100.05",
  };
  const values = {
    refund_no: "RF1",
    refunded_on: today,
    account_id: "1",
    amount: "100.05",
    rate_to_base: "1",
    purchase_units_per_refund_unit: "1",
    reason: "Actual refund received",
  };
  assert.deepEqual(
    validateCorrection("refunds", values, [], lookups, purchase),
    {},
  );
  assert.ok(
    validateCorrection(
      "refunds",
      { ...values, amount: "100.06" },
      [],
      lookups,
      purchase,
    ).amount,
  );
  assert.ok(
    validateCorrection(
      "refunds",
      { ...values, account_id: "2" },
      [],
      lookups,
      purchase,
    ).account_id,
  );
  assert.ok(
    validateCorrection(
      "refunds",
      { ...values, rate_to_base: "2" },
      [],
      lookups,
      purchase,
    ).rate_to_base,
  );
  assert.ok(
    validateCorrection(
      "refunds",
      { ...values, amount: "1.001" },
      [],
      lookups,
      purchase,
    ).amount,
  );
});
