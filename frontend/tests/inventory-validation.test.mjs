import assert from "node:assert/strict";
import test from "node:test";
import {
  convertedMinor,
  formatMinor,
  inventoryDate,
  inventoryToday,
  validateInventory,
} from "../src/lib/inventory.ts";

const lookups = {
  company: { base_currency_id: 1, timezone: "Asia/Kabul" },
  branches: [{ id: 1, name: "Kabul" }],
  locations: [],
  categories: [{ id: 1, name: "Wool" }],
  weavers: [{ id: 1, name: "Weaver" }],
  expense_categories: [{ id: 1, name: "Bonus" }],
  currencies: [
    { id: 1, name: "USD", code: "USD", decimal_places: 2 },
    { id: 2, name: "AFN", code: "AFN", decimal_places: 2 },
  ],
  accounts: [
    { id: 1, name: "Cash", branch_id: 1, currency_id: 1, balance: "1000.00" },
  ],
};
const today = inventoryToday();
test("inventory dates reject impossible dates, future days and ancient dates without throwing", () => {
  assert.equal(inventoryDate("2026-02-30", "2026-10-19"), false);
  assert.equal(inventoryDate("2026-99-99", "2026-10-19"), false);
  assert.equal(inventoryDate("2099-01-01", "2026-10-19"), false);
  assert.equal(inventoryDate("0001-01-01", "2026-10-19"), false);
  assert.equal(inventoryDate("2024-02-29", "2026-10-19"), true);
});
test("money conversion remains exact for large values and rounds to currency precision", () => {
  assert.equal(formatMinor(convertedMinor("1.01", "70.125", 2)), "70.83");
  assert.equal(formatMinor(convertedMinor("1.01", "70.125", 0)), "71.00");
  assert.equal(
    formatMinor(convertedMinor("9999999999999999.99", "1", 2)),
    "9999999999999999.99",
  );
  assert.equal(formatMinor(convertedMinor("0.01", "0.5", 2)), "0.01");
});
test("carpet registration validates required fields, paired positive dimensions and names", () => {
  const errors = validateInventory(
    "carpets",
    { carpet_code: "lower case", name: "A", length_cm: "2", width_cm: "" },
    [],
    lookups,
  );
  for (const field of [
    "carpet_code",
    "name",
    "registration_branch_id",
    "category_id",
    "width_cm",
  ])
    assert.ok(errors[field]);
  assert.deepEqual(
    validateInventory(
      "carpets",
      {
        carpet_code: "C-01",
        name: "Wool carpet",
        registration_branch_id: "1",
        category_id: "1",
        length_cm: "200",
        width_cm: "150",
      },
      [],
      lookups,
    ),
    {},
  );
});
test("purchase posting validates per-carpet prices, base rate, dates and at least one line", () => {
  const values = {
    purchase_no: "P-1",
    branch_id: "1",
    weaver_id: "1",
    purchase_on: today,
    currency_id: "1",
    rate_to_base: "1",
  };
  assert.ok(validateInventory("purchases", values, [], lookups).items);
  const line = {
    carpet_id: 1,
    expected_sequence: 0,
    label: "C1",
    unit_price: "1.001",
  };
  assert.ok(
    validateInventory("purchases", values, [line], lookups)[
      "items.0.unit_price"
    ],
  );
  assert.ok(
    validateInventory(
      "purchases",
      { ...values, rate_to_base: "2" },
      [{ ...line, unit_price: "100.10" }],
      lookups,
    ).rate_to_base,
  );
  assert.deepEqual(
    validateInventory(
      "purchases",
      values,
      [{ ...line, unit_price: "100.10" }],
      lookups,
    ),
    {},
  );
});
test("receipt and transfer forms require locations, different destinations and carpets", () => {
  assert.ok(
    validateInventory(
      "receipts",
      { receipt_no: "R1", received_on: today },
      [],
      lookups,
    ).location_id,
  );
  assert.ok(
    validateInventory(
      "transfers",
      {
        transfer_no: "T1",
        transferred_on: today,
        from_location_id: "1",
        to_location_id: "1",
      },
      [],
      lookups,
    ).to_location_id,
  );
});
test("purchase settlement rejects overpayment, insufficient cash and inaccurate same-currency rates", () => {
  const values = {
    payment_no: "PAY1",
    payment_type: "purchase",
    branch_id: "1",
    account_id: "1",
    purchase_id: "1",
    amount: "100.05",
    rate_to_base: "1",
    purchase_units_per_payment_unit: "1",
    payment_on: today,
    reason: "Partial settlement",
  };
  const purchase = {
    id: 1,
    branch_id: 1,
    currency_id: 1,
    purchase_on: "2026-01-01",
    remaining_amount: "100.00",
  };
  assert.ok(
    validateInventory("payments", values, [], lookups, purchase).amount,
  );
  assert.ok(
    validateInventory(
      "payments",
      { ...values, amount: "2000" },
      [],
      lookups,
      purchase,
    ).amount,
  );
  assert.ok(
    validateInventory(
      "payments",
      { ...values, amount: "1", purchase_units_per_payment_unit: "2" },
      [],
      lookups,
      purchase,
    ).purchase_units_per_payment_unit,
  );
  assert.deepEqual(
    validateInventory(
      "payments",
      { ...values, amount: "50" },
      [],
      lookups,
      purchase,
    ),
    {},
  );
});
test("bonus requires weaver, reason and expense category, without a purchase allocation", () => {
  const values = {
    payment_no: "BONUS1",
    payment_type: "additional",
    branch_id: "1",
    account_id: "1",
    amount: "10",
    rate_to_base: "1",
    payment_on: today,
    reason: "Quality bonus",
  };
  assert.ok(
    validateInventory("payments", values, [], lookups).expense_category_id,
  );
  assert.deepEqual(
    validateInventory(
      "payments",
      { ...values, weaver_id: "1", expense_category_id: "1" },
      [],
      lookups,
    ),
    {},
  );
});
