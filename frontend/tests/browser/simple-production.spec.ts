import { execFileSync } from "node:child_process";
import path from "node:path";
import type { Locator } from "@playwright/test";
import { expect, test, openSidebarPage, type Page } from "./fixtures";
import { productionText } from "../../src/lib/manager-production-i18n";

type Row = { id: number; [key: string]: unknown };
type Fixture = { suffix: string; branch: number; location: number; category: number; currency: number; account: number; facility: number; facilityLocation: number };
const label = (text: string) => new RegExp(`^${text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}(?: [*])?$`);
function fixture(): Fixture {
  const cwd = path.resolve(process.cwd(), "../backend");
  const seed = (script: string) => JSON.parse(execFileSync("php", [script], { cwd, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }));
  return { ...seed("scripts/seed-inventory-browser.php"), ...seed("scripts/seed-washing-browser.php") };
}
async function login(page: Page) {
  await page.goto("/"); await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click(); await expect(page.locator(".profile-button")).toBeVisible();
}
async function api(page: Page, endpoint: string, data?: Record<string, unknown>) {
  expect(new URL(page.url()).origin).toBe("http://127.0.0.1:3102");
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  const headers = { Authorization: `Bearer ${token}`, "Idempotency-Key": crypto.randomUUID(), Accept: "application/json" };
  const response = data ? await page.request.post(`/api${endpoint}`, { headers, data }) : await page.request.get(`/api${endpoint}`, { headers });
  expect(response.ok(), `${endpoint}: ${await response.text()}`).toBe(true); return response.json();
}
async function fill(dialog: Locator, values: Record<string, string>, selects: Record<string, string> = {}) {
  for (const [name, value] of Object.entries(selects)) await dialog.getByLabel(label(name)).selectOption(value);
  for (const [name, value] of Object.entries(values)) await dialog.getByLabel(label(name)).fill(value);
}
async function save(page: Page, button: string) {
  await page.getByRole("dialog").getByRole("button", { name: button, exact: true }).click(); await expect(page.getByRole("dialog")).not.toBeVisible();
}

