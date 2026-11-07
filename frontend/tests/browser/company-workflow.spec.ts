import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test, openSidebarPage, type Page } from "./fixtures";
import { productionText } from "../../src/lib/manager-production-i18n";

test.use({ actionTimeout: 15_000 });
type Row = { id: number; [key: string]: unknown };
type Fixture = { suffix: string; branch: number; location: number; weaver: number; currency: number; account: number };
const field = (name: string) => new RegExp(`^${name}(?: [*])?$`);
function fixture(): Fixture {
  return JSON.parse(execFileSync("php", ["scripts/seed-inventory-browser.php"], { cwd: path.resolve(process.cwd(), "../backend"), encoding: "utf8" }));
}
async function login(page: Page, username = "browser_admin") {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill(username);
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

test("only the company production modules are offered and earlier drafts remain read-only", async ({ page }) => {
  const f = fixture();
  execFileSync("php", ["scripts/seed-company-history-browser.php", JSON.stringify(f)], { cwd: path.resolve(process.cwd(), "../backend"), stdio: "pipe" });
  await login(page);
  const sidebar = page.locator(".sidebar");
  for (const name of ["Purchases", "Production", "Inventory", "Washing"]) await expect(sidebar.getByRole("button", { name, exact: true })).toBeVisible();
  for (const name of ["Weavers & Artisans", "Suppliers", "Operations"]) await expect(sidebar.getByRole("button", { name, exact: true })).toHaveCount(0);
  await sidebar.getByRole("button", { name: "Purchases", exact: true }).click();
  const menu = page.getByRole("menu", { name: "Purchases", exact: true });
  await expect(menu.getByRole("menuitem")).toHaveCount(3);
  for (const name of ["Carpet purchases", "Purchase drafts", "Weaver returns"]) await expect(menu.getByRole("menuitem", { name, exact: true })).toHaveCount(0);
  await page.keyboard.press("Escape");
  await openSidebarPage(page, "Purchase drafts");
  await page.getByRole("textbox", { name: "Search records", exact: true }).fill(`HISTORY-${f.suffix}`);
  await page.getByRole("button", { name: "Search", exact: true }).click();
  const row = page.getByRole("row").filter({ hasText: `HISTORY-${f.suffix}` });
  await expect(row).toBeVisible();
  for (const name of ["New purchase draft", "Edit draft", "Post draft", "Cancel draft"]) await expect(page.getByRole("button", { name, exact: true })).toHaveCount(0);
  await row.getByRole("button", { name: "View", exact: true }).click();
  await expect(page.getByRole("dialog")).toBeVisible();
  await expect(page.getByRole("dialog").getByRole("button", { name: "Save draft", exact: true })).toHaveCount(0);
  await page.getByRole("dialog").getByRole("button", { name: "Close", exact: true }).click();
  await page.goto("/?page=Carpet+purchases");
  await expect(page.getByRole("heading", { name: "Historical records", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Purchase & receive", exact: true })).toHaveCount(0);
  await openSidebarPage(page, "Finished carpets");
  await expect(page.getByRole("button", { name: "Register carpet", exact: true })).toHaveCount(0);
  for (const label of ["Material receipts", "Tool receipts"]) {
    await openSidebarPage(page, label);
    await expect(page.getByRole("button", { name: label === "Material receipts" ? "Receive materials" : "Receive tools", exact: true })).toHaveCount(0);
  }
});

test("supplier creation, required supplier validation, supply receiving and partial settlement reconcile through the forms", async ({ page }) => {
  test.setTimeout(180_000);
  const f = fixture(); const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await login(page); await openSidebarPage(page, "Supplier records");
  await expect(page.getByRole("button", { name: "Weavers & Artisans", exact: true })).toHaveCount(0);
  await page.getByRole("button", { name: "Add record", exact: true }).click();
  let dialog = page.getByRole("dialog");
  await dialog.getByLabel(field("Name")).fill(`Supplier ${f.suffix}`);
  await dialog.getByLabel(field("Phone")).fill("0700000011");
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  const supplier = (await api(page, `/directories/suppliers?search=${f.suffix}`)).data[0];
  const material = (await api(page, "/business/materials", { code: `C-W-${f.suffix}`, name: `Wool ${f.suffix}`, unit: "kg" })).data;
  await openSidebarPage(page, "Materials & tools");
  await page.getByRole("button", { name: "Purchase and receive supplies", exact: true }).click(); dialog = page.getByRole("dialog");
  await dialog.getByLabel(field("Branch")).selectOption(String(f.branch));
  await dialog.getByLabel(field("Receive into store")).selectOption(String(f.location));
  await dialog.getByLabel(field("Material")).selectOption(String(material.id));
  await dialog.getByLabel(field("Quantity")).fill("5"); await dialog.getByLabel(field("Cost per unit")).fill("40");
  await dialog.getByRole("button", { name: "Purchase and receive supplies", exact: true }).click();
  await expect(dialog.getByLabel(field("Supplier"))).toHaveAttribute("aria-invalid", "true");
  await dialog.getByLabel(field("Supplier")).selectOption(String(supplier.id));
  await dialog.getByLabel("Pay now", { exact: true }).check();
  await dialog.getByLabel(field("Pay from account")).selectOption(String(f.account));
  await dialog.getByLabel(field("Payment amount")).fill("50");
  await dialog.getByRole("button", { name: "Purchase and receive supplies", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await openSidebarPage(page, "Supplier balances");
  await page.getByRole("textbox", { name: "Search suppliers", exact: true }).fill(f.suffix);
  await page.getByRole("button", { name: "Search", exact: true }).click();
  const supplierRow = page.getByRole("row").filter({ hasText: `Supplier ${f.suffix}` });
  await expect(supplierRow).toContainText("150.00");
  await supplierRow.getByRole("button", { name: "View statement", exact: true }).click();
  await page.getByRole("button", { name: "Pay remaining", exact: true }).click(); dialog = page.getByRole("dialog");
  await dialog.getByLabel(field("Pay from account")).selectOption(String(f.account));
  await dialog.getByLabel(field("Payment amount")).fill("151");
  await dialog.getByRole("button", { name: "Pay remaining", exact: true }).click();
  await expect(dialog).toContainText("Payment cannot exceed the purchase balance.");
  await dialog.getByLabel(field("Payment amount")).fill("150");
  await dialog.getByRole("button", { name: "Pay remaining", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await expect(page.getByRole("button", { name: "Pay remaining", exact: true })).toHaveCount(0);
  const statement = (await api(page, `/production/suppliers/${supplier.id}/statement`)).data;
  expect(statement.balances[0].owed).toBe("0.00"); expect(statement.payments).toHaveLength(2);
  const account = (await api(page, `/foundation/accounts?search=${f.suffix}`)).data.find((row: Row) => row.id === f.account);
  expect(account.balance).toBe("4800.00");
  await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  await page.getByRole("button", { name: /dark mode|تاریک/ }).click();
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    const cells = page.locator("td[data-label]");
    for (const cell of await cells.all()) {
      if (!await cell.isVisible()) continue;
      expect(await cell.evaluate((element) => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
      expect(await cell.evaluate((element) => {
        const content = element.querySelector("div")?.getBoundingClientRect();
        const bounds = element.getBoundingClientRect();
        return !content || (content.top >= bounds.top && content.bottom <= bounds.bottom + 1);
      })).toBe(true);
    }
  }
  await expect(page.getByRole("heading", { name: productionText("fa", "Latest supplier payments"), exact: true })).toBeVisible();
  await page.screenshot({ path: "test-results/company-supplier-statement-dari.png", fullPage: true });
  expect(errors).toEqual([]);
});

test("a sales account cannot open supply balances through a saved URL", async ({ page }) => {
  await login(page, "browser_sales");
  await page.goto("/?page=Supplier+balances");
  await expect(page.getByRole("heading", { name: "Dashboard", exact: true })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Supplier balances", exact: true })).toHaveCount(0);
});
