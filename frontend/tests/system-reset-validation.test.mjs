import { test } from "node:test";
import assert from "node:assert/strict";
import { validateReset, RESET_CONFIRMATION } from "../src/lib/system-reset.ts";
import { resetText } from "../src/lib/system-reset-i18n.ts";

test("reset requires a password and an exact explicit confirmation", () => {
  assert.deepEqual(Object.keys(validateReset("", "")), ["password", "confirmation"]);
  for (const phrase of ["RESET", "reset all data", " RESET ALL DATA", "RESET ALL DATA "])
    assert.ok(validateReset("correct password", phrase).confirmation);
  assert.ok(validateReset("x".repeat(256), RESET_CONFIRMATION).password);
  assert.deepEqual(validateReset(" password with spaces ", RESET_CONFIRMATION), {});
});

test("reset messages and setup guidance are available in Dari", () => {
  for (const label of ["System reset", "Current password", "Users kept", "Review again",
    "Your current password is incorrect.", "System data reset successfully.", "Sign in again"])
    assert.notEqual(resetText("fa", label), label);
});