test("simple production: purchase supplies, give, receive through washing, settle and return leftovers", async ({ page }) => {
  test.setTimeout(180_000);
  const f = fixture(); const errors: string[] = []; page.on("pageerror", (error) => errors.push(error.message));
  await page.setViewportSize({ width: 1440, height: 1000 }); await login(page);
  const supplier = (await api(page, "/directories/suppliers", { name: `Wool supplier ${f.suffix}`, status: "active" })).data;
  const manager = (await api(page, "/production/managers", { code: `S-M-${f.suffix}`, name: `Simple manager ${f.suffix}`, branch_id: f.branch, status: "active" })).data;
  const material = (await api(page, "/business/materials", { code: `S-W-${f.suffix}`, name: `Wool ${f.suffix}`, unit: "kg" })).data;
  const tool = (await api(page, "/production/tools", { code: `S-T-${f.suffix}`, name: `Comb ${f.suffix}`, status: "active" })).data;
  await openSidebarPage(page, "Production orders"); await page.getByRole("button", { name: "Production setup", exact: true }).click();
  let dialog = page.getByRole("dialog");
  await fill(dialog, {}, { Branch: String(f.branch), "Materials and tools store": String(f.location), "Finished carpet store": String(f.location), "Default washing facility": String(f.facility), "Washing stock location": String(f.facilityLocation) });
  await save(page, "Save setup");
  await openSidebarPage(page, "Materials & tools"); await page.getByRole("button", { name: "Purchase and receive supplies", exact: true }).click(); dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: "Purchase and receive supplies", exact: true }).click(); await expect(dialog.locator('[aria-invalid="true"]').first()).toBeVisible();
  await fill(dialog, { Quantity: "20", "Cost per unit": "40" }, { Branch: String(f.branch), Supplier: String(supplier.id), Material: String(material.id) });
  await expect(dialog.getByLabel(label("Receive into store"))).toHaveValue(String(f.location));
  await dialog.getByRole("button", { name: "Add item", exact: true }).click();
  await dialog.getByLabel(label("Item type")).nth(1).selectOption("tool");
  await dialog.getByLabel(label("Tool")).selectOption(String(tool.id));
  await dialog.getByLabel(label("Quantity")).nth(1).fill("2"); await dialog.getByLabel(label("Cost per unit")).nth(1).fill("75");
  await dialog.getByLabel("Pay now", { exact: true }).check(); await dialog.getByLabel(label("Pay from account")).selectOption(String(f.account));
  await expect(dialog.getByLabel(label("Payment amount"))).toHaveValue("950.00"); await save(page, "Purchase and receive supplies");
  await openSidebarPage(page, "Production orders"); await page.getByRole("button", { name: "Create production order", exact: true }).click(); dialog = page.getByRole("dialog");
  await fill(dialog, { "Design name": `Simple Chobi ${f.suffix}`, "Target quantity": "2", "Agreed rate / amount": "60" }, { Manager: String(manager.id), "Carpet category": String(f.category) });
  await dialog.getByText("Additional charges and deductions", { exact: true }).click(); await dialog.getByRole("button", { name: "Add pricing factor", exact: true }).last().click();
  await fill(dialog, { "Factor label": "Border finishing", "Factor value": "120" }); await save(page, "Save order");
  const workspace = page.getByRole("region", { name: "Production order workspace", exact: true }); await expect(workspace).toBeVisible();
  const order = (await api(page, `/production/orders?search=${f.suffix}`)).data[0];
  await workspace.getByRole("button", { name: "Give to manager", exact: true }).click(); dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: "Add material", exact: true }).click(); await fill(dialog.getByRole("region", { name: "Materials", exact: true }), { Quantity: "21" }, { Material: String(material.id) });
  await dialog.getByRole("button", { name: "Give to manager", exact: true }).click(); await expect(dialog).toContainText("Quantity exceeds the available stock.");
  await fill(dialog.getByRole("region", { name: "Materials", exact: true }), { Quantity: "16" });
  await dialog.getByRole("button", { name: "Add tool", exact: true }).click(); await fill(dialog.getByRole("region", { name: "Tools", exact: true }), { Quantity: "2" }, { Tool: String(tool.id) });
  await dialog.getByLabel("Give a production advance now", { exact: true }).check(); await fill(dialog, { "Payment amount": "200" }, { "Pay from account": String(f.account) }); await save(page, "Give to manager");
  for (const waste of ["0", "1"]) {
    await workspace.getByRole("button", { name: "Receive carpets", exact: true }).click(); dialog = page.getByRole("dialog");
    const document = (await api(page, `/production/orders/${order.id}`)).data;
    await fill(dialog, { "Length (cm)": "300", "Width (cm)": "200", "Quantity used": "7", "Waste quantity": waste }, { "Design line": String(document.lines[0].id) });
    await expect(dialog).toContainText("420.00"); await save(page, "Receive carpets");
    await workspace.getByRole("button", { name: "Receive from washing", exact: true }).click(); dialog = page.getByRole("dialog");
    await dialog.getByRole("button", { name: "Receive from washing", exact: true }).click(); await expect(dialog).toContainText("Confirm that the washed carpets have physically arrived.");
    await fill(dialog, { "Total washing charge": "50" }); await dialog.getByLabel("Pay washing charge now", { exact: true }).check(); await dialog.getByLabel(label("Pay from account")).selectOption(String(f.account));
    await dialog.getByLabel("I confirm these carpets are washed and physically received.", { exact: true }).check(); await save(page, "Receive from washing");
  }
  await workspace.getByRole("button", { name: "Pay remaining", exact: true }).click(); dialog = page.getByRole("dialog");
  await expect(dialog.getByLabel(label("Payment amount"))).toHaveValue("640.00"); await dialog.getByLabel(label("Pay from account")).selectOption(String(f.account)); await save(page, "Pay remaining");
  await expect(workspace.locator('li[data-current="true"]')).toContainText("Complete");
  const final = (await api(page, `/production/orders/${order.id}`)).data; expect(final.state).toBe("completed"); expect(final.balance_due).toBe("0.00");
  const account = (await api(page, `/foundation/accounts?search=${f.suffix}`)).data.find((row: Row) => row.id === f.account); expect(account.balance).toBe("3110.00");
  await workspace.getByText("History and other actions", { exact: true }).click(); await workspace.getByRole("button", { name: "Return supplies", exact: true }).click(); dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: "Add material", exact: true }).click(); await fill(dialog.getByRole("region", { name: "Materials", exact: true }), { Quantity: "1" }, { Material: String(material.id) });
  await dialog.getByRole("button", { name: "Add tool", exact: true }).click(); await fill(dialog.getByRole("region", { name: "Tools", exact: true }), { Quantity: "2" }, { Tool: String(tool.id) }); await save(page, "Return supplies");
  const context = (await api(page, `/production/orders/${order.id}/workflow`)).data; expect(context.materials[0].held_quantity).toBe("0.000"); expect(Number(context.materials[0].warehouse_quantity)).toBe(5); expect(Number(context.tools[0].warehouse_quantity)).toBe(2);
  await page.screenshot({ path: "test-results/simple-production-complete.png", fullPage: true }); expect(errors).toEqual([]);
});

