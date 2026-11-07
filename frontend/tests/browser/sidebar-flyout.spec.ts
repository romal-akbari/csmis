import { expect, test, openSidebarPage, type Page } from "./fixtures";
import { productionText } from "../../src/lib/manager-production-i18n";

async function login(page: Page) {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_sidebar");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

async function geometry(page: Page) {
  return page.locator(".sidebar").evaluate((sidebar) => {
    const rect = sidebar.getBoundingClientRect();
    const nav = sidebar.querySelector(".navigation")!.getBoundingClientRect();
    return [rect.width, rect.height, nav.width, nav.height].map(
      (value) => Math.round(value * 100) / 100,
    );
  });
}

function expectSameGeometry(actual: number[], expected: number[]) {
  // Layout engines can round the inherited responsive width by a fraction of a pixel.
  actual.forEach((value, index) =>
    expect(Math.abs(value - expected[index])).toBeLessThan(0.5),
  );
}

test("only live modules appear and all implemented destinations open without resizing the sidebar", async ({
  page,
}) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);
  const sidebar = page.locator(".sidebar");
  await expect(sidebar.locator(".navigation > .nav-item")).toHaveCount(13);
  await expect(sidebar.locator(".navigation > .nav-item").nth(1)).toHaveAttribute("aria-label", "Inventory");
  await expect(sidebar.locator(".navigation > .nav-item").nth(2)).toHaveAttribute("aria-label", "Purchase");
  const purchaseIcon = sidebar.getByRole("button", { name: "Purchase", exact: true }).locator("svg").first();
  await expect(purchaseIcon).toBeVisible();
  const iconBounds = (await purchaseIcon.boundingBox())!;
  expect(iconBounds.width).toBeGreaterThanOrEqual(12);
  expect(iconBounds.height).toBeGreaterThanOrEqual(12);
  for (const label of [
    "Orders",
    "Shipments",
    "Export Orders",
    "Reports",
    "Analytics",
  ]) {
    await expect(
      sidebar.getByRole("button", { name: label, exact: true }),
    ).toHaveCount(0);
  }
  const before = await geometry(page);
  const destinations = {
    Inventory: [
      "Branches",
      "Stock locations",
      "Carpet categories",
      "Washing facilities",
      "Carpets",
      "Stock receipts",
      "Stock transfers",
      "Stock corrections",
      "Materials & tools stock",
    ],
    Purchase: ["Carpet purchases", "Materials & tools", "Purchase drafts", "Weaver returns"],
    "Weavers & Artisans": ["Weaver records", "Weaver payments", "Weaver refunds"],
    Suppliers: ["Supplier records", "Supplier payments"],
    Production: ["Production orders", "Production managers", "Partnerships"],
    Sales: ["Sales invoices", "Customer receipts"],
    Expenses: ["Expense records", "Expense categories"],
    Operations: ["Employees", "Washing batches", "Washing facility stock"],
    Finance: [
      "Accounts",
      "Currencies",
      "Exchange rates",

      "Washing costs",
    ],
    Users: ["Users and access", "Roles and permissions"],
    Settings: ["Company settings", "Audit history"],
  };
  for (const [group, labels] of Object.entries(destinations)) {
    for (const label of labels) {
      const trigger = sidebar.getByRole("button", { name: group, exact: true });
      await trigger.click();
      const menu = page.getByRole("menu", { name: group, exact: true });
      await expect(menu).toBeVisible();
      await expect(menu.getByRole("menuitem")).toHaveText(labels);
      if (group === "Inventory") {
        for (const purchasePage of destinations.Purchase) {
          await expect(menu.getByRole("menuitem", { name: purchasePage, exact: true })).toHaveCount(0);
        }
      }
      await expect(trigger).toHaveAttribute("aria-expanded", "true");
      expectSameGeometry(await geometry(page), before);
      const sidebarBox = (await sidebar.boundingBox())!;
      const menuBox = (await menu.boundingBox())!;
      expect(menuBox.x).toBeGreaterThanOrEqual(sidebarBox.x + sidebarBox.width);
      expect(menuBox.y).toBeGreaterThanOrEqual(0);
      expect(menuBox.y + menuBox.height).toBeLessThanOrEqual(900);
      if (label === "Accounts") {
        await menu.evaluate(async (element) => {
          await Promise.allSettled(
            element.getAnimations().map((animation) => animation.finished),
          );
        });
        await page.screenshot({
          path: "test-results/sidebar-flyout-desktop.png",
        });
      }
      await menu.getByRole("menuitem", { name: label, exact: true }).click();
      await expect(menu).not.toBeVisible();
      const directoryHeading = label === "Weaver records" ? "Weavers & Artisans" : label === "Supplier records" ? "Suppliers" : null;
      await expect(
        page.getByRole("heading", { name: directoryHeading ?? label, exact: true }),
      ).toBeVisible();
      await expect(
        page
          .locator("#dashboard-content")
          .getByText("Loading...", { exact: true }),
      ).toHaveCount(0);
      await expect(
        page.locator("#dashboard-content").getByRole("alert"),
      ).toHaveCount(0);
      await expect(page.locator("#dashboard-content nav")).toHaveCount(directoryHeading ? 1 : 0);
      await expect(trigger).toHaveClass(/active/);
      expectSameGeometry(await geometry(page), before);
      await expect(sidebar.locator(".navigation > .nav-item")).toHaveCount(13);
    }
  }
  // Settings contains only company preferences and audit, without unrelated lists.
  await sidebar.getByRole("button", { name: "Settings", exact: true }).click();
  await expect(
    page
      .getByRole("menu", { name: "Settings", exact: true })
      .getByRole("menuitem"),
  ).toHaveText(["Company settings", "Audit history"]);
  await page.keyboard.press("Escape");
});

