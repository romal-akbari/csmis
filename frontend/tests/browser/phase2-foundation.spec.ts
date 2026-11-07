import { expect, test, openSidebarPage } from "./fixtures";

test("foundation reference directories archive, filter, and reactivate without losing records", async ({
  page,
}) => {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  for (const label of [
    "Carpet categories",
    "Expense categories",
    "Washing facilities",
  ]) {
    await openSidebarPage(page, label);
    await page.getByRole("button", { name: "Add record", exact: true }).click();
    const dialog = page.getByRole("dialog");
    const name = `Phase 2 ${label} ${Date.now()}`;
    await dialog.locator("#foundation-name").fill(name);
    await dialog.getByRole("button", { name: "Save", exact: true }).click();
    await expect(dialog).not.toBeVisible();
    const row = page.getByRole("row").filter({ hasText: name });
    await row.getByRole("button", { name: "Archive", exact: true }).click();
    await row
      .getByRole("button", { name: "Confirm archive", exact: true })
      .click();
    await expect(row).toContainText("inactive");
    await page.locator("#foundation-status").selectOption("active");
    await expect(row).toHaveCount(0);
    await page.locator("#foundation-status").selectOption("inactive");
    await expect(row).toBeVisible();
    await row.getByRole("button", { name: "Edit", exact: true }).click();
    await dialog.locator("#foundation-status").selectOption("active");
    await dialog.getByRole("button", { name: "Save", exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await expect(row).toHaveCount(0);
    await page.locator("#foundation-status").selectOption("active");
    await expect(row).toBeVisible();
  }
});
