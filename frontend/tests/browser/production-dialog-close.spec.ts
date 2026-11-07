import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test, openSidebarPage, type Page } from "./fixtures";
import { productionText } from "../../src/lib/manager-production-i18n";

async function openManager(page: Page) {
  const fixture = JSON.parse(execFileSync("php", ["scripts/seed-inventory-browser.php"], {
    cwd: path.resolve(process.cwd(), "../backend"), encoding: "utf8", stdio: ["ignore", "pipe", "pipe"],
  }));
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  const name = `Close test manager ${fixture.suffix}`;
  const response = await page.request.post("/api/production/managers", {
    headers: { Authorization: `Bearer ${token}`, "Idempotency-Key": crypto.randomUUID(), Accept: "application/json" },
    data: { name, branch_id: fixture.branch, status: "active" },
  });
  expect(response.ok(), await response.text()).toBe(true);
  await openSidebarPage(page, "Production managers");
  await page.getByRole("row").filter({ hasText: name }).getByRole("button", { name: "View statement", exact: true }).click();
  await expect(page.getByRole("dialog").getByRole("heading", { name: "Manager statement", exact: true })).toBeVisible();
  return name;
}

test("manager tool and material movement close icons return to the statement repeatedly", async ({ page }) => {
  await openManager(page);
  const dialog = page.getByRole("dialog");
  const writes: string[] = [];
  page.on("request", (request) => {
    if (request.url().includes("/api/production/") && request.method() === "POST") writes.push(request.url());
  });
  for (const title of ["Record tool movement", "Record material movement", "Record tool movement"]) {
    await dialog.getByRole("button", { name: title, exact: true }).click();
    await expect(dialog.getByRole("heading", { name: title, exact: true })).toBeVisible();
    if (title === "Record material movement") {
      await dialog.getByRole("button", { name: title, exact: true }).click();
      await expect(dialog.locator('[aria-invalid="true"]').first()).toBeVisible();
    }
    await dialog.getByRole("button", { name: "Close", exact: true }).click();
    await expect(dialog.getByRole("heading", { name: "Manager statement", exact: true })).toBeVisible();
  }
  await dialog.getByRole("button", { name: "Close", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  expect(writes).toEqual([]);
});

test("Dari tool movement supports keep editing, discard, Escape and reduced motion", async ({ page }) => {
  const name = await openManager(page);
  const dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: "Close", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  const t = (text: string) => productionText("fa", text);
  await page.getByRole("row").filter({ hasText: name }).getByRole("button", { name: t("View statement"), exact: true }).click();
  await page.setViewportSize({ width: 390, height: 844 });
  await dialog.getByRole("button", { name: t("Record tool movement"), exact: true }).click();
  await dialog.getByLabel(new RegExp(`^${t("Quantity")}`)).fill("2");
  await dialog.getByRole("button", { name: t("Close"), exact: true }).click();
  await expect(dialog.getByText(t("Discard unsaved changes?"), { exact: true })).toBeVisible();
  await dialog.getByRole("button", { name: t("Keep editing"), exact: true }).click();
  await expect(dialog.getByLabel(new RegExp(`^${t("Quantity")}`))).toHaveValue("2");
  await page.keyboard.press("Escape");
  await dialog.getByRole("button", { name: t("Discard"), exact: true }).click();
  await expect(dialog.getByRole("heading", { name: t("Manager statement"), exact: true })).toBeVisible();
  await page.emulateMedia({ reducedMotion: "reduce" });
  await dialog.getByRole("button", { name: t("Record tool movement"), exact: true }).click();
  await page.keyboard.press("Escape");
  await expect(dialog.getByRole("heading", { name: t("Manager statement"), exact: true })).toBeVisible();
  await dialog.getByRole("button", { name: t("Close"), exact: true }).click();
  await expect(dialog).not.toBeVisible();
});

test("background stock refreshes do not keep a manager movement dialog stuck closing", async ({ page }) => {
  await openManager(page);
  const dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: "Record tool movement", exact: true }).click();
  await expect(dialog.getByRole("heading", { name: "Record tool movement", exact: true })).toBeVisible();
  // The same event is emitted when stock is updated in another tab.
  await page.evaluate(() => {
    const timer = setInterval(() => window.dispatchEvent(new Event("csms:balances-updated")), 40);
    setTimeout(() => clearInterval(timer), 2500);
  });
  await dialog.getByRole("button", { name: "Close", exact: true }).click();
  await expect(dialog.getByRole("heading", { name: "Manager statement", exact: true })).toBeVisible({ timeout: 1200 });
});
