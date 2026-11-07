import { execFileSync } from "node:child_process";
import path from "node:path";
import { test, expect, type Page } from "@playwright/test";
import { inventoryToday } from "../../src/lib/inventory";

const backend = path.resolve(process.cwd(), "../backend");
const password = "ResetBrowserOnly!42";
test.beforeEach(() => {
  execFileSync("php", ["scripts/seed-reset-browser.php"], { cwd: backend, stdio: "pipe" });
});
async function login(page: Page, user = "reset_admin") {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill(user);
  await page.getByLabel("Password", { exact: true }).fill(password);
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}
async function settings(page: Page) {
  await page.locator(".sidebar").getByRole("button", { name: "Settings", exact: true }).click();
  await page.getByRole("menuitem", { name: "Company settings", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Company settings", exact: true })).toBeVisible();
}
async function api(page: Page, method: "get" | "post" | "put", resource: string,
  data?: Record<string, unknown>) {
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  const response = await page.request[method](`/api${resource}`, {
    data, headers: { Accept: "application/json", Authorization: `Bearer ${token}`,
      "Idempotency-Key": `reset-browser-${Date.now()}-${Math.random()}` },
  });
  expect(response.ok(), await response.text()).toBe(true);
  return response.json();
}

test("reset review validates passwords, fits Dari mobile and is unavailable to non-admins", async ({ page }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page);
  await settings(page);
  let posts = 0;
  page.on("request", (request) => {
    if (request.method() === "POST" && request.url().endsWith("/api/system/reset")) posts++;
  });
  await page.getByRole("button", { name: "Reset system data", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByText("Users kept", { exact: true })).toBeVisible();
  const submit = dialog.getByRole("button", { name: "Clear all business data", exact: true });
  await expect(submit).toBeDisabled();
  await dialog.getByLabel("Current password", { exact: true }).fill("wrong-password");
  await dialog.locator("#system-reset-confirmation").fill("RESET");
  await expect(submit).toBeDisabled();
  expect(posts).toBe(0);
  await dialog.locator("#system-reset-confirmation").fill("RESET ALL DATA");
  await submit.click();
  await expect(dialog.locator("#system-reset-password-error"))
    .toContainText("Your current password is incorrect.");
  expect(posts).toBe(1);
  await page.screenshot({ path: "test-results/system-reset-review-desktop.png", fullPage: true });
  await dialog.getByRole("button", { name: "Cancel", exact: true }).click();
  await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  await page.getByRole("button", { name: "بازنشانی معلومات سیستم", exact: true }).click();
  await expect(dialog).toHaveAttribute("dir", "rtl");
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 });
    const box = (await dialog.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(box.y + box.height).toBeLessThanOrEqual(845);
    expect(await dialog.evaluate((node) => node.scrollWidth <= node.clientWidth + 1)).toBe(true);
  }
  await expect(dialog.getByText("کاربران حفظ می‌شوند", { exact: true })).toBeVisible();
  await page.screenshot({ path: "test-results/system-reset-review-dari-mobile.png",
    fullPage: true, animations: "disabled" });
  await dialog.getByRole("button", { name: "انصراف", exact: true }).click();
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.getByRole("combobox", { name: "Display language" }).selectOption("en");
  await page.locator(".profile-button").click();
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await login(page, "reset_staff");
  await settings(page);
  await expect(page.getByRole("button", { name: "Reset system data", exact: true })).toHaveCount(0);
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  const denied = await page.request.post("/api/system/reset", {
    data: {}, headers: { Authorization: `Bearer ${token}` },
  });
  expect(denied.status()).toBe(403);
});

