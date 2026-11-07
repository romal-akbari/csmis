import { expect, test, openSidebarPage, type Page } from "./fixtures";

async function login(page: Page) {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_sidebar");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

async function expectDesktopLayout(
  page: Page,
  collapsed: boolean,
  direction: "ltr" | "rtl",
) {
  await expect(page.locator(".dashboard-shell")).toHaveAttribute(
    "data-sidebar-collapsed",
    String(collapsed),
  );
  const toggle = page.locator(".topbar .sidebar-toggle");
  await expect(toggle).toHaveCount(1);
  await expect(toggle).toBeVisible();
  await expect(page.locator(".sidebar .sidebar-toggle")).toHaveCount(0);
  const toggleBounds = (await toggle.boundingBox())!;
  expect(toggleBounds.width).toBeLessThanOrEqual(30);
  expect(toggleBounds.height).toBeLessThanOrEqual(30);
  await expectSidebarWidth(page, collapsed);
  await expect
    .poll(async () => {
      const sidebar = (await page.locator(".sidebar").boundingBox())!;
      const main = (await page.locator(".main-shell").boundingBox())!;
      return direction === "rtl"
        ? Math.abs(main.x + main.width - sidebar.x)
        : Math.abs(main.x - sidebar.x - sidebar.width);
    })
    .toBeLessThan(2);
  const sidebar = (await page.locator(".sidebar").boundingBox())!;
  expect(Math.abs(sidebar.height - page.viewportSize()!.height)).toBeLessThan(
    1,
  );
  if (!collapsed) await expectStoryAtBottom(page);
  if (direction === "rtl") {
    expect(
      Math.abs(sidebar.x + sidebar.width - page.viewportSize()!.width),
    ).toBeLessThan(2);
  } else {
    expect(Math.abs(sidebar.x)).toBeLessThan(2);
  }
  await expectNoHorizontalOverflow(page);
}

async function expectSidebarWidth(page: Page, collapsed: boolean) {
  const expectedWidth = collapsed
    ? 76
    : await page.evaluate(
        () =>
          Number.parseFloat(
            getComputedStyle(document.documentElement).fontSize,
          ) * 18.5,
      );
  await expect
    .poll(async () => {
      const sidebar = (await page.locator(".sidebar").boundingBox())!;
      return Math.abs(sidebar.width - expectedWidth);
    })
    .toBeLessThan(1);
}

async function expectStoryAtBottom(page: Page) {
  await expect(page.locator(".sidebar-landscape-light")).toBeVisible();
  await expect
    .poll(() =>
      page.locator(".sidebar").evaluate((sidebar) => {
        const image = sidebar.querySelector(".sidebar-landscape-light")!;
        return Math.abs(
          image.getBoundingClientRect().bottom -
            sidebar.getBoundingClientRect().bottom,
        );
      }),
    )
    .toBeLessThan(1);
  const sizing = await page.locator(".sidebar").evaluate((sidebar) => {
    const image = sidebar
      .querySelector(".sidebar-landscape-light")!
      .getBoundingClientRect();
    const rootSize = Number.parseFloat(
      getComputedStyle(document.documentElement).fontSize,
    );
    return {
      widthDifference: Math.abs(image.width - sidebar.clientWidth),
      heightDifference: Math.abs(image.height - rootSize * 7.6875),
    };
  });
  expect(sizing.widthDifference).toBeLessThan(1);
  expect(sizing.heightDifference).toBeLessThan(1);
}

async function expectNoHorizontalOverflow(page: Page) {
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth + 1,
    ),
  ).toBe(true);
}

