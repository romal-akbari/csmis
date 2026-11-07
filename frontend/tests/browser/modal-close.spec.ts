import { expect, test, openSidebarPage, type Page } from "./fixtures";
import { productionText } from "../../src/lib/manager-production-i18n";

test.use({ actionTimeout: 15_000 });

async function login(page: Page) {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

test("account close icon dismisses the form and allows it to reopen", async ({ page }) => {
  await login(page);
  await openSidebarPage(page, "Accounts");
  const add = page.getByRole("button", { name: "Add record", exact: true });
  const dialog = page.getByRole("dialog");
  for (let attempt = 0; attempt < 2; attempt++) {
    await add.click();
    await expect(dialog).toBeVisible();
    await dialog.getByRole("button", { name: "Close", exact: true }).locator("svg").click();
    await expect(dialog).not.toBeVisible();
  }
  await add.click();
  await expect(dialog).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(dialog).not.toBeVisible();
});

for (const locale of ["en", "fa"]) {
  test(`production order closes from its direct URL with defaults and validation errors in ${locale}`, async ({ page }) => {
    const t = (text: string) => productionText(locale, text);
    const writes: string[] = [];
    await page.route("**/api/production/lookups", async (route) => {
      const response = await route.fetch();
      const result = await response.json();
      // Exercise the single-choice defaults used by a newly configured company.
      result.data.managers = result.data.managers.slice(0, 1);
      result.data.categories = result.data.categories.slice(0, 1);
      await route.fulfill({ response, json: result });
    });
    await login(page);
    if (locale === "fa") {
      await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
      await page.setViewportSize({ width: 390, height: 740 });
    }
    await page.goto("/?page=Production+orders");
    await expect(page.locator(".profile-button")).toBeVisible();
    page.on("request", (request) => {
      if (request.url().includes("/api/production/") && request.method() === "POST") writes.push(request.url());
    });
    const add = page.getByRole("button", { name: t("Create production order"), exact: true });
    const dialog = page.getByRole("dialog");
    for (let attempt = 0; attempt < 3; attempt++) {
      await add.click();
      await expect(dialog).toBeVisible();
      if (attempt === 1) {
        await dialog.getByRole("button", { name: t("Save order"), exact: true }).click();
        await expect(dialog.locator('[aria-invalid="true"]').first()).toBeVisible();
      }
      await dialog.getByRole("button", { name: t("Close"), exact: true }).locator("svg").click();
      await expect(dialog).not.toBeVisible();
      await expect(add).toBeFocused();
    }
    await add.click();
    await dialog.getByLabel(t("Design name"), { exact: false }).fill("Unsaved Chobi design");
    await dialog.getByRole("button", { name: t("Save order"), exact: true }).scrollIntoViewIfNeeded();
    await dialog.getByRole("button", { name: t("Close"), exact: true }).click();
    const discard = dialog.getByRole("button", { name: t("Discard"), exact: true });
    await expect(discard).toBeInViewport({ ratio: 1 });
    await dialog.getByRole("button", { name: t("Keep editing"), exact: true }).click();
    await expect(dialog.getByLabel(t("Design name"), { exact: false })).toHaveValue("Unsaved Chobi design");
    await page.keyboard.press("Escape");
    await discard.click();
    await expect(dialog).not.toBeVisible();
    await add.click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel(t("Design name"), { exact: false })).toHaveValue("");
    await page.keyboard.press("Escape");
    await expect(dialog).not.toBeVisible();
    expect(writes).toEqual([]);
  });
}

test("supply purchase close icon and Escape dismiss the form with unsaved-change protection", async ({ page }) => {
  await login(page);
  await openSidebarPage(page, "Materials & tools");
  const add = page.getByRole("button", { name: "Purchase and receive supplies", exact: true });
  const dialog = page.getByRole("dialog");
  for (let attempt = 0; attempt < 2; attempt++) {
    await add.click();
    await expect(dialog).toBeVisible();
    await dialog.getByRole("button", { name: "Close", exact: true }).locator("svg").click();
    await expect(dialog).not.toBeVisible();
  }
  await add.click();
  await expect(dialog).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(dialog).not.toBeVisible();

  await add.click();
  await dialog.getByLabel(/^Quantity/).fill("2");
  await dialog.getByRole("button", { name: "Close", exact: true }).click();
  await expect(dialog.getByText("Discard unsaved changes?", { exact: true })).toBeVisible();
  await dialog.getByRole("button", { name: "Keep editing", exact: true }).click();
  await expect(dialog.getByLabel(/^Quantity/)).toHaveValue("2");
  await dialog.getByRole("button", { name: "Close", exact: true }).click();
  await dialog.getByRole("button", { name: "Discard", exact: true }).click();
  await expect(dialog).not.toBeVisible();
});