test("confirmed reset preserves legacy user timestamps, clears washing data and restores its exact backup", async ({ page }) => {
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page);
  const lookups = (await api(page, "get", "/inventory/lookups")).data;
  const today = inventoryToday(lookups.company.timezone);
  const suffix = Date.now().toString(36).toUpperCase();
  const branch = (await api(page, "post", "/foundation/branches", {
    code: `RESET-${suffix}`, name: "Reset test branch", is_main: false, status: "active",
  })).data.id;
  const category = (await api(page, "post", "/foundation/categories", {
    name: `Reset category ${suffix}`, status: "active",
  })).data.id;
  const province = (await api(page, "post", "/directories/provinces", {
    name: `Reset province ${suffix}`, status: "active",
  })).data.id;
  const district = (await api(page, "post", "/directories/districts", {
    name: `Reset district ${suffix}`, province_id: province, status: "active",
  })).data.id;
  const area = (await api(page, "post", "/directories/areas", {
    name: `Reset area ${suffix}`, district_id: district, status: "active",
  })).data.id;
  const weaver = (await api(page, "post", "/directories/weavers", {
    name: `Reset weaver ${suffix}`, registration_date: today,
    manager_employee_id: null, area_id: area, status: "active",
  })).data.id;
  const location = (await api(page, "post", "/foundation/locations", {
    code: `RESET-L-${suffix}`, name: "Reset shelf", branch_id: branch,
    washing_facility_id: null, location_type: "shelf", status: "active",
  })).data.id;
  const account = (await api(page, "post", "/foundation/accounts", {
    code: `RESET-CASH-${suffix}`, name: "Reset cash", branch_id: branch, account_type: "cash",
    currency_id: lookups.company.base_currency_id, status: "active",
  })).data.id;
  await api(page, "post", "/foundation/ledger", { account_id: account,
    entry_type: "opening", amount: "1000", rate_to_base: "1",
    transaction_date: today, description: "Committed reset test money" });
  const carpet = (await api(page, "post", "/inventory/carpets", {
    carpet_code: `RESET-C-${suffix}`, name: "Reset test carpet",
    registration_branch_id: branch, category_id: category,
  })).data.id;
  const second = (await api(page, "post", "/inventory/carpets", {
    carpet_code: `RESET-C2-${suffix}`, name: "Reset draft carpet",
    registration_branch_id: branch, category_id: category,
  })).data.id;
  const draft = (await api(page, "post", "/inventory/corrections/drafts", {
    purchase_no: `RESET-D-${suffix}`, branch_id: branch, weaver_id: weaver,
    currency_id: lookups.company.base_currency_id, purchase_on: today, rate_to_base: "1",
    items: [{ carpet_id: second, unit_price: "20.00" }],
  })).data.id;
  const postedDraft = (await api(page, "post", `/inventory/corrections/drafts/${draft}/post`,
    { version: 1 })).data.posted_purchase_id;
  await api(page, "post", `/inventory/purchases/${postedDraft}/void`, {
    voided_on: today, reason: "Test cancelled acquisition",
    items: [{ carpet_id: second, expected_sequence: 1 }],
  });
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  const upload = await page.request.post(`/api/inventory/carpets/${carpet}/images`, {
    multipart: { image: { name: "reset.png", mimeType: "image/png",
      buffer: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0ioAAAAASUVORK5CYII=", "base64") } },
    headers: { Accept: "application/json", Authorization: `Bearer ${token}`, "Idempotency-Key": `reset-image-${suffix}` },
  });
  expect(upload.ok(), await upload.text()).toBe(true);
  const purchase = (await api(page, "post", "/inventory/purchases", {
    purchase_no: `RESET-P-${suffix}`, branch_id: branch, weaver_id: weaver,
    currency_id: lookups.company.base_currency_id, purchase_on: today, rate_to_base: "1",
    items: [{ carpet_id: carpet, unit_price: "100.10" }],
  })).data.id;
  await api(page, "post", "/inventory/receipts", { receipt_no: `RESET-R-${suffix}`,
    location_id: location, received_on: today,
    items: [{ carpet_id: carpet, expected_sequence: 1 }] });
  await api(page, "post", "/inventory/corrections/adjustments", {
    adjustment_no: `RESET-A-${suffix}`, branch_id: branch, adjustment_type: "missing",
    adjusted_on: today, reason: "Test missing count",
    items: [{ carpet_id: carpet, expected_sequence: 2 }],
  });
  await api(page, "post", "/inventory/corrections/adjustments", {
    adjustment_no: `RESET-F-${suffix}`, branch_id: branch, adjustment_type: "found",
    location_id: location, adjusted_on: today, reason: "Test found count",
    items: [{ carpet_id: carpet, expected_sequence: 3 }],
  });
  await api(page, "post", "/inventory/payments", {
    payment_no: `RESET-PAY-${suffix}`, branch_id: branch, weaver_id: weaver,
    account_id: account, payment_type: "purchase", amount: "100.10", rate_to_base: "1",
    payment_on: today, reason: "Committed test settlement", allocations: [{
      weaver_purchase_id: purchase, amount_in_payment_currency: "100.10",
      purchase_units_per_payment_unit: "1",
    }],
  });
  await api(page, "post", "/inventory/corrections/returns", {
    return_no: `RESET-RET-${suffix}`, weaver_purchase_id: purchase,
    from_location_id: location, returned_on: today, reason: "Test return",
    items: [{ carpet_id: carpet, expected_sequence: 4 }],
  });
  await api(page, "post", "/inventory/corrections/refunds", {
    refund_no: `RESET-RF-${suffix}`, weaver_purchase_id: purchase, account_id: account,
    amount: "40.00", rate_to_base: "1", purchase_units_per_refund_unit: "1",
    refunded_on: today, reason: "Actual test refund",
  });
  // Populate every immutable Phase 4 table through the real API before reset.
  const facility = (await api(page, "post", "/foundation/facilities", {
    name: `Reset washing facility ${suffix}`, status: "active",
  })).data.id;
  const facilityLocation = (await api(page, "post", "/foundation/locations", {
    code: `RESET-WL-${suffix}`, name: "Reset washing custody", branch_id: null,
    washing_facility_id: facility, location_type: "washing", status: "active",
  })).data.id;
  const washCarpet = (await api(page, "post", "/inventory/carpets", {
    carpet_code: `RESET-WC-${suffix}`, name: "Reset washing carpet",
    registration_branch_id: branch, category_id: category,
  })).data.id;
  await api(page, "post", "/inventory/purchases", {
    purchase_no: `RESET-WP-${suffix}`, branch_id: branch, weaver_id: weaver,
    currency_id: lookups.company.base_currency_id, purchase_on: today, rate_to_base: "1",
    items: [{ carpet_id: washCarpet, unit_price: "50.00" }],
  });
  const washing = (await api(page, "post", "/washing/dispatch", {
    order_no: `RESET-W-${suffix}`, branch_id: branch, facility_id: facility,
    facility_location_id: facilityLocation, sent_on: today,
    items: [{ carpet_id: washCarpet, expected_sequence: 1 }],
  })).data.id;
  const visit = (await api(page, "get", `/washing/orders/${washing}`)).data.items[0].id;
  await api(page, "post", `/washing/orders/${washing}/complete`, {
    completed_on: today, items: [{ washing_order_item_id: visit, expected_sequence: 2 }],
  });
  await api(page, "post", `/washing/orders/${washing}/return`, {
    returned_on: today, location_id: location,
    items: [{ washing_order_item_id: visit, expected_sequence: 3 }],
  });
  const washingBill = (await api(page, "post", "/washing/bills", {
    expense_no: `RESET-WB-${suffix}`, invoice_no: `RESET-WI-${suffix}`,
    branch_id: branch, facility_id: facility, currency_id: lookups.company.base_currency_id,
    rate_to_base: "1", billed_on: today, amount: "10.00",
    allocations: [{ washing_order_item_id: visit, amount: "10.00" }],
  })).data.id;
  const washingPayment = (await api(page, "post", `/washing/bills/${washingBill}/payments`, {
    payment_no: `RESET-WPAY-${suffix}`, account_id: account, amount: "5.00", rate_to_base: "1",
    bill_units_per_payment_unit: "1", payment_on: today, reason: "Reset washing payment coverage",
  })).data.id;
  await api(page, "post", `/washing/payments/${washingPayment}/reverse`, {
    reason: "Reset washing payment reversal coverage",
  });
  await api(page, "post", `/washing/bills/${washingBill}/void`, {
    voided_on: today, reason: "Reset voided washing bill coverage",
  });
  execFileSync("php", ["scripts/verify-reset-browser.php", "capture"], { cwd: backend });
  await settings(page);
  await page.getByRole("button", { name: "Reset system data", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByText("Users kept", { exact: true })).toBeVisible();
  await dialog.getByLabel("Current password", { exact: true }).fill(password);
  await dialog.locator("#system-reset-confirmation").fill("RESET ALL DATA");
  const posted = page.waitForResponse((response) =>
    response.url().endsWith("/api/system/reset") && response.request().method() === "POST");
  await dialog.getByRole("button", { name: "Clear all business data", exact: true }).click();
  const response = await posted;
  expect(response.ok(), await response.text()).toBe(true);
  const reset = (await response.json()).data;
  await expect(dialog.getByRole("heading", { name: "System data reset successfully." }))
    .toBeVisible();
  const evidence = JSON.parse(execFileSync("php", ["scripts/verify-reset-browser.php",
    "verify", reset.reset_id], { cwd: backend, encoding: "utf8" }));
  expect(evidence.backup_restore_matched).toBe(true);
  expect(evidence.authentication_preserved).toBe(true);
  expect(evidence.legacy_user_timestamps_preserved).toBe(true);
  expect(evidence.employee_links_cleared).toBe(true);
  expect(evidence.populated_washing_tables_cleared).toBe(6);
  await dialog.getByRole("button", { name: "Sign in again", exact: true }).click();
  await expect(page.getByRole("button", { name: "Sign in", exact: true })).toBeVisible();
  await login(page);
  const profile = (await api(page, "get", "/me")).data;
  expect(profile.is_administrator).toBe(true);
  const overview = (await api(page, "get", "/dashboard/overview")).data;
  expect(overview.metrics.available).toBe(0);
  expect(overview.finance.cash).toEqual([]);
  await settings(page);
  await expect(page.getByText(/Base currency is not configured\./)).toBeVisible();
  await page.locator(".profile-button").click();
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await login(page, "reset_staff");
  expect((await api(page, "get", "/me")).data.roles).toContain("Branch Manager");
  await page.locator(".profile-button").click();
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await login(page);
  const currency = (await api(page, "post", "/foundation/currencies", {
    code: "USD", name: "US Dollar", symbol: "$", decimal_places: 2, is_active: true,
  })).data.id;
  await api(page, "put", "/foundation/settings", { base_currency_id: currency,
    timezone: "Asia/Kabul", default_locale: "en", reminder_days_before: 3 });
  const freshBranch = (await api(page, "post", "/foundation/branches", {
    code: "AFTER-RESET", name: "New branch after reset", is_main: true, status: "active",
  })).data.id;
  const freshAccount = (await api(page, "post", "/foundation/accounts", {
    code: "AFTER-RESET-CASH", name: "New cash after reset", branch_id: freshBranch, account_type: "cash",
    currency_id: currency, status: "active",
  })).data.id;
  await api(page, "post", "/foundation/ledger", { account_id: freshAccount,
    entry_type: "opening", amount: "10.00", rate_to_base: "1",
    transaction_date: today, description: "New data after reset" });
  const ledger = await api(page, "get", `/foundation/ledger?account_id=${freshAccount}`);
  expect(ledger.balance).toBe("10.00");
});
