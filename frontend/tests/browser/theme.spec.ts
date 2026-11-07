import type { Locator } from "@playwright/test";
import { foundationText } from "../../src/lib/foundation-i18n";
import { expect, test, openSidebarPage, type Page } from "./fixtures";

async function login(page: Page) {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

async function expectTheme(page: Page, theme: "light" | "dark") {
  await expect(page.locator("html")).toHaveAttribute("data-theme", theme);
  await expect(page.locator(".theme-toggle")).toHaveAttribute(
    "aria-pressed", String(theme === "dark"),
  );
}

// Resolve transparent table cells through their ancestors. Canvas normalizes
// CSS colors, including color-mix(), so the check measures the rendered palette.
async function palette(locator: Locator) {
  return locator.evaluate((element) => {
    const context = document.createElement("canvas").getContext("2d")!;
    context.canvas.width = context.canvas.height = 1;
    const rgba = (color: string) => {
      context.clearRect(0, 0, 1, 1);
      context.fillStyle = color;
      context.fillRect(0, 0, 1, 1);
      return Array.from(context.getImageData(0, 0, 1, 1).data).map(
        (value, index) => index === 3 ? value / 255 : value,
      );
    };
    let background = [0, 0, 0, 0];
    for (let node: Element | null = element; node; node = node.parentElement) {
      const behind = rgba(getComputedStyle(node).backgroundColor);
      const alpha = background[3] + behind[3] * (1 - background[3]);
      if (alpha > 0) background = [
        ...background.slice(0, 3).map((channel, index) =>
          (channel * background[3] + behind[index] * behind[3] * (1 - background[3])) / alpha),
        alpha,
      ];
      if (background[3] >= 0.999) break;
    }
    const luminance = (channels: number[]) => {
      const linear = channels.slice(0, 3).map((channel) => {
        const value = channel / 255;
        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
      });
      return linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
    };
    const foreground = rgba(getComputedStyle(element).color);
    const frontLuminance = luminance(foreground);
    const backLuminance = luminance(background);
    return {
      foreground, background, backLuminance,
      contrast: (Math.max(frontLuminance, backLuminance) + 0.05) /
        (Math.min(frontLuminance, backLuminance) + 0.05),
    };
  });
}

async function expectReadableDark(locator: Locator) {
  await expect(locator).toBeVisible();
  const colors = await palette(locator);
  expect(colors.background[3]).toBeGreaterThan(0.99);
  expect(colors.backLuminance).toBeLessThan(0.2);
  expect(colors.contrast).toBeGreaterThanOrEqual(4.5);
  return colors;
}

async function mockReadOnlyTables(page: Page) {
  await page.route(/\/api\/customers(?:\?.*)?$/, (route) => route.fulfill({
    json: {
      data: [{ id: 990001, name: "Theme preview customer", phone: "0700123456",
        address: null, customer_type: "retail", registration_date: "2026-10-19",
        notes: null, status: "active", created_by: null,
        created_at: "2026-10-19T00:00:00Z", updated_at: "2026-10-19T00:00:00Z" }],
      current_page: 1, last_page: 1, per_page: 10, total: 1,
      company_timezone: "Asia/Kabul", company_today: "2026-10-19",
    },
  }));
  await page.route(/\/api\/foundation\/facilities(?:\?.*)?$/, (route) => route.fulfill({
    json: { data: [{ id: 990002, name: "Theme preview facility", location: "Kabul",
      contact_name: "Demo contact", phone: "0700123456", status: "active" }],
      current_page: 1, last_page: 1, total: 1 },
  }));
  await page.route(/\/api\/business\/purchases(?:\?.*)?$/, (route) => route.fulfill({
    json: { data: [{ id: 990003, purchase_no: "THEME-PREVIEW-ONLY",
      weaver_name: "Theme preview weaver", weaver_id: 1, branch_id: 1,
      purchase_on: "2026-10-19", currency_id: 1, currency_code: "USD",
      total_amount: "300.30", paid_amount: "100.05", remaining_amount: "200.25",
      returned_amount: "0.00", refunded_amount: "0.00", state: "posted" }],
      current_page: 1, last_page: 1, total: 1 },
  }));
}

test("theme switches with keyboard, persists, synchronizes tabs, and keeps login light", async ({ page, context }) => {
  test.setTimeout(120_000);
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => {
    if (message.type() === "error" && /hydration|did not match|server rendered/i.test(message.text()))
      errors.push(message.text());
  });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page);
  await expectTheme(page, "light");
  const toggle = page.getByRole("button", { name: "Switch to dark mode", exact: true });
  await toggle.focus();
  await toggle.press("Space");
  await expectTheme(page, "dark");
  await expect(page.locator(".theme-toggle")).toBeFocused();
  await expect(page.locator(".theme-toggle")).toHaveAccessibleName("Switch to light mode");
  expect(await page.locator(".theme-toggle").evaluate((node) => {
    const style = getComputedStyle(node);
    return style.outlineStyle !== "none" && parseFloat(style.outlineWidth) > 0;
  })).toBe(true);
  await expectReadableDark(page.locator(".profile-info strong"));
  await expectReadableDark(page.getByRole("button", { name: /^Active customers/ }).locator("strong"));
  expect(await page.evaluate(() => localStorage.getItem("csms_theme"))).toBe("dark");
  await page.reload();
  await expect(page.locator(".profile-button")).toBeVisible();
  await expectTheme(page, "dark");
  await expect(page.getByRole("button", { name: /^Active customers/ })).toBeVisible();
  await page.screenshot({ path: "test-results/theme-overview-dark-desktop.png", fullPage: true, animations: "disabled" });

  const otherTab = await context.newPage();
  await otherTab.goto("/");
  await expect(otherTab.locator(".profile-button")).toBeVisible();
  await expectTheme(otherTab, "dark");
  await otherTab.getByRole("button", { name: "Switch to light mode", exact: true }).click();
  await expectTheme(otherTab, "light");
  await expectTheme(page, "light");
  await otherTab.close();
  await page.getByRole("button", { name: "Switch to dark mode", exact: true }).click();
  await expectTheme(page, "dark");
  await page.locator(".profile-button").click();
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Welcome back", exact: true })).toBeVisible();
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await expect(page.locator(".theme-toggle")).toHaveCount(0);
  expect(await page.evaluate(() => localStorage.getItem("csms_theme"))).toBe("dark");
  await page.reload();
  await expect(page.locator("html")).toHaveAttribute("data-theme", "light");
  await login(page);
  await expectTheme(page, "dark");
  expect(errors).toEqual([]);
});

