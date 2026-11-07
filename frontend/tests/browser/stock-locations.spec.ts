import { execFileSync } from "node:child_process";
import path from "node:path";
import { foundationText } from "../../src/lib/foundation-i18n";
import { expect, test, openSidebarPage, type Page } from "./fixtures";

function fixture() {
  const cwd = path.resolve(process.cwd(), "../backend");
  const seed = (script: string) => JSON.parse(execFileSync("php", [script], { cwd, encoding: "utf8" }));
  return { ...seed("scripts/seed-inventory-browser.php"), ...seed("scripts/seed-washing-browser.php") };
}

async function login(page: Page) {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await openSidebarPage(page, "Stock locations");
}

async function savedLocation(page: Page, code: string) {
  expect(new URL(page.url()).origin).toBe("http://127.0.0.1:3102");
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  const response = await page.request.get(`/api/foundation/locations?search=${code.split("-").at(-1)}`, { headers: { Authorization: `Bearer ${token}` } });
  expect(response.ok(), await response.text()).toBe(true);
  const saved = (await response.json()).data.find((row: { code: string }) => row.code === code);
  expect(saved, `Saved stock location ${code}`).toBeTruthy();
  return saved;
}

test("stock locations guide the owner selection and clear conflicting owners on create and edit", async ({ page }) => {
  test.setTimeout(120_000);
  const f = fixture(); await login(page);
  const code = `OWNER-${f.suffix}`;
  await page.getByRole("button", { name: "Add record", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await dialog.locator("#foundation-code").fill(code);
  await dialog.locator("#foundation-name").fill(`Owner selection ${f.suffix}`);
  await dialog.locator("#foundation-location_type").selectOption("warehouse");
  await expect(dialog.locator("#foundation-branch_id")).toBeVisible();
  await expect(dialog.locator("#foundation-washing_facility_id")).toHaveCount(0);
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog.getByText("Choose a branch for this stock location.", { exact: true })).toBeVisible();
  await dialog.locator("#foundation-branch_id").selectOption(String(f.branch));
  await expect(dialog.getByText("Choose a branch for this stock location.", { exact: true })).toHaveCount(0);
  await dialog.locator("#foundation-location_type").selectOption("washing");
  await expect(dialog.locator("#foundation-branch_id")).toHaveCount(0);
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog.getByText("Choose a washing facility for this location.", { exact: true })).toBeVisible();
  await dialog.locator("#foundation-washing_facility_id").selectOption(String(f.facility));
  await dialog.locator("#foundation-location_type").selectOption("warehouse");
  await expect(dialog.locator("#foundation-branch_id")).toHaveValue("");
  await expect(dialog.locator("#foundation-washing_facility_id")).toHaveCount(0);
  await dialog.locator("#foundation-branch_id").selectOption(String(f.branch));
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  let saved = await savedLocation(page, code);
  expect(saved.branch_id).toBe(f.branch); expect(saved.washing_facility_id).toBeNull();

  const row = page.getByRole("row").filter({ hasText: code });
  await row.getByRole("button", { name: "Edit", exact: true }).click();
  await expect(dialog.locator("#foundation-branch_id")).toHaveValue(String(f.branch));
  await dialog.locator("#foundation-location_type").selectOption("washing");
  await dialog.locator("#foundation-washing_facility_id").selectOption(String(f.facility));
  await dialog.getByRole("button", { name: "Save", exact: true }).click(); await expect(dialog).not.toBeVisible();
  saved = await savedLocation(page, code);
  expect(saved.branch_id).toBeNull(); expect(saved.washing_facility_id).toBe(f.facility);

  await row.getByRole("button", { name: "Edit", exact: true }).click();
  await expect(dialog.locator("#foundation-washing_facility_id")).toHaveValue(String(f.facility));
  await dialog.locator("#foundation-location_type").selectOption("shelf");
  await expect(dialog.locator("#foundation-branch_id")).toHaveValue("");
  await dialog.locator("#foundation-branch_id").selectOption(String(f.branch));
  await dialog.getByRole("button", { name: "Save", exact: true }).click(); await expect(dialog).not.toBeVisible();
  await page.reload();
  saved = await savedLocation(page, code);
  expect(saved.location_type).toBe("shelf"); expect(saved.branch_id).toBe(f.branch); expect(saved.washing_facility_id).toBeNull();
});

test("washing location creation uses the facility field and translated validation in mobile Dari", async ({ page }) => {
  test.setTimeout(120_000);
  const f = fixture(); await login(page);
  await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole("button", { name: foundationText("fa", "Add record"), exact: true }).click();
  const dialog = page.getByRole("dialog");
  await dialog.locator("#foundation-code").fill(`FAOWNER-${f.suffix}`);
  await dialog.locator("#foundation-name").fill(`Washing yard ${f.suffix}`);
  await dialog.locator("#foundation-location_type").selectOption("washing");
  await dialog.getByRole("button", { name: foundationText("fa", "Save"), exact: true }).click();
  await expect(dialog.getByText(foundationText("fa", "Choose a washing facility for this location."), { exact: true })).toBeVisible();
  await expect(dialog.locator("#foundation-branch_id")).toHaveCount(0);
  await expect(dialog).toHaveAttribute("dir", "rtl");
  expect(await dialog.evaluate((node) => node.scrollWidth <= node.clientWidth + 1)).toBe(true);
  await dialog.locator("#foundation-washing_facility_id").selectOption(String(f.facility));
  await page.screenshot({ path: "test-results/stock-location-dari.png", fullPage: true });
  await dialog.getByRole("button", { name: foundationText("fa", "Save"), exact: true }).click();
  await expect(dialog).not.toBeVisible();
  const saved = await savedLocation(page, `FAOWNER-${f.suffix}`);
  expect(saved.branch_id).toBeNull(); expect(saved.washing_facility_id).toBe(f.facility);
});
