import { execFileSync } from "node:child_process";
import path from "node:path";
import type { Locator } from "@playwright/test";
import { inventoryToday } from "../../src/lib/inventory";
import { foundationText } from "../../src/lib/foundation-i18n";
import { washingText } from "../../src/lib/washing-i18n";
import { expect, test, openSidebarPage, type Page } from "./fixtures";

type Fixture = {
  suffix: string;
  branch: number;
  other: number;
  location: number;
  destination: number;
  category: number;
  weaver: number;
  currency: number;
  account: number;
  facility: number;
  otherFacility: number;
  facilityLocation: number;
  otherFacilityLocation: number;
  facilityUsername: string;
};

function fixture(): Fixture {
  const cwd = path.resolve(process.cwd(), "../backend");
  const run = (script: string) => JSON.parse(execFileSync("php", [script], {
    cwd, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"],
  }));
  return { ...run("scripts/seed-inventory-browser.php"), ...run("scripts/seed-washing-browser.php") };
}

async function login(page: Page, username = "browser_admin") {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill(username);
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

async function headers(page: Page) {
  expect(new URL(page.url()).origin).toBe("http://127.0.0.1:3102");
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  expect(token).toBeTruthy();
  return {
    Authorization: `Bearer ${token}`,
    Accept: "application/json",
    "Idempotency-Key": `washing-browser-${Date.now()}-${Math.random()}`,
  };
}

async function api(page: Page, method: "get" | "post", endpoint: string, data?: Record<string, unknown>) {
  const response = await page.request[method](`/api${endpoint}`, { headers: await headers(page), data });
  expect(response.ok(), `${method.toUpperCase()} ${endpoint}: ${await response.text()}`).toBe(true);
  return response.json();
}

async function preparePurchase(page: Page, f: Fixture, count = 2) {
  const lookups = (await api(page, "get", "/inventory/lookups")).data;
  const today = inventoryToday(lookups.company.timezone);
  const carpets: Array<{ id: number; code: string; sequence: number }> = [];
  for (let index = 0; index < count; index++) {
    const code = `W-${f.suffix}-${index + 1}`;
    const carpet = (await api(page, "post", "/inventory/carpets", {
      carpet_code: code,
      name: `Washing browser carpet ${index + 1}`,
      registration_branch_id: f.branch,
      category_id: f.category,
    })).data;
    carpets.push({ id: carpet.id, code, sequence: 0 });
  }
  await api(page, "post", "/inventory/purchases", {
    purchase_no: `WP-${f.suffix}`,
    branch_id: f.branch,
    weaver_id: f.weaver,
    purchase_on: today,
    currency_id: f.currency,
    rate_to_base: "1",
    items: carpets.map((carpet, index) => ({ carpet_id: carpet.id, unit_price: index ? "200.20" : "100.10" })),
  });
  for (const carpet of carpets) {
    const current = (await api(page, "get", `/inventory/carpets/${carpet.id}`)).data;
    carpet.sequence = Number(current.sequence_no);
  }
  return { carpets, today };
}

async function search(page: Page, value: string) {
  await page.getByRole("textbox", { name: "Search records", exact: true }).fill(value);
  await page.getByRole("button", { name: "Search", exact: true }).click();
}

async function findRow(page: Page, label: string, reference: string) {
  await openSidebarPage(page, label);
  await search(page, reference);
  const row = page.getByRole("row").filter({ hasText: reference });
  await expect(row).toBeVisible();
  return row;
}

async function expectFits(page: Page, locator: Locator) {
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  const bounds = (await locator.boundingBox())!;
  expect(bounds.x).toBeGreaterThanOrEqual(-1);
  expect(bounds.x + bounds.width).toBeLessThanOrEqual(page.viewportSize()!.width + 1);
}

test("washing custody, partial completion and stock return, billed costs and separate reversible payments work end to end", async ({ page }) => {
  test.setTimeout(240_000);
  const f = fixture();
  await page.setViewportSize({ width: 1440, height: 1000 });
  const errors: string[] = [];
  const commands: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("request", (request) => {
    if (request.method() === "POST" && request.url().includes("/api/washing/")) commands.push(request.url());
  });
  await login(page);
  const { carpets, today } = await preparePurchase(page, f);
  const batchNo = `WB-${f.suffix}`;
  const billNo = `BILL-${f.suffix}`;
  const dialog = page.getByRole("dialog");

  await openSidebarPage(page, "Washing batches");
  await page.getByRole("button", { name: "Send to washing", exact: true }).click();
  await dialog.getByRole("button", { name: "Send to washing", exact: true }).click();
  await expect(dialog.getByText("This field is required.", { exact: true }).first()).toBeVisible();
  expect(commands).toHaveLength(0);
  await dialog.getByLabel("Batch reference", { exact: true }).fill(batchNo);
  await dialog.getByLabel("Branch", { exact: true }).selectOption(String(f.branch));
  await dialog.getByLabel("Washing facility", { exact: true }).selectOption(String(f.facility));
  await dialog.getByLabel("Facility location", { exact: true }).selectOption(String(f.facilityLocation));
  await dialog.getByLabel("Dispatch date", { exact: true }).fill(today);
  await dialog.getByRole("button", { name: "Find carpets", exact: true }).click();
  for (const carpet of carpets)
    await dialog.getByRole("checkbox", { name: new RegExp(carpet.code) }).check();
  await dialog.getByRole("button", { name: "Send to washing", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  let row = await findRow(page, "Washing batches", batchNo);
  const disclosure = row.locator("button[aria-expanded][aria-controls]");
  await disclosure.focus();
  await disclosure.press("Enter");
  await expect(disclosure).toHaveAttribute("aria-expanded", "true");
  await expect(page.locator(`[id="${await disclosure.getAttribute("aria-controls")}"]`)).toContainText(`Washing custody`);
  await disclosure.press("Space");
  await expect(disclosure).toHaveAttribute("aria-expanded", "false");
  await row.getByRole("button", { name: "View", exact: true }).click();
  await expect(dialog).toContainText(carpets[0].code);
  await expect(dialog).toContainText(carpets[1].code);
  await dialog.getByRole("button", { name: "Mark completed", exact: true }).click();
  await dialog.getByLabel("Completion date", { exact: true }).fill(today);
  await dialog.getByRole("checkbox", { name: new RegExp(carpets[0].code) }).check();
  await dialog.getByRole("button", { name: "Mark completed", exact: true }).click();
  await expect(dialog.getByLabel("Completion date", { exact: true })).toHaveCount(0);
  await dialog.getByRole("button", { name: "Return to stock", exact: true }).click();
  await dialog.getByLabel("Return date", { exact: true }).fill(today);
  await dialog.getByLabel("Stock location", { exact: true }).selectOption(String(f.location));
  await dialog.getByRole("checkbox", { name: new RegExp(carpets[0].code) }).check();
  await dialog.getByRole("button", { name: "Return to stock", exact: true }).click();
  await expect(dialog.getByLabel("Return date", { exact: true })).toHaveCount(0);
  await dialog.getByRole("button", { name: "Close", exact: true }).first().click();

  const returned = (await api(page, "get", `/inventory/carpets/${carpets[0].id}`)).data;
  expect(returned.stock_status).toBe("in_stock");
  expect(Number(returned.location_id)).toBe(f.location);
  const washing = (await api(page, "get", `/inventory/carpets/${carpets[1].id}`)).data;
  expect(Number(washing.location_id)).toBe(f.facilityLocation);

  await openSidebarPage(page, "Washing facility stock");
  await search(page, carpets[1].code);
  await expect(page.getByRole("table")).toContainText(carpets[1].code);
  await expect(page.getByRole("table")).not.toContainText(carpets[0].code);
  const downloadPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "Export CSV", exact: true }).click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toBe("washing-facility-stock.csv");
  let report = "";
  const reportStream = await download.createReadStream();
  for await (const chunk of reportStream!) report += chunk.toString();
  expect(report).toContain(carpets[1].code);
  expect(report).not.toContain(carpets[0].code);
  await page.screenshot({ path: "test-results/washing-facility-custody.png", fullPage: true });

  await page.locator(".theme-toggle").click();
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  await openSidebarPage(page, "Washing costs");
  await page.getByRole("button", { name: "Record washing bill", exact: true }).click();
  await dialog.getByLabel("Bill reference", { exact: true }).fill(billNo);
  await dialog.getByLabel("Invoice number", { exact: true }).fill(`INV-${f.suffix}`);
  await dialog.getByLabel("Branch", { exact: true }).selectOption(String(f.branch));
  await dialog.getByLabel("Washing facility", { exact: true }).selectOption(String(f.facility));
  await dialog.getByLabel("Currency", { exact: true }).selectOption(String(f.currency));
  await dialog.getByLabel("Total amount", { exact: true }).fill("30.30");
  await dialog.getByRole("button", { name: "Find visits", exact: true }).click();
  for (const carpet of carpets)
    await dialog.getByRole("checkbox", { name: new RegExp(carpet.code) }).check();
  await dialog.getByLabel(`Amount for ${carpets[0].code}`, { exact: true }).fill("10.10");
  await dialog.getByLabel(`Amount for ${carpets[1].code}`, { exact: true }).fill("20.19");
  const beforeInvalidBill = commands.length;
  await dialog.getByRole("button", { name: "Record washing bill", exact: true }).click();
  await expect(dialog.getByText("Allocated amounts must equal the bill total.", { exact: true }).first()).toBeVisible();
  expect(commands).toHaveLength(beforeInvalidBill);
  await dialog.getByLabel(`Amount for ${carpets[1].code}`, { exact: true }).fill("20.20");
  await dialog.getByRole("button", { name: "Record washing bill", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  row = await findRow(page, "Washing costs", billNo);
  await expect(row).toContainText("30.30");
  const allocatedCarpet = (await api(page, "get", `/inventory/carpets/${carpets[0].id}`)).data;
  expect(allocatedCarpet.washing_costs[0].total_amount).toBe("10.10");
  let accounts = (await api(page, "get", "/inventory/lookups")).data.accounts;
  expect(accounts.find((account: { id: number }) => account.id === f.account).balance).toBe("5000.00");
  await row.getByRole("button", { name: "View", exact: true }).click();
  await dialog.getByRole("button", { name: "Record payment", exact: true }).click();
  await dialog.getByLabel("Payment reference", { exact: true }).fill(`W-PAY-${f.suffix}`);
  await dialog.getByLabel("Account", { exact: true }).selectOption(String(f.account));
  await dialog.getByLabel("Reason", { exact: true }).fill("Part payment of the washing facility invoice");
  await dialog.getByLabel("Amount", { exact: true }).fill("30.31");
  const beforeOverpayment = commands.length;
  await dialog.getByRole("button", { name: "Record payment", exact: true }).click();
  await expect(dialog.getByText("Payment exceeds the outstanding bill balance.", { exact: true }).first()).toBeVisible();
  expect(commands).toHaveLength(beforeOverpayment);
  await dialog.getByLabel("Amount", { exact: true }).fill("10.10");
  await dialog.getByRole("button", { name: "Record payment", exact: true }).click();
  await expect(dialog.getByLabel("Payment reference", { exact: true })).toHaveCount(0);
  await expect(dialog).toContainText("20.20");
  await expect(dialog).toContainText(`W-PAY-${f.suffix}`);
  accounts = (await api(page, "get", "/inventory/lookups")).data.accounts;
  expect(accounts.find((account: { id: number }) => account.id === f.account).balance).toBe("4989.90");
  await page.screenshot({ path: "test-results/washing-cost-payment.png", fullPage: true });
  await dialog.getByRole("button", { name: "Reverse payment", exact: true }).click();
  await dialog.getByLabel("Reason", { exact: true }).fill("Correcting a browser test payment entered in error");
  await dialog.getByRole("button", { name: "Reverse payment", exact: true }).click();
  await expect(dialog.getByLabel("Reason", { exact: true })).toHaveCount(0);
  await expect(dialog).toContainText("30.30");
  await dialog.getByRole("button", { name: "Void bill", exact: true }).click();
  await dialog.getByLabel("Reason", { exact: true }).fill("Replacing an incorrect washing cost voucher");
  await dialog.getByRole("button", { name: "Void bill", exact: true }).click();
  await expect(dialog.getByLabel("Reason", { exact: true })).toHaveCount(0);
  await expect(dialog.getByRole("button", { name: "Record payment", exact: true })).toHaveCount(0);
  await expect(dialog.getByText("Void", { exact: true }).first()).toBeVisible();
  await dialog.getByRole("button", { name: "Close", exact: true }).first().click();
  accounts = (await api(page, "get", "/inventory/lookups")).data.accounts;
  expect(accounts.find((account: { id: number }) => account.id === f.account).balance).toBe("5000.00");
  expect((await api(page, "get", `/inventory/carpets/${carpets[0].id}`)).data.washing_costs).toEqual([]);

  // Finish the remaining carpet, then wash the first carpet again after a branch transfer.
  const batches = (await api(page, "get", `/washing/orders?search=${batchNo}`)).data;
  let batch = (await api(page, "get", `/washing/orders/${batches[0].id}`)).data;
  const pending = batch.items.find((item: { state: string }) => item.state === "sent");
  await api(page, "post", `/washing/orders/${batch.id}/complete`, {
    completed_on: today, items: [{ washing_order_item_id: pending.id, expected_sequence: pending.current_sequence }],
  });
  batch = (await api(page, "get", `/washing/orders/${batch.id}`)).data;
  const completed = batch.items.find((item: { state: string }) => item.state === "completed");
  await api(page, "post", `/washing/orders/${batch.id}/return`, {
    returned_on: today, location_id: f.location,
    items: [{ washing_order_item_id: completed.id, expected_sequence: completed.current_sequence }],
  });
  let tracked = (await api(page, "get", `/inventory/carpets/${carpets[0].id}`)).data;
  await api(page, "post", "/inventory/transfers", {
    transfer_no: `W-TRANSFER-${f.suffix}`, from_location_id: f.location,
    to_location_id: f.destination, transferred_on: today,
    items: [{ carpet_id: tracked.id, expected_sequence: tracked.sequence_no }],
  });
  tracked = (await api(page, "get", `/inventory/carpets/${carpets[0].id}`)).data;
  const secondVisit = (await api(page, "post", "/washing/dispatch", {
    order_no: `REWASH-${f.suffix}`, branch_id: f.other, facility_id: f.facility,
    facility_location_id: f.facilityLocation, sent_on: today,
    items: [{ carpet_id: tracked.id, expected_sequence: tracked.sequence_no }],
  })).data;
  tracked = (await api(page, "get", `/inventory/carpets/${carpets[0].id}`)).data;
  expect(tracked.stock_status).toBe("at_washing");
  expect(Number(tracked.custody_branch_id)).toBe(f.other);
  expect(tracked.washing_visits).toHaveLength(2);
  const nextBatch = (await api(page, "get", `/washing/orders/${secondVisit.id}`)).data;
  await api(page, "post", `/washing/orders/${secondVisit.id}/complete`, {
    completed_on: today,
    items: [{ washing_order_item_id: nextBatch.items[0].id, expected_sequence: tracked.sequence_no }],
  });
  tracked = (await api(page, "get", `/inventory/carpets/${carpets[0].id}`)).data;
  await api(page, "post", `/washing/orders/${secondVisit.id}/return`, {
    returned_on: today, location_id: f.destination,
    items: [{ washing_order_item_id: nextBatch.items[0].id, expected_sequence: tracked.sequence_no }],
  });
  tracked = (await api(page, "get", `/inventory/carpets/${carpets[0].id}`)).data;
  expect(tracked.stock_status).toBe("in_stock");
  expect(Number(tracked.location_id)).toBe(f.destination);
  expect(errors).toEqual([]);
});

test("a facility user sees only assigned custody, completes washing in mobile Dari, and cannot access financial data", async ({ page }) => {
  test.setTimeout(180_000);
  const f = fixture();
  await login(page);
  const { carpets, today } = await preparePurchase(page, f);
  const own = (await api(page, "post", "/washing/dispatch", {
    order_no: `OWN-${f.suffix}`,
    branch_id: f.branch,
    facility_id: f.facility,
    facility_location_id: f.facilityLocation,
    sent_on: today,
    items: [{ carpet_id: carpets[0].id, expected_sequence: carpets[0].sequence }],
  })).data;
  const foreign = (await api(page, "post", "/washing/dispatch", {
    order_no: `OTHER-${f.suffix}`,
    branch_id: f.branch,
    facility_id: f.otherFacility,
    facility_location_id: f.otherFacilityLocation,
    sent_on: today,
    items: [{ carpet_id: carpets[1].id, expected_sequence: carpets[1].sequence }],
  })).data;
  await api(page, "post", "/logout");
  await page.evaluate(() => localStorage.removeItem("csms_token"));
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page, f.facilityUsername);
  const scopedHeaders = await headers(page);
  for (const endpoint of ["/washing/bills", `/washing/bills/${own.id}`]) {
    const denied = await page.request.get(`/api${endpoint}`, { headers: scopedHeaders });
    expect(denied.status()).toBe(403);
  }
  const otherOrder = await page.request.get(`/api/washing/orders/${foreign.id}`, { headers: scopedHeaders });
  expect(otherOrder.status()).toBe(404);
  const deniedDispatch = await page.request.post("/api/washing/dispatch", { headers: scopedHeaders, data: {} });
  expect(deniedDispatch.status()).toBe(403);
  const ownData = (await api(page, "get", `/washing/orders/${own.id}`)).data;
  expect(ownData.order_no).toBe(`OWN-${f.suffix}`);
  for (const financialKey of ["amount", "base_amount", "unit_price", "washing_cost", "allocations", "payments"])
    expect(JSON.stringify(ownData)).not.toContain(`"${financialKey}":`);
  const custody = (await api(page, "get", "/washing/stock")).data;
  expect(JSON.stringify(custody)).toContain(carpets[0].code);
  expect(JSON.stringify(custody)).not.toContain(carpets[1].code);

  await page.getByRole("combobox", { name: "Display language", exact: true }).selectOption("fa");
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await page.locator(".theme-toggle").click();
  await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
  await openSidebarPage(page, "Washing batches", "fa");
  await expect(page.getByRole("table")).toContainText(`OWN-${f.suffix}`);
  await expect(page.getByRole("table")).not.toContainText(`OTHER-${f.suffix}`);
  await expect(page.getByRole("button", { name: washingText("fa", "Send to washing"), exact: true })).toHaveCount(0);
  await expect(page.getByRole("menuitem", { name: foundationText("fa", "Washing costs"), exact: true })).toHaveCount(0);
  await expectFits(page, page.locator(".main-content"));
  const row = page.getByRole("row").filter({ hasText: `OWN-${f.suffix}` });
  await row.getByRole("button", { name: washingText("fa", "View"), exact: true }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toHaveAttribute("dir", "rtl");
  await expect(dialog.getByRole("button", { name: washingText("fa", "Return to stock"), exact: true })).toHaveCount(0);
  await expect(dialog.getByRole("button", { name: washingText("fa", "Record payment"), exact: true })).toHaveCount(0);
  await dialog.getByRole("button", { name: washingText("fa", "Mark completed"), exact: true }).click();
  await dialog.getByRole("checkbox", { name: new RegExp(carpets[0].code) }).check();
  await dialog.getByRole("button", { name: washingText("fa", "Mark completed"), exact: true }).click();
  await expect(dialog.getByLabel(washingText("fa", "Completion date"), { exact: true })).toHaveCount(0);
  await expect(dialog.getByText(washingText("fa", "Completed"), { exact: true }).first()).toBeVisible();
  await expectFits(page, dialog);
  await page.setViewportSize({ width: 320, height: 844 });
  await expectFits(page, dialog);
  await page.screenshot({ path: "test-results/washing-facility-mobile-dari.png", fullPage: true });
});