test("dark tables, form dialogs, and expanded purchase details remain readable and distinct", async ({ page }) => {
  test.setTimeout(120_000);
  await mockReadOnlyTables(page);
  const writes: string[] = [];
  page.on("request", (request) => {
    if (/\/api\/(customers|foundation|inventory)(?:[/?]|$)/.test(request.url()) &&
      !["GET", "HEAD"].includes(request.method())) writes.push(request.url());
  });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.emulateMedia({ reducedMotion: "reduce" });
  await login(page);
  await page.getByRole("button", { name: "Switch to dark mode", exact: true }).click();
  await expectTheme(page, "dark");
  await page.locator(".sidebar").getByRole("button", { name: "Customers", exact: true }).click();
  const customers = page.locator(".customers-table");
  await expectReadableDark(customers.getByRole("columnheader").first());
  const customerName = customers.getByText("Theme preview customer", { exact: true });
  await expectReadableDark(customerName);
  await customerName.hover();
  await expectReadableDark(customerName);
  const add = page.getByRole("button", { name: "+ Add customer", exact: true });
  await add.click();
  const dialog = page.getByRole("dialog");
  await expectReadableDark(dialog.getByRole("heading", { name: "New customer", exact: true }));
  const name = dialog.getByLabel("Name *", { exact: true });
  await expectReadableDark(name);
  await name.fill("Unsaved theme preview");
  expect(await dialog.evaluate((element) => element.getAnimations().length)).toBe(0);
  await page.screenshot({ path: "test-results/theme-customer-dialog-dark.png", fullPage: true, animations: "disabled" });
  await page.keyboard.press("Escape");
  await expect(dialog).toHaveCount(0);
  await expect(add).toBeFocused();

  await openSidebarPage(page, "Washing facilities");
  const facilities = page.getByRole("table");
  await expectReadableDark(facilities.getByRole("columnheader").first());
  await expectReadableDark(facilities.getByText("Theme preview facility", { exact: true }));
  await page.getByRole("button", { name: "Add record", exact: true }).click();
  await expectReadableDark(dialog.locator("h2"));
  await expectReadableDark(dialog.locator("input").first());
  await dialog.getByRole("button", { name: "Close", exact: true }).click();

  await openSidebarPage(page, "Carpet purchases");
  const purchaseTable = page.getByRole("table");
  const summary = purchaseTable.getByRole("row").filter({ hasText: "THEME-PREVIEW-ONLY" });
  await expectReadableDark(summary.getByText("THEME-PREVIEW-ONLY", { exact: true }));
  const expand = summary.locator("button[aria-expanded][aria-controls]");
  await expand.click();
  const detailId = await expand.getAttribute("aria-controls");
  const details = page.locator(`[id="${detailId}"]`);
  await expect(details).toBeVisible();
  const region = details.getByRole("region");
  const expandedColors = await expectReadableDark(region.getByRole("heading"));
  const summaryColors = await palette(summary.getByText("THEME-PREVIEW-ONLY", { exact: true }));
  expect(expandedColors.background).not.toEqual(summaryColors.background);
  await expectReadableDark(region.getByText("Paid amount", { exact: true }));
  expect(await details.locator("[data-purchase-disclosure]").evaluate(
    (node) => getComputedStyle(node).transitionProperty,
  )).toBe("none");
  await expect.poll(() => purchaseTable.locator("..").evaluate(
    (node) => node.scrollWidth <= node.clientWidth + 1,
  )).toBe(true);
  await page.screenshot({ path: "test-results/theme-purchase-expanded-dark.png", fullPage: true, animations: "disabled" });
  expect(writes).toEqual([]);
});