test("flyouts support keyboard, compact rail, Dari and mobile without resizing the sidebar", async ({
  page,
}) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);
  const sidebar = page.locator(".sidebar");
  const inventory = sidebar.getByRole("button", {
    name: "Inventory",
    exact: true,
  });
  await inventory.focus();
  await page.keyboard.press("Enter");
  const menu = page.getByRole("menu", { name: "Inventory", exact: true });
  await expect(
    menu.getByRole("menuitem", { name: "Branches", exact: true }),
  ).toBeFocused();
  await page.keyboard.press("ArrowDown");
  await expect(
    menu.getByRole("menuitem", { name: "Stock locations", exact: true }),
  ).toBeFocused();
  await page.keyboard.press("Home");
  await expect(
    menu.getByRole("menuitem", { name: "Branches", exact: true }),
  ).toBeFocused();
  await page.keyboard.press("End");
  await expect(
    menu.getByRole("menuitem", { name: "Materials & tools stock", exact: true }),
  ).toBeFocused();
  await page.keyboard.press("Escape");
  await expect(menu).not.toBeVisible();
  await expect(inventory).toBeFocused();

  await page.keyboard.press("Enter");
  await expect(menu).toBeVisible();
  await expect(
    menu.getByRole("menuitem", { name: "Branches", exact: true }),
  ).toBeFocused();
  await page.keyboard.press("Tab");
  await expect(menu).not.toBeVisible();
  await expect(
    sidebar.getByRole("button", { name: "Purchase", exact: true }),
  ).toBeFocused();

  await page
    .getByRole("button", { name: "Collapse sidebar", exact: true })
    .click();
  await expect(sidebar).toHaveCSS("width", "76px");
  const compact = await geometry(page);
  await sidebar.getByRole("button", { name: "Finance", exact: true }).click();
  const finance = page.getByRole("menu", { name: "Finance", exact: true });
  await expect(finance).toBeVisible();
  expectSameGeometry(await geometry(page), compact);
  await page.mouse.click(1400, 400);
  await expect(finance).not.toBeVisible();

  await page
    .getByRole("combobox", { name: "Display language" })
    .selectOption("fa");
  await sidebar.getByRole("button", { name: "امور مالی", exact: true }).click();
  const rtlMenu = page.getByRole("menu", { name: "امور مالی", exact: true });
  await expect(rtlMenu).toHaveAttribute("dir", "rtl");
  const sideBox = (await sidebar.boundingBox())!;
  const rtlBox = (await rtlMenu.boundingBox())!;
  expect(rtlBox.x + rtlBox.width).toBeLessThanOrEqual(sideBox.x);
  await page.keyboard.press("Escape");
  await sidebar.getByRole("button", { name: "خرید", exact: true }).click();
  const rtlPurchase = page.getByRole("menu", { name: "خرید", exact: true });
  await expect(rtlPurchase).toHaveAttribute("dir", "rtl");
  await expect(rtlPurchase.getByRole("menuitem")).toHaveText(["Carpet purchases", "Materials & tools", "Purchase drafts", "Weaver returns"].map((text) => productionText("fa", text)));
  const rtlPurchaseBox = (await rtlPurchase.boundingBox())!;
  expect(rtlPurchaseBox.x + rtlPurchaseBox.width).toBeLessThanOrEqual(sideBox.x);
  expectSameGeometry(await geometry(page), compact);
  await page.keyboard.press("Escape");

  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 });
    await page
      .getByRole("button", { name: "Open navigation", exact: true })
      .click();
    await expect(sidebar).toBeVisible();
    const dimensions = await geometry(page);
    const inventoryTrigger = sidebar.getByRole("button", {
      name: "موجودی",
      exact: true,
    });
    await inventoryTrigger.click();
    const mobile = page.getByRole("menu", {
      name: "موجودی",
      exact: true,
    });
    await expect(mobile).toBeVisible();
    const bounds = (await mobile.boundingBox())!;
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(width);
    expect(bounds.y).toBeGreaterThanOrEqual(0);
    expect(bounds.y + bounds.height).toBeLessThanOrEqual(844);
    expectSameGeometry(await geometry(page), dimensions);
    if (width === 390) {
      await mobile.evaluate(async (element) => {
        await Promise.allSettled(
          element.getAnimations().map((animation) => animation.finished),
        );
      });
      await page.screenshot({
        path: "test-results/sidebar-flyout-mobile-rtl.png",
      });
    }
    await page.keyboard.press("Escape");
    await expect(mobile).not.toBeVisible();
    await expect(sidebar).toBeVisible();
    await expect(inventoryTrigger).toBeFocused();
    await openSidebarPage(page, "Branches", "fa");
    await expect(sidebar).not.toBeVisible();
    await expect(
      page.getByRole("heading", { name: "شعبه‌ها", exact: true }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Open navigation", exact: true }).click();
    const purchaseDimensions = await geometry(page);
    await sidebar.getByRole("button", { name: "خرید", exact: true }).click();
    const purchaseMenu = page.getByRole("menu", { name: "خرید", exact: true });
    await expect(purchaseMenu).toHaveAttribute("dir", "rtl");
    await expect(purchaseMenu.getByRole("menuitem")).toHaveText(["Carpet purchases", "Materials & tools", "Purchase drafts", "Weaver returns"].map((text) => productionText("fa", text)));
    const purchaseBounds = (await purchaseMenu.boundingBox())!;
    expect(purchaseBounds.x).toBeGreaterThanOrEqual(0);
    expect(purchaseBounds.x + purchaseBounds.width).toBeLessThanOrEqual(width);
    expect(purchaseBounds.y).toBeGreaterThanOrEqual(0);
    expect(purchaseBounds.y + purchaseBounds.height).toBeLessThanOrEqual(844);
    expectSameGeometry(await geometry(page), purchaseDimensions);
    if (width === 390) {
      await purchaseMenu.evaluate(async (element) => {
        await Promise.allSettled(element.getAnimations().map((animation) => animation.finished));
      });
      await page.screenshot({ path: "test-results/purchase-sidebar-mobile-rtl.png" });
    }
    await purchaseMenu.getByRole("menuitem", { name: "خرید قالین", exact: true }).click();
    await expect(sidebar).not.toBeVisible();
    await expect(page.getByRole("heading", { name: "خرید قالین", exact: true })).toBeVisible();
    await expect(sidebar.getByRole("button", { name: "خرید", exact: true, includeHidden: true })).toHaveClass(/active/);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
    ).toBe(true);
  }
});

