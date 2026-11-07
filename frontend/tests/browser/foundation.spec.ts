import { expect, test, openSidebarPage, type Page } from "./fixtures";

async function login(page: Page, username = "browser_admin") {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill(username);
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

test("login errors, branch CRUD, client/server validation, and audit persist", async ({
  page,
}) => {
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/");
  await expect(
    page.getByRole("button", { name: "Sign in", exact: true }),
  ).toBeEnabled();
  await page.getByLabel("Username", { exact: true }).fill("browser_invalid");
  await page.getByLabel("Password", { exact: true }).fill("invalid");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(
    page.getByRole("alert").filter({ hasText: "Invalid username or password" }),
  ).toBeVisible();
  await login(page);
  await openSidebarPage(page, "Branches");
  await expect(
    page.getByRole("heading", { name: "Branches", exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Add record", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(
    dialog.getByText("This field is required.").first(),
  ).toBeVisible();
  const code = `E2E-${Date.now().toString(36).toUpperCase()}`;
  await dialog.getByLabel("Code", { exact: false }).fill(code);
  await dialog.getByLabel("Name", { exact: false }).fill("Browser branch");
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  const row = page.getByRole("row").filter({ hasText: code });
  await expect(row).toContainText("Browser branch");
  await row.getByRole("button", { name: "Edit", exact: true }).click();
  await dialog
    .getByLabel("Name", { exact: false })
    .fill("Browser branch updated");
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(row).toContainText("Browser branch updated");
  await page.getByRole("button", { name: "Add record", exact: true }).click();
  await dialog.getByLabel("Code", { exact: false }).fill(code);
  await dialog.getByLabel("Name", { exact: false }).fill("Duplicate branch");
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog.getByText(/already been taken/).first()).toBeVisible();
  await dialog.getByRole("button", { name: "Close", exact: true }).click();
  await page.reload();
  await openSidebarPage(page, "Branches");
  await expect(page.getByRole("row").filter({ hasText: code })).toContainText(
    "updated",
  );
  await openSidebarPage(page, "Audit history");
  await expect(page.getByRole("table")).toContainText("branches");
  expect(errors).toEqual([]);
});

test("account opening, decimal posting, safe retry, reversal, and overdraft", async ({
  page,
}) => {
  await login(page);
  await openSidebarPage(page, "Accounts");
  await page.getByRole("button", { name: "Add record", exact: true }).click();
  const dialog = page.getByRole("dialog");
  const code = `CASH-${Date.now().toString(36).toUpperCase()}`;
  await dialog.getByLabel("Code", { exact: false }).fill(code);
  await dialog.getByLabel("Name", { exact: false }).fill("Browser Cash");
  await expect(dialog.getByLabel("Branch", { exact: false })).toHaveCount(0);
  await dialog
    .getByRole("combobox", { name: /^Currency/ })
    .selectOption({ label: "US Dollar" });
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await page
    .getByRole("row")
    .filter({ hasText: code })
    .getByRole("button", { name: "Add money", exact: true })
    .click();
  await expect(page.getByLabel("Entry type", { exact: true })).toHaveValue("opening");
  await page.getByLabel("Entry type", { exact: true }).selectOption("opening");
  await page.getByLabel("Amount", { exact: true }).fill("100.10");
  await page.getByLabel("Description", { exact: true }).fill("Browser opening");
  await page.getByRole("button", { name: "Post entry", exact: true }).click();
  await expect(page.getByText("100.10 USD", { exact: true })).toBeVisible();
  await expect(page.getByLabel("Entry type", { exact: true })).toHaveValue("income");
  await page.getByLabel("Entry type", { exact: true }).selectOption("income");
  await page.getByLabel("Amount", { exact: true }).fill("0.001");
  await page.getByLabel("Description", { exact: true }).fill("Browser income");
  await page.getByRole("button", { name: "Post entry", exact: true }).click();
  await expect(
    page.getByText("Enter a positive amount with up to 2 decimal places."),
  ).toBeVisible();
  await page.getByLabel("Amount", { exact: true }).fill("0.20");
  // Simulate a lost response after the server committed. The retained key must replay.
  let intercepted = false;
  await page.route("**/api/foundation/ledger", async (route) => {
    if (route.request().method() === "POST" && !intercepted) {
      intercepted = true;
      const response = await route.fetch();
      expect(response.status()).toBe(201);
      await route.abort("failed");
    } else await route.continue();
  });
  await page.getByRole("button", { name: "Post entry", exact: true }).click();
  await expect(page.getByText(/Connection failed/)).toBeVisible();
  await page.getByRole("button", { name: "Post entry", exact: true }).click();
  await expect(page.getByText("100.30 USD", { exact: true })).toBeVisible();
  await expect(
    page.getByRole("row").filter({ hasText: "Browser income" }),
  ).toHaveCount(1);
  await page
    .getByRole("row")
    .filter({ hasText: "Browser income" })
    .getByRole("button", { name: /Reverse entry/ })
    .click();
  await page.getByLabel("Reason", { exact: true }).fill("Browser correction");
  await page
    .getByRole("button", { name: "Reverse entry", exact: true })
    .click();
  await expect(page.getByText("100.10 USD", { exact: true })).toBeVisible();
  await page.getByLabel("Entry type", { exact: true }).selectOption("expense");
  await page.getByLabel("Amount", { exact: true }).fill("100.11");
  await page
    .getByLabel("Description", { exact: true })
    .fill("Overdraft attempt");
  await page.getByRole("button", { name: "Post entry", exact: true }).click();
  await expect(page.getByText(/Insufficient balance/).first()).toBeVisible();
  await expect(page.getByText("100.10 USD", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Accounts", exact: true }).click();
  await page.getByRole("row").filter({ hasText: code })
    .getByRole("button", { name: "Add money", exact: true }).click();
  await expect(page.getByLabel("Entry type", { exact: true })).toHaveValue("income");
});

test("scoped sales user cannot access administration, including direct API calls", async ({
  page,
}) => {
  await login(page, "browser_sales");
  await expect(page.locator(".topbar .notification-button")).toHaveCount(0);
  await page.locator(".profile-button").click();
  await expect(
    page.getByRole("button", { name: "Account & preferences", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Sign out", exact: true }),
  ).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(
    page.getByRole("button", { name: "Users", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Settings", exact: true }),
  ).toHaveCount(0);
  for (const label of [
    "Branches",
    "Stock locations",
    "Currencies",
    "Carpet categories",
  ]) {
    await openSidebarPage(page, label);
    const screen = page.getByRole("region", { name: label, exact: true });
    await expect(screen.getByText("Loading...", { exact: true })).toHaveCount(
      0,
    );
    await expect(screen.getByRole("table")).toBeVisible();
    await expect(
      screen.getByRole("button", { name: "Add record", exact: true }),
    ).toHaveCount(0);
    await expect(screen.getByRole("alert")).toHaveCount(0);
  }
  const statuses = await page.evaluate(async () => {
    const headers = {
      Authorization: `Bearer ${localStorage.getItem("csms_token")}`,
      Accept: "application/json",
    };
    return Promise.all(
      ["/api/foundation/users", "/api/foundation/audit"].map(
        async (url) => (await fetch(url, { headers })).status,
      ),
    );
  });
  expect(statuses).toEqual([403, 403]);
});

test("mobile forms fit the viewport and Dari preference survives refresh", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page);
  await page.getByRole("button", { name: "Open navigation" }).click();
  await openSidebarPage(page, "Branches");
  await page.getByRole("button", { name: "Add record", exact: true }).click();
  const box = await page.getByRole("dialog").boundingBox();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.width).toBeLessThanOrEqual(390);
  await page
    .getByRole("dialog")
    .getByRole("button", { name: "Close", exact: true })
    .click();
  await page
    .getByRole("combobox", { name: "Display language" })
    .selectOption("fa");
  await expect(page.locator('section[dir="rtl"]').first()).toBeVisible();
  await page.reload();
  await page.getByRole("button", { name: "Open navigation" }).click();
  await openSidebarPage(page, "Branches", "fa");
  await expect(page.locator('section[dir="rtl"]').first()).toBeVisible();
  await page
    .getByRole("combobox", { name: "Display language" })
    .selectOption("en");
  await expect(
    page.getByRole("combobox", { name: "Display language" }),
  ).toHaveValue("en");
});

test("administrator creates a scoped user who can sign in, then revokes that session", async ({
  page,
}) => {
  await login(page);
  await openSidebarPage(page, "Users and access");
  await page.getByRole("button", { name: "Add record", exact: true }).click();
  const dialog = page.getByRole("dialog");
  const username = `ui_user_${Date.now().toString(36)}`;
  await dialog.locator("#foundation-name").fill("Browser Accountant");
  await dialog.getByLabel("Username", { exact: false }).fill(username);
  await dialog
    .getByLabel("Password", { exact: false })
    .fill("BrowserOnlyTest!42");
  await dialog
    .getByRole("group", { name: "Roles" })
    .getByLabel("Accountant", { exact: true })
    .check();
  await dialog
    .getByRole("group", { name: "Branch access" })
    .getByLabel("Browser fixture", { exact: true })
    .check();
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  const row = page.getByRole("row").filter({ hasText: username });
  await expect(row).toBeVisible();
  const token = await page.evaluate(async (name) => {
    const response = await fetch("/api/login", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify({ username: name, password: "BrowserOnlyTest!42" }),
    });
    return (await response.json()).token as string;
  }, username);
  expect(token).toBeTruthy();
  await row.getByRole("button", { name: "Edit", exact: true }).click();
  await expect(dialog.getByLabel("Password", { exact: false })).toHaveValue("");
  await dialog.getByLabel("Active", { exact: true }).uncheck();
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  const status = await page.evaluate(
    async (bearer) =>
      (
        await fetch("/api/me", {
          headers: {
            Authorization: `Bearer ${bearer}`,
            Accept: "application/json",
          },
        })
      ).status,
    token,
  );
  expect(status).toBe(401);
});

test("foundation flyout destinations load and company settings validate before saving", async ({
  page,
}) => {
  await login(page);
  await openSidebarPage(page, "Branches");
  for (const label of [
    "Stock locations",
    "Currencies",
    "Exchange rates",
    "Employees",
    "Carpet categories",
    "Expense categories",
    "Washing facilities",
    "Roles and permissions",
  ]) {
    await openSidebarPage(page, label);
    await expect(
      page.getByRole("heading", { name: label, exact: true }),
    ).toBeVisible();
    await expect(page.getByText("Loading...", { exact: true })).toHaveCount(0);
    await expect(
      page.getByRole("alert").filter({ hasText: /Could not|error|permission/ }),
    ).toHaveCount(0);
  }
  await openSidebarPage(page, "Company settings");
  await page.getByRole("button", { name: "Edit", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByLabel("Timezone", { exact: false }).fill("Moon/Base");
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog.getByText("Choose a valid timezone.")).toBeVisible();
  await dialog.getByLabel("Timezone", { exact: false }).fill("Asia/Kabul");
  await dialog
    .getByLabel("Reminder days before due date", { exact: false })
    .fill("5");
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await expect(
    page.getByText("Settings saved.", { exact: true }),
  ).toBeVisible();
  await page.locator(".profile-button").click();
  await page.getByRole("button", { name: "Sign out", exact: false }).click();
  await expect(
    page.getByRole("heading", { name: "Welcome back" }),
  ).toBeVisible();
});