test("unpaid supply purchase can be paid later and worker support remains separate", async ({ page }) => {
  const f = fixture(); await login(page);
  const supplier = (await api(page, "/directories/suppliers", { name: `Unpaid supplier ${f.suffix}`, status: "active" })).data;
  const material = (await api(page, "/business/materials", { code: `SP-${f.suffix}`, name: `Support wool ${f.suffix}`, unit: "kg" })).data;
  const supply = (await api(page, "/production/supply-purchases", { supplier_id: supplier.id, branch_id: f.branch, location_id: f.location, currency_id: f.currency, rate_to_base: "1", purchased_on: "2026-01-10", items: [{ kind: "material", item_id: material.id, quantity: "1", unit_cost: "100" }] })).data;
  await openSidebarPage(page, "Materials & tools"); await page.getByRole("row").filter({ hasText: supply.purchase_no }).getByRole("button", { name: "View details", exact: true }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Pay remaining", exact: true }).click(); await page.getByRole("dialog").getByLabel(label("Pay from account")).selectOption(String(f.account));
  await page.getByRole("dialog").getByRole("button", { name: "Pay remaining", exact: true }).click(); await expect(page.getByRole("dialog").getByRole("button", { name: "Pay remaining", exact: true })).not.toBeVisible();
  await page.getByRole("dialog").getByRole("button", { name: "Close", exact: true }).click();
  await api(page, "/production/managers", { code: `SM-${f.suffix}`, name: `Support manager ${f.suffix}`, branch_id: f.branch, status: "active" });
  await openSidebarPage(page, "Production managers"); await page.getByRole("row").filter({ hasText: `Support manager ${f.suffix}` }).getByRole("button", { name: "View statement", exact: true }).click(); await page.getByRole("dialog").getByRole("button", { name: "Worker support", exact: true }).click();
  const dialog = page.getByRole("dialog"); await fill(dialog, { "Beneficiary name": "Abdul Rahman — practice worker", Amount: "300", "Recovery terms": "Cash instalments by agreement", Reason: "Family support through manager" });
  await dialog.getByLabel("Pay now", { exact: true }).check(); await dialog.getByLabel(label("Pay from account")).selectOption(String(f.account)); await dialog.getByLabel("The manager has delivered this support to the worker", { exact: true }).check(); await fill(dialog, { "Delivered amount": "250", Acknowledgement: "Manager confirms handover to worker" });
  await fill(dialog, { "Payment amount": "100" }); await dialog.getByRole("button", { name: "Save worker support", exact: true }).click(); await expect(dialog).toContainText("Delivery cannot exceed the funded amount.");
  await fill(dialog, { "Payment amount": "300" });
  await dialog.getByRole("button", { name: "Save worker support", exact: true }).click(); await expect(page.getByRole("dialog").getByRole("heading", { name: "Manager statement", exact: true })).toBeVisible();
  await expect(page.getByRole("dialog")).toContainText("50.00");
  const account = (await api(page, `/foundation/accounts?search=${f.suffix}`)).data.find((row: Row) => row.id === f.account); expect(account.balance).toBe("4600.00");
});

test("new materials and tools can be added inside a supply purchase without losing the form", async ({ page }) => {
  const f = fixture(); await login(page);
  const supplier = (await api(page, "/directories/suppliers", { name: `Inline supplier ${f.suffix}`, status: "active" })).data;
  await openSidebarPage(page, "Materials & tools");
  await page.getByRole("button", { name: "Purchase and receive supplies", exact: true }).click(); let dialog = page.getByRole("dialog");
  await fill(dialog, { Quantity: "20", "Cost per unit": "40" }, { Branch: String(f.branch), Supplier: String(supplier.id), "Receive into store": String(f.location) });
  await dialog.getByRole("button", { name: "Add new material", exact: true }).click(); dialog = page.getByRole("dialog");
  await expect(dialog.locator("#foundation-code")).toHaveAttribute("placeholder", "Generated automatically when saved"); await dialog.locator("#foundation-name").fill(`Inline wool ${f.suffix}`); await dialog.locator("#foundation-unit").selectOption("kg"); await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog.getByRole("heading", { name: "Purchase and receive supplies", exact: true })).toBeVisible(); await expect(dialog.getByLabel(label("Quantity"))).toHaveValue("20"); await expect(dialog.getByLabel(label("Cost per unit"))).toHaveValue("40");
  await expect(dialog.getByLabel(label("Material")).locator("option:checked")).toHaveText(`Inline wool ${f.suffix}`);
  await dialog.getByRole("button", { name: "Add item", exact: true }).click(); await dialog.getByLabel(label("Item type")).nth(1).selectOption("tool"); await dialog.getByRole("button", { name: "Add new tool", exact: true }).click();
  await expect(dialog.locator("#foundation-code")).toHaveAttribute("placeholder", "Generated automatically when saved"); await dialog.locator("#foundation-name").fill(`Inline comb ${f.suffix}`); await dialog.locator("#foundation-status").selectOption("active"); await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog.getByLabel(label("Tool")).locator("option:checked")).toHaveText(`Inline comb ${f.suffix}`); await dialog.getByLabel(label("Quantity")).nth(1).fill("2"); await dialog.getByLabel(label("Cost per unit")).nth(1).fill("75"); await save(page, "Purchase and receive supplies");
});

