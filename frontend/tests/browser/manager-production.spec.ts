import { execFileSync } from "node:child_process";
import path from "node:path";
import type { Locator } from "@playwright/test";
import { productionText } from "../../src/lib/manager-production-i18n";
import { expect, test, openSidebarPage, type Page } from "./fixtures";

type Fixture = { suffix: string; branch: number; location: number; category: number; currency: number; account: number; facility: number; facilityLocation: number };
type Row = { id: number; [key: string]: unknown };
const label = (text: string) => new RegExp(`^${text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}(?: [*])?$`);
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
async function fill(dialog: Locator, values: Record<string, string>, selects: Record<string, string> = {}) {
  for (const name of [...Object.keys(selects), ...Object.keys(values)]) {
    const field = dialog.getByLabel(label(name));
    if (await field.count()) await field.first().evaluate((element) => { for (let parent = element.parentElement; parent; parent = parent.parentElement) if (parent.tagName === "DETAILS") (parent as HTMLDetailsElement).open = true; });
  }
  for (const [name, value] of Object.entries(selects)) await dialog.getByLabel(label(name)).selectOption(value);
  for (const [name, value] of Object.entries(values)) await dialog.getByLabel(label(name)).fill(value);
}
async function submit(page: Page, button: string, returnsTo?: string) {
  const dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: button, exact: true }).click();
  if (returnsTo) await expect(dialog.getByRole("heading", { name: returnsTo, exact: true })).toBeVisible();
  else await expect(dialog).not.toBeVisible();
}
async function balance(page: Page, f: Fixture) {
  return (await api(page, `/foundation/accounts?search=${f.suffix}`)).data.find((row: Row) => row.id === f.account).balance;
}
async function manager(page: Page, f: Fixture) {
  return (await api(page, "/production/managers", { code: `M-${f.suffix}`, name: `Production manager ${f.suffix}`, branch_id: f.branch, status: "active" })).data.id as number;
}
async function acceptDelivery(page: Page, f: Fixture, washingId: number, date: string) {
  let batch = (await api(page, `/washing/orders/${washingId}`)).data;
  await api(page, `/washing/orders/${washingId}/complete`, { completed_on: date, items: batch.items.map((item: Row) => ({ washing_order_item_id: item.id, expected_sequence: item.current_sequence })) });
  batch = (await api(page, `/washing/orders/${washingId}`)).data;
  await api(page, `/washing/orders/${washingId}/return`, { returned_on: date, location_id: f.location, items: batch.items.map((item: Row) => ({ washing_order_item_id: item.id, expected_sequence: item.current_sequence })) });
}

