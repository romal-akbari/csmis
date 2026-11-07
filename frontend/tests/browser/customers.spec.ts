import { expect, test, type Page } from "./fixtures";

async function login(page: Page) {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

test("customer dialog transitions keep focus contained until dismissal and respect reduced motion", async ({
  page,
}) => {
  await page.emulateMedia({ reducedMotion: "no-preference" });
  await login(page);
  await page.locator(".sidebar").getByRole("button", { name: "Customers", exact: true }).click();
  const add = page.getByRole("button", { name: "+ Add customer", exact: true });
  await expect(add).toBeEnabled();
  await add.focus();
  // Observe the first rendered frame so the opening cannot silently become instant.
  const enteredWithMotion = await page.evaluate(() => {
    return new Promise<boolean>((resolve) => {
      const observer = new MutationObserver(() => {
        const dialog = document.querySelector<HTMLDialogElement>(
          ".customers-form[open]",
        );
        if (!dialog) return;
        observer.disconnect();
        resolve(
          dialog
            .getAnimations()
            .some((animation) => animation.playState === "running") &&
            Number(getComputedStyle(dialog).opacity) < 1,
        );
      });
      observer.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ["open"],
      });
      document
        .querySelector<HTMLButtonElement>(
          ".customers-head-actions .primary-button",
        )!
        .click();
    });
  });
  expect(enteredWithMotion).toBe(true);
  const dialog = page.getByRole("dialog");
  await dialog.evaluate(async (element) => {
    await Promise.allSettled(
      element.getAnimations().map((animation) => animation.finished),
    );
  });
  await expect(dialog.getByLabel("Name *", { exact: true })).toBeFocused();
  const exit = await dialog.evaluate(async (element: HTMLDialogElement) => {
    element.querySelector<HTMLButtonElement>(".customers-modal-close")!.click();
    await new Promise<void>((resolve) =>
      requestAnimationFrame(() => resolve()),
    );
    const animations = element.getAnimations();
    animations.forEach((animation) => animation.pause());
    return {
      modal: element.matches(":modal"),
      animated: animations.length > 0,
    };
  });
  expect(exit).toEqual({ modal: true, animated: true });
  // Repeated dismissal must keep keyboard focus inside until the exit finishes.
  await page.keyboard.press("Escape");
  await expect(dialog).toHaveAttribute("open", "");
  expect(
    await dialog.evaluate((element) =>
      element.contains(document.activeElement),
    ),
  ).toBe(true);
  await dialog.evaluate((element) => {
    element.getAnimations().forEach((animation) => animation.play());
  });
  await expect(dialog).toHaveCount(0);
  await expect(add).toBeFocused();

  await page.emulateMedia({ reducedMotion: "reduce" });
  await add.click();
  await expect(dialog).toBeVisible();
  expect(
    await dialog.evaluate((element) => element.getAnimations().length),
  ).toBe(0);
  await page.keyboard.press("Escape");
  await expect(dialog).toHaveCount(0);
  await expect(add).toBeFocused();

  // Reopening after dismissal also supports the footer and backdrop controls.
  await add.click();
  await dialog.getByRole("button", { name: "Cancel", exact: true }).click();
  await expect(dialog).toHaveCount(0);
  await add.click();
  await page.mouse.click(2, 2);
  await expect(dialog).toHaveCount(0);
  await expect(add).toBeFocused();
});