test("purchase destinations retain their parent highlight and sidebar dimensions after refresh", async ({ page }) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);
  const sidebar = page.locator(".sidebar");
  const before = await geometry(page);
  for (const label of ["Carpet purchases", "Purchase drafts", "Weaver returns"]) {
    await openSidebarPage(page, label);
    await expect(page.getByRole("heading", { name: label, exact: true })).toBeVisible();
    await expect(sidebar.getByRole("button", { name: "Purchase", exact: true })).toHaveClass(/active/);
    await expect(sidebar.getByRole("button", { name: "Inventory", exact: true })).not.toHaveClass(/active/);
    expect(new URL(page.url()).searchParams.get("page")).toBe(label);
    await page.reload();
    await expect(page.getByRole("heading", { name: label, exact: true })).toBeVisible();
    await expect(sidebar.getByRole("button", { name: "Purchase", exact: true })).toHaveClass(/active/);
    await expect(sidebar.getByRole("button", { name: "Inventory", exact: true })).not.toHaveClass(/active/);
    expectSameGeometry(await geometry(page), before);
    await expect(sidebar.locator(".navigation > .nav-item")).toHaveCount(13);
  }
  await sidebar.getByRole("button", { name: "Purchase", exact: true }).click();
  const menu = page.getByRole("menu", { name: "Purchase", exact: true });
  await expect(menu.getByRole("menuitem", { name: "Weaver returns", exact: true })).toHaveAttribute("aria-current", "page");
  await page.screenshot({ path: "test-results/purchase-sidebar-desktop.png", fullPage: true });
});