test("simple order workspace and receive form fit mobile Dari in dark mode", async ({ page }) => {
  const f = fixture(); await login(page);
  const manager = (await api(page, "/production/managers", { code: `MOBILE-${f.suffix}`, name: `Mobile manager ${f.suffix}`, branch_id: f.branch, status: "active" })).data;
  await api(page, "/production/setup", { branch_id: f.branch, materials_location_id: f.location, finished_location_id: f.location, facility_id: f.facility, facility_location_id: f.facilityLocation, payment_trigger: "stock_acceptance" });
  const order = (await api(page, "/production/orders", { branch_id: f.branch, manager_id: manager.id, currency_id: f.currency, rate_to_base: "1", ordered_on: "2026-01-01", due_on: "2026-12-01", description: "Mobile workflow", payment_trigger: "stock_acceptance", lines: [{ name: "Mobile Chobi", category_id: f.category, target_quantity: 1, pricing_method: "piece", rate: "100" }], factors: [] })).data;
  await openSidebarPage(page, "Production orders"); await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  await page.getByRole("row").filter({ hasText: order.order_no }).getByRole("button", { name: productionText("fa", "View details"), exact: true }).click();
  const workspace = page.getByRole("region", { name: productionText("fa", "Production order workspace"), exact: true }); await expect(workspace).toHaveAttribute("dir", "rtl");
  await page.getByRole("button", { name: /dark mode|تاریک/ }).click();
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  }
  await workspace.getByRole("button", { name: productionText("fa", "Receive carpets"), exact: true }).click();
  const dialog = page.getByRole("dialog"); await expect(dialog).toHaveAttribute("dir", "rtl"); expect(await dialog.evaluate((element) => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
  await page.screenshot({ path: "test-results/simple-production-dari-mobile.png", fullPage: true });
});