test("light and dark theme controls fit mobile in English and Dari without moving navigation", async ({ page }) => {
  test.setTimeout(120_000);
  await login(page);
  const language = page.getByRole("combobox", { name: "Display language" });
  for (const locale of ["en", "fa"]) {
    await language.selectOption(locale);
    await expect(page.locator("html")).toHaveAttribute("dir", locale === "fa" ? "rtl" : "ltr");
    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 844 });
      for (const theme of ["dark", "light"] as const) {
        await page.locator(".theme-toggle").click();
        await expectTheme(page, theme);
        const toggle = page.locator(".theme-toggle");
        await expect(toggle).toBeVisible();
        await expect(toggle).toHaveAccessibleName(foundationText(locale,
          theme === "dark" ? "Switch to light mode" : "Switch to dark mode"));
        for (const control of [toggle, language, page.locator(".profile-button"), page.getByRole("button", { name: "Open navigation", exact: true })]) {
          const bounds = (await control.boundingBox())!;
          expect(bounds.x).toBeGreaterThanOrEqual(-1);
          expect(bounds.x + bounds.width).toBeLessThanOrEqual(width + 1);
          expect(bounds.height).toBeGreaterThanOrEqual(28);
        }
        await expect.poll(() => page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        )).toBe(true);
        if (theme === "dark" && width === 390)
          await page.screenshot({ path: `test-results/theme-${locale}-mobile-dark.png`, fullPage: true, animations: "disabled" });
      }
    }
  }
});

test("theme stays consistent when browser storage is full and sidebar silhouettes stay visible", async ({ page, context }) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page);
  await expectTheme(page, "light");
  await page.evaluate(() => {
    const testingWindow = window as Window & { __themeOriginalSetItem?: Storage["setItem"] };
    const original = Storage.prototype.setItem;
    testingWindow.__themeOriginalSetItem = original;
    Storage.prototype.setItem = function (this: Storage, key: string, value: string) {
      if (key === "csms_theme") throw new DOMException("Theme test storage full", "QuotaExceededError");
      return original.call(this, key, value);
    };
  });
  try {
    for (const theme of ["dark", "light", "dark"] as const) {
      await page.locator(".theme-toggle").click();
      await expectTheme(page, theme);
      await expect(page.locator(".theme-toggle")).toHaveAccessibleName(
        theme === "dark" ? "Switch to light mode" : "Switch to dark mode",
      );
      if (theme === "dark") {
        const silhouettes = page.locator(".sidebar .reference-icon");
        expect(await silhouettes.count()).toBeGreaterThan(2);
        expect(await silhouettes.evaluateAll((nodes) => nodes.every(
          (node) => getComputedStyle(node).filter !== "none",
        ))).toBe(true);
      }
    }
    await expect(page.locator(".profile-button")).toBeVisible();
    expect(await page.evaluate(() => Boolean(localStorage.getItem("csms_token")))).toBe(true);
  } finally {
    await page.evaluate(() => {
      const testingWindow = window as Window & { __themeOriginalSetItem?: Storage["setItem"] };
      if (testingWindow.__themeOriginalSetItem)
        Storage.prototype.setItem = testingWindow.__themeOriginalSetItem;
      delete testingWindow.__themeOriginalSetItem;
    });
  }
  await page.getByRole("button", { name: "Switch to light mode", exact: true }).click();
  await expectTheme(page, "light");
  expect(await page.evaluate(() => localStorage.getItem("csms_theme"))).toBe("light");
  const otherTab = await context.newPage();
  try {
    await otherTab.goto("/");
    await expect(otherTab.locator(".profile-button")).toBeVisible();
    await expectTheme(otherTab, "light");
    await otherTab.getByRole("button", { name: "Switch to dark mode", exact: true }).click();
    await expectTheme(otherTab, "dark");
    await expectTheme(page, "dark");
  } finally {
    await otherTab.close();
  }
});
