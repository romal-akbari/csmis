import { execFileSync } from "node:child_process";
import path from "node:path";
import { expect, test, openSidebarPage, type Page } from "./fixtures";
import { inventoryToday } from "../../src/lib/inventory";

type Fixture = {
  suffix: string;
  branch: number;
  location: number;
  category: number;
  weaver: number;
  currency: number;
  account: number;
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
async function api(
  page: Page,
  method: "get" | "post",
  url: string,
  data?: Record<string, unknown>,
) {
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  const result = await page.request[method](`/api${url}`, {
    data,
    headers: {
      Authorization: `Bearer ${token}`,
      Accept: "application/json",
      "Idempotency-Key": `browser-${Date.now()}-${Math.random()}`,
    },
  });
  expect(result.ok(), await result.text()).toBe(true);
  return result.json();
}
async function search(page: Page, text: string) {
  await page
    .getByRole("textbox", { name: "Search records", exact: true })
    .fill(text);
  await page.getByRole("button", { name: "Search", exact: true }).click();
}

test("draft editing and posting, paid cancellation, refunds, stock corrections and returns work through the UI", async ({
  page,
}) => {
  test.setTimeout(180_000);
  const f = fixture();
  const codes = [`C-${f.suffix}-1`, `C-${f.suffix}-2`];
  const carpets: number[] = [];
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page);
  const today = inventoryToday(
    (await api(page, "get", "/inventory/lookups")).data.company.timezone,
  );
  for (const code of codes)
    carpets.push(
      (
        await api(page, "post", "/inventory/carpets", {
          carpet_code: code,
          name: "Correction carpet",
          registration_branch_id: f.branch,
          category_id: f.category,
        })
      ).data.id,
    );
  const shelf = (
    await api(page, "post", "/foundation/locations", {
      code: `S-${f.suffix}`,
      name: `Correction shelf ${f.suffix}`,
      branch_id: f.branch,
      washing_facility_id: null,
      location_type: "shelf",
      status: "active",
    })
  ).data.id;
  const dialog = page.getByRole("dialog");
  // Retain a pre-upgrade unreceived draft to verify cancellation and refund compatibility.
  await api(page, "post", "/inventory/corrections/drafts", { purchase_no: `D-${f.suffix}`, branch_id: f.branch, purchase_on: today, rate_to_base: "1", items: [] });
  await openSidebarPage(page, "Purchase drafts");
  await search(page, `D-${f.suffix}`);
  await page
    .getByRole("row")
    .filter({ hasText: `D-${f.suffix}` })
    .getByRole("button", { name: "Edit draft", exact: true })
    .click();
  await dialog
    .getByLabel("Weaver", { exact: true })
    .selectOption(String(f.weaver));
  await dialog
    .getByLabel("Currency", { exact: true })
    .selectOption(String(f.currency));
  for (const code of codes)
    await dialog
      .getByRole("checkbox", {
        name: `${code} · Correction carpet`,
        exact: true,
      })
      .check();
  await dialog
    .getByRole("textbox", {
      name: `Unit price ${codes[0]} · Correction carpet`,
      exact: true,
    })
    .fill("100.10");
  await dialog
    .getByRole("textbox", {
      name: `Unit price ${codes[1]} · Correction carpet`,
      exact: true,
    })
    .fill("200.20");
  await dialog.getByRole("button", { name: "Save draft", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  const historicalDraft = (await api(page, "get", `/inventory/corrections/drafts?search=D-${f.suffix}`)).data[0];
  await api(page, "post", `/inventory/corrections/drafts/${historicalDraft.id}/post`, { version: historicalDraft.version });
  await openSidebarPage(page, "Carpet purchases");
  await search(page, `D-${f.suffix}`);
  let row = page.getByRole("row").filter({ hasText: `D-${f.suffix}` });
  await expect(row).toContainText("300.30");
  await row
    .getByRole("button", { name: "Record payment", exact: true })
    .click();
  await dialog
    .getByLabel(/^Account(?: [*])?$/)
    .selectOption(String(f.account));
  await dialog.getByLabel(/^Amount(?: [*])?$/).fill("50.00");
  await dialog
    .getByLabel(/^Reason(?: [*])?$/)
    .fill("Genuine partial payment");
  await dialog
    .getByRole("button", { name: "Record payment", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await row.getByRole("button", { name: "Void purchase", exact: true }).click();
  await dialog
    .getByLabel(/^Reason(?: [*])?$/)
    .fill("Purchase cancelled before receiving");
  await dialog
    .getByRole("button", { name: "Void purchase", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await expect(row).toContainText("Credit owed by weaver: 50.00");
  await row.getByRole("button", { name: "Record refund", exact: true }).click();
  await dialog.getByLabel("Refund reference").fill(`RF1-${f.suffix}`);
  await dialog
    .getByLabel(/^Account(?: [*])?$/)
    .selectOption(String(f.account));
  await dialog.getByLabel(/^Amount(?: [*])?$/).fill("50.00");
  await dialog
    .getByLabel(/^Reason(?: [*])?$/)
    .fill("Cash refund actually received");
  await dialog
    .getByRole("button", { name: "Record refund", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await expect(row).not.toContainText("Credit owed by weaver");

  // Reacquire the same physical carpets without rewriting the cancelled acquisition.
  const purchase = (
    await api(page, "post", "/inventory/purchases", {
      purchase_no: `P2-${f.suffix}`,
      branch_id: f.branch,
      weaver_id: f.weaver,
      purchase_on: today,
      currency_id: f.currency,
      rate_to_base: "1",
      items: [
        { carpet_id: carpets[0], unit_price: "100.10" },
        { carpet_id: carpets[1], unit_price: "200.20" },
      ],
    })
  ).data.id;
  await api(page, "post", "/inventory/receipts", {
    receipt_no: `R-${f.suffix}`,
    location_id: f.location,
    received_on: today,
    items: carpets.map((carpet_id) => ({ carpet_id, expected_sequence: 3 })),
  });
  await api(page, "post", "/inventory/payments", {
    payment_no: `PAY2-${f.suffix}`,
    branch_id: f.branch,
    weaver_id: f.weaver,
    account_id: f.account,
    payment_type: "purchase",
    amount: "300.30",
    rate_to_base: "1",
    payment_on: today,
    reason: "Actual payment on reacquisition",
    allocations: [
      {
        weaver_purchase_id: purchase,
        amount_in_payment_currency: "300.30",
        purchase_units_per_payment_unit: "1",
      },
    ],
  });
  await openSidebarPage(page, "Stock corrections");
  for (const [index, type, destination] of [
    [1, "relocate", shelf],
    [2, "missing", null],
    [3, "found", f.location],
  ] as const) {
    await page
      .getByRole("button", { name: "Correct stock", exact: true })
      .click();
    await dialog
      .getByLabel("Correction reference")
      .fill(`A${index}-${f.suffix}`);
    await dialog
      .getByLabel("Branch", { exact: true })
      .selectOption(String(f.branch));
    await dialog
      .getByLabel("Correction type", { exact: true })
      .selectOption(type);
    if (destination)
      await dialog
        .getByLabel("To location", { exact: true })
        .selectOption(String(destination));
    await dialog
      .getByRole("checkbox", {
        name: `${codes[0]} · Correction carpet`,
        exact: true,
      })
      .check();
    await dialog
      .getByLabel(/^Reason(?: [*])?$/)
      .fill(`Physical count correction ${type}`);
    await dialog
      .getByRole("button", { name: "Correct stock", exact: true })
      .click();
    await expect(dialog).not.toBeVisible();
  }
  await openSidebarPage(page, "Carpet purchases");
  await search(page, `P2-${f.suffix}`);
  row = page.getByRole("row").filter({ hasText: `P2-${f.suffix}` });
  await expect(row.getByRole("button", { name: "Void purchase", exact: true })).toHaveCount(0);
  await row
    .getByRole("button", { name: "Return carpets", exact: true })
    .click();
  await dialog.getByLabel("Return reference").fill(`RET-${f.suffix}`);
  await dialog
    .getByLabel("From location", { exact: true })
    .selectOption(String(f.location));
  await dialog.getByRole("checkbox", { name: new RegExp(codes[0]) }).check();
  await dialog
    .getByLabel(/^Reason(?: [*])?$/)
    .fill("Return defective weave to weaver");
  await dialog
    .getByRole("button", { name: "Return carpets", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await expect(row).toContainText("Credit owed by weaver: 100.10");
  await row.getByRole("button", { name: "Record refund", exact: true }).click();
  await dialog.getByLabel("Refund reference").fill(`RF2-${f.suffix}`);
  await dialog
    .getByLabel(/^Account(?: [*])?$/)
    .selectOption(String(f.account));
  await dialog.getByLabel(/^Amount(?: [*])?$/).fill("100.10");
  await dialog
    .getByLabel(/^Reason(?: [*])?$/)
    .fill("Refund received for returned carpet");
  await dialog
    .getByRole("button", { name: "Record refund", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await expect(row).not.toContainText("Credit owed by weaver");
  const stock = (await api(page, "get", `/inventory/carpets/${carpets[0]}`))
    .data;
  expect(stock.stock_status).toBe("returned");
  expect(stock.events).toHaveLength(8);
  expect(
    (await api(page, "get", `/foundation/ledger?account_id=${f.account}`))
      .balance,
  ).toBe("4799.80");
  await openSidebarPage(page, "Weaver returns");
  await search(page, `RET-${f.suffix}`);
  await page
    .getByRole("row")
    .filter({ hasText: `RET-${f.suffix}` })
    .getByRole("button", { name: "View", exact: true })
    .click();
  await expect(dialog).toContainText(codes[0]);
  await expect(dialog).toContainText("100.10");
  await page.screenshot({
    path: "test-results/inventory-return-review.png",
    fullPage: true,
  });
  await dialog
    .getByRole("button", { name: "Close", exact: true })
    .first()
    .click();
  await openSidebarPage(page, "Weaver refunds");
  await search(page, `RF2-${f.suffix}`);
  const refund = page.getByRole("row").filter({ hasText: `RF2-${f.suffix}` });
  await refund
    .getByRole("button", { name: "Reverse refund", exact: true })
    .click();
  await dialog
    .getByLabel("Reversal reason", { exact: true })
    .fill("Correct the refund record");
  await dialog
    .getByRole("button", { name: "Reverse refund", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  await expect(refund).toContainText("Reversed");
  const updated = (
    await api(page, "get", `/inventory/purchases?search=P2-${f.suffix}`)
  ).data[0];
  expect(updated.remaining_amount).toBe("-100.10");
});

test("Dari draft cancellation validates, fits mobile, and sales cannot post corrections", async ({
  page,
}) => {
  test.setTimeout(90_000);
  const f = fixture();
  await login(page);
  await page
    .getByRole("combobox", { name: "Display language" })
    .selectOption("fa");
  await openSidebarPage(page, "Purchase drafts", "fa");
  await page
    .getByRole("button", { name: "مسوده جدید خرید", exact: true })
    .click();
  const dialog = page.getByRole("dialog");
  await dialog
    .getByRole("button", { name: "ذخیره مسوده", exact: true })
    .click();
  await expect(dialog).toContainText("این خانه الزامی است.");
  await dialog
    .getByLabel("شعبه", { exact: true })
    .selectOption(String(f.branch));
  await dialog
    .getByRole("button", { name: "ذخیره مسوده", exact: true })
    .click();
  await expect(dialog).not.toBeVisible();
  const generatedReference = await page.getByRole("table").locator("tbody tr").first().getByRole("cell").first().innerText();
  const row = page.getByRole("row").filter({ hasText: generatedReference });
  await row.getByRole("button", { name: "لغو مسوده", exact: true }).click();
  await dialog.getByRole("button", { name: "لغو مسوده", exact: true }).click();
  await expect(dialog.getByRole("alert")).toContainText(
    "دلیلی با حداقل سه حرف وارد کنید.",
  );
  await dialog
    .getByLabel("دلیل", { exact: true })
    .fill("این مسوده دیگر لازم نیست");
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
    path: "test-results/inventory-corrections-dari-mobile.png",
    fullPage: true,
  });
  await dialog.getByRole("button", { name: "لغو مسوده", exact: true }).click();
  await expect(dialog).not.toBeVisible();
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page
    .getByRole("combobox", { name: "وضعیت", exact: true })
    .selectOption("cancelled");
  await expect(row).toBeVisible();
  await expect(
    row.getByRole("button", { name: "ویرایش مسوده", exact: true }),
  ).toHaveCount(0);
  await page.locator(".profile-button").click();
  await page.getByRole("button", { name: "خروج", exact: true }).click();
  await login(page, "browser_sales");
  await openSidebarPage(page, "Stock corrections");
  await expect(
    page.getByRole("button", { name: "Correct stock", exact: true }),
  ).toHaveCount(0);
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  for (const resource of ["drafts", "returns", "refunds", "adjustments"]) {
    const result = await page.request.post(
      `/api/inventory/corrections/${resource}`,
      { data: {}, headers: { Authorization: `Bearer ${token}` } },
    );
    expect(result.status()).toBe(403);
  }
});
