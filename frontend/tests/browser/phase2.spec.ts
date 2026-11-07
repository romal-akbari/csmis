import { expect, test, openSidebarPage, type Page } from "./fixtures";

async function login(page: Page, username = "browser_admin") {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill(username);
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

test("geography hierarchy, weaver validation, manager assignment and archive/restore work end to end", async ({
  page,
}) => {
  test.setTimeout(120_000);
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  const suffix = Date.now().toString(36);
  const province = `Phase2 province ${suffix}`;
  const district = `Phase2 district ${suffix}`;
  const area = `Phase2 area ${suffix}`;
  const weaver = `Phase2 weaver ${suffix}`;
  await login(page);
  await openSidebarPage(page, "Weaver records");
  const screen = page.getByRole("region", { name: "Directories", exact: true });
  const dialog = page.getByRole("dialog");
  const tab = (name: string) =>
    screen.getByRole("navigation").getByRole("button", { name, exact: true });
  async function add(name: string) {
    await screen
      .getByRole("button", { name: "Add record", exact: true })
      .click();
    await expect(dialog).toBeVisible();
    await dialog.locator("#directory-name").fill(name);
  }
  async function save() {
    await dialog.getByRole("button", { name: "Save", exact: true }).click();
    await expect(dialog).not.toBeVisible();
  }
  async function search(name: string) {
    await screen.locator("#directory-search").fill(name);
    await screen.getByRole("button", { name: "Search", exact: true }).click();
  }

  await tab("Provinces").click();
  await add(province);
  await dialog.locator("#directory-name_fa").fill("ولایت آزمایشی");
  await save();
  await expect(
    screen.getByRole("row").filter({ hasText: province }),
  ).toBeVisible();
  // Server validation catches duplicate names that pass client field validation.
  await add(province);
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog.locator("#directory-name-error")).toContainText(
    /taken|already exists/,
  );
  await dialog.getByRole("button", { name: "Cancel", exact: true }).click();

  await tab("Districts").click();
  await add(district);
  await dialog
    .locator("#directory-province_id")
    .selectOption({ label: province });
  await save();
  await expect(
    screen.getByRole("row").filter({ hasText: district }),
  ).toContainText(province);
  await tab("Areas").click();
  await add(area);
  await expect(dialog.locator("#directory-district_id")).toBeDisabled();
  await dialog
    .locator("#directory-province_id")
    .selectOption({ label: province });
  await dialog
    .locator("#directory-district_id")
    .selectOption({ label: district });
  await save();
  await expect(screen.getByRole("row").filter({ hasText: area })).toContainText(
    district,
  );
  await screen
    .getByRole("row")
    .filter({ hasText: area })
    .getByRole("button", { name: "Edit", exact: true })
    .click();
  await expect(
    dialog.locator("#directory-province_id").locator("option:checked"),
  ).toHaveText(province);
  await dialog.locator("#directory-name_fa").fill("ساحه آزمایشی");
  await save();

  // Create a real employee through the existing protected API, then assign it in the UI.
  const manager = await page.evaluate(async (suffix) => {
    const headers = {
      Authorization: `Bearer ${localStorage.getItem("csms_token")}`,
      Accept: "application/json",
      "Content-Type": "application/json",
    };
    const lookups = await (
      await fetch("/api/foundation/lookups", { headers })
    ).json();
    const branch = lookups.data.branches.find(
      (item: { name: string }) => item.name === "Browser fixture",
    );
    const response = await fetch("/api/foundation/employees", {
      method: "POST",
      headers,
      body: JSON.stringify({
        name: `Phase2 manager ${suffix}`,
        branch_id: branch.id,
        status: "active",
      }),
    });
    if (!response.ok)
      throw new Error(`Employee fixture failed: ${await response.text()}`);
    return (await response.json()).data;
  }, suffix);
  await tab("Weavers & Artisans").click();
  await screen.getByRole("button", { name: "Add record", exact: true }).click();
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog.locator("#directory-name-error")).toContainText(
    "required",
  );
  await dialog.locator("#directory-name").fill(weaver);
  await dialog
    .locator("#directory-province_id")
    .selectOption({ label: province });
  await dialog
    .locator("#directory-district_id")
    .selectOption({ label: district });
  await dialog.locator("#directory-area_id").selectOption({ label: area });
  // Changing a parent clears stale child IDs and disables the next choice.
  await dialog.locator("#directory-province_id").selectOption("");
  await expect(dialog.locator("#directory-district_id")).toHaveValue("");
  await expect(dialog.locator("#directory-area_id")).toHaveValue("");
  await expect(dialog.locator("#directory-area_id")).toBeDisabled();
  await dialog
    .locator("#directory-province_id")
    .selectOption({ label: province });
  await dialog
    .locator("#directory-district_id")
    .selectOption({ label: district });
  await dialog.locator("#directory-area_id").selectOption({ label: area });
  await dialog
    .locator("#directory-manager_employee_id")
    .selectOption(String(manager.id));
  await dialog.locator("#directory-phone").fill("bad-phone");
  await dialog.locator("#directory-registration_date").fill("2099-01-01");
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog.locator("#directory-phone-error")).toBeVisible();
  await expect(
    dialog.locator("#directory-registration_date-error"),
  ).toBeVisible();
  await dialog.locator("#directory-phone").fill("+93 700 123 456");
  await dialog.locator("#directory-registration_date").fill("2024-02-29");
  await save();
  await search(weaver);
  const row = screen.getByRole("row").filter({ hasText: weaver });
  await expect(row).toContainText(manager.name);
  await expect(row).toContainText(area);
  await row.getByRole("button", { name: "Edit", exact: true }).click();
  await dialog
    .locator("#directory-notes")
    .fill("Updated through the browser workflow");
  await save();
  await row.getByRole("button", { name: "Archive", exact: true }).click();
  await dialog.getByRole("button", { name: "Archive", exact: true }).click();
  await expect(row).toHaveCount(0);
  await screen.locator("#directory-status").selectOption("inactive");
  await expect(row).toContainText("inactive");
  await row.getByRole("button", { name: "Restore", exact: true }).click();
  await dialog.getByRole("button", { name: "Restore", exact: true }).click();
  await expect(row).toHaveCount(0);
  await screen.locator("#directory-status").selectOption("active");
  await expect(row).toContainText(weaver);
  // A referenced geographic record cannot be archived and leave orphan history.
  await tab("Provinces").click();
  await search(province);
  await screen
    .getByRole("row")
    .filter({ hasText: province })
    .getByRole("button", { name: "Archive", exact: true })
    .click();
  await dialog.getByRole("button", { name: "Archive", exact: true }).click();
  await expect(screen.getByRole("alert")).toContainText(/in use/);
  expect(errors).toEqual([]);
});

