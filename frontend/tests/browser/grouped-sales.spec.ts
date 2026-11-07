import { execFileSync } from "node:child_process";
import path from "node:path";
import type { Locator } from "@playwright/test";
import { expect, test, openSidebarPage, type Page } from "./fixtures";
import { businessText } from "../../src/lib/business-i18n";

type Fixture = { suffix: string; branch: number; location: number; category: number; currency: number; facility: number; facilityLocation: number };
type StockGroup = { stock_key: string; name: string; available_quantity: number; branch_id: number };
type Sale = { id: number; sale_no: string; total_amount: string; items: { carpet_id: number; carpet_code: string; unit_price: string; discount: string }[] };
type Scenario = Fixture & { name: string; customer: number; stockKey: string; codes: string[] };
const label = (name: string) => new RegExp(`^${name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}(?: [*])?$`);

function fixture(): Fixture {
  const cwd = path.resolve(process.cwd(), "../backend");
  const seed = (script: string) => JSON.parse(execFileSync("php", [script], { cwd, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }));
  return { ...seed("scripts/seed-inventory-browser.php"), ...seed("scripts/seed-washing-browser.php") };
}

async function login(page: Page) {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

async function api(page: Page, endpoint: string, data?: Record<string, unknown>) {
  expect(new URL(page.url()).origin).toBe("http://127.0.0.1:3102");
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  const headers = { Authorization: `Bearer ${token}`, "Idempotency-Key": crypto.randomUUID(), Accept: "application/json" };
  const response = data ? await page.request.post(`/api${endpoint}`, { headers, data }) : await page.request.get(`/api${endpoint}`, { headers });
  expect(response.ok(), `${endpoint}: ${await response.text()}`).toBe(true);
  return response.json();
}

async function readyCarpets(page: Page): Promise<Scenario> {
  const f = fixture();
  await login(page);
  const name = `Grouped Chobi ${f.suffix}`;
  const manager = (await api(page, "/production/managers", { name: `Sales manager ${f.suffix}`, branch_id: f.branch, status: "active" })).data;
  const customer = (await api(page, "/customers", { name: `Sales customer ${f.suffix}`, customer_type: "retail", registration_date: "2026-01-10", status: "active" })).data.id;
  await api(page, "/production/setup", { branch_id: f.branch, materials_location_id: f.location, finished_location_id: f.location, facility_id: f.facility, facility_location_id: f.facilityLocation, payment_trigger: "stock_acceptance" });
  const order = (await api(page, "/production/orders", { branch_id: f.branch, manager_id: manager.id, currency_id: f.currency, rate_to_base: "1", ordered_on: "2026-01-10", due_on: "2026-01-11", description: "Grouped sales browser workflow", payment_trigger: "stock_acceptance", lines: [{ name, category_id: f.category, target_quantity: 2, pricing_method: "piece", rate: "100" }], factors: [] })).data;
  const document = (await api(page, `/production/orders/${order.id}`)).data;
  await api(page, `/production/orders/${order.id}/receive`, { delivered_on: "2026-01-10", usages: [], no_materials_used: true, items: [1, 2].map(() => ({ line_id: document.lines[0].id, length_cm: "300", width_cm: "200" })) });
  const workflow = (await api(page, `/production/orders/${order.id}/workflow`)).data;
  await api(page, `/production/orders/${order.id}/accept`, { returned_on: "2026-01-10", confirmed_received: true, record_cost_later: false, washing_amount: "20", items: workflow.washing_items.map((item: { washing_order_item_id: number; expected_sequence: number }) => ({ washing_order_item_id: item.washing_order_item_id, expected_sequence: item.expected_sequence })) });
  const received = (await api(page, `/production/orders/${order.id}`)).data;
  const codes = received.deliveries.flatMap((delivery: { items: { carpet_code: string }[] }) => delivery.items.map((item) => item.carpet_code));
  const groups: StockGroup[] = (await api(page, "/business/lookups")).data.stock_groups;
  const group = groups.find((item) => item.name === name);
  expect(group?.available_quantity).toBe(2);
  return { ...f, name, customer, stockKey: group!.stock_key, codes };
}

async function openSale(page: Page, f: Scenario, locale = "en") {
  const t = (text: string) => businessText(locale, text);
  await openSidebarPage(page, "Sales invoices", locale);
  await page.getByRole("button", { name: t("Sell carpets"), exact: true }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel(label(t("Branch"))).selectOption(String(f.branch));
  await dialog.getByLabel(label(t("Customer"))).selectOption(String(f.customer));
  await dialog.getByRole("searchbox", { name: t("Find a production design"), exact: true }).fill(f.suffix);
  return dialog;
}

async function selectDesign(dialog: Locator, f: Scenario, locale = "en") {
  const option = dialog.getByRole("checkbox", { name: new RegExp(f.name) });
  await expect(option).toHaveCount(1);
  await option.check();
  return dialog.getByRole("region", { name: `${businessText(locale, "Production design")} · ${f.name}`, exact: true });
}

async function saveSale(page: Page, dialog: Locator): Promise<Sale> {
  const response = page.waitForResponse((response) => response.url().endsWith("/api/business/sales") && response.request().method() === "POST");
  await dialog.getByRole("button", { name: "Sell carpets", exact: true }).click();
  const saved = await response;
  expect(saved.ok(), await saved.text()).toBe(true);
  const result = (await saved.json()).data;
  await expect(dialog).not.toBeVisible();
  return (await api(page, `/business/sales/${result.id}`)).data;
}

test("one design option sells one carpet at a time and keeps each carpet traceable", async ({ page }) => {
  test.setTimeout(180_000);
  const f = await readyCarpets(page);
  let dialog = await openSale(page, f);
  await expect(dialog.getByRole("checkbox", { name: new RegExp(f.name) })).toHaveCount(1);
  await expect(dialog).toContainText("Available quantity: 2");
  for (const code of f.codes) await expect(dialog).not.toContainText(code);
  let selected = await selectDesign(dialog, f);
  await selected.getByLabel("Quantity", { exact: true }).fill("1");
  await selected.getByLabel("Unit price", { exact: true }).fill("2000");
  await selected.getByLabel("Discount per carpet", { exact: true }).fill("100");
  await expect(dialog).toContainText("Total: 1900.00");
  const first = await saveSale(page, dialog);
  expect(first.total_amount).toBe("1900.00");
  expect(first.items).toHaveLength(1);
  expect(f.codes).toContain(first.items[0].carpet_code);
  const remaining: StockGroup[] = (await api(page, "/business/lookups")).data.stock_groups;
  expect(remaining.find((group) => group.stock_key === f.stockKey)?.available_quantity).toBe(1);

  dialog = await openSale(page, f);
  await expect(dialog).toContainText("Available quantity: 1");
  selected = await selectDesign(dialog, f);
  await selected.getByLabel("Unit price", { exact: true }).fill("2500");
  const second = await saveSale(page, dialog);
  expect(second.items).toHaveLength(1);
  expect(second.items[0].carpet_id).not.toBe(first.items[0].carpet_id);
  expect([first.items[0].carpet_code, second.items[0].carpet_code].sort()).toEqual(f.codes.sort());
  const final: StockGroup[] = (await api(page, "/business/lookups")).data.stock_groups;
  expect(final.find((group) => group.stock_key === f.stockKey)).toBeUndefined();
});

test("grouped sales reject unavailable quantities and invalid prices before sending a request", async ({ page }) => {
  test.setTimeout(180_000);
  const f = await readyCarpets(page);
  const dialog = await openSale(page, f);
  const selected = await selectDesign(dialog, f);
  let posts = 0;
  page.on("request", (request) => { if (request.url().endsWith("/api/business/sales") && request.method() === "POST") posts++; });
  const quantity = selected.getByLabel("Quantity", { exact: true });
  const price = selected.getByLabel("Unit price", { exact: true });
  await price.fill("2000");
  for (const invalid of ["0", "1.5", "3"]) {
    await quantity.fill(invalid);
    await dialog.getByRole("button", { name: "Sell carpets", exact: true }).click();
    await expect(quantity).toHaveAttribute("aria-invalid", "true");
  }
  await quantity.fill("1");
  for (const invalid of ["0", "-50"]) {
    await price.fill(invalid);
    await dialog.getByRole("button", { name: "Sell carpets", exact: true }).click();
    await expect(price).toHaveAttribute("aria-invalid", "true");
  }
  await price.fill("2000");
  const discount = selected.getByLabel("Discount per carpet", { exact: true });
  await discount.fill("2000");
  await dialog.getByRole("button", { name: "Sell carpets", exact: true }).click();
  await expect(discount).toHaveAttribute("aria-invalid", "true");
  expect(posts).toBe(0);
  expect(((await api(page, "/business/lookups")).data.stock_groups as StockGroup[]).find((group) => group.stock_key === f.stockKey)?.available_quantity).toBe(2);
});

test("quantity two calculates both prices and discounts and records two physical carpet sales", async ({ page }) => {
  test.setTimeout(180_000);
  const f = await readyCarpets(page);
  const dialog = await openSale(page, f);
  const selected = await selectDesign(dialog, f);
  await selected.getByLabel("Quantity", { exact: true }).fill("2");
  await selected.getByLabel("Unit price", { exact: true }).fill("2000");
  await selected.getByLabel("Discount per carpet", { exact: true }).fill("100");
  await expect(dialog).toContainText("Total: 3800.00");
  const sale = await saveSale(page, dialog);
  expect(sale.total_amount).toBe("3800.00");
  expect(sale.items).toHaveLength(2);
  expect(new Set(sale.items.map((item) => item.carpet_id)).size).toBe(2);
  expect(sale.items.map((item) => item.carpet_code).sort()).toEqual(f.codes.sort());
  await page.getByRole("row").filter({ hasText: sale.sale_no }).getByRole("button", { name: "View details", exact: true }).click();
  for (const code of f.codes) await expect(page.getByRole("dialog")).toContainText(code);
});

test("grouped sales are readable on mobile Dari and the close icon protects unsaved quantities", async ({ page }) => {
  test.setTimeout(180_000);
  const f = await readyCarpets(page);
  const errors: string[] = []; page.on("pageerror", (error) => errors.push(error.message));
  await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  const t = (text: string) => businessText("fa", text);
  const dialog = await openSale(page, f, "fa");
  const selected = await selectDesign(dialog, f, "fa");
  await selected.getByLabel(t("Quantity"), { exact: true }).fill("2");
  await selected.getByLabel(t("Unit price"), { exact: true }).fill("2000");
  await expect(dialog).toHaveAttribute("dir", "rtl");
  for (const theme of ["dark", "light"]) {
    // Theme controls are outside the native modal; preserve the live form while checking each theme.
    await page.evaluate((next) => document.documentElement.setAttribute("data-theme", next), theme);
    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 844 });
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
      expect(await dialog.evaluate((element) => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
      await expect(selected.getByLabel(t("Quantity"), { exact: true })).toHaveValue("2");
    }
    await page.screenshot({ path: `test-results/grouped-sales-dari-mobile-${theme}.png`, fullPage: true });
  }
  await dialog.getByRole("button", { name: t("Close"), exact: true }).click();
  await expect(dialog).toContainText(t("Discard unsaved changes?"));
  await dialog.getByRole("button", { name: t("Keep editing"), exact: true }).click();
  await expect(selected.getByLabel(t("Quantity"), { exact: true })).toHaveValue("2");
  await dialog.getByRole("button", { name: t("Close"), exact: true }).click();
  await dialog.getByRole("button", { name: t("Discard"), exact: true }).click();
  await expect(dialog).not.toBeVisible();
  expect(errors).toEqual([]);
});

test("stock changed by another sale is shown inline and preserves the unsaved form", async ({ page }) => {
  test.setTimeout(180_000);
  const f = await readyCarpets(page);
  const dialog = await openSale(page, f);
  const selected = await selectDesign(dialog, f);
  await selected.getByLabel("Quantity", { exact: true }).fill("2");
  await selected.getByLabel("Unit price", { exact: true }).fill("2000");
  await api(page, "/business/sales", { branch_id: f.branch, customer_id: f.customer, currency_id: f.currency, rate_to_base: "1", sold_on: "2026-01-10", due_on: "2026-01-11", stock_groups: [{ stock_key: f.stockKey, quantity: 1, unit_price: "2000", discount: "0" }] });
  const response = page.waitForResponse((response) => response.url().endsWith("/api/business/sales") && response.request().method() === "POST");
  await dialog.getByRole("button", { name: "Sell carpets", exact: true }).click();
  expect((await response).status()).toBe(422);
  await expect(dialog).toBeVisible();
  await expect(selected.getByLabel("Quantity", { exact: true })).toHaveAttribute("aria-invalid", "true");
  await expect(selected.getByLabel("Quantity", { exact: true })).toHaveValue("2");
  await expect(selected.getByLabel("Unit price", { exact: true })).toHaveValue("2000");
  await dialog.getByRole("button", { name: "Refresh stock", exact: true }).click();
  await expect(dialog).toContainText("Available quantity: 1");
  await selected.getByLabel("Quantity", { exact: true }).fill("1");
  const sale = await saveSale(page, dialog);
  expect(sale.items).toHaveLength(1);
});
