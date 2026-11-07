import { expect, test } from "./fixtures";

test("sign in validates missing fields and accepts saved credentials without change events", async ({
  page,
}) => {
  let requests = 0;
  page.on("request", (request) => {
    if (request.url().endsWith("/api/login")) requests++;
  });
  await page.goto("/");
  const submit = page.getByRole("button", { name: "Sign in", exact: true });
  await expect(submit).toBeEnabled();
  await submit.click();
  await expect(
    page.getByText("Enter your username.", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Enter your password.", { exact: true }),
  ).toBeVisible();
  await expect(page.getByLabel("Username", { exact: true })).toBeFocused();
  expect(requests).toBe(0);

  // Password managers can populate the DOM without firing input/change events.
  await page.evaluate(() => {
    (document.getElementById("login-username") as HTMLInputElement).value =
      "browser_admin";
    (document.getElementById("login-password") as HTMLInputElement).value =
      "BrowserOnlyTest!42";
  });
  // A React rerender must also preserve these autofilled values.
  await page
    .getByRole("button", { name: "Show password", exact: true })
    .click();
  await expect(page.getByLabel("Username", { exact: true })).toHaveValue(
    "browser_admin",
  );
  await expect(page.getByLabel("Password", { exact: true })).toHaveValue(
    "BrowserOnlyTest!42",
  );
  await submit.click();
  await expect(page.locator(".profile-button")).toBeVisible();
  expect(requests).toBe(1);
});

test("sign in prevents duplicate requests and becomes usable after a connection failure", async ({
  page,
}) => {
  let requests = 0;
  let release!: () => void;
  const pending = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route("**/api/login", async (route) => {
    requests++;
    if (requests === 1) {
      await pending;
      await route.abort("failed");
    } else await route.continue();
  });
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.locator("form").evaluate((form: HTMLFormElement) => {
    form.requestSubmit();
    form.requestSubmit();
  });
  await expect(
    page.getByRole("button", { name: "Signing you in...", exact: true }),
  ).toBeDisabled();
  await expect.poll(() => requests).toBe(1);
  release();
  await expect(page.locator("form").getByRole("alert")).toContainText(
    "Could not reach the server.",
  );
  const submit = page.getByRole("button", { name: "Sign in", exact: true });
  await expect(submit).toBeEnabled();
  await expect(page.getByLabel("Password", { exact: true })).toBeEnabled();
  await submit.click();
  await expect(page.locator(".profile-button")).toBeVisible();
  expect(requests).toBe(2);
});

test("a stalled sign in times out and allows a successful retry", async ({
  page,
}) => {
  await page.clock.install();
  let requests = 0;
  await page.route("**/api/login", async (route) => {
    requests++;
    // Leave the first request unanswered to model an unresponsive server.
    if (requests > 1) await route.continue();
  });
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Signing you in...", exact: true }),
  ).toBeDisabled();
  await expect.poll(() => requests).toBe(1);
  await page.clock.fastForward(15_000);
  await expect(page.locator("form").getByRole("alert")).toHaveText(
    "Signing in took too long. Please try again.",
  );
  const submit = page.getByRole("button", { name: "Sign in", exact: true });
  await expect(submit).toBeEnabled();
  await submit.click();
  await expect(page.locator(".profile-button")).toBeVisible();
  expect(requests).toBe(2);
});

test("an unavailable application shows a clear sign-in error and permits retry", async ({ page }) => {
  let requests = 0;
  await page.route("**/api/login", async (route) => {
    requests++;
    if (requests === 1) {
      await route.fulfill({ status: 502, contentType: "text/plain", body: "Upstream connection refused" });
    } else await route.continue();
  });
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator("form").getByRole("alert")).toHaveText(
    "Sign-in is temporarily unavailable. Please try again shortly.",
  );
  await expect(page.getByRole("button", { name: "Sign in", exact: true })).toBeEnabled();
  await expect(page.getByLabel("Username", { exact: true })).toHaveValue("browser_admin");
  await expect(page.getByLabel("Password", { exact: true })).toHaveValue("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
  expect(requests).toBe(2);
});
