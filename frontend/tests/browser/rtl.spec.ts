import { expect, test, openSidebarPage, type Page } from "./fixtures";

async function expectDirection(page: Page, direction: "rtl" | "ltr") {
  const locale = direction === "rtl" ? "fa" : "en";
  await expect(page.locator("html")).toHaveAttribute("dir", direction);
  await expect(page.locator("html")).toHaveAttribute("lang", locale);
  await expect(page.locator(".dashboard-shell")).toHaveAttribute(
    "dir",
    direction,
  );
  await expect(page.locator(".topbar")).toHaveCSS("direction", direction);
  await expect(
    page.getByRole("combobox", { name: "Display language" }),
  ).toHaveValue(locale);
}

async function expectDesktopLayout(page: Page, direction: "rtl" | "ltr") {
  await expectDirection(page, direction);
  const viewport = page.viewportSize()!;
  const sidebar = (await page.locator(".sidebar").boundingBox())!;
  const main = (await page.locator(".main-shell").boundingBox())!;
  const toggle = (await page.locator(".topbar .sidebar-toggle").boundingBox())!;
  const actions = (await page.locator(".header-actions").boundingBox())!;
  if (direction === "rtl") {
    expect(Math.abs(sidebar.x + sidebar.width - viewport.width)).toBeLessThan(
      2,
    );
    expect(Math.abs(main.x + main.width - sidebar.x)).toBeLessThan(2);
    expect(toggle.x).toBeGreaterThan(actions.x);
  } else {
    expect(Math.abs(sidebar.x)).toBeLessThan(2);
    expect(Math.abs(main.x - sidebar.width)).toBeLessThan(2);
    expect(toggle.x).toBeLessThan(actions.x);
  }
}

test("language switches mirror the full layout, persist, and work on mobile", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_rtl");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
  await expectDesktopLayout(page, "ltr");
  const language = page.getByRole("combobox", { name: "Display language" });
  await expect(language).toHaveCount(1);
  await expect(page.locator(".topbar .language-dropdown")).toBeVisible();
  await expect(page.locator(".language-switch")).toHaveCount(0);
  await page.locator(".profile-button").click();
  await expect(page.locator(".profile-popover")).toBeVisible();
  await page.screenshot({
    path: "test-results/topbar-language-account.png",
    fullPage: true,
  });
  await page.locator(".profile-button").click();

  // Use the header control: this previously changed text without mirroring layout.
  await language.selectOption("fa");
  await expectDesktopLayout(page, "rtl");
  await page.reload();
  await expectDesktopLayout(page, "rtl");
  await openSidebarPage(page, "Branches", "fa");
  const screen = page.locator('section[lang="fa"]');
  await expect(screen).toHaveAttribute("dir", "rtl");
  await expect(screen.getByRole("table")).toBeVisible();
  await expect(language).toHaveCount(1);
  await expect(
    screen.getByRole("button", { name: /^(English|دری)$/ }),
  ).toHaveCount(0);
  const headers = screen.getByRole("columnheader");
  expect((await headers.first().boundingBox())!.x).toBeGreaterThan(
    (await headers.last().boundingBox())!.x,
  );

  await screen.getByRole("button", { name: "افزودن", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toHaveCSS("direction", "rtl");
  await dialog.locator("#foundation-code").fill("RTL-CHECK");
  await expect(dialog.locator("#foundation-code")).toHaveCSS(
    "direction",
    "ltr",
  );
  await dialog.locator("#foundation-name").fill("شعبه کابل");
  await expect(dialog.locator("#foundation-name")).toHaveCSS(
    "direction",
    "rtl",
  );
  await dialog.getByRole("button", { name: "بستن", exact: true }).click();

  // Foundation screens use the single global language dropdown in the top bar.
  await language.selectOption("en");
  await expectDesktopLayout(page, "ltr");
  await expect(
    page
      .locator('section[lang="en"]')
      .getByRole("button", { name: /^(English|دری)$/ }),
  ).toHaveCount(0);
  await language.selectOption("fa");
  await expectDesktopLayout(page, "rtl");
  await page.screenshot({
    path: "test-results/rtl-desktop.png",
    fullPage: true,
  });

  await page.setViewportSize({ width: 390, height: 844 });
  await expect(language).toBeVisible();
  const languageBounds = (await language.boundingBox())!;
  expect(languageBounds.x).toBeGreaterThanOrEqual(0);
  expect(languageBounds.x + languageBounds.width).toBeLessThanOrEqual(391);
  await page.screenshot({
    path: "test-results/topbar-mobile.png",
    fullPage: true,
  });
  await page.getByRole("button", { name: "Open navigation" }).click();
  await expect(page.locator(".sidebar")).toBeVisible();
  await expect(page.locator(".sidebar")).toHaveCSS(
    "transform",
    "matrix(1, 0, 0, 1, 0, 0)",
  );
  const drawer = (await page.locator(".sidebar").boundingBox())!;
  expect(Math.abs(drawer.x + drawer.width - 390)).toBeLessThan(2);
  await page.keyboard.press("Escape");
  await expect(page.locator(".sidebar")).not.toBeVisible();
  await expect(
    page.getByRole("button", { name: "Open navigation" }),
  ).toBeFocused();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth + 1,
    ),
  ).toBe(true);
  await page.screenshot({
    path: "test-results/rtl-mobile.png",
    fullPage: true,
  });

  await language.selectOption("en");
  await expectDirection(page, "ltr");
  await page.getByRole("button", { name: "Open navigation" }).click();
  await expect(page.locator(".sidebar")).toHaveCSS(
    "transform",
    "matrix(1, 0, 0, 1, 0, 0)",
  );
  expect(
    Math.abs((await page.locator(".sidebar").boundingBox())!.x),
  ).toBeLessThan(2);
  await page.keyboard.press("Escape");

  // A failed preference save must not leave UI direction and saved language apart.
  await page.route("**/api/profile/locale", (route) =>
    route.fulfill({
      status: 500,
      contentType: "application/json",
      body: JSON.stringify({ message: "Language save unavailable" }),
    }),
  );
  await language.selectOption("fa");
  await expect(
    page
      .getByRole("alert")
      .filter({ hasText: "Language save unavailable" })
      .first(),
  ).toBeVisible();
  await expectDirection(page, "ltr");
  await page.unroute("**/api/profile/locale");

  await language.selectOption("fa");
  await expectDirection(page, "rtl");
  await page.locator(".profile-button").click();
  await expect(page.locator(".profile-popover")).toBeVisible();
  const popover = (await page.locator(".profile-popover").boundingBox())!;
  expect(popover.x).toBeGreaterThanOrEqual(0);
  expect(popover.x + popover.width).toBeLessThanOrEqual(391);
  await page.getByRole("button", { name: "خروج", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Welcome back" }),
  ).toBeVisible();
  await expect(page.locator("html")).toHaveAttribute("dir", "ltr");
  await expect(page.locator("html")).toHaveAttribute("lang", "en");
});
