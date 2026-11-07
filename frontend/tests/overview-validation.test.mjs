import { test } from "node:test";
import assert from "node:assert/strict";
import { moneyLabel, dateLabel } from "../src/lib/overview.ts";
import { overviewText } from "../src/lib/overview-i18n.ts";

test("dashboard currency labels retain exact large values, signs and precision", () => {
  assert.equal(moneyLabel("9007199254740993.01", 2, "en"), "9,007,199,254,740,993.01");
  assert.equal(moneyLabel("-0.05", 2, "en"), "−0.05");
  assert.equal(moneyLabel("120.00", 0, "en"), "120");
  assert.equal(moneyLabel("300.30", 2, "fa"), "۳۰۰٫۳۰");
});

test("overview dates use stored calendar days and Dari labels are translated", () => {
  assert.equal(dateLabel("2026-10-19", "en"), "Oct 19");
  for (const label of ["Carpet activity", "Current balances", "Available carpets",
    "View chart data", "Stock corrected", "Refresh overview"])
    assert.notEqual(overviewText("fa", label), label);
});
