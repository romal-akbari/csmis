import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test, openSidebarPage, type Page } from "./fixtures";
import { productionText } from "../../src/lib/manager-production-i18n";

type Fixture = { suffix: string; branch: number; location: number; category: number; currency: number; facility: number; facilityLocation: number };
const field = (name: string) => new RegExp(`^${name}(?: [*])?$`);
function fixture(): Fixture {
  const seed = (script: string) => JSON.parse(execFileSync("php", [script], { cwd: path.resolve(process.cwd(), "../backend"), encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }));
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

test("account opening needs no code or rate and sidebar destinations match their business modules", async ({ page }) => {
  const f = fixture(); await login(page);
  await expect(page.locator(".sidebar").getByRole("button", { name: "Customers", exact: true })).toHaveCount(0);
  await openSidebarPage(page, "Customers"); await expect(page.getByRole("heading", { name: "Customers", exact: true })).toBeVisible();
  await openSidebarPage(page, "Carpet categories"); await expect(page.getByRole("heading", { name: "Carpet categories", exact: true })).toBeVisible();
  await openSidebarPage(page, "Accounts"); await page.getByRole("button", { name: "Add record", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByLabel(field("Code"))).toHaveAttribute("placeholder", "Generated automatically when saved");
  await expect(dialog.getByLabel(field("Rate to base currency"))).toHaveCount(0);
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog.getByLabel(field("Name"))).toHaveAttribute("aria-invalid", "true");
  await dialog.getByLabel(field("Name")).fill(`Auto cash ${f.suffix}`);
  await dialog.getByLabel(field("Currency")).selectOption(String(f.currency));
  await dialog.getByLabel(field("Opening balance")).fill("10000");
  await dialog.getByRole("button", { name: "Save", exact: true }).click(); await expect(dialog).not.toBeVisible();
  const account = (await api(page, `/foundation/accounts?search=Auto%20cash%20${f.suffix}`)).data[0];
  expect(account.code).toMatch(/^ACC-\d{4}-\d{8}$/); expect(account.balance).toBe("10000.00");
  await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  await page.getByRole("button", { name: productionText("fa", "Add record"), exact: true }).click();
  await expect(dialog.getByLabel(field(productionText("fa", "Code")))).toHaveAttribute("placeholder", productionText("fa", "Generated automatically when saved"));
  await expect(dialog.getByLabel(field(productionText("fa", "Rate to base currency")))).toHaveCount(0);
  await dialog.getByRole("button", { name: productionText("fa", "Close"), exact: true }).click(); await expect(dialog).not.toBeVisible();
});

test("stock details separate tool custody from carpets and fit Dari mobile in both themes", async ({ page }) => {
  test.setTimeout(180_000);
  const f = fixture(); await page.setViewportSize({ width: 1440, height: 1000 }); await login(page);
  const errors: string[] = []; page.on("pageerror", (error) => errors.push(error.message));
  const supplier = (await api(page, "/directories/suppliers", { name: `Stock supplier ${f.suffix}`, status: "active" })).data;
  const manager = (await api(page, "/production/managers", { name: `Stock manager ${f.suffix}`, branch_id: f.branch, status: "active" })).data;
  const tool = (await api(page, "/production/tools", { name: `Weaving comb ${f.suffix}`, status: "active" })).data;
  await api(page, "/production/setup", { branch_id: f.branch, materials_location_id: f.location, finished_location_id: f.location, facility_id: f.facility, facility_location_id: f.facilityLocation, payment_trigger: "stock_acceptance" });
  await api(page, "/production/supply-purchases", { supplier_id: supplier.id, branch_id: f.branch, location_id: f.location, currency_id: f.currency, rate_to_base: "1", purchased_on: "2026-01-10", items: [{ kind: "tool", item_id: tool.id, quantity: "5", unit_cost: "100" }] });
  const order = (await api(page, "/production/orders", { branch_id: f.branch, manager_id: manager.id, currency_id: f.currency, rate_to_base: "1", ordered_on: "2026-01-10", due_on: "2026-01-11", description: "Stock overview browser order", payment_trigger: "stock_acceptance", lines: [{ name: `Chobi ${f.suffix}`, category_id: f.category, target_quantity: 2, pricing_method: "piece", rate: "100" }], factors: [] })).data;
  await api(page, `/production/orders/${order.id}/issue`, { moved_on: "2026-01-10", movement_type: "issue", materials: [], tools: [{ tool_id: tool.id, quantity: 2 }], reason: "Issue two combs to manager" });
  const document = (await api(page, `/production/orders/${order.id}`)).data;
  await api(page, `/production/orders/${order.id}/receive`, { delivered_on: "2026-01-10", usages: [], no_materials_used: true, items: [1, 2].map(() => ({ line_id: document.lines[0].id, length_cm: "300", width_cm: "200" })) });
  const context = (await api(page, `/production/orders/${order.id}/workflow`)).data;
  await api(page, `/production/orders/${order.id}/accept`, { returned_on: "2026-01-10", confirmed_received: true, record_cost_later: true, items: context.washing_items.map((item: { washing_order_item_id: number; expected_sequence: number }) => ({ washing_order_item_id: item.washing_order_item_id, expected_sequence: item.expected_sequence })) });
  const location = (await api(page, `/inventory/stock-overview/${f.location}`)).data.location;
  await openSidebarPage(page, "Stock overview");
  await page.getByRole("textbox", { name: "Search stock locations", exact: true }).fill(f.suffix);
  await page.getByRole("button", { name: "Search", exact: true }).click();
  const row = page.getByRole("row").filter({ hasText: location.name });
  await expect(row.getByRole("cell", { name: "3", exact: true })).toBeVisible();
  await expect(row.getByRole("cell", { name: "2", exact: true })).toBeVisible();
  await row.getByRole("button", { name: `Details · ${location.name}`, exact: true }).click();
  await expect(page.getByRole("button", { name: "Tools (3)", exact: true })).toHaveAttribute("aria-pressed", "true");
  await expect(page.getByRole("row").filter({ hasText: `Weaving comb ${f.suffix}` })).toBeVisible();
  await page.getByRole("button", { name: "Carpets (2)", exact: true }).click();
  await expect(page.locator("#dashboard-content tbody tr")).toHaveCount(2);
  await expect(page.locator("#dashboard-content tbody")).not.toContainText(`Weaving comb ${f.suffix}`);
  await page.screenshot({ path: "test-results/stock-overview-carpets-desktop.png", fullPage: true });
  await page.reload(); await expect(page.getByRole("heading", { name: "Stock overview", exact: true })).toBeVisible();
  await expect(page).toHaveURL(/page=Stock\+overview/);
  await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  const t = (text: string) => productionText("fa", text);
  await page.getByRole("textbox", { name: t("Search stock locations"), exact: true }).fill(f.suffix);
  await page.getByRole("button", { name: t("Search"), exact: true }).click();
  await page.getByRole("button", { name: `${t("Details")} · ${location.name}`, exact: true }).click();
  await page.getByRole("button", { name: `${t("Carpets")} (2)`, exact: true }).click();
  await expect(page.locator("#dashboard-content tbody tr")).toHaveCount(2);
  await page.setViewportSize({ width: 320, height: 844 });
  for (const theme of ["dark", "light"]) {
    if (await page.locator("html").getAttribute("data-theme") !== theme) await page.locator(".theme-toggle").click();
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await expect(page.getByRole("button", { name: `${t("Carpets")} (2)`, exact: true })).toHaveAttribute("aria-pressed", "true");
    await expect(page.locator("#dashboard-content tbody tr")).toHaveCount(2);
    expect(await page.locator("#dashboard-content tbody tr").evaluateAll((rows) => rows.every((row) => {
      const cells = Array.from(row.querySelectorAll("td"));
      return cells.every((cell, index) => {
        const bounds = cell.getBoundingClientRect();
        const childrenFit = Array.from(cell.children).every((child) => child.getBoundingClientRect().bottom <= bounds.bottom + 1);
        return childrenFit && (!cells[index + 1] || bounds.bottom <= cells[index + 1].getBoundingClientRect().top + 1);
      });
    }))).toBe(true);
    await page.screenshot({ path: `test-results/stock-overview-dari-mobile-${theme}.png`, fullPage: true });
  }
  expect(errors).toEqual([]);
});
