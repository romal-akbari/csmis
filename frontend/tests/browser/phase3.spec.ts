import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test, openSidebarPage, type Page } from "./fixtures";

type Fixture = {
  suffix: string;
  branch: number;
  other: number;
  location: number;
  destination: number;
  category: number;
  weaver: number;
  currency: number;
  account: number;
  expenseCategory: number;
};
function fixture(): Fixture {
  return JSON.parse(
    execFileSync("php", ["scripts/seed-inventory-browser.php"], {
      cwd: path.resolve(process.cwd(), "../backend"),
      encoding: "utf8",
    }),
  );
}
async function login(page: Page, username = "browser_admin") {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill(username);
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}
async function search(page: Page, text: string) {
  await page
    .getByRole("textbox", { name: "Search records", exact: true })
    .fill(text);
  await page.getByRole("button", { name: "Search", exact: true }).click();
}

test("carpets, images, multi-item acquisition, receiving, transfer, payments and reversal work end to end", async ({
  page, context,
}) => {
  test.setTimeout(180_000);
  const f = fixture();
  const codes = [`I-${f.suffix}-1`, `I-${f.suffix}-2`];
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page);
  await openSidebarPage(page, "Carpets");
  const dialog = page.getByRole("dialog");
  for (const code of codes) {
    await page
      .getByRole("button", { name: "Register carpet", exact: true })
      .click();
    await dialog.getByLabel("Carpet code", { exact: true }).fill(code);
    await dialog
      .getByLabel("Name", { exact: true })
      .fill(`Wool carpet ${code}`);
    await dialog
      .getByLabel("Registration branch", { exact: true })
      .selectOption(String(f.branch));
    await dialog
      .getByLabel("Carpet categories", { exact: true })
      .selectOption(String(f.category));
    await dialog.getByLabel("Length (cm)").fill("200");
    await dialog.getByLabel("Width (cm)").fill("150");
    await dialog
      .getByRole("button", { name: "Register carpet", exact: true })
      .click();
    await expect(dialog).not.toBeVisible();
  }
  await search(page, codes[0]);
  await page
    .getByRole("row")
    .filter({ hasText: codes[0] })
    .getByRole("button", { name: "View", exact: true })
    .click();
  await dialog
    .getByLabel("Upload image", { exact: true })
    .setInputFiles({
      name: "carpet.png",
      mimeType: "image/png",
      buffer: Buffer.from(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a0ioAAAAASUVORK5CYII=",
        "base64",
      ),
    });
  await dialog
    .getByLabel("Image description", { exact: true })
    .fill("Wool carpet photo");
  await dialog.getByRole("button", { name: "Upload", exact: true }).click();
  await expect(dialog.getByAltText("Wool carpet photo")).toBeVisible();
  await expect
    .poll(() =>
      dialog
        .getByAltText("Wool carpet photo")
        .evaluate((image) => (image as HTMLImageElement).naturalWidth),
    )
    .toBeGreaterThan(0);
  await dialog.getByRole("button", { name: "Close", exact: true }).click();

  await openSidebarPage(page, "Carpet purchases");
  await page.getByRole("button", { name: "Purchase & receive", exact: true }).click();
  await expect(dialog.getByLabel("Purchase reference")).toHaveCount(0);
  await dialog.getByLabel(/^Branch(?: [*])?$/).selectOption(String(f.branch));
  await dialog.getByLabel(/^Weaver(?: [*])?$/).selectOption(String(f.weaver));
  await dialog.getByLabel(/^Currency(?: [*])?$/).selectOption(String(f.currency));
  await dialog.getByLabel(/^Destination stock location(?: [*])?$/).selectOption(String(f.location));
  for (const code of codes) await dialog.getByRole("checkbox", { name: new RegExp(code) }).check();
  await dialog.getByLabel("Unit price", { exact: true }).nth(0).fill("100.10");
  await dialog.getByLabel("Unit price", { exact: true }).nth(1).fill("200.20");
  await expect(dialog).toContainText("300.30");
  const savedResponse = page.waitForResponse((response) => response.url().endsWith("/api/business/purchases") && response.request().method() === "POST");
  await dialog.getByRole("button", { name: "Purchase & receive", exact: true }).click();
  const purchaseReference = (await (await savedResponse).json()).data.purchase_no;
  await expect(dialog).not.toBeVisible();
  await search(page, purchaseReference);
  let purchaseRow = page.getByRole("row").filter({ hasText: purchaseReference });
  await expect(purchaseRow).toContainText("300.30");
  const accountsTab = await context.newPage();
  await accountsTab.goto("/");
  await openSidebarPage(accountsTab, "Accounts");
  await accountsTab.getByRole("textbox", { name: "Search", exact: true }).fill(f.suffix);
  await accountsTab.getByRole("button", { name: "Search", exact: true }).click();
  const accountRow = accountsTab.getByRole("row").filter({ hasText: `Inventory cash ${f.suffix}` });
  await expect(accountRow).toContainText("5000.00");
  await page.bringToFront();
  await purchaseRow
    .getByRole("button", { name: "Record payment", exact: true })
    .click();
  await dialog
    .getByLabel(/^Account(?: [*])?$/)
    .selectOption(String(f.account));
  await dialog.getByLabel(/^Amount(?: [*])?$/).fill("100.05");
  await dialog
    .getByLabel(/^Reason(?: [*])?$/)
    .fill("Partial purchase payment");
  const paymentResponse = page.waitForResponse((response) =>
    /\/api\/business\/purchases\/\d+\/pay$/.test(response.url()) &&
    response.request().method() === "POST",
  );
  await dialog
    .getByRole("button", { name: "Record payment", exact: true })
    .click();
  const paymentReference = (await (await paymentResponse).json()).data.payment_no;
  expect(paymentReference).toMatch(/^WPAY-/);
  await expect(dialog).not.toBeVisible();
  await expect(purchaseRow).toContainText("200.25");
  await expect(accountRow).toContainText("4899.95");
  await accountRow.getByRole("button", { name: "View ledger", exact: true }).click();
  await expect(accountsTab.getByText("4899.95 USD", { exact: true })).toBeVisible();
  await page.bringToFront();

  await openSidebarPage(page, "Stock receipts");
  await expect(page.getByRole("table")).toContainText(`Inventory shelf ${f.suffix}`);

  await openSidebarPage(page, "Stock transfers");
  await page
    .getByRole("button", { name: "Transfer stock", exact: true })
    .click();
  await dialog.getByLabel("Transfer reference").fill(`T-${f.suffix}`);
  await dialog
    .getByLabel("From location", { exact: true })
    .selectOption(String(f.location));
  await dialog
    .getByLabel("To location", { exact: true })
    .selectOption(String(f.destination));
  await dialog
    .getByRole("checkbox", {
      name: `${codes[0]} · Wool carpet ${codes[0]}`,
      exact: true,
    })
    .check();
  await dialog
    .getByRole("button", { name: "Transfer stock", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await openSidebarPage(page, "Carpets");
  await search(page, codes[0]);
  const carpetRow = page.getByRole("row").filter({ hasText: codes[0] });
  await expect(carpetRow).toContainText(`Inventory destination ${f.suffix}`);
  await carpetRow.getByRole("button", { name: "View", exact: true }).click();
  await expect(dialog.getByRole("row")).toHaveCount(4);
  await expect(dialog.getByAltText("Wool carpet photo")).toBeVisible();
  await page.screenshot({
    path: "test-results/phase3-carpet-history.png",
    fullPage: true,
  });
  await dialog
    .getByRole("button", { name: "Delete image", exact: true })
    .click();
  await expect(dialog.getByAltText("Wool carpet photo")).toHaveCount(0);
  await expect(dialog).toContainText("No images yet.");
  await dialog.getByRole("button", { name: "Close", exact: true }).click();

  await openSidebarPage(page, "Weaver payments");
  await page
    .getByRole("button", { name: "Record payment", exact: true })
    .click();
  await dialog.getByLabel("Payment reference").fill(`BONUS-${f.suffix}`);
  await dialog
    .getByLabel("Branch", { exact: true })
    .selectOption(String(f.branch));
  await dialog
    .getByLabel("Payment type", { exact: true })
    .selectOption("additional");
  await dialog
    .getByLabel("Account", { exact: true })
    .selectOption(String(f.account));
  await dialog
    .getByLabel("Weaver", { exact: true })
    .selectOption(String(f.weaver));
  await dialog
    .getByLabel("Expense category", { exact: true })
    .selectOption(String(f.expenseCategory));
  await dialog.getByLabel("Amount", { exact: true }).fill("10.00");
  await dialog.getByLabel("Reason", { exact: true }).fill("Quality bonus");
  await dialog
    .getByRole("button", { name: "Record payment", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await search(page, paymentReference);
  const paymentRow = page
    .getByRole("row")
    .filter({ hasText: paymentReference });
  await paymentRow
    .getByRole("button", { name: "Reverse payment", exact: true })
    .click();
  await dialog
    .getByLabel("Reversal reason", { exact: true })
    .fill("Correcting the test payment");
  await dialog
    .getByRole("button", { name: "Reverse payment", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await expect(paymentRow).toContainText("Reversed");
  await expect(accountsTab.getByText("4990.00 USD", { exact: true })).toBeVisible();
  await accountsTab.getByRole("button", { name: "Accounts", exact: true }).click();
  await expect(accountRow).toContainText("4990.00");
  await accountsTab.getByRole("button", { name: "Refresh balances", exact: true }).click();
  await expect(accountRow).toContainText("4990.00");
  await accountsTab.close();
  await openSidebarPage(page, "Carpet purchases");
  await search(page, purchaseReference);
  purchaseRow = page.getByRole("row").filter({ hasText: purchaseReference });
  await expect(
    purchaseRow.getByRole("cell", { name: "300.30", exact: true }),
  ).toHaveCount(1);
  const purchaseToggle = purchaseRow.getByRole("button", {
    name: `Show purchase details ${purchaseReference}`, exact: true,
  });
  await purchaseToggle.click();
  const purchaseDetails = page.getByRole("region", {
    name: `Purchase details ${purchaseReference}`, exact: true,
  });
  await expect(purchaseDetails).toContainText("Paid amount");
  await expect(purchaseDetails).toContainText("0.00");
  expect(errors).toEqual([]);
});

test("Dari inventory forms validate and fit mobile; sales cannot post acquisitions or payments", async ({
  page,
}) => {
  const f = fixture();
  await login(page);
  await page
    .getByRole("combobox", { name: "Display language" })
    .selectOption("fa");
  await openSidebarPage(page, "Carpets", "fa");
  await page.getByRole("button", { name: "ثبت قالین", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await dialog.getByRole("button", { name: "ثبت قالین", exact: true }).click();
  await expect(dialog).toContainText("این خانه الزامی است.");
  await dialog.getByLabel("کد قالین", { exact: true }).fill(`FA-${f.suffix}`);
  await dialog.getByLabel("نام", { exact: true }).fill("قالین پشمی هرات");
  await dialog
    .getByLabel("شعبه ثبت", { exact: true })
    .selectOption(String(f.branch));
  await dialog
    .getByLabel("دسته‌بندی قالین‌ها", { exact: true })
    .selectOption(String(f.category));
  for (const width of [390, 320]) {
    await page.setViewportSize({ width, height: 844 });
    await expect(dialog).toHaveAttribute("dir", "rtl");
    const box = (await dialog.boundingBox())!;
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
    expect(
      await dialog.evaluate((node) => node.scrollWidth <= node.clientWidth + 1),
    ).toBe(true);
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
    ).toBe(true);
  }
  await page.screenshot({
    path: "test-results/phase3-dari-mobile.png",
    fullPage: true,
  });
  await dialog.getByRole("button", { name: "ثبت قالین", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.locator(".profile-button").click();
  await page.getByRole("button", { name: "خروج", exact: true }).click();
  await login(page, "browser_sales");
  await openSidebarPage(page, "Carpets");
  await expect(
    page.getByRole("button", { name: "Register carpet", exact: true }),
  ).toHaveCount(0);
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  for (const resource of ["purchases", "payments", "receipts", "transfers"]) {
    const result = await page.request.post(`/api/inventory/${resource}`, {
      data: {},
      headers: { Authorization: `Bearer ${token}` },
    });
    expect(result.status()).toBe(403);
  }
});