test("sidebar collapses, keeps navigation accessible, and remembers the preference", async ({
  page,
}) => {
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);
  await expectDesktopLayout(page, false, "ltr");
  await expect(page.locator('.brand-lockup[data-artwork-theme="light"]')).toBeVisible();
  const dashboard = page
    .locator(".sidebar")
    .getByRole("button", { name: "Dashboard", exact: true });
  const dashboardIcon = dashboard.locator(".sidebar-dashboard-icon");
  await expect(dashboard).toHaveAttribute("aria-current", "page");
  await expect(dashboardIcon).toHaveCSS("color", "rgb(241, 239, 235)");
  await page.screenshot({
    path: "test-results/sidebar-expanded.png",
    fullPage: true,
  });

  const collapse = page.locator(".topbar").getByRole("button", {
    name: "Collapse sidebar",
    exact: true,
  });
  await expect(collapse).toHaveAttribute("aria-expanded", "true");
  await expect(collapse).toHaveAttribute("aria-controls", "dashboard-sidebar");
  await collapse.click();
  await expectDesktopLayout(page, true, "ltr");
  await expect(dashboardIcon).toHaveCSS("color", "rgb(241, 239, 235)");
  const expand = page.locator(".topbar").getByRole("button", {
    name: "Expand sidebar",
    exact: true,
  });
  await expect(expand).toHaveAttribute("aria-expanded", "false");
  await page.screenshot({
    path: "test-results/sidebar-collapsed.png",
    fullPage: true,
  });

  // Icon-only navigation keeps a usable name and works at shorter screen heights.
  await page.setViewportSize({ width: 1440, height: 640 });
  const inventory = page
    .locator(".sidebar")
    .getByRole("button", { name: "Inventory", exact: true });
  await expect(inventory).toHaveAttribute("title", "Inventory");
  await openSidebarPage(page, "Branches");
  await expect(
    page.getByRole("heading", { name: "Branches", exact: true }),
  ).toBeVisible();
  await expect(inventory).toHaveClass(/active/);
  await expect(dashboard).not.toHaveAttribute("aria-current", "page");
  await expect(dashboardIcon).toHaveCSS("color", "rgb(32, 61, 84)");
  await expectNoHorizontalOverflow(page);

  await page.reload();
  await expectDesktopLayout(page, true, "ltr");
  // Keyboard activation must work just like the pointer control.
  await expand.focus();
  await page.keyboard.press("Enter");
  await expectDesktopLayout(page, false, "ltr");
  await expect(page.locator('.brand-lockup[data-artwork-theme="light"]')).toBeVisible();
  await page.reload();
  await expectDesktopLayout(page, false, "ltr");
  expect(errors).toEqual([]);
});

test("compact sidebar mirrors in Dari and mobile keeps its full-width drawer", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await login(page);
  await page
    .getByRole("combobox", { name: "Display language" })
    .selectOption("fa");
  await expect(page.locator(".dashboard-shell")).toHaveAttribute("dir", "rtl");
  await page
    .getByRole("button", { name: "جمع کردن نوار کناری", exact: true })
    .click();
  await expectDesktopLayout(page, true, "rtl");
  await openSidebarPage(page, "Branches", "fa");
  await expect(
    page.locator('section[lang="fa"]').getByRole("table"),
  ).toBeVisible();
  await page.screenshot({
    path: "test-results/sidebar-collapsed-rtl.png",
    fullPage: true,
  });

  await page.setViewportSize({ width: 390, height: 844 });
  await expect(page.locator(".sidebar")).not.toBeVisible();
  await expect(page.locator(".main-shell")).toHaveCSS(
    "margin-inline-start",
    "0px",
  );
  await page
    .getByRole("button", { name: "Open navigation", exact: true })
    .click();
  await expect(page.locator(".sidebar")).toBeVisible();
  await expectSidebarWidth(page, false);
  await expect(page.locator(".sidebar")).toHaveCSS(
    "transform",
    "matrix(1, 0, 0, 1, 0, 0)",
  );
  await expect(page.locator(".sidebar-toggle")).not.toBeVisible();
  const drawer = (await page.locator(".sidebar").boundingBox())!;
  expect(Math.abs(drawer.x + drawer.width - 390)).toBeLessThan(2);
  await expectStoryAtBottom(page);
  const mobileInventory = page
    .locator(".sidebar")
    .getByRole("button", { name: "موجودی", exact: true });
  await expect(mobileInventory).toBeVisible();
  await openSidebarPage(page, "Branches", "fa");
  await expect(page.locator(".sidebar")).not.toBeVisible();
  await expectNoHorizontalOverflow(page);
  await page
    .getByRole("button", { name: "Open navigation", exact: true })
    .click();
  await page.keyboard.press("Escape");
  await expect(page.locator(".sidebar")).not.toBeVisible();
  await expect(
    page.getByRole("button", { name: "Open navigation", exact: true }),
  ).toBeFocused();

  // Visiting mobile must not erase the desktop size preference.
  await page.setViewportSize({ width: 1440, height: 900 });
  await expectDesktopLayout(page, true, "rtl");
  await page
    .getByRole("button", { name: "باز کردن نوار کناری", exact: true })
    .click();
  await expectDesktopLayout(page, false, "rtl");
  await page
    .getByRole("combobox", { name: "Display language" })
    .selectOption("en");
  await expectDesktopLayout(page, false, "ltr");
});