test("customer validation, audited lifecycle, filters and translated mobile form work end to end", async ({
  page,
}) => {
  test.setTimeout(120_000);
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await login(page);
  await page.locator(".sidebar").getByRole("button", { name: "Customers", exact: true }).click();
  const screen = page.locator(".customers-screen");
  await expect(
    screen.getByRole("button", { name: "Dashboard", exact: true }),
  ).toHaveCount(0);
  await expect(
    screen.getByRole("heading", { name: "Customers", exact: true }),
  ).toBeVisible();
  await screen
    .getByRole("button", { name: "+ Add customer", exact: true })
    .click();
  const dialog = page.getByRole("dialog");
  const close = dialog.getByRole("button", { name: "Close", exact: true });
  await expect(close.locator("svg")).toBeVisible();
  await close.click();
  await expect(dialog).not.toBeVisible();
  await expect(
    screen.getByRole("button", { name: "+ Add customer", exact: true }),
  ).toBeFocused();
  await screen
    .getByRole("button", { name: "+ Add customer", exact: true })
    .click();
  await dialog
    .getByRole("button", { name: "Add customer", exact: true })
    .click();
  await expect(
    dialog.getByText("Customer name is required.", { exact: true }),
  ).toBeVisible();
  const name = `Customer E2E ${Date.now().toString(36)}`;
  await dialog.getByLabel("Name *", { exact: true }).fill(name);
  await dialog.getByLabel("Phone", { exact: true }).fill("invalid-phone");
  await dialog
    .getByRole("button", { name: "Add customer", exact: true })
    .click();
  await expect(
    dialog.getByText("Use digits, spaces, +, - and ( ) only.", { exact: true }),
  ).toBeVisible();
  await dialog.getByLabel("Phone", { exact: true }).fill("+93 70 123 4567");
  let rejected = false;
  let submitted = 0;
  await page.route("**/api/customers", async (route) => {
    if (route.request().method() !== "POST") return route.continue();
    submitted++;
    if (!rejected) {
      rejected = true;
      return route.fulfill({
        status: 422,
        contentType: "application/json",
        body: JSON.stringify({
          message: "Invalid input.",
          errors: { name: ["Server rejected this customer name."] },
        }),
      });
    }
    return route.continue();
  });
  await dialog
    .getByRole("button", { name: "Add customer", exact: true })
    .click();
  await expect(
    dialog.getByText("Server rejected this customer name.", { exact: true }),
  ).toBeVisible();
  await dialog.getByLabel("Name *", { exact: true }).fill(name);
  // Two synchronous submits must issue one mutation while saving.
  await dialog.locator("form").evaluate((form: HTMLFormElement) => {
    form.requestSubmit();
    form.requestSubmit();
  });
  await expect(dialog).not.toBeVisible();
  expect(submitted).toBe(2); // One rejected request plus one committed request.
  await page.unroute("**/api/customers");

  await screen.getByLabel("Search customers", { exact: true }).fill(name);
  await screen.getByRole("button", { name: "Search", exact: true }).click();
  const row = screen.getByRole("row").filter({ hasText: name });
  await expect(row).toHaveCount(1);
  await row.getByRole("button", { name: "Edit", exact: true }).click();
  await dialog.getByLabel("Type *", { exact: true }).selectOption("vip");
  await dialog.getByLabel("Status *", { exact: true }).selectOption("inactive");
  await dialog
    .getByLabel("Notes", { exact: true })
    .fill("Browser lifecycle verified");
  await dialog
    .getByRole("button", { name: "Save changes", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await expect(row).toContainText("Inactive");
  await screen
    .getByLabel("Filter by status", { exact: true })
    .selectOption("active");
  await expect(row).toHaveCount(0);
  await screen
    .getByLabel("Filter by status", { exact: true })
    .selectOption("inactive");
  await expect(row).toHaveCount(1);
  await screen
    .getByLabel("Filter by customer type", { exact: true })
    .selectOption("retail");
  await expect(row).toHaveCount(0);
  await screen
    .getByLabel("Filter by customer type", { exact: true })
    .selectOption("vip");
  await expect(row).toHaveCount(1);

  await row.getByRole("button", { name: "Archive", exact: true }).click();
  await row.getByRole("button", { name: "Cancel", exact: true }).click();
  await expect(
    row.getByRole("button", { name: "Archive", exact: true }),
  ).toBeVisible();
  await row.getByRole("button", { name: "Archive", exact: true }).click();
  await row
    .getByRole("button", { name: "Confirm archive?", exact: true })
    .click();
  await expect(row).toHaveCount(0);
  await screen
    .getByLabel("Directory records", { exact: true })
    .selectOption("only");
  await expect(row).toHaveCount(1);
  await expect(
    row.getByRole("button", { name: "Edit", exact: true }),
  ).toHaveCount(0);
  await row.getByRole("button", { name: "Restore", exact: true }).click();
  await expect(row).toHaveCount(0);
  await screen
    .getByLabel("Directory records", { exact: true })
    .selectOption("");
  await expect(row).toContainText("Inactive");

  const language = page.getByRole("combobox", { name: "Display language" });
  await language.selectOption("fa");
  await expect(screen).toHaveAttribute("dir", "rtl");
  await expect(
    screen.getByRole("heading", { name: "مشتریان", exact: true }),
  ).toBeVisible();
  await page.setViewportSize({ width: 390, height: 844 });
  await screen
    .getByRole("button", { name: "+ افزودن مشتری", exact: true })
    .click();
  await expect(dialog).toHaveCSS("direction", "rtl");
  const box = await dialog.boundingBox();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.width).toBeLessThanOrEqual(390);
  await dialog
    .getByRole("button", { name: "افزودن مشتری", exact: true })
    .click();
  await expect(
    dialog.getByText("نام مشتری الزامی است.", { exact: true }),
  ).toBeVisible();
  await expect(
    dialog.getByLabel("شماره تماس", { exact: true }),
  ).toHaveAttribute("dir", "ltr");
  await dialog.getByRole("button", { name: "بستن", exact: true }).click();
  await language.selectOption("en");
  await expect(screen).toHaveAttribute("dir", "ltr");
  expect(errors).toEqual([]);
});

test("customer directory retries failed loading and returns to the last page after archiving its final row", async ({
  page,
}) => {
  test.setTimeout(120_000);
  await login(page);
  const prefix = `Pagination E2E ${Date.now().toString(36)}`;
  const statuses = await page.evaluate(async (name) => {
    const headers = {
      Authorization: `Bearer ${localStorage.getItem("csms_token")}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    };
    const metadata = await (await fetch("/api/customers", { headers })).json();
    return Promise.all(
      Array.from({ length: 11 }, async (_, index) => {
        const response = await fetch("/api/customers", {
          method: "POST",
          headers,
          body: JSON.stringify({
            name: `${name} ${index}`,
            customer_type: "retail",
            registration_date: metadata.company_today,
            status: "active",
          }),
        });
        return response.status;
      }),
    );
  }, prefix);
  expect(statuses).toEqual(Array(11).fill(201));
  // Development Strict Mode can mount/load twice. Model an outage that
  // lasts until Retry, rather than failing only the discarded first request.
  let outage = true;
  await page.route("**/api/customers?*", async (route) => {
    if (outage)
      return route.fulfill({
        status: 503,
        contentType: "application/json",
        body: JSON.stringify({ message: "Temporary directory outage" }),
      });
    return route.continue();
  });
  await page.locator(".sidebar").getByRole("button", { name: "Customers", exact: true }).click();
  const screen = page.locator(".customers-screen");
  await expect(screen.getByRole("alert")).toContainText(
    "Temporary directory outage",
  );
  outage = false;
  await screen.getByRole("button", { name: "Retry", exact: true }).click();
  await expect(screen.getByRole("alert")).toHaveCount(0);
  await screen.getByLabel("Search customers", { exact: true }).fill(prefix);
  await screen.getByRole("button", { name: "Search", exact: true }).click();
  await expect(screen.getByText("Page 1 of 2", { exact: true })).toBeVisible();
  await screen.getByRole("button", { name: "Next", exact: true }).click();
  await expect(screen.getByText("Page 2 of 2", { exact: true })).toBeVisible();
  const row = screen.getByRole("row").filter({ hasText: prefix });
  await expect(row).toHaveCount(1);
  await row.getByRole("button", { name: "Archive", exact: true }).click();
  await row
    .getByRole("button", { name: "Confirm archive?", exact: true })
    .click();
  await expect(screen.getByRole("row").filter({ hasText: prefix })).toHaveCount(
    10,
  );
  await expect(
    screen.getByRole("navigation", { name: "Pagination", exact: true }),
  ).toHaveCount(0);
  await expect(screen.getByText("10 records", { exact: true })).toBeVisible();
});