test("supplier create/edit/archive/restore, persistence and Dari mobile layout", async ({
  page,
}) => {
  await login(page);
  await openSidebarPage(page, "Supplier records");
  const screen = page.getByRole("region", { name: "Directories", exact: true });
  const name = `Phase2 supplier ${Date.now().toString(36)}`;
  const dialog = page.getByRole("dialog");
  await screen.getByRole("button", { name: "Add record", exact: true }).click();
  await dialog.locator("#directory-name").fill(name);
  await dialog.locator("#directory-phone").fill("+93 700 555 123");
  await dialog.locator("#directory-address").fill("Kabul supplier office");
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  const row = screen.getByRole("row").filter({ hasText: name });
  await row.getByRole("button", { name: "Edit", exact: true }).click();
  await dialog.locator("#directory-address").fill("Updated supplier office");
  await dialog.getByRole("button", { name: "Save", exact: true }).click();
  await expect(row).toContainText("Updated supplier office");
  await row.getByRole("button", { name: "Archive", exact: true }).click();
  await dialog.getByRole("button", { name: "Archive", exact: true }).click();
  await expect(row).toHaveCount(0);
  await screen.locator("#directory-status").selectOption("inactive");
  await row.getByRole("button", { name: "Restore", exact: true }).click();
  await dialog.getByRole("button", { name: "Restore", exact: true }).click();
  await screen.locator("#directory-status").selectOption("active");
  await expect(row).toBeVisible();
  await page.reload();
  await openSidebarPage(page, "Supplier records");
  await expect(row).toContainText("Updated supplier office");
  await page
    .getByRole("combobox", { name: "Display language" })
    .selectOption("fa");
  const dari = page.getByRole("region", { name: "فهرست‌ها", exact: true });
  await expect(dari).toHaveAttribute("dir", "rtl");
  await page.setViewportSize({ width: 390, height: 844 });
  await dari.getByRole("button", { name: "افزودن", exact: true }).click();
  await expect(dialog).toHaveAttribute("dir", "rtl");
  const bounds = (await dialog.boundingBox())!;
  expect(bounds.width).toBeLessThanOrEqual(390);
  expect(bounds.x).toBeGreaterThanOrEqual(0);
  await page.screenshot({
    path: "test-results/phase2-directory-rtl-mobile.png",
    fullPage: true,
  });
  await page.keyboard.press("Escape");
  await expect(dialog).not.toBeVisible();
  await page
    .getByRole("combobox", { name: "Display language" })
    .selectOption("en");
});

test("users without directory permissions cannot discover modules or bypass the API", async ({
  page,
}) => {
  await login(page, "browser_sales");
  await expect(
    page
      .locator(".sidebar")
      .getByRole("button", { name: "Weavers & Artisans", exact: true }),
  ).toHaveCount(0);
  await expect(
    page
      .locator(".sidebar")
      .getByRole("button", { name: "Suppliers", exact: true }),
  ).toHaveCount(0);
  const statuses = await page.evaluate(async () => {
    const headers = {
      Authorization: `Bearer ${localStorage.getItem("csms_token")}`,
      Accept: "application/json",
    };
    return Promise.all(
      ["weavers", "suppliers", "provinces", "lookups"].map(
        async (resource) =>
          (await fetch(`/api/directories/${resource}`, { headers })).status,
      ),
    );
  });
  expect(statuses).toEqual([403, 403, 403, 403]);
});
