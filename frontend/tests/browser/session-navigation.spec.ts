import { expect, test, openSidebarPage, type Page } from "./fixtures";

async function login(page: Page, username = "browser_admin") {
  await page.getByLabel("Username", { exact: true }).fill(username);
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

const destination = (page: Page) => new URL(page.url()).searchParams.get("page");

async function watchForLoginFlash(page: Page) {
  await page.addInitScript(() => {
    const observed = window as Window & { loginRenderedDuringRestore?: boolean };
    observed.loginRenderedDuringRestore = false;
    const inspect = () => {
      if (document.getElementById("login-username")) {
        observed.loginRenderedDuringRestore = true;
      }
    };
    new MutationObserver(inspect).observe(document, { childList: true, subtree: true });
    inspect();
  });
}

test("refresh verifies a stored session without rendering login and keeps the current module", async ({ page }) => {
  test.setTimeout(120_000);
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("/");
  await login(page);
  await openSidebarPage(page, "Accounts");
  await expect(page.getByRole("heading", { name: "Accounts", exact: true })).toBeVisible();
  await expect.poll(() => destination(page)).toBe("Accounts");
  await watchForLoginFlash(page);

  let releaseVerification!: () => void;
  const verificationGate = new Promise<void>((resolve) => { releaseVerification = resolve; });
  let verificationStarted!: () => void;
  const verificationRequest = new Promise<void>((resolve) => { verificationStarted = resolve; });
  await page.route("**/api/me", async (route) => {
    verificationStarted();
    await verificationGate;
    await route.continue();
  });
  try {
    await page.reload({ waitUntil: "domcontentloaded" });
    await verificationRequest;
    await expect(page.getByLabel("Username", { exact: true })).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Sign in", exact: true })).toHaveCount(0);
    await expect(page.locator(".profile-button")).toHaveCount(0);
    expect(await page.evaluate(() => (window as Window & { loginRenderedDuringRestore?: boolean }).loginRenderedDuringRestore)).toBe(false);
  } finally {
    releaseVerification();
  }
  await expect(page.locator(".profile-button")).toBeVisible();
  await expect(page.getByRole("heading", { name: "Accounts", exact: true })).toBeVisible();
  expect(destination(page)).toBe("Accounts");
  expect(await page.evaluate(() => (window as Window & { loginRenderedDuringRestore?: boolean }).loginRenderedDuringRestore)).toBe(false);
  expect(errors).toEqual([]);
});

test("module URLs survive refresh and browser back and forward restore the matching destination", async ({ page }) => {
  test.setTimeout(120_000);
  await page.goto("/");
  await login(page);
  await openSidebarPage(page, "Accounts");
  await expect.poll(() => destination(page)).toBe("Accounts");
  await openSidebarPage(page, "Production orders");
  await expect(page.getByRole("heading", { name: "Production orders", exact: true })).toBeVisible();
  await expect.poll(() => destination(page)).toBe("Production orders");

  await page.goBack();
  await expect(page.getByRole("heading", { name: "Accounts", exact: true })).toBeVisible();
  expect(destination(page)).toBe("Accounts");
  await page.goForward();
  await expect(page.getByRole("heading", { name: "Production orders", exact: true })).toBeVisible();
  expect(destination(page)).toBe("Production orders");
  await page.reload();
  await expect(page.getByRole("heading", { name: "Production orders", exact: true })).toBeVisible();
  expect(destination(page)).toBe("Production orders");
});

test("a permitted requested module opens after sign in and a fresh profile rejects a forbidden destination", async ({ page }) => {
  test.setTimeout(120_000);
  await page.goto(`/?${new URLSearchParams({ page: "Accounts" })}`);
  await login(page);
  await expect(page.getByRole("heading", { name: "Accounts", exact: true })).toBeVisible();

  await page.locator(".profile-button").click();
  await page.getByRole("button", { name: "Sign out", exact: true }).click();
  await expect(page.getByLabel("Username", { exact: true })).toBeVisible();
  await expect.poll(() => destination(page)).toBeNull();
  await page.goto(`/?${new URLSearchParams({ page: "Accounts" })}`);
  await login(page, "browser_sales");
  await expect(page.getByRole("heading", { name: "Dashboard", exact: true })).toBeVisible();
  await expect.poll(() => destination(page)).toBeNull();
  await expect(page.getByRole("heading", { name: "Accounts", exact: true })).toHaveCount(0);
  await expect(page.locator(".profile-button")).toContainText("Sales User");
  await page.locator(".sidebar").getByRole("button", { name: "Finance", exact: true }).click();
  await expect(page.getByRole("menu", { name: "Finance", exact: true }).getByRole("menuitem", { name: "Accounts", exact: true })).toHaveCount(0);
  await page.keyboard.press("Escape");
  await page.reload();
  await expect(page.getByRole("heading", { name: "Dashboard", exact: true })).toBeVisible();
  await expect.poll(() => destination(page)).toBeNull();
});

test("unknown requested modules safely show the dashboard", async ({ page }) => {
  await page.goto("/");
  await login(page);
  for (const requested of ["Unimplemented secret report", "constructor", "prototype", "__proto__"]) {
    await page.goto(`/?${new URLSearchParams({ page: requested })}`);
    await expect(page.getByRole("heading", { name: "Dashboard", exact: true })).toBeVisible();
    await expect.poll(() => destination(page)).toBeNull();
    await expect(page.getByText(requested, { exact: true })).toHaveCount(0);
    await expect(page.locator("#dashboard-content").getByRole("alert")).toHaveCount(0);
  }
});

test("a temporary session check failure retries without losing the requested module or showing login", async ({ page }) => {
  await page.goto("/");
  await login(page);
  await openSidebarPage(page, "Accounts");
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  await watchForLoginFlash(page);
  let fail = true;
  await page.route("**/api/me", async (route) => {
    if (fail) await route.fulfill({ status: 503, contentType: "application/json", body: JSON.stringify({ message: "Session verification is temporarily unavailable." }) });
    else await route.continue();
  });
  await page.reload();
  await expect(page.locator('.auth-gate[role="alert"]')).toContainText("Session verification is temporarily unavailable.");
  await expect(page.getByLabel("Username", { exact: true })).toHaveCount(0);
  expect(destination(page)).toBe("Accounts");
  expect(await page.evaluate(() => localStorage.getItem("csms_token"))).toBe(token);
  fail = false;
  await page.getByRole("button", { name: "Try again", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Accounts", exact: true })).toBeVisible();
  expect(destination(page)).toBe("Accounts");
  expect(await page.evaluate(() => (window as Window & { loginRenderedDuringRestore?: boolean }).loginRenderedDuringRestore)).toBe(false);
});

test("a delayed old-token rejection cannot sign out a newly verified session", async ({ page }) => {
  test.setTimeout(120_000);
  await page.goto("/");
  const oldToken = "superseded-invalid-session-token";
  await page.evaluate((token) => localStorage.setItem("csms_token", token), oldToken);
  let releaseOldRequest!: () => void;
  const oldRequestGate = new Promise<void>((resolve) => { releaseOldRequest = resolve; });
  let oldRequestStarted!: () => void;
  const oldRequest = new Promise<void>((resolve) => { oldRequestStarted = resolve; });
  await page.route("**/api/me", async (route) => {
    if (route.request().headers().authorization === `Bearer ${oldToken}`) {
      oldRequestStarted();
      await oldRequestGate;
      await route.fulfill({ status: 401, contentType: "application/json", body: JSON.stringify({ message: "Unauthenticated." }) });
    } else await route.continue();
  });
  await page.goto(`/?${new URLSearchParams({ page: "Accounts" })}`, { waitUntil: "domcontentloaded" });
  await oldRequest;
  const loginResponse = await page.request.post("/api/login", {
    headers: { Accept: "application/json" },
    data: { username: "browser_admin", password: "BrowserOnlyTest!42" },
  });
  expect(loginResponse.ok()).toBe(true);
  const newToken: string = (await loginResponse.json()).token;
  const oldRejection = page.waitForResponse((response) => response.url().endsWith("/api/me") && response.status() === 401 && response.request().headers().authorization === `Bearer ${oldToken}`);
  try {
    // A successful sign-in elsewhere in this browser replaces the shared token.
    await page.evaluate((token) => {
      localStorage.setItem("csms_token", token);
      window.dispatchEvent(new Event("csms:token-changed"));
    }, newToken);
    await expect(page.getByRole("heading", { name: "Accounts", exact: true })).toBeVisible();
  } finally {
    releaseOldRequest();
  }
  await (await oldRejection).finished();
  await page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));
  await expect.poll(() => page.evaluate(() => localStorage.getItem("csms_token"))).toBe(newToken);
  await expect(page.locator(".profile-button")).toBeVisible();
  await expect(page.getByRole("heading", { name: "Accounts", exact: true })).toBeVisible();
  await expect(page.getByLabel("Username", { exact: true })).toHaveCount(0);
});

for (const revoked of [false, true]) {
  test(`${revoked ? "a server-revoked" : "an invalid"} stored token shows the genuine sign-in form`, async ({ page }) => {
    await page.goto("/");
    if (revoked) {
      await login(page);
      await openSidebarPage(page, "Accounts");
      const token = await page.evaluate(() => localStorage.getItem("csms_token"));
      expect(token).toBeTruthy();
      const response = await page.request.post("/api/logout", { headers: { Authorization: `Bearer ${token}` } });
      expect(response.ok()).toBe(true);
    } else {
      await page.evaluate(() => localStorage.setItem("csms_token", "invalid-session-token"));
    }
    await page.reload();
    await expect(page.getByLabel("Username", { exact: true })).toBeVisible();
    await expect(page.getByRole("button", { name: "Sign in", exact: true })).toBeEnabled();
    await expect(page.locator(".profile-button")).toHaveCount(0);
    await expect.poll(() => page.evaluate(() => localStorage.getItem("csms_token"))).toBeNull();
  });
}

test("the brand home link opens the dashboard and keeps navigation history consistent", async ({ page }) => {
  await page.goto("/");
  await login(page);
  await openSidebarPage(page, "Accounts");
  await expect.poll(() => destination(page)).toBe("Accounts");
  await page.getByRole("link", { name: "Sakhi Zada Uzbek Ltd home", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Dashboard", exact: true })).toBeVisible();
  await expect.poll(() => destination(page)).toBeNull();
  await page.reload();
  await expect(page.getByRole("heading", { name: "Dashboard", exact: true })).toBeVisible();
  await expect.poll(() => destination(page)).toBeNull();
  await page.goBack();
  await expect(page.getByRole("heading", { name: "Accounts", exact: true })).toBeVisible();
  expect(destination(page)).toBe("Accounts");
});

test("an empty stored token shows the sign-in form without getting stuck verifying", async ({ page }) => {
  await page.goto("/");
  await page.evaluate(() => localStorage.setItem("csms_token", ""));
  await page.reload();
  await expect(page.getByLabel("Username", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Sign in", exact: true })).toBeEnabled();
  await expect(page.locator(".profile-button")).toHaveCount(0);
});
