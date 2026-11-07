import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test, openSidebarPage, type Page } from "./fixtures";

const labelName = (label: string) => new RegExp(`^${label.replaceAll("(", "[(]").replaceAll(")", "[)]")}(?: [*])?$`);

type Fixture = { suffix: string; branch: number; location: number; category: number; weaver: number; currency: number; account: number; expenseCategory: number };
function fixture(): Fixture { return JSON.parse(execFileSync("php", ["scripts/seed-inventory-browser.php"], { cwd: path.resolve(process.cwd(), "../backend"), encoding: "utf8" })); }
async function login(page: Page) {
  page.setDefaultTimeout(15_000);
  await page.goto("/"); await page.getByLabel(labelName("Username")).fill("browser_admin"); await page.getByLabel(labelName("Password")).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click(); await expect(page.locator(".profile-button")).toBeVisible();
}
async function api(page: Page, url: string, data?: Record<string, unknown>) {
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  const headers = { Authorization: `Bearer ${token}`, "Idempotency-Key": crypto.randomUUID() };
  const result = data ? await page.request.post(`/api${url}`, { data, headers }) : await page.request.get(`/api${url}`, { headers });
  expect(result.ok(), await result.text()).toBe(true); return result.json();
}
async function register(page: Page, f: Fixture, suffix: string) {
  return (await api(page, "/inventory/carpets", { carpet_code: `B-${f.suffix}-${suffix}`, name: `Business carpet ${suffix}`, registration_branch_id: f.branch, category_id: f.category })).data.id as number;
}
async function save(page: Page, title: string) { const dialog = page.getByRole("dialog"); await dialog.getByRole("button", { name: title, exact: true }).click(); await expect(dialog.getByRole("heading", { name: title, exact: true })).not.toBeVisible(); }
async function detailAction(page: Page, title: string, fields: Record<string, string>, selects: Record<string, string> = {}) {
  const dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: title, exact: true }).click();
  for (const [label, value] of Object.entries(fields)) await dialog.getByLabel(labelName(label)).fill(value);
  for (const [label, value] of Object.entries(selects)) await dialog.getByLabel(labelName(label)).selectOption(value);
  await save(page, title);
  await expect(page.getByRole("dialog").getByRole("heading", { name: "View details", exact: true })).toBeVisible();
}

