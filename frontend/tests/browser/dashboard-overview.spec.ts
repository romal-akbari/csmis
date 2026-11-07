import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test, type Page } from "./fixtures";
import { inventoryToday } from "../../src/lib/inventory";
import { countLabel, type Overview } from "../../src/lib/overview";

async function login(page: Page, username = "browser_admin") {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill(username);
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

async function api(page: Page, resource: string, data?: Record<string, unknown>) {
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  const headers = { Authorization: `Bearer ${token}`,
    "Idempotency-Key": `overview-ui-${Date.now()}-${Math.random()}` };
  const response = data
    ? await page.request.post(`/api${resource}`, { data, headers })
    : await page.request.get(`/api${resource}`, { headers });
  expect(response.ok(), await response.text()).toBe(true);
  return response.json();
}

test("branded overview displays real data, filters the chart, and opens existing pages", async ({ page }) => {
  test.setTimeout(120_000);
  const fixture = JSON.parse(execFileSync("php", ["scripts/seed-inventory-browser.php"], {
    cwd: path.resolve(process.cwd(), "../backend"), encoding: "utf8",
  }));
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page);
  const today = inventoryToday((await api(page, "/inventory/lookups")).data.company.timezone);
  const carpets: number[] = [];
  for (const number of [1, 2]) {
    carpets.push((await api(page, "/inventory/carpets", {
      carpet_code: `OV-${fixture.suffix}-${number}`, name: "Overview wool carpet",
      registration_branch_id: fixture.branch, category_id: fixture.category,
    })).data.id);
  }
  await api(page, "/inventory/purchases", {
    purchase_no: `OV-${fixture.suffix}`, branch_id: fixture.branch,
    weaver_id: fixture.weaver, currency_id: fixture.currency,
    rate_to_base: "1", purchase_on: today,
    items: [{ carpet_id: carpets[0], unit_price: "100.10" },
      { carpet_id: carpets[1], unit_price: "200.20" }],
  });
  await api(page, "/inventory/receipts", {
    receipt_no: `OV-R-${fixture.suffix}`, location_id: fixture.location,
    received_on: today, items: [{ carpet_id: carpets[0], expected_sequence: 1 }],
  });
  const updated = page.waitForResponse((response) =>
    response.url().includes("/api/dashboard/overview?") && response.ok());
  await page.getByRole("button", { name: "Refresh overview", exact: true }).click();
  const data: Overview = (await (await updated).json()).data;
  const screen = page.getByRole("region", { name: "Business overview", exact: true });
  for (const [label, key] of [
    ["Available carpets", "available"], ["Awaiting receipt", "awaiting_receipt"],
    ["Active customers", "customers"], ["Active weavers", "weavers"],
  ] as const) {
    await expect(screen.getByRole("button", { name: new RegExp(`^${label}`) }))
      .toContainText(countLabel(data.metrics[key]!, "en"));
  }
  await expect(screen.getByRole("heading", { name: "Current balances" })).toBeVisible();
  await expect(screen.getByRole("heading", { name: "Total purchases", exact: true })).toBeVisible();
  await expect(screen.getByRole("heading", { name: "Total expenses", exact: true })).toBeVisible();
  await expect(screen.getByRole("heading", { name: "Account balances", exact: true })).toBeVisible();
  await expect(screen.getByRole("heading", { name: "Recent transactions", exact: true })).toBeVisible();
  await expect(screen.getByRole("heading", { name: "Branch information", exact: true })).toBeVisible();
  await screen.getByText("Profit/loss reporting", { exact: true }).click();
  await expect(screen.getByText(/Full profit\/loss reports and exports/)).toBeVisible();
  const filter = page.waitForResponse((response) =>
    response.url().includes("/api/dashboard/overview?days=7") && response.ok());
  await screen.getByRole("button", { name: "7 days", exact: true }).click();
  expect((await (await filter).json()).data.stock.trend).toHaveLength(7);
  await expect(screen.getByRole("button", { name: "7 days" }))
    .toHaveAttribute("aria-pressed", "true");
  // Clicking the selected period must not leave the overview stuck loading.
  await screen.getByRole("button", { name: "7 days", exact: true }).click();
  await expect(screen.getByRole("button", { name: "Refresh overview" })).toBeEnabled();
  const slider = screen.getByRole("slider", { name: "Inspect activity day" });
  await slider.focus();
  await slider.press("Home");
  await expect(slider).toHaveValue("0");
  await slider.press("End");
  await expect(slider).toHaveValue("6");
  await screen.getByText("View chart data", { exact: true }).click();
  await expect(screen.getByRole("table")).toBeVisible();
  await expect(screen.getByRole("table").getByRole("row")).toHaveCount(8);
  await screen.getByText("View chart data", { exact: true }).click();
  await page.screenshot({ path: "test-results/dashboard-overview-desktop.png", fullPage: true });
  await screen.getByRole("button", { name: "Carpets", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Carpets", exact: true })).toBeVisible();
  await page.locator(".sidebar").getByRole("button", { name: "Dashboard", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Dashboard", exact: true })).toBeVisible();
  expect(errors).toEqual([]);
});

test("overview mirrors in Dari and fits desktop, tablet and mobile", async ({ page }) => {
  test.setTimeout(90_000);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page);
  await expect(page.getByRole("heading", { name: "Stock overview", exact: true })).toBeVisible();
  await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  const screen = page.getByRole("region", { name: "نمای کلی کسب‌وکار", exact: true });
  await expect(screen).toHaveAttribute("dir", "rtl");
  await expect(screen.getByRole("heading", { name: "داشبورد", exact: true })).toBeVisible();
  for (const width of [1440, 1024, 768, 390, 320]) {
    await page.setViewportSize({ width, height: 1000 });
    await expect.poll(() => page.evaluate(() =>
      document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    const bounds = (await screen.boundingBox())!;
    expect(bounds.x).toBeGreaterThanOrEqual(-1);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(width + 1);
    await expect(screen.getByRole("heading", { name: "فعالیت قالین‌ها", exact: true }))
      .toBeVisible();
  }
  await page.screenshot({ path: "test-results/dashboard-overview-dari-mobile.png", fullPage: true });
});

test("failed loads retry and sales users see no financial or weaver totals", async ({ page }) => {
  let fail = true;
  await page.route("**/api/dashboard/overview?*", async (route) => {
    if (fail) await route.abort("failed");
    else await route.continue();
  });
  await login(page, "browser_sales");
  const screen = page.getByRole("region", { name: "Business overview", exact: true });
  await expect(screen.getByRole("alert")).toContainText(
    "Could not load the overview. Please try again.");
  fail = false;
  await page.getByRole("button", { name: "Try again", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Stock overview", exact: true })).toBeVisible();
  await expect(page.getByRole("heading", { name: "Current balances" })).toHaveCount(0);
  await expect(page.getByRole("heading", { name: "Total purchases", exact: true })).toHaveCount(0);
  await expect(page.getByRole("heading", { name: "Total expenses", exact: true })).toHaveCount(0);
  await expect(page.getByRole("heading", { name: "Account balances", exact: true })).toHaveCount(0);
  await expect(page.getByRole("heading", { name: "Recent transactions", exact: true })).toHaveCount(0);
  await expect(page.getByRole("button", { name: /^Active weavers/ })).toHaveCount(0);
  const data: Overview = (await api(page, "/dashboard/overview")).data;
  expect(data.finance).toEqual({ cash: null, payables: null, washing_payables: null });
  expect(data.metrics.weavers).toBeNull();
  await expect(screen.getByText("No carpets registered yet", { exact: true })).toBeVisible();
});

test("revoked dashboard access clears previously displayed totals", async ({ page }) => {
  let deny = false;
  await page.route("**/api/dashboard/overview?*", async (route) => {
    if (deny) await route.fulfill({ status: 403, contentType: "application/json",
      body: JSON.stringify({ message: "Forbidden" }) });
    else await route.continue();
  });
  await login(page);
  const screen = page.getByRole("region", { name: "Business overview", exact: true });
  await expect(screen.getByRole("heading", { name: "Stock overview" })).toBeVisible();
  deny = true;
  await screen.getByRole("button", { name: "Refresh overview" }).click();
  await expect(screen.getByRole("alert")).toContainText(
    "Your account does not have dashboard access.");
  await expect(screen.getByRole("heading", { name: "Stock overview" })).toHaveCount(0);
  await expect(screen.getByRole("heading", { name: "Current balances" })).toHaveCount(0);
});

test("a failed period change retains the matching chart label and can be retried", async ({ page }) => {
  let fail = true;
  await page.route("**/api/dashboard/overview?days=7", async (route) => {
    if (fail) await route.fulfill({ status: 503, contentType: "application/json",
      body: JSON.stringify({ message: "Temporarily unavailable" }) });
    else await route.continue();
  });
  await login(page);
  const screen = page.getByRole("region", { name: "Business overview", exact: true });
  await expect(screen.getByRole("heading", { name: "Stock overview" })).toBeVisible();
  await screen.getByRole("button", { name: "7 days", exact: true }).click();
  await expect(screen.getByRole("alert")).toContainText(
    "Could not load the overview. Please try again.");
  await expect(screen.getByRole("button", { name: "30 days", exact: true }))
    .toHaveAttribute("aria-pressed", "true");
  fail = false;
  await screen.getByRole("button", { name: "7 days", exact: true }).click();
  await expect(screen.getByRole("button", { name: "7 days", exact: true }))
    .toHaveAttribute("aria-pressed", "true");
  await expect(screen.getByRole("alert")).toHaveCount(0);
});
