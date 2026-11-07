import assert from "node:assert/strict";
import test from "node:test";
import { emptyFactor, emptyLine, positiveDecimal, validateFields, validateOrder } from "../src/lib/manager-production.ts";

const today = "2026-10-24";
const values = { ordered_on: today, due_on: "2026-11-24", rate_to_base: "1" };
const line = { ...emptyLine(), name: "Chobi floral", category_id: "1", rate: "150", target_quantity: "4" };

test("manager orders describe target designs without requiring preregistered carpets", () => {
  assert.deepEqual(validateOrder(values, [line], [], today), {});
  assert.deepEqual(validateOrder(values, [{ ...line, rate: "0" }], [{ ...emptyFactor(), label: "Finishing", value: "80" }], today), {});
  assert.ok(validateOrder(values, [], [], today).lines);
  assert.ok(validateOrder(values, [{ ...line, target_quantity: "10001" }], [], today)["lines.0.target_quantity"]);
});

test("planned dimensions must be paired and use the actual delivery precision", () => {
  assert.ok(validateOrder(values, [{ ...line, length_cm: "200" }], [], today)["lines.0.width_cm"]);
  assert.deepEqual(validateOrder(values, [{ ...line, length_cm: "200.25", width_cm: "150" }], [], today), {});
  assert.ok(validateOrder(values, [{ ...line, length_cm: "200.125", width_cm: "150" }], [], today)["lines.0.length_cm"]);
});

test("manual terms and ordered factors require reviewable agreed data", () => {
  assert.ok(validateOrder(values, [{ ...line, pricing_method: "manual" }], [], today)["lines.0.manual_reason"]);
  const factor = { ...emptyFactor(), label: "Complex design", kind: "multiplier", value: "1.25", operation: "deduct" };
  assert.ok(validateOrder(values, [line], [factor], today)["factors.0.operation"]);
  assert.deepEqual(validateOrder(values, [line], [{ ...factor, operation: "add" }], today), {});
  assert.ok(validateOrder(values, [line], [{ ...factor, kind: "rate_quantity", operation: "add", quantity: "0" }], today)["factors.0.quantity"]);
});

test("dates and choices cannot silently accept future movements or unknown records", () => {
  const fields = [{ key: "lot_id", label: "Lot", type: "select", options: [{ value: "1", label: "Wool" }] }, { key: "moved_on", label: "Date", type: "date" }];
  assert.deepEqual(validateFields(fields, { lot_id: "1", moved_on: today }, today), {});
  assert.ok(validateFields(fields, { lot_id: "2", moved_on: "2026-10-25" }, today).lot_id);
  assert.ok(validateFields(fields, { lot_id: "1", moved_on: "2026-10-25" }, today).moved_on);
  assert.equal(positiveDecimal("-1"), false);
  assert.equal(positiveDecimal("1e3"), false);
  assert.equal(positiveDecimal("1.125"), true);
});