for (const scenario of [
  { width: 1440, locale: "en", dark: false, reduced: false },
  { width: 390, locale: "en", dark: false, reduced: false },
  { width: 320, locale: "fa", dark: true, reduced: true },
]) {
  test(`closing a scrolled purchase keeps the discard choice visible at ${scenario.width}px in ${scenario.locale}`, async ({ page }) => {
    const t = (text: string) => productionText(scenario.locale, text);
    await page.setViewportSize({ width: scenario.width, height: 740 });
    await page.emulateMedia({ reducedMotion: scenario.reduced ? "reduce" : "no-preference" });
    if (scenario.dark) await page.addInitScript(() => localStorage.setItem("csms_theme", "dark"));
    await login(page);
    if (scenario.locale === "fa") await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
    await openSidebarPage(page, "Materials & tools", scenario.locale);
    const add = page.getByRole("button", { name: t("Purchase and receive supplies"), exact: true });
    await add.click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toHaveAttribute("dir", scenario.locale === "fa" ? "rtl" : "ltr");
    await dialog.getByLabel(t("Quantity"), { exact: false }).fill("2");
    for (let item = 0; item < 3; item++) {
      await dialog.getByRole("button", { name: t("Add item"), exact: true }).click();
    }
    await dialog.getByRole("button", { name: t("Purchase and receive supplies"), exact: true }).scrollIntoViewIfNeeded();
    await dialog.getByRole("button", { name: t("Close"), exact: true }).click();
    const discard = dialog.getByRole("button", { name: t("Discard"), exact: true });
    const keepEditing = dialog.getByRole("button", { name: t("Keep editing"), exact: true });
    await expect(discard).toBeInViewport({ ratio: 1 });
    await expect(keepEditing).toBeFocused();
    await page.screenshot({ path: `test-results/purchase-close-${scenario.width}-${scenario.locale}.png` });
    await page.keyboard.press("Enter");
    await expect(dialog.getByRole("button", { name: t("Close"), exact: true })).toBeFocused();
    await expect(dialog.getByLabel(t("Quantity"), { exact: false }).first()).toHaveValue("2");
    await page.keyboard.press("Escape");
    await expect(discard).toBeInViewport({ ratio: 1 });
    await discard.click();
    await expect(dialog).not.toBeVisible();
    await expect(add).toBeFocused();
    await add.click();
    await dialog.getByRole("button", { name: t("Close"), exact: true }).click();
    await expect(dialog).not.toBeVisible();
  });
}

test("a scrolled business form keeps its discard confirmation visible", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 740 });
  await login(page);
  await openSidebarPage(page, "Expense records");
  await page.getByRole("button", { name: "Record expense", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel(/^Description/).fill("Unsaved office expense");
  await dialog.getByRole("button", { name: "Record expense", exact: true }).scrollIntoViewIfNeeded();
  await dialog.getByRole("button", { name: "Close", exact: true }).click();
  await expect(dialog.getByRole("button", { name: "Discard", exact: true })).toBeInViewport({ ratio: 1 });
  await expect(dialog.getByRole("button", { name: "Keep editing", exact: true })).toBeFocused();
  await dialog.getByRole("button", { name: "Discard", exact: true }).click();
  await expect(dialog).not.toBeVisible();
});

test("other shared modal families close with the icon and Escape without submitting", async ({ page }) => {
  test.setTimeout(120_000);
  const errors: string[] = [];
  const writes: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await login(page);
  page.on("request", (request) => {
    if (request.url().includes("/api/") && !["GET", "HEAD", "OPTIONS"].includes(request.method())) writes.push(request.url());
  });
  for (const [destination, action] of [
    ["Supplier records", "Add record"],
    ["Stock transfers", "Transfer stock"],
    ["Stock corrections", "Correct stock"],
    ["Washing batches", "Send to washing"],
    ["Production orders", "Create production order"],
  ]) {
    await openSidebarPage(page, destination);
    const add = page.getByRole("button", { name: action, exact: true });
    await add.click();
    const dialog = page.getByRole("dialog");
    await dialog.getByRole("button", { name: "Close", exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await add.click();
    await expect(dialog).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(dialog).not.toBeVisible();
  }
  await page.locator(".sidebar").getByRole("button", { name: "Customers", exact: true }).click();
  for (const icon of [true, false]) {
    await page.getByRole("button", { name: "+ Add customer", exact: true }).click();
    await expect(page.getByRole("dialog")).toBeVisible();
    if (icon) await page.getByRole("dialog").getByRole("button", { name: "Close", exact: true }).click();
    else await page.keyboard.press("Escape");
    await expect(page.getByRole("dialog")).not.toBeVisible();
  }
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});