test("manager contract, partial direct-to-washing deliveries, custom price and settlement work through the forms", async ({ page }) => {
  test.setTimeout(300_000);
  const f = fixture();
  const errors: string[] = []; page.on("pageerror", (error) => errors.push(error.message));
  await page.setViewportSize({ width: 1440, height: 1000 }); await login(page);
  await openSidebarPage(page, "Production managers");
  await page.getByRole("button", { name: "Add manager", exact: true }).click();
  await fill(page.getByRole("dialog"), { Code: `M-${f.suffix}`, Name: `Production manager ${f.suffix}`, Phone: "0700000000" }, { Branch: String(f.branch) });
  await submit(page, "Add manager");
  const managerId = (await api(page, `/production/managers?search=${f.suffix}`)).data[0].id;
  await openSidebarPage(page, "Production orders");
  await page.getByRole("button", { name: "Create production order", exact: true }).click();
  let dialog = page.getByRole("dialog");
  await fill(dialog, { Description: "Two wool carpets, agreed finishing premium", "Design name": "Khal Mohammadi", "Target quantity": "2", "Length (cm)": "300", "Width (cm)": "200", "Agreed rate / amount": "100" }, {
    Branch: String(f.branch), Manager: String(managerId), "When does the manager earn payment?": "stock_acceptance", "Carpet category": String(f.category), "Pricing method": "area",
  });
  await dialog.getByText("Additional charges and deductions", { exact: true }).click();
  await dialog.getByRole("button", { name: "Add pricing factor", exact: true }).last().click();
  await fill(dialog, { "Factor label": "Agreed finishing premium", "Factor value": "200" });
  let lostResponse = false;
  await page.route("**/api/production/orders", async (route) => {
    if (route.request().method() === "POST" && !lostResponse) {
      lostResponse = true;
      const committed = await route.fetch(); expect(committed.status()).toBe(201);
      await route.abort("failed");
    } else await route.continue();
  });
  await dialog.getByRole("button", { name: "Save order", exact: true }).click();
  await expect(dialog.getByRole("alert").filter({ hasText: "Your form has been kept" })).toBeVisible();
  await submit(page, "Save order");
  await page.getByRole("button", { name: "Back to orders", exact: true }).click();
  const orders = await api(page, `/production/orders?search=${f.suffix}`);
  expect(orders.total).toBe(1);
  const order = orders.data[0];
  await page.getByRole("row").filter({ hasText: order.order_no }).getByRole("button", { name: "View details", exact: true }).click();
  await page.getByText("History and other actions", { exact: true }).click();
  await page.getByRole("button", { name: "Full history", exact: true }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Record production payment", exact: true }).click();
  await fill(page.getByRole("dialog"), { Amount: "100", Reason: "Production advance before completion" }, { Account: String(f.account), "Payment type": "advance" });
  await submit(page, "Record production payment", "Production order");
  expect(await balance(page, f)).toBe("4900.00");
  for (let index = 1; index <= 2; index++) {
    await page.getByRole("dialog").getByRole("button", { name: "Send to washing", exact: true }).click();
    dialog = page.getByRole("dialog");
    const detail = (await api(page, `/production/orders/${order.id}`)).data;
    await fill(dialog, { "Carpet code (optional)": `MP-${f.suffix}-${index}` }, { "Washing facility": String(f.facility), "Washing stock location": String(f.facilityLocation), "Design line": String(detail.lines[0].id) });
    await dialog.getByRole("button", { name: "Calculate production charge", exact: true }).click();
    await expect(dialog).toContainText("700.00");
    await submit(page, "Save delivery", "Production order");
    const stockList = (await api(page, `/inventory/carpets?search=MP-${f.suffix}-${index}`)).data;
    expect(stockList[0].stock_status).toBe("at_washing");
    const stock = (await api(page, `/inventory/carpets/${stockList[0].id}`)).data;
    expect(Number(stock.location_id)).toBe(f.facilityLocation);
    const batchList = (await api(page, "/washing/orders")).data;
    const batch = batchList.find((row: Row) => String(row.order_no).startsWith("MWASH") && Number(row.branch_id) === f.branch);
    expect(batch).toBeTruthy();
    await acceptDelivery(page, f, batch.id, detail.ordered_on);
    const accepted = (await api(page, `/production/orders/${order.id}`)).data;
    expect(accepted.earned_amount).toBe(index === 1 ? "700.00" : "1400.00");
    expect(accepted.deliveries[0].facility_name).toBeTruthy();
    expect(accepted.deliveries[0].washing_reference).toMatch(/^MWASH-/);
    expect((await api(page, `/inventory/carpets/${stock.id}`)).data.stock_status).toBe("in_stock");
    await page.getByRole("dialog").getByRole("button", { name: "Close", exact: true }).click();
    await page.getByRole("button", { name: "Back to orders", exact: true }).click();
    await page.getByRole("button", { name: "Refresh", exact: true }).click();
    await page.getByRole("row").filter({ hasText: order.order_no }).getByRole("button", { name: "View details", exact: true }).click();
  await page.getByText("History and other actions", { exact: true }).click();
  await page.getByRole("button", { name: "Full history", exact: true }).click();
  }
  await page.getByRole("dialog").getByRole("button", { name: "Record production payment", exact: true }).click();
  await fill(page.getByRole("dialog"), { Amount: "1300", Reason: "Settle completed and accepted carpets" }, { Account: String(f.account) });
  await submit(page, "Record production payment", "Production order");
  expect(await balance(page, f)).toBe("3600.00");
  await page.getByRole("dialog").getByRole("button", { name: "Close order", exact: true }).click();
  await fill(page.getByRole("dialog"), { Reason: "All carpets accepted and manager settled" });
  await submit(page, "Close order", "Production order");
  expect((await api(page, `/production/orders/${order.id}`)).data.state).toBe("closed");
  await page.screenshot({ path: "test-results/manager-production-settled.png", fullPage: true });
  expect(errors).toEqual([]);
});

test("worker assistance distinguishes cash funding, manager handover and recoverable debt", async ({ page }) => {
  test.setTimeout(180_000); const f = fixture(); await login(page); const managerId = await manager(page, f);
  await openSidebarPage(page, "Worker assistance");
  await page.getByRole("button", { name: "Create assistance", exact: true }).click();
  await fill(page.getByRole("dialog"), { "Beneficiary name": `Long-serving worker ${f.suffix}`, Amount: "500", Reason: "Approved family support through manager", "Recovery terms": "Worker repays 400 AFN in two agreed instalments" }, { Branch: String(f.branch), Manager: String(managerId), "Assistance type": "advance", Debtor: "worker" });
  await submit(page, "Create assistance");
  const assistance = (await api(page, `/production/assistance?search=${f.suffix}`)).data[0];
  await page.getByRole("row").filter({ hasText: assistance.assistance_no }).getByRole("button", { name: "View details", exact: true }).click();
  async function action(title: string, amount: string, reason: string) {
    await page.getByRole("dialog").getByRole("button", { name: title, exact: true }).click();
    await fill(page.getByRole("dialog"), { Amount: amount, Reason: reason }, { Account: String(f.account) });
    await submit(page, title, "Worker assistance");
  }
  await action("Fund assistance", "500", "Cash entrusted to manager"); expect(await balance(page, f)).toBe("4500.00");
  await page.getByRole("dialog").getByRole("button", { name: "Confirm delivery", exact: true }).click();
  await fill(page.getByRole("dialog"), { Amount: "400", Acknowledgement: "Worker confirms receiving 400 AFN from manager" });
  await submit(page, "Confirm delivery", "Worker assistance"); expect(await balance(page, f)).toBe("4500.00");
  await action("Return unused funds", "100", "Manager returns undelivered balance");
  await action("Record recovery", "200", "First repayment received from worker");
  expect(await balance(page, f)).toBe("4800.00");
  const saved = (await api(page, `/production/assistance/${assistance.id}`)).data;
  expect(saved.with_manager_amount).toBe("0.00"); expect(saved.advance_outstanding).toBe("200.00");
  await page.getByRole("dialog").getByRole("button", { name: "Close", exact: true }).click();
  await openSidebarPage(page, "Production managers");
  await page.getByRole("row").filter({ hasText: `Production manager ${f.suffix}` }).getByRole("button", { name: "View statement", exact: true }).click();
  await expect(page.getByRole("dialog")).toContainText(assistance.assistance_no);
  await expect(page.getByRole("dialog")).toContainText("200.00");
});

test("manager production validates before posting and stays usable in mobile Dari and dark mode", async ({ page }) => {
  test.setTimeout(120_000); fixture(); await login(page); await openSidebarPage(page, "Production orders");
  let posts = 0; page.on("request", (request) => { if (request.method() === "POST" && request.url().endsWith("/api/production/orders")) posts++; });
  await page.getByRole("button", { name: "Create production order", exact: true }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Save order", exact: true }).click();
  await expect(page.getByRole("dialog").getByText("This field is required.", { exact: true }).first()).toBeVisible(); expect(posts).toBe(0);
  await page.getByRole("dialog").getByRole("button", { name: "Close", exact: true }).click();
  await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  await page.evaluate(() => localStorage.setItem("csms_theme", "dark")); await page.reload();
  await page.getByRole("button", { name: productionText("fa", "Create production order"), exact: true }).click();
  const dialog = page.getByRole("dialog"); await expect(dialog).toHaveAttribute("dir", "rtl");
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 });
    expect(await dialog.evaluate((element) => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  }
  await dialog.getByText(productionText("fa", "Dates, currency and order notes"), { exact: true }).click();
  await dialog.getByLabel(label(productionText("fa", "Description"))).fill("Agreement pending review");
  await dialog.getByRole("button", { name: productionText("fa", "Close"), exact: true }).click();
  await expect(dialog.getByText(productionText("fa", "Discard unsaved changes?"), { exact: true })).toBeVisible();
  await page.screenshot({ path: "test-results/manager-production-dari-mobile.png", fullPage: true });
});

test("materials and reusable tools move from warehouse to manager with distinct consumption and custody", async ({ page }) => {
  test.setTimeout(240_000); const f = fixture(); await login(page); const managerId = await manager(page, f);
  const date = (await api(page, "/production/lookups")).data.company.timezone;
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: date, year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
  const material = (await api(page, "/business/materials", { code: `MAT-${f.suffix}`, name: `Natural wool ${f.suffix}`, unit: "kg" })).data.id;
  const materialLot = (await api(page, "/business/material-receipts", { branch_id: f.branch, material_id: material, location_id: f.location, received_on: today, quantity: "10", currency_id: f.currency, rate_to_base: "1", unit_cost: "10" })).data.id;
  const order = (await api(page, "/production/orders", { manager_id: managerId, branch_id: f.branch, currency_id: f.currency, rate_to_base: "1", ordered_on: today, due_on: today, description: "Materials and tools browser workflow", payment_trigger: "completion", lines: [{ name: "Wool carpet", category_id: f.category, target_quantity: 1, pricing_method: "piece", rate: "100" }], factors: [] })).data;
  await openSidebarPage(page, "Tools");
  await page.getByRole("button", { name: "Add tool", exact: true }).click();
  await fill(page.getByRole("dialog"), { Code: `TOOL-${f.suffix}`, Name: `Weaving comb ${f.suffix}` });
  await submit(page, "Add tool");
  const tool = (await api(page, `/production/tools?search=${f.suffix}`)).data[0];
  await openSidebarPage(page, "Tool receipts");
  await page.getByRole("button", { name: "Receive tools", exact: true }).click();
  await fill(page.getByRole("dialog"), { Quantity: "3", "Cost per unit": "20" }, { Tool: String(tool.id), Branch: String(f.branch), "Destination stock location": String(f.location) });
  await submit(page, "Receive tools");
  const toolLot = (await api(page, "/production/tool-receipts")).data.find((row: Row) => row.tool_id === tool.id);
  await page.getByRole("row").filter({ hasText: toolLot.lot_no }).getByRole("button", { name: "View details", exact: true }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Record payment", exact: true }).click();
  await fill(page.getByRole("dialog"), { Amount: "60", Reason: "Purchase of reusable weaving combs" }, { Account: String(f.account) });
  await submit(page, "Record payment", "Tool receipts"); expect(await balance(page, f)).toBe("4940.00");
  await page.getByRole("dialog").getByRole("button", { name: "Close", exact: true }).click();
  await openSidebarPage(page, "Production managers");
  await page.getByRole("row").filter({ hasText: `Production manager ${f.suffix}` }).getByRole("button", { name: "View statement", exact: true }).click();
  async function move(tools: boolean, type: string, quantity: string) {
    const title = tools ? "Record tool movement" : "Record material movement";
    await page.getByRole("dialog").getByRole("button", { name: title, exact: true }).click();
    await fill(page.getByRole("dialog"), { Quantity: quantity, Reason: `Record ${type} for manager stock custody` }, { [tools ? "Tool lot" : "Material lot"]: String(tools ? toolLot.id : materialLot), "Movement type": type });
    if (!tools && type === "consume") await page.getByRole("dialog").getByLabel(label("Production order")).selectOption(String(order.id));
    await submit(page, title, "Manager statement");
  }
  await move(false, "issue", "5"); await move(false, "consume", "2"); await move(false, "return", "1");
  await move(true, "issue", "3"); await move(true, "return", "1"); await move(true, "damage", "1");
  const statement = (await api(page, `/production/managers/${managerId}/statement`)).data;
  expect(statement.materials[0].held_quantity).toBe("2.000"); expect(Number(statement.tools[0].held_quantity)).toBe(1);
  expect(Number(statement.tools[0].damaged_quantity)).toBe(1);
  expect(statement.material_movements.map((row: Row) => row.movement_type)).toEqual(["return", "consume", "issue"]);
  expect(statement.tool_movements.map((row: Row) => row.movement_type)).toEqual(["damage", "return", "issue"]);
  const lookups = (await api(page, "/production/lookups")).data;
  expect(Number(lookups.material_lots.find((row: Row) => row.id === materialLot).warehouse_quantity)).toBe(6);
  expect(Number(lookups.tool_lots.find((row: Row) => row.id === toolLot.id).warehouse_quantity)).toBe(1);
});

async function destinationOrder(page: Page, f: Fixture) {
  const managerId = await manager(page, f);
  const facilityId = (await api(page, "/foundation/facilities", { name: `Destination washing ${f.suffix}`, status: "active" })).data.id;
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Kabul", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
  const order = (await api(page, "/production/orders", { manager_id: managerId, branch_id: f.branch, currency_id: f.currency, rate_to_base: "1", ordered_on: today, due_on: today,
    description: "Washing destination setup", payment_trigger: "completion", lines: [{ name: "Destination design", category_id: f.category, target_quantity: 1, length_cm: "200", width_cm: "150", pricing_method: "piece", rate: "100" }], factors: [] })).data;
  const detail = (await api(page, `/production/orders/${order.id}`)).data;
  await openSidebarPage(page, "Production orders");
  await page.getByRole("row").filter({ hasText: order.order_no }).getByRole("button", { name: "View details", exact: true }).click();
  await page.getByText("History and other actions", { exact: true }).click();
  await page.getByRole("button", { name: "Full history", exact: true }).click();
  await page.getByRole("dialog").getByRole("button", { name: "Send to washing", exact: true }).click();
  return { order, line: detail.lines[0], facilityId };
}

test("missing washing destinations can be created inline without losing the delivery or changing warehouse ownership", async ({ page }) => {
  test.setTimeout(180_000);
  const f = fixture(); await login(page);
  const { order, line, facilityId } = await destinationOrder(page, f);
  let dialog = page.getByRole("dialog");
  await expect(dialog.getByLabel(label("Washing stock location"))).toBeDisabled();
  await fill(dialog, { "Carpet code (optional)": `DEST-${f.suffix}` }, { "Washing facility": String(facilityId), "Design line": String(line.id) });
  await expect(dialog.getByText(/No active washing stock location is linked/)).toBeVisible();
  await expect(dialog.getByRole("button", { name: "Save delivery", exact: true })).toBeDisabled();
  await dialog.getByRole("button", { name: "Add washing location", exact: true }).click();
  dialog = page.getByRole("dialog");
  await expect(dialog.locator("#foundation-location_type")).toHaveValue("washing");
  await expect(dialog.locator("#foundation-washing_facility_id")).toHaveValue(String(facilityId));
  await expect(dialog.locator("#foundation-branch_id")).toHaveCount(0);
  await dialog.locator("#foundation-code").fill(`DEST-WASH-${f.suffix}`);
  await dialog.locator("#foundation-name").fill(`Destination yard ${f.suffix}`);
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog.getByRole("heading", { name: "Send to washing", exact: true })).toBeVisible();
  const location = (await api(page, `/foundation/locations?search=${f.suffix}`)).data.find((row: Row) => row.code === `DEST-WASH-${f.suffix}`);
  expect(location.branch_id).toBeNull(); expect(location.washing_facility_id).toBe(facilityId);
  await expect(dialog.getByLabel(label("Washing stock location"))).toHaveValue(String(location.id));
  await expect(dialog.getByLabel(label("Carpet code (optional)"))).toHaveValue(`DEST-${f.suffix}`);
  await expect(dialog.getByLabel(label("Length (cm)"))).toHaveValue("200.00");
  await expect(dialog.getByRole("button", { name: "Save delivery", exact: true })).toBeEnabled();
  await submit(page, "Save delivery", "Production order");
  const saved = (await api(page, `/production/orders/${order.id}`)).data;
  expect(saved.deliveries[0].items[0].stock_status).toBe("at_washing");
  expect(saved.deliveries[0].items[0].location_name).toBe(`Destination yard ${f.suffix}`);
  const warehouse = (await api(page, `/foundation/locations?search=${f.suffix}`)).data.find((row: Row) => row.id === f.location);
  expect(warehouse.branch_id).toBe(f.branch); expect(warehouse.washing_facility_id).toBeNull();
});

test("refreshing washing destinations picks up newly configured locations and keeps facility choices separate", async ({ page }) => {
  test.setTimeout(150_000);
  const f = fixture(); await login(page);
  const { line, facilityId } = await destinationOrder(page, f);
  const dialog = page.getByRole("dialog");
  await fill(dialog, { "Carpet code (optional)": `REFRESH-${f.suffix}` }, { "Washing facility": String(facilityId), "Design line": String(line.id) });
  await expect(dialog.getByText(/No active washing stock location is linked/)).toBeVisible();
  const location = (await api(page, "/foundation/locations", { code: `REFRESH-${f.suffix}`, name: `Fresh washing yard ${f.suffix}`, location_type: "washing", status: "active", branch_id: null, washing_facility_id: facilityId })).data;
  await dialog.getByRole("button", { name: "Refresh locations", exact: true }).click();
  await expect(dialog.getByLabel(label("Washing stock location"))).toHaveValue(String(location.id));
  await expect(dialog.getByLabel(label("Carpet code (optional)"))).toHaveValue(`REFRESH-${f.suffix}`);
  await dialog.getByLabel(label("Washing facility")).selectOption(String(f.facility));
  await expect(dialog.getByLabel(label("Washing stock location"))).toHaveValue(String(f.facilityLocation));
  await expect(dialog.getByLabel(label("Washing stock location")).locator(`option[value="${location.id}"]`)).toHaveCount(0);
  await dialog.getByLabel(label("Washing facility")).selectOption(String(facilityId));
  await expect(dialog.getByLabel(label("Washing stock location"))).toHaveValue(String(location.id));
});