test("account creation, automatic purchase receiving, safe retry, supplier purchase and expenses work in the browser", async ({ page, context }) => {
  test.setTimeout(240_000);
  const f = fixture(); const errors: string[] = []; page.on("pageerror", (e) => errors.push(e.message));
  await page.setViewportSize({ width: 1440, height: 1000 }); await login(page);
  const carpet = await register(page, f, "BUY");
  const supplier = (await api(page, "/directories/suppliers", { name: `Supplier ${f.suffix}`, status: "active" })).data.id;
  await openSidebarPage(page, "Accounts"); await page.getByRole("button", { name: "Add record", exact: true }).click();
  let dialog = page.getByRole("dialog");
  await expect(dialog.getByLabel(labelName("Branch"))).toHaveCount(0);
  await expect(dialog.getByRole("button", { name: "Close", exact: true }).locator("svg")).toHaveCount(1);
  await dialog.getByLabel(labelName("Code")).fill(`B-CASH-${f.suffix}`); await dialog.getByLabel(labelName("Name")).fill(`Company account ${f.suffix}`);
  await dialog.getByLabel(labelName("Currency")).selectOption(String(f.currency)); await dialog.getByLabel(labelName("Opening balance")).fill("50000");
  await dialog.getByRole("button", { name: "Save", exact: true }).click(); await expect(dialog).not.toBeVisible();
  const account = (await api(page, `/foundation/accounts?search=${f.suffix}`)).data.find((a: { code: string }) => a.code === `B-CASH-${f.suffix}`).id;
  const accountsTab = await context.newPage(); await accountsTab.goto("/"); await openSidebarPage(accountsTab, "Accounts");
  await accountsTab.getByLabel(labelName("Search")).fill(f.suffix); await accountsTab.getByRole("button", { name: "Search", exact: true }).click();
  const accountRow = accountsTab.getByRole("row").filter({ hasText: `Company account ${f.suffix}` }); await expect(accountRow).toContainText("50000.00");
  await page.bringToFront(); await openSidebarPage(page, "Carpet purchases"); await page.getByRole("button", { name: "Purchase & receive", exact: true }).click();
  dialog = page.getByRole("dialog"); await expect(dialog.getByLabel(labelName("Purchase reference"))).toHaveCount(0);
  await dialog.getByLabel(labelName("Branch")).selectOption(String(f.branch)); await dialog.getByLabel(labelName("Weaver")).selectOption(String(f.weaver));
  await dialog.getByLabel(labelName("Destination stock location")).selectOption(String(f.location));
  await dialog.getByRole("checkbox", { name: new RegExp(`B-${f.suffix}-BUY`) }).check(); await dialog.getByLabel(labelName("Unit price")).fill("1000");
  await dialog.getByRole("checkbox", { name: "Pay now", exact: true }).check(); await dialog.getByLabel(labelName("Account")).selectOption(String(account)); await dialog.getByLabel(labelName("Amount")).fill("500");
  let lost = false;
  await page.route("**/api/business/purchases", async (route) => {
    if (route.request().method() === "POST" && !lost) { lost = true; const result = await route.fetch(); expect(result.status()).toBe(201); await route.abort("failed"); } else await route.continue();
  });
  await dialog.getByRole("button", { name: "Purchase & receive", exact: true }).click(); await expect(dialog.getByRole("alert").filter({ hasText: "Your form has been kept" })).toBeVisible();
  await save(page, "Purchase & receive");
  await expect(accountRow).toContainText("49500.00");
  const stock = (await api(page, `/inventory/carpets/${carpet}`)).data; expect(stock.stock_status).toBe("in_stock"); expect(stock.location_id).toBe(f.location);
  const purchases = (await api(page, `/business/purchases?search=PUR-`)).data;
  const purchase = purchases.find((p: { weaver_id: number; total_amount: string }) => p.weaver_id === f.weaver && p.total_amount === "1000.00");
  expect(purchase.remaining_amount).toBe("500.00");
  const row = page.locator("tr[data-expanded]").filter({ hasText: purchase.purchase_no });
  await row.getByRole("button", { name: new RegExp(`Show purchase details ${purchase.purchase_no}`) }).click(); await expect(row.locator("button[aria-expanded]")).toHaveAttribute("aria-expanded", "true");
  await row.getByRole("button", { name: "View details", exact: true }).click(); await expect(page.getByRole("dialog")).toContainText(`Inventory shelf ${f.suffix}`); await page.getByRole("dialog").getByRole("button", { name: "Close", exact: true }).click();
  const supplierCarpet = await register(page, f, "SUP");
  await page.reload();
  await openSidebarPage(page, "Carpet purchases"); await page.getByRole("button", { name: "Purchase & receive", exact: true }).click(); dialog = page.getByRole("dialog");
  await dialog.getByLabel(labelName("Branch")).selectOption(String(f.branch)); await dialog.getByLabel(labelName("Seller type")).selectOption("supplier"); await dialog.getByLabel(labelName("Supplier")).selectOption(String(supplier));
  await dialog.getByLabel(labelName("Destination stock location")).selectOption(String(f.location)); await dialog.getByRole("checkbox", { name: new RegExp(`B-${f.suffix}-SUP`) }).check(); await dialog.getByLabel(labelName("Unit price")).fill("200");
  await save(page, "Purchase & receive"); expect((await api(page, `/inventory/carpets/${supplierCarpet}`)).data.stock_status).toBe("in_stock");
  await openSidebarPage(page, "Expense records"); await page.getByRole("button", { name: "Record expense", exact: true }).click(); dialog = page.getByRole("dialog");
  await dialog.getByLabel(labelName("Branch")).selectOption(String(f.branch)); await dialog.getByLabel(labelName("Expense category")).selectOption(String(f.expenseCategory));
  await dialog.getByLabel(labelName("Payee")).fill(`Landlord ${f.suffix}`); await dialog.getByLabel(labelName("Description")).fill("Office rent payment"); await dialog.getByLabel(labelName("Expense amount")).fill("100");
  await dialog.getByRole("checkbox", { name: "Pay now", exact: true }).check(); await dialog.getByLabel(labelName("Account")).selectOption(String(account)); await dialog.getByLabel(labelName("Amount")).fill("100");
  await save(page, "Record expense"); await expect(accountRow).toContainText("49400.00");
  await page.getByRole("row").filter({ hasText: `Landlord ${f.suffix}` }).getByRole("button", { name: "View details" }).click();
  await page.getByRole("dialog").getByLabel("Upload receipt").setInputFiles({ name: "receipt.png", mimeType: "image/png", buffer: Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0ioAAAAASUVORK5CYII=", "base64") });
  await expect(page.getByRole("dialog").getByRole("button", { name: /Download.*receipt.png/ })).toBeVisible();
  await page.screenshot({ path: "test-results/business-expense-receipt.png", fullPage: true });
  await page.getByRole("dialog").getByRole("button", { name: "Close", exact: true }).click();
  await openSidebarPage(page, "Weaver records");
  await page.getByRole("row").filter({ hasText: `Inventory weaver ${f.suffix}` }).getByRole("button", { name: "View activity", exact: true }).click();
  await expect(page.getByRole("dialog")).toContainText(purchase.purchase_no);
  await expect(page.getByRole("dialog")).toContainText("500.00");
  expect(errors).toEqual([]); await accountsTab.close();
});

test("materials, historical production, partnership, sale collection and final settlement work through the forms", async ({ page }) => {
  test.setTimeout(300_000); const f = fixture(); const errors: string[] = []; page.on("pageerror", (e) => errors.push(e.message)); await login(page);
  const historicalCarpet = await register(page, f, "PROD"); await register(page, f, "PART");
  const customer = (await api(page, "/customers", { name: `Customer ${f.suffix}`, customer_type: "retail", registration_date: "2026-01-01", status: "active" })).data.id;
  await openSidebarPage(page, "Materials"); await page.getByRole("button", { name: "Add material", exact: true }).click(); let dialog = page.getByRole("dialog");
  await dialog.getByLabel(labelName("Code")).fill(`WOOL-${f.suffix}`); await dialog.getByLabel(labelName("Name")).fill(`Natural wool ${f.suffix}`); await save(page, "Add material");
  const material = (await api(page, `/business/materials?search=${f.suffix}`)).data[0].id;
  await openSidebarPage(page, "Material receipts"); await page.getByRole("button", { name: "Receive materials", exact: true }).click(); dialog = page.getByRole("dialog");
  await dialog.getByLabel(labelName("Branch")).selectOption(String(f.branch)); await dialog.getByLabel(labelName("Material")).selectOption(String(material)); await dialog.getByLabel(labelName("Destination stock location")).selectOption(String(f.location));
  await dialog.getByLabel(labelName("Quantity")).fill("10"); await dialog.getByLabel(labelName("Cost per unit")).fill("100"); await save(page, "Receive materials");
  const lot = (await api(page, "/business/material-receipts")).data.find((l: { material_id: number }) => l.material_id === material).id;
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Kabul", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
  await api(page, "/business/production", { branch_id: f.branch, weaver_id: f.weaver, model: "company_funded", ordered_on: today, due_on: today,
    currency_id: f.currency, rate_to_base: "1", description: "Historical company production", items: [{ carpet_id: historicalCarpet, cost_weight: "1" }] });
  for (const partner of [false, true]) {
    const label = partner ? "Partnerships" : "Production orders"; const title = partner ? "Create partnership" : "Create production order";
    await openSidebarPage(page, label);
    if (partner) {
      await page.getByRole("button", { name: title, exact: true }).click(); dialog = page.getByRole("dialog");
      await dialog.getByLabel(labelName("Branch")).selectOption(String(f.branch)); await dialog.getByLabel(labelName("Weaver")).selectOption(String(f.weaver)); await dialog.getByLabel(labelName("Description")).fill("Partnership production");
      await dialog.getByLabel(labelName("Sharing basis")).selectOption("net_cost_recovery"); await dialog.getByLabel(labelName("Company profit / proceeds share (%)")).fill("60"); await dialog.getByLabel(labelName("Company loss share (%)")).fill("60"); await dialog.getByLabel(labelName("Ownership, contributions and agreement terms")).fill("Company owns materials; partner contributes labor. Costs recover first and support offsets settlement.");
      await dialog.getByRole("checkbox", { name: new RegExp(`B-${f.suffix}-PART`) }).check(); await save(page, title);
    } else {
      await page.getByRole("button", { name: "Production history", exact: true }).click();
      await expect(page.getByRole("button", { name: title, exact: true })).toHaveCount(0);
    }
    await page.getByRole("row").filter({ hasText: `Inventory weaver ${f.suffix}` }).getByRole("button", { name: "View details" }).click();
    await detailAction(page, "Move materials", { Quantity: "2", Reason: "Issue wool to weaver" }, { "Material lot": String(lot), "Movement type": "issue" });
    await detailAction(page, "Move materials", { Quantity: "2", Reason: "Wool consumed in weaving" }, { "Material lot": String(lot), "Movement type": "consume" });
    await detailAction(page, partner ? "Record partner contribution" : "Record labor earned", { [partner ? "Partner contribution" : "Labor earned"]: partner ? "300" : "500", Description: "Completed weaving labor" });
    await detailAction(page, "Record payment", { Amount: "100", Reason: "Recorded production payment" }, { Account: String(f.account) });
    await detailAction(page, "Update progress", { "Completion percent": "100", "Progress notes": "Carpet completed and quality checked" });
    await detailAction(page, "Receive finished carpets", {}, { "Destination stock location": String(f.location) });
    await expect(page.getByRole("dialog")).toContainText(partner ? "500.00" : "700.00");
    await page.getByRole("dialog").getByRole("button", { name: "Close", exact: true }).click();
  }
  await openSidebarPage(page, "Sales invoices"); await page.getByRole("button", { name: "Sell carpets", exact: true }).click(); dialog = page.getByRole("dialog");
  await dialog.getByLabel(labelName("Branch")).selectOption(String(f.branch)); await dialog.getByLabel(labelName("Customer")).selectOption(String(customer)); await dialog.getByRole("checkbox", { name: new RegExp(`B-${f.suffix}-PART`) }).check(); await dialog.getByLabel(labelName("Unit price")).fill("1500");
  await dialog.getByRole("checkbox", { name: "Collect now", exact: true }).check(); await dialog.getByLabel(labelName("Account")).selectOption(String(f.account)); await dialog.getByLabel(labelName("Amount")).fill("1500"); await save(page, "Sell carpets");
  await openSidebarPage(page, "Partnerships"); await page.getByRole("row").filter({ hasText: `Inventory weaver ${f.suffix}` }).getByRole("button", { name: "View details" }).click();
  await expect(page.getByRole("dialog")).toContainText("Ready for settlement");
  await detailAction(page, "Finalize partnership settlement", {});
  await expect(page.getByRole("dialog").locator("dl div").filter({ hasText: "Partner balance due" })).toContainText("600.00");
  await detailAction(page, "Pay / recover partnership balance", { Amount: "600", Reason: "Partner final proceeds" }, { Account: String(f.account) });
  await expect(page.getByRole("dialog").locator("dl div").filter({ hasText: "Remaining settlement balance" })).toContainText("0.00");
  const balance = (await api(page, `/foundation/accounts?search=${f.suffix}`)).data.find((a: { id: number }) => a.id === f.account).balance; expect(balance).toBe("5700.00");
  await page.getByRole("dialog").getByRole("heading", { name: "Final settlement", exact: true }).first().scrollIntoViewIfNeeded();
  await page.screenshot({ path: "test-results/business-partnership-settlement.png", fullPage: true }); expect(errors).toEqual([]);
});

test("a loss-sharing settlement can recover a partner advance into an empty company account", async ({ page }) => {
  test.setTimeout(120_000);
  const f = fixture();
  await login(page);
  const date = new Date().toISOString().slice(0, 10);
  const carpet = await register(page, f, "LOSS");
  const material = (await api(page, "/business/materials", { code: `LOSS-${f.suffix}`, name: "Loss settlement wool", unit: "kg" })).data.id;
  const lot = (await api(page, "/business/material-receipts", { branch_id: f.branch, material_id: material, location_id: f.location, received_on: date, quantity: "2", currency_id: f.currency, rate_to_base: "1", unit_cost: "100" })).data.id;
  const order = (await api(page, "/business/partnerships", {
    branch_id: f.branch, weaver_id: f.weaver, model: "partnership", ordered_on: date, due_on: date,
    currency_id: f.currency, rate_to_base: "1", description: "Loss sharing with advance recovery", share_basis: "net_cost_recovery",
    company_share: "60", company_loss_share: "60", agreement_terms: "Recover costs, share profits and losses 60/40, and offset partner support.", items: [{ carpet_id: carpet, cost_weight: "1" }],
  })).data.id;
  for (const movement_type of ["issue", "consume"]) await api(page, `/business/partnerships/${order}/move`, { lot_id: lot, movement_type, quantity: "2", moved_on: date, reason: "Materials for loss-sharing example" });
  await api(page, `/business/partnerships/${order}/contribution`, { contributed_on: date, amount: "100", description: "Partner labor" });
  const payment = { account_id: f.account, amount: "50", rate_to_base: "1", units_per_payment_unit: "1", payment_on: date, reason: "Partner support" };
  await api(page, `/business/partnerships/${order}/pay`, { ...payment, payment_type: "support" });
  await api(page, `/business/partnerships/${order}/progress`, { progress_on: date, percent: "100", notes: "Completed" });
  await api(page, `/business/partnerships/${order}/receive`, { received_on: date, location_id: f.location });
  const customer = (await api(page, "/customers", { name: `Loss customer ${f.suffix}`, customer_type: "retail", registration_date: date, status: "active" })).data.id;
  await api(page, "/business/sales", { branch_id: f.branch, customer_id: customer, sold_on: date, due_on: date, currency_id: f.currency, rate_to_base: "1",
    items: [{ carpet_id: carpet, expected_sequence: 1, unit_price: "100", discount: "0" }], payment: { ...payment, amount: "100", reason: "Customer payment" } });
  await api(page, `/business/partnerships/${order}/settle`, { settled_on: date });
  const account = (await api(page, "/foundation/accounts", { code: `RECOVERY-${f.suffix}`, name: `Recovery cash ${f.suffix}`, currency_id: f.currency, account_type: "cash", status: "active", opening_amount: "0", opening_on: date, opening_rate: "1" })).data.id;
  await openSidebarPage(page, "Partnerships");
  await page.getByRole("row").filter({ hasText: `Inventory weaver ${f.suffix}` }).getByRole("button", { name: "View details", exact: true }).click();
  await expect(page.getByRole("dialog").locator("dl div").filter({ hasText: "Partner balance due" })).toContainText("-30.00");
  await expect(page.getByRole("dialog").locator("dl div").filter({ hasText: "Remaining settlement balance" })).toContainText("30.00");
  await detailAction(page, "Pay / recover partnership balance", { Amount: "30", Reason: "Partner repays excess support" }, { Account: String(account) });
  await expect(page.getByRole("dialog").locator("dl div").filter({ hasText: "Remaining settlement balance" })).toContainText("0.00");
  const savedAccount = (await api(page, `/foundation/accounts?search=${f.suffix}`)).data.find((row: { id: number }) => row.id === account);
  expect(savedAccount.balance).toBe("30.00");
});

test("business forms validate in Dari, respect dark mode, fit mobile and protect unsaved changes", async ({ page }) => {
  test.setTimeout(120_000); fixture(); await login(page); await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  await page.evaluate(() => { localStorage.setItem("csms_theme", "dark"); }); await page.reload();
  await openSidebarPage(page, "Partnerships", "fa"); await page.getByRole("button", { name: "ایجاد شراکت", exact: true }).click(); const dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: "ایجاد شراکت", exact: true }).click(); await expect(dialog.getByText("این خانه الزامی است.").first()).toBeVisible();
  for (const width of [390, 320]) { await page.setViewportSize({ width, height: 844 }); await expect(dialog).toHaveAttribute("dir", "rtl"); expect(await dialog.evaluate((n) => n.scrollWidth <= n.clientWidth + 1)).toBe(true); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true); }
  await dialog.getByLabel(labelName("توضیحات")).fill("توافق تولید قالین"); await dialog.getByRole("button", { name: "بستن", exact: true }).click(); await expect(dialog.getByText("تغییرات ذخیره‌نشده کنار گذاشته شوند؟")).toBeVisible();
  await page.screenshot({ path: "test-results/business-dari-mobile.png", fullPage: true }); await dialog.getByRole("button", { name: "کنار گذاشتن", exact: true }).click(); await expect(dialog).not.toBeVisible();
});
