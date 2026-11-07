import assert from "node:assert/strict";
import { test } from "node:test";
import { emptyCustomer, todayIso, validCustomerDate, validateCustomer } from "../src/lib/customer-validation.ts";
import { customerLabel, customerText } from "../src/lib/customer-i18n.ts";

const today = "2026-10-17";
const valid = { ...emptyCustomer(today), name: "زهرا احمدی", phone: "+93 70 123 4567" };

test("customer date rejects impossible, ambiguous and unsupported database dates", () => {
  for (const value of ["2026-02-30", "2025-02-29", "2026-1-01", "2026-01-1", "0999-12-31", "0000-01-01", "2026-01-01T00:00:00Z", "not-a-date"]) {
    assert.equal(validCustomerDate(value), false, value);
    assert.ok(validateCustomer({ ...valid, registration_date: value }, today).registration_date);
  }
  for (const value of ["1000-01-01", "2024-02-29", today]) assert.equal(validCustomerDate(value), true, value);
  assert.ok(validateCustomer({ ...valid, registration_date: "2026-10-18" }, today).registration_date);
  assert.deepEqual(validateCustomer(valid, today), {});
});

test("defaults and maximum date follow the company day across browser timezone boundaries", () => {
  const instant = new Date("2026-10-16T22:00:00Z");
  assert.equal(todayIso("Asia/Kabul", instant), "2026-10-17");
  assert.equal(todayIso("America/New_York", instant), "2026-10-16");
  assert.equal(emptyCustomer(today).registration_date, today);
  assert.equal(validateCustomer(valid, today).registration_date, undefined);
});

test("required values, phone syntax, enums and code-point lengths match server rules", () => {
  for (const [field, value] of [["name", " "], ["name", "م"], ["phone", "call me"], ["phone", "123456"], ["customer_type", "gold"], ["status", "unknown"], ["notes", "م".repeat(2001)], ["address", "م".repeat(256)]]) {
    assert.ok(validateCustomer({ ...valid, [field]: value }, today)[field], field);
  }
  assert.deepEqual(validateCustomer({ ...valid, name: "🙂".repeat(150), address: "م".repeat(255), notes: "م".repeat(2000), phone: "" }, today), {});
  assert.ok(validateCustomer({ ...valid, name: "🙂".repeat(151) }, today).name);
  assert.deepEqual(validateCustomer({ ...valid, name: "  Kabul Rugs  ", address: " ".repeat(300), notes: " " }, today), {});
});

test("Dari directory, status and field-error messages are translated without altering data", () => {
  assert.equal(customerText("en", "Customers"), "Customers");
  assert.equal(customerText("fa", "Customers"), "مشتریان");
  assert.equal(customerLabel("fa", "inactive"), "غیرفعال");
  assert.notEqual(customerText("fa", "Customer name is required."), "Customer name is required.");
  assert.equal(customerText("fa", "Server detail"), "Server detail");
});
