import assert from "node:assert/strict";
import test from "node:test";
import { businessFields, paymentFields, validateBusiness } from "../src/lib/business.ts";
import { inventoryToday } from "../src/lib/inventory.ts";

const today = inventoryToday();
const lookups = { company: { base_currency_id: 1, timezone: "Asia/Kabul" }, branches: [{ id: 1 }], locations: [{ id: 1, branch_id: 1 }],
  currencies: [{ id: 1, decimal_places: 2 }, { id: 2, decimal_places: 0 }], weavers: [{ id: 1 }], suppliers: [{ id: 2 }], customers: [{ id: 3 }],
  accounts: [{ id: 1, currency_id: 1, balance: "1000" }], materials: [{ id: 1 }], expense_categories: [{ id: 1 }] };
const purchase = { branch_id: "1", seller_type: "weaver", weaver_id: "1", currency_id: "1", rate_to_base: "1", purchase_on: today, location_id: "1" };
const line = { carpet_id: 1, expected_sequence: 2, unit_price: "100.10", discount: "0", cost_weight: "1", label: "C1" };
const check = (resource, values, lines = [], action = "create") => validateBusiness(businessFields(resource, action, values), values, lines, resource, action, lookups);

test("purchase and receive requires a destination and generates its reference outside the form", () => {
  assert.deepEqual(check("purchases", purchase, [line]), {});
  assert.ok(check("purchases", { ...purchase, location_id: "" }, [line]).location_id);
  assert.equal(businessFields("purchases", "create", purchase).some((f) => f.key === "purchase_no"), false);
  assert.ok(check("purchases", purchase, []).items);
  assert.ok(check("purchases", purchase, [line, line]).items);
});
test("buying from a supplier uses the supplier field and validates quantities and price precision", () => {
  const values = { ...purchase, seller_type: "supplier", supplier_id: "2", currency_id: "2" };
  assert.ok(check("purchases", values, [line])["items.0.unit_price"]);
  assert.deepEqual(check("purchases", values, [{ ...line, unit_price: "100" }]), {});
  assert.ok(check("purchases", { ...values, supplier_id: "99" }, [line]).supplier_id);
});
test("production requires individual carpets, dates and positive allocation weights", () => {
  const values = { branch_id: "1", weaver_id: "1", currency_id: "1", rate_to_base: "1", ordered_on: today, due_on: today, description: "Wool production" };
  assert.deepEqual(check("production", values, [line]), {});
  assert.ok(check("production", values, [{ ...line, cost_weight: "0" }])["items.0.cost_weight"]);
  assert.ok(check("production", { ...values, due_on: "2026-02-30" }, [line]).due_on);
  assert.ok(check("production", { ...values, rate_to_base: "2" }, [line]).rate_to_base);
});
test("partnership agreements require sharing basis, percentages and written terms", () => {
  const values = { branch_id: "1", weaver_id: "1", currency_id: "1", rate_to_base: "1", ordered_on: today, due_on: today, description: "Partnership carpets", share_basis: "net_cost_recovery", company_share: "60", company_loss_share: "50", agreement_terms: "Company provides materials and partner contributes labor." };
  assert.deepEqual(check("partnerships", values, [line]), {});
  assert.ok(check("partnerships", { ...values, company_share: "101" }, [line]).company_share);
  assert.ok(check("partnerships", { ...values, agreement_terms: "" }, [line]).agreement_terms);
  assert.ok(check("partnerships", { ...values, share_basis: "unsupported" }, [line]).share_basis);
});
test("sales require a customer and reject discounts larger than the price", () => {
  const values = { branch_id: "1", customer_id: "3", currency_id: "1", rate_to_base: "1", sold_on: today, due_on: today };
  assert.deepEqual(check("sales", values, [line]), {});
  assert.ok(check("sales", values, [{ ...line, discount: "100.10" }])["items.0.discount"]);
  assert.ok(check("sales", { ...values, customer_id: "" }, [line]).customer_id);
});
test("payment validation rejects exponents, overdrafts, invalid accounts and rates", () => {
  const values = { account_id: "1", amount: "100", rate_to_base: "1", units_per_payment_unit: "1", payment_on: today, reason: "Partial payment" };
  const validate = (v) => validateBusiness(paymentFields, v, [], "expenses", "pay", lookups);
  assert.deepEqual(validate(values), {});
  assert.ok(validate({ ...values, amount: "1e2" }).amount);
  assert.ok(validate({ ...values, amount: "1001" }).amount);
  assert.ok(validate({ ...values, rate_to_base: "0" }).rate_to_base);
  assert.ok(validate({ ...values, account_id: "999" }).account_id);
});

test("partnership recoveries accept incoming money into an empty account while outgoing settlements require cash", () => {
  const emptyAccount = { ...lookups, accounts: [{ id: 1, currency_id: 1, balance: "0" }] };
  const values = { account_id: "1", amount: "30", rate_to_base: "1", units_per_payment_unit: "1", payment_on: today, reason: "Recover partner advance" };
  assert.deepEqual(validateBusiness(paymentFields, values, [], "partnerships", "settlement-pay", emptyAccount, "in"), {});
  assert.ok(validateBusiness(paymentFields, values, [], "partnerships", "settlement-pay", emptyAccount, "out").amount);
});
