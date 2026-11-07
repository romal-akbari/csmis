import { execFileSync } from "node:child_process";
import path from "node:path";
import { test as base } from "@playwright/test";
import { expect, type Page } from "@playwright/test";
import { SIDEBAR_SUBMENUS } from "../../src/lib/navigation";
import { foundationText } from "../../src/lib/foundation-i18n";
import { businessText } from "../../src/lib/business-i18n";
import { productionText } from "../../src/lib/manager-production-i18n";
import { canonicalPage, historicalPage } from "../../src/lib/company-workflow";

/** Keep browser cases independent without weakening the real login limiter. */
export const test = base.extend<{ resetBrowserUsers: void }>({
  resetBrowserUsers: [
    async ({ baseURL }, use) => {
      if (baseURL !== "http://127.0.0.1:3102") {
        throw new Error(
          "Browser fixtures require the isolated test application.",
        );
      }
      execFileSync("php", ["scripts/seed-browser-tests.php"], {
        cwd: path.resolve(process.cwd(), "../backend"),
        stdio: "pipe",
      });
      await use();
    },
    { auto: true },
  ],
});

export { expect } from "@playwright/test";
export type { Page } from "@playwright/test";

export async function openSidebarPage(
  page: Page,
  label: string,
  locale = "en",
) {
  label = canonicalPage(label);
  if (historicalPage(label)) {
    await openSidebarPage(page, "Historical records", locale);
    await page.getByRole("combobox", { name: productionText(locale, "History section"), exact: true }).selectOption(label);
    return label;
  }
  if (["Materials", "Tools", "Material receipts", "Tool receipts"].includes(label)) {
    await openSidebarPage(page, "Materials & tools stock", locale);
    await page.getByRole("button", { name: businessText(locale, foundationText(locale, label)), exact: true }).click();
    return label;
  }
  if (label === "Worker assistance") {
    await page.goto("/?page=Worker+assistance");
    await expect(page.locator(".profile-button")).toBeVisible();
    return label;
  }
  const parent = Object.entries(SIDEBAR_SUBMENUS).find(([, items]) =>
    items.includes(label),
  )?.[0];
  if (!parent) throw new Error(`No sidebar destination for ${label}`);
  // Reloads and sign-in verify the session before mounting navigation.
  await expect(page.locator(".profile-button")).toBeVisible();
  const sidebar = page.locator(".sidebar");
  if (!(await sidebar.isVisible())) {
    await page
      .getByRole("button", { name: "Open navigation", exact: true })
      .click();
  }
  await sidebar
    .getByRole("button", { name: businessText(locale, foundationText(locale, parent)), exact: true })
    .click();
  const menu = page.getByRole("menu", {
    name: businessText(locale, foundationText(locale, parent)),
    exact: true,
  });
  await menu
    .getByRole("menuitem", { name: productionText(locale, businessText(locale, foundationText(locale, label))), exact: true })
    .click();
  await expect(menu).not.toBeVisible();
  return label;
}
