import assert from "node:assert/strict";
import { test } from "node:test";
import {
  FOUNDATION_RESOURCES,
  initialValues,
  validateFoundation,
  foundationPayload,
  validDecimal,
  validDate,
  today,
} from "../src/lib/foundation.ts";

const resource = (key) => FOUNDATION_RESOURCES.find((r) => r.key === key);
const lookups = {
  branches: [{ id: 1 }],
  facilities: [{ id: 2 }],
  currencies: [{ id: 1 }, { id: 2 }],
  roles: [{ id: 3, name: "Administrator" }],
};

test("account creation accepts an automatic code and opening balance without a rate field", () => {
  const item = resource("accounts");
  const form = { ...initialValues(item), name: "Office cash", currency_id: "1", opening_amount: "10000", opening_on: "2026-01-01" };
  assert.deepEqual(validateFoundation(item, form, false, lookups), {});
  assert.equal(item.fields.some((field) => field.key === "opening_rate"), false);
  assert.equal(foundationPayload(item, form).code, null);
  assert.equal("opening_rate" in foundationPayload(item, form), false);
  assert.ok(validateFoundation(item, { ...form, code: "bad code" }, false, lookups).code);
  assert.ok(validateFoundation(item, { ...form, code: "" }, true, lookups).code);
  assert.ok(validateFoundation(item, { ...form, opening_amount: "1.001" }, false, lookups).opening_amount);
});

test("all directories reject an empty form without relying on HTML validation", () => {
  for (const item of FOUNDATION_RESOURCES) {
    assert.ok(
      Object.keys(validateFoundation(item, initialValues(item), false, lookups))
        .length,
    );
  }
});

test("money validation rejects rounding, exponents, negative, zero, and overflow", () => {
  for (const amount of [
    "0",
    "-1",
    "1e3",
    "NaN",
    "1.001",
    "01",
    "99999999999999999",
    "",
  ]) {
    assert.equal(validDecimal(amount, 16, 2), false, amount);
  }
  for (const amount of ["0.01", "100.10", "9999999999999999.99"]) {
    assert.equal(validDecimal(amount, 16, 2), true, amount);
  }
});

test("dates reject impossible days and respect the company timezone", () => {
  assert.equal(validDate("2026-02-30"), false);
  assert.equal(validDate("0001-01-01"), false);
  assert.equal(validDate("2024-02-29"), true);
  assert.equal(validDate("not-a-date"), false);
  assert.match(today("Asia/Kabul"), /^\d{4}-\d{2}-\d{2}$/);
});

test("location requires exactly one owner and matching type", () => {
  const item = resource("locations");
  const form = {
    ...initialValues(item),
    code: "WH-1",
    name: "Main stock",
    branch_id: "1",
  };
  assert.deepEqual(validateFoundation(item, form, false, lookups), {});
  assert.ok(
    validateFoundation(
      item,
      { ...form, washing_facility_id: "2" },
      false,
      lookups,
    ).branch_id,
  );
  assert.ok(
    validateFoundation(
      item,
      { ...form, location_type: "washing" },
      false,
      lookups,
    ).location_type,
  );
  assert.deepEqual(
    validateFoundation(
      item,
      {
        ...form,
        branch_id: "",
        washing_facility_id: "2",
        location_type: "washing",
      },
      false,
      lookups,
    ),
    {},
  );
});

test("a missing location owner is reported on the selector for its location type", () => {
  const item = resource("locations");
  const form = { ...initialValues(item), code: "WH-2", name: "Location owner validation" };
  for (const location_type of ["showroom", "warehouse", "shelf", "other"]) {
    const errors = validateFoundation(item, { ...form, location_type }, false, lookups);
    assert.equal(errors.branch_id, "Choose a branch for this stock location.");
    assert.equal(errors.location_type, undefined);
    assert.equal(errors.washing_facility_id, undefined);
  }
  const washing = validateFoundation(item, { ...form, location_type: "washing" }, false, lookups);
  assert.equal(washing.washing_facility_id, "Choose a washing facility for this location.");
  assert.equal(washing.branch_id, undefined);
  assert.equal(washing.location_type, undefined);
});

test("user scopes and password rules also apply in the client", () => {
  const item = resource("users");
  const form = {
    ...initialValues(item),
    name: "Test User",
    username: "test_user",
    password: "short",
    role_ids: [4],
  };
  const errors = validateFoundation(item, form, false, lookups);
  assert.ok(errors.password);
  assert.ok(
    validateFoundation(
      item,
      { ...form, password: "ش".repeat(37) },
      false,
      lookups,
    ).password,
  );
  assert.ok(errors.branch_ids);
  assert.equal(
    validateFoundation(
      item,
      { ...form, password: "", role_ids: [3] },
      true,
      lookups,
    ).password,
    undefined,
  );
  assert.equal(
    initialValues(item, { id: 1, password: "never-copy-me" }).password,
    "",
  );
});

test("invalid options, references, codes, email, phone, and oversized fields fail", () => {
  const item = resource("employees");
  const errors = validateFoundation(
    item,
    {
      ...initialValues(item),
      name: "x".repeat(151),
      branch_id: "999",
      email: "bad",
      phone: "letters",
      status: "invalid",
    },
    false,
    lookups,
  );
  for (const field of ["name", "branch_id", "email", "phone", "status"])
    assert.ok(errors[field]);
  assert.ok(
    validateFoundation(resource("currencies"), { code: "usd" }, false, lookups)
      .code,
  );
});

test("payload preserves decimal text and converts only reference identifiers", () => {
  const item = resource("exchange-rates");
  const form = {
    base_currency_id: "1",
    quote_currency_id: "2",
    rate: "70.12345678",
    effective_date: "2026-01-01",
  };
  assert.deepEqual(validateFoundation(item, form, false, lookups), {});
  assert.equal(foundationPayload(item, form).rate, "70.12345678");
  assert.equal(foundationPayload(item, form).base_currency_id, 1);
  assert.ok(
    validateFoundation(
      item,
      { ...form, quote_currency_id: "1" },
      false,
      lookups,
    ).quote_currency_id,
  );
});
