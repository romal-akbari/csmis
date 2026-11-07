import { expect, test, type Page } from "./fixtures";

async function checkLayout(page: Page) {
  // Measure one layout frame, including while the sidebar width animates.
  const { main, screen, panel } = await page.evaluate(() => {
    const bounds = (selector: string) => {
      const rect = document.querySelector(selector)!.getBoundingClientRect();
      return { x: rect.x, width: rect.width };
    };
    return {
      main: bounds(".main-shell"),
      screen: bounds(".customers-screen"),
      panel: bounds(".customers-panel"),
    };
  });
  expect(Math.abs(screen.x - main.x)).toBeLessThan(2);
  expect(Math.abs(screen.width - main.width)).toBeLessThan(2);
  const gutters = [
    panel.x - screen.x,
    screen.x + screen.width - panel.x - panel.width,
  ];
  for (const gutter of gutters) {
    expect(gutter).toBeGreaterThanOrEqual(8);
    expect(gutter).toBeLessThanOrEqual(20);
  }
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth + 1,
    ),
  ).toBe(true);
  const controls = await page
    .locator(
      ".customers-filters input, .customers-filters select, .customers-filters button",
    )
    .evaluateAll((elements) =>
      elements.map((element) => {
        const bounds = element.getBoundingClientRect();
        return {
          start: bounds.left,
          end: bounds.right,
          height: bounds.height,
          font: parseFloat(getComputedStyle(element).fontSize),
        };
      }),
    );
  for (const control of controls) {
    expect(control.start).toBeGreaterThanOrEqual(0);
    expect(control.end).toBeLessThanOrEqual(page.viewportSize()!.width + 1);
    expect(control.height).toBeGreaterThanOrEqual(36);
    expect(control.font).toBeGreaterThanOrEqual(12);
  }
}

test("Customers fills its workspace and reflows across desktop, tablet and mobile in both directions", async ({
  page,
}) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1920, height: 1080 });
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
  const language = page.getByRole("combobox", { name: "Display language" });
  await language.selectOption("en");
  await page.route("**/api/customers?*", async (route) => {
    await route.fulfill({
      json: {
        current_page: 1,
        last_page: 1,
        per_page: 10,
        total: 2,
        company_today: "2026-10-17",
        company_timezone: "Asia/Kabul",
        data: [
          {
            id: 1,
            name: "Zahra Ahmadi",
            phone: "+93 70 123 4567",
            address: "Kabul",
            customer_type: "retail",
            status: "active",
            registration_date: "2026-10-01",
            notes: null,
            deleted_at: null,
          },
          {
            id: 2,
            name: "W".repeat(150),
            phone: "+93 79 555 4321",
            address: null,
            customer_type: "wholesale",
            status: "active",
            registration_date: "2026-10-02",
            notes: null,
            deleted_at: null,
          },
        ],
      },
    });
  });
  await page
    .locator(".sidebar")
    .getByRole("button", { name: "Customers", exact: true })
    .click();
  await expect(page.locator(".customers-table tbody tr")).toHaveCount(2);
  await checkLayout(page);
  expect(
    await page
      .locator(".customers-table-wrap")
      .evaluate((element) => element.scrollWidth <= element.clientWidth + 1),
  ).toBe(true);
  await page.screenshot({
    path: "test-results/customer-responsive-desktop.png",
  });
  await page
    .getByRole("button", { name: "Collapse sidebar", exact: true })
    .click();
  await expect(page.locator(".sidebar")).toHaveCSS("width", "76px");
  await checkLayout(page);
  await page
    .getByRole("button", { name: "Expand sidebar", exact: true })
    .click();

  for (const [width, height] of [
    [1440, 720],
    [1024, 768],
    [768, 1024],
    [390, 844],
    [320, 740],
  ]) {
    await page.setViewportSize({ width, height });
    await checkLayout(page);
    if (width === 768)
      await page.screenshot({
        path: "test-results/customer-responsive-tablet.png",
      });
  }
  const tableSizes = await page
    .locator(".customers-table-wrap")
    .evaluate((element) => ({
      outer: element.clientWidth,
      content: element.scrollWidth,
    }));
  expect(tableSizes.content).toBeGreaterThan(tableSizes.outer);
  await language.selectOption("fa");
  await expect(page.locator(".customers-screen")).toHaveAttribute("dir", "rtl");
  for (const [width, height] of [
    [1920, 1080],
    [1024, 768],
    [390, 844],
    [320, 740],
  ]) {
    await page.setViewportSize({ width, height });
    await checkLayout(page);
    if (width === 390)
      await page.screenshot({
        path: "test-results/customer-responsive-mobile-rtl.png",
      });
  }

  await page.locator(".customers-head-actions .primary-button").click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toBeVisible();
  for (const [width, height] of [
    [320, 740],
    [1440, 720],
  ]) {
    await page.setViewportSize({ width, height });
    const bounds = (await dialog.boundingBox())!;
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(width);
    expect(bounds.height).toBeLessThanOrEqual(height);
    expect(
      await dialog.evaluate(
        (element) => element.scrollWidth <= element.clientWidth + 1,
      ),
    ).toBe(true);
    const sizes = await dialog
      .locator("input, select, textarea")
      .evaluateAll((elements) =>
        elements.map((element) =>
          parseFloat(getComputedStyle(element).fontSize),
        ),
      );
    for (const size of sizes) expect(size).toBeGreaterThanOrEqual(16);
    await dialog.locator(".customers-form-body").evaluate((element) => {
      element.scrollTop = element.scrollHeight;
    });
    const close = (await dialog
      .locator(".customers-modal-close")
      .boundingBox())!;
    const actions = (await dialog
      .locator(".customers-form-actions")
      .boundingBox())!;
    expect(close.y).toBeGreaterThanOrEqual(0);
    expect(actions.y + actions.height).toBeLessThanOrEqual(height);
    await dialog.locator(".customers-form-body").evaluate((element) => {
      element.scrollTop = 0;
    });
    await page.screenshot({
      path: `test-results/customer-dialog-${width === 320 ? "mobile" : "desktop"}-rtl.png`,
    });
  }
  await page.keyboard.press("Escape");
  await language.selectOption("en");
});
