import { expect, test, openSidebarPage } from "./fixtures";

test("top bar uses live controls and Dari forms validate, save, and fit narrow screens", async ({
  page,
}) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  const header = page.locator(".topbar");
  await expect(header.locator(".profile-button")).toBeVisible();
  await expect(header.getByRole("textbox")).toHaveCount(0);
  await expect(
    header.locator(".notification-button, .notification-dot, .search-results"),
  ).toHaveCount(0);

  // Removing the sample global search must preserve the real customer search.
  await page
    .locator(".sidebar")
    .getByRole("button", { name: "Customers", exact: true })
    .click();
  await page
    .getByRole("textbox", { name: "Search customers", exact: true })
    .fill(`no-match-${Date.now()}`);
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await expect(
    page.getByText("No customers match your search.", { exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Clear", exact: true }).click();
  await expect(
    page.getByRole("textbox", { name: "Search customers", exact: true }),
  ).toHaveValue("");

  await header.locator(".profile-button").click();
  await header
    .getByRole("button", { name: "Account & preferences", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Company settings", exact: true }),
  ).toBeVisible();
  await header
    .getByRole("combobox", { name: "Display language" })
    .selectOption("fa");
  await header.locator(".profile-button").click();
  await expect(
    header
      .locator(".profile-popover")
      .getByText("مدیر سیستم · Sakhi Zada Uzbek Ltd", { exact: true }),
  ).toBeVisible();
  await expect(
    header.getByRole("button", { name: "خروج", exact: true }),
  ).toBeVisible();
  await header
    .getByRole("button", { name: "حساب و تنظیمات", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "تنظیمات شرکت", exact: true }),
  ).toBeVisible();

  await openSidebarPage(page, "Carpet categories", "fa");
  await page.getByRole("button", { name: "افزودن", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: "ذخیره", exact: true }).click();
  await expect(dialog.locator("#foundation-name-error")).toHaveText(
    "این خانه الزامی است.",
  );
  await expect(dialog.locator("#foundation-status option:checked")).toHaveText(
    "فعال",
  );
  await expect(dialog.locator("#foundation-status")).toHaveValue("active");
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 });
    const box = (await dialog.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(width);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
    ).toBe(true);
  }
  await page.screenshot({
    path: "test-results/phase2-dari-validation-mobile.png",
  });
  const name = `قالین آزمایشی ${Date.now()}`;
  await dialog.locator("#foundation-name").fill(name);
  await dialog.getByRole("button", { name: "ذخیره", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await page.reload();
  await openSidebarPage(page, "Carpet categories", "fa");
  await page.locator("#foundation-search").fill(name);
  await page.getByRole("button", { name: "جستجو", exact: true }).click();
  await expect(page.getByRole("row").filter({ hasText: name })).toContainText(
    "فعال",
  );
  // Searching the same text again must refetch, including after a server failure.
  await page.route("**/api/foundation/categories?**", (route) =>
    route.fulfill({
      status: 500,
      contentType: "application/json",
      body: JSON.stringify({ message: "Could not load records." }),
    }),
  );
  await page.getByRole("button", { name: "جستجو", exact: true }).click();
  await expect(
    page.getByRole("alert").filter({ hasText: "بارگیری معلومات ممکن نشد." }),
  ).toBeVisible();
  await page.unroute("**/api/foundation/categories?**");
  await page.getByRole("button", { name: "جستجو", exact: true }).click();
  await expect(page.getByRole("row").filter({ hasText: name })).toContainText(
    "فعال",
  );
  await header.locator(".profile-button").click();
  const menu = header.locator(".profile-popover");
  await expect(
    menu.getByRole("button", { name: "خروج", exact: true }),
  ).toBeVisible();
  const bounds = (await menu.boundingBox())!;
  expect(bounds.x).toBeGreaterThanOrEqual(0);
  expect(bounds.x + bounds.width).toBeLessThanOrEqual(320);
  await page.screenshot({ path: "test-results/phase2-topbar-mobile.png" });
});
