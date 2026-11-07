import type { Locator } from "@playwright/test";
import { businessText } from "../../src/lib/business-i18n";
import { expect, test, openSidebarPage, type Page } from "./fixtures";

const longReference = `PURCHASE-${"A".repeat(31)}`;
const longWeaver = `Weaver ${"W".repeat(143)}`;
const creditReference = "CREDIT-REFUND-EXAMPLE";
const purchases = [
  {
    id: 900001,
    purchase_no: longReference,
    weaver_name: longWeaver,
    weaver_id: 1,
    branch_id: 1,
    purchase_on: "2026-10-01",
    currency_id: 1,
    currency_code: "USD",
    total_amount: "300.30",
    paid_amount: "100.05",
    remaining_amount: "200.25",
    returned_amount: "0.00",
    refunded_amount: "0.00",
    state: "posted",
    can_void: true,
  },
  {
    id: 900002,
    purchase_no: creditReference,
    weaver_name: "Zahra Ahmadi",
    weaver_id: 2,
    branch_id: 1,
    purchase_on: "2026-10-02",
    currency_id: 1,
    currency_code: "USD",
    total_amount: "100.00",
    paid_amount: "100.00",
    remaining_amount: "-50.00",
    returned_amount: "100.00",
    refunded_amount: "50.00",
    state: "void",
  },
];

async function openPurchases(page: Page) {
  await page.route(/\/api\/business\/purchases(?:\?.*)?$/, async (route) => {
    await route.fulfill({
      json: {
        data: purchases,
        total: purchases.length,
        current_page: 1,
        last_page: 1,
      },
    });
  });
  await page.route(/\/api\/business\/purchases\/90000[12]$/, async (route) => {
    const id = Number(new URL(route.request().url()).pathname.split("/").at(-1));
    await route.fulfill({
      json: { data: { ...purchases.find((row) => row.id === id), items: [] } },
    });
  });
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill("browser_admin");
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
  await openSidebarPage(page, "Carpet purchases");
  await expect(page.getByRole("table")).toContainText(longReference);
  return page.getByRole("table");
}

function disclosure(row: Locator) {
  return row.locator("button[aria-expanded][aria-controls]");
}

async function controlledDetails(page: Page, button: Locator) {
  const id = await button.getAttribute("aria-controls");
  expect(id).toBeTruthy();
  const details = page.locator(`[id="${id}"]`);
  await expect(details).toHaveCount(1);
  expect(await details.evaluate((node) => node.tagName)).toBe("TR");
  return details;
}

async function expectFits(page: Page, table: Locator) {
  const container = table.locator("..");
  await expect
    .poll(() =>
      container.evaluate((node) => node.scrollWidth <= node.clientWidth + 1),
    )
    .toBe(true);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth + 1,
    ),
  ).toBe(true);
  const bounds = (await table.boundingBox())!;
  expect(bounds.x).toBeGreaterThanOrEqual(-1);
  expect(bounds.x + bounds.width).toBeLessThanOrEqual(
    page.viewportSize()!.width + 1,
  );
}

test("purchase disclosures work with keyboard, keep rows independent, and retain permitted actions", async ({
  page,
}) => {
  test.setTimeout(90_000);
  const errors: string[] = [];
  const writes: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("request", (request) => {
    if (
      request.url().includes("/api/inventory/") &&
      !["GET", "HEAD"].includes(request.method())
    )
      writes.push(`${request.method()} ${request.url()}`);
  });
  await page.setViewportSize({ width: 1440, height: 1000 });
  const table = await openPurchases(page);
  const row = table.getByRole("row").filter({ hasText: longReference });
  const creditRow = table.getByRole("row").filter({ hasText: creditReference });
  const first = disclosure(row);
  const second = disclosure(creditRow);
  await expect(first).toHaveAccessibleName(`Show purchase details ${longReference}`);
  await expect(first).toHaveAttribute("aria-expanded", "false");
  await expect(second).toHaveAttribute("aria-expanded", "false");
  const details = await controlledDetails(page, first);
  const creditDetails = await controlledDetails(page, second);
  await expect(details).not.toBeVisible();
  await expect(creditDetails).not.toBeVisible();
  await expectFits(page, table);

  await first.focus();
  await first.press("Enter");
  await expect(first).toBeFocused();
  await expect(first).toHaveAttribute("aria-expanded", "true");
  await expect(first).toHaveAccessibleName(`Hide purchase details ${longReference}`);
  await expect(details).toBeVisible();
  for (const label of [
    "Date", "Currency", "Paid amount", "Returned amount", "Refunded amount",
  ])
    await expect(details.getByText(label, { exact: true })).toBeVisible();
  for (const value of ["2026-10-01", "USD", "100.05"])
    await expect(details.getByText(value, { exact: true })).toBeVisible();
  await expect(second).toHaveAttribute("aria-expanded", "false");
  await second.click();
  await expect(creditDetails).toBeVisible();
  await expect(details).toBeVisible();
  await first.focus();
  await first.press("Space");
  await expect(first).toBeFocused();
  await expect(first).toHaveAttribute("aria-expanded", "false");
  await expect(details).not.toBeVisible();
  await expect(creditDetails).toBeVisible();

  await expect(creditRow).toContainText("Credit owed by weaver: 50.00");
  await expect(creditRow.getByRole("button", { name: "Record payment", exact: true })).toHaveCount(0);
  await expect(creditRow.getByRole("button", { name: "Void purchase", exact: true })).toHaveCount(0);
  await expect(creditRow.getByRole("button", { name: "Return carpets", exact: true })).toHaveCount(0);
  await creditRow.getByRole("button", { name: "Record refund", exact: true }).click();
  const dialog = page.getByRole("dialog");
  await expect(dialog.getByRole("heading", { name: "Record refund", exact: true })).toBeVisible();
  await dialog.getByRole("button", { name: "Close", exact: true }).first().click();
  await expect(dialog).not.toBeVisible();
  await row.getByRole("button", { name: "View details", exact: true }).click();
  await expect(dialog).toContainText(longReference);
  await dialog.getByRole("button", { name: "Close", exact: true }).first().click();
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});

test("purchase data stays readable without horizontal scrolling on desktop and mobile in English and Dari", async ({
  page,
}) => {
  test.setTimeout(120_000);
  await page.setViewportSize({ width: 1440, height: 1000 });
  const table = await openPurchases(page);
  const row = table.getByRole("row").filter({ hasText: longReference });
  const creditRow = table.getByRole("row").filter({ hasText: creditReference });
  const first = disclosure(row);
  const second = disclosure(creditRow);
  const details = await controlledDetails(page, first);
  const creditDetails = await controlledDetails(page, second);
  await first.click();
  await second.click();

  for (const locale of ["en", "fa"]) {
    await page.getByRole("combobox", { name: "Display language" }).selectOption(locale);
    await expect(page.locator("html")).toHaveAttribute("dir", locale === "fa" ? "rtl" : "ltr");
    const t = (text: string) => businessText(locale, text);
    await expect(first).toHaveAccessibleName(`${t("Hide purchase details")} ${longReference}`);
    for (const width of [1440, 1024, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      await expectFits(page, table);
      await expect(row.getByText(longReference, { exact: true })).toBeVisible();
      await expect(row).toContainText("300.30");
      await expect(details).toBeVisible();
      for (const label of [
        "Date", "Currency", "Paid amount", "Returned amount", "Refunded amount",
      ])
        await expect(details.getByText(t(label), { exact: true })).toBeVisible();
      for (const value of ["2026-10-01", "USD", "100.05"])
        await expect(details.getByText(value, { exact: true })).toBeVisible();
      const fontSizes = await table
        .locator("th:visible, td:visible, dt:visible, dd:visible")
        .evaluateAll((nodes) => nodes.map((node) => getComputedStyle(node).fontSize));
      expect(fontSizes.length).toBeGreaterThan(10);
      expect([...new Set(fontSizes)]).toEqual(["12px"]);
      if (width <= 390) {
        const totalHeader = table.getByRole("columnheader", { name: t("Purchase total"), exact: true });
        const totalBounds = (await totalHeader.boundingBox())!;
        expect(totalBounds.width).toBeGreaterThanOrEqual(70);
        expect(totalBounds.height).toBeLessThan(90);
        await expect(details.getByText(longWeaver, { exact: true })).toBeVisible();
        await expect(details.getByText("200.25", { exact: true })).toBeVisible();
        await expect(details.getByRole("button", { name: t("View details"), exact: true })).toBeVisible();
        await expect(details.getByRole("button", { name: t("Record payment"), exact: true })).toBeVisible();
        await expect(creditDetails).toContainText(`${t("Credit owed by weaver")}: 50.00`);
        await expect(creditDetails.getByRole("button", { name: t("Record refund"), exact: true })).toBeVisible();
      }
      if ((locale === "en" && width === 1440) || (locale === "fa" && width === 390))
        await page.screenshot({
          path: `test-results/purchase-table-${locale}-${width}.png`,
          fullPage: true,
          animations: "disabled",
        });
    }
  }
});

test("purchase details animate in both directions, stay distinct, and respect reduced motion", async ({
  page,
}) => {
  test.setTimeout(120_000);
  await page.emulateMedia({ reducedMotion: "no-preference" });
  await page.setViewportSize({ width: 1440, height: 1000 });
  const table = await openPurchases(page);
  const row = table.getByRole("row").filter({ hasText: longReference });
  const first = disclosure(row);
  const details = await controlledDetails(page, first);
  const wrapper = details.locator("[data-purchase-disclosure]");
  await expect(wrapper).toHaveCount(1);

  // Sample the rendered height each frame: a transition declaration alone
  // cannot establish that a table row really opens and closes gradually.
  const sampleToggle = () => first.evaluate(async (button) => {
    const target = document.getElementById(button.getAttribute("aria-controls")!);
    const panel = target!.querySelector<HTMLElement>("[data-purchase-disclosure]")!;
    const samples = [panel.getBoundingClientRect().height];
    (button as HTMLButtonElement).click();
    const started = performance.now();
    await new Promise<void>((resolve) => {
      const sample = () => {
        samples.push(panel.getBoundingClientRect().height);
        if (performance.now() - started >= 550) resolve();
        else requestAnimationFrame(sample);
      };
      requestAnimationFrame(sample);
    });
    return samples;
  });
  const opening = await sampleToggle();
  const expandedHeight = opening.at(-1)!;
  expect(opening[0]).toBeLessThanOrEqual(1);
  expect(expandedHeight).toBeGreaterThan(50);
  expect(opening.some((height) => height > 2 && height < expandedHeight - 2)).toBe(true);
  await expect(first).toHaveAttribute("aria-expanded", "true");
  await expect(details).toHaveAttribute("aria-hidden", "false");
  await expect(details).not.toHaveAttribute("inert");
  const panelBackground = await details.getByRole("region").evaluate(
    (node) => getComputedStyle(node).backgroundColor,
  );
  const summaryBackground = await row.evaluate(
    (node) => getComputedStyle(node).backgroundColor,
  );
  expect(panelBackground).not.toBe("rgba(0, 0, 0, 0)");
  expect(panelBackground).not.toBe(summaryBackground);
  await expectFits(page, table);

  const closing = await sampleToggle();
  expect(closing[0]).toBeCloseTo(expandedHeight, 0);
  expect(closing.at(-1)).toBeLessThanOrEqual(1);
  expect(closing.some((height) => height > 2 && height < expandedHeight - 2)).toBe(true);
  await expect(details).not.toBeVisible();
  await expect(details).toHaveAttribute("aria-hidden", "true");
  await expect(details).toHaveAttribute("inert");

  // Reverse while moving in each direction. Neither click should snap to
  // the start/end, and the final state must follow the most recent click.
  const reversal = await first.evaluate(async (button) => {
    const panel = document.getElementById(button.getAttribute("aria-controls")!)!
      .querySelector<HTMLElement>("[data-purchase-disclosure]")!;
    const height = () => panel.getBoundingClientRect().height;
    const waitFrames = (duration: number) => new Promise<void>((resolve) => {
      const started = performance.now();
      const tick = () => performance.now() - started >= duration
        ? resolve() : requestAnimationFrame(tick);
      requestAnimationFrame(tick);
    });
    (button as HTMLButtonElement).click();
    await waitFrames(90);
    const beforeClose = height();
    (button as HTMLButtonElement).click();
    const afterClose = height();
    await waitFrames(50);
    const beforeReopen = height();
    (button as HTMLButtonElement).click();
    const afterReopen = height();
    await waitFrames(550);
    return { beforeClose, afterClose, beforeReopen, afterReopen, final: height() };
  });
  expect(reversal.beforeClose).toBeGreaterThan(1);
  expect(reversal.beforeClose).toBeLessThan(expandedHeight - 1);
  expect(Math.abs(reversal.afterClose - reversal.beforeClose)).toBeLessThan(2);
  expect(Math.abs(reversal.afterReopen - reversal.beforeReopen)).toBeLessThan(2);
  expect(reversal.final).toBeCloseTo(expandedHeight, 0);
  await expect(first).toHaveAttribute("aria-expanded", "true");

  await page.setViewportSize({ width: 320, height: 1000 });
  await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
  await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
  await expectFits(page, table);
  const mobileFonts = await details.locator("dt:visible, dd:visible")
    .evaluateAll((nodes) => [...new Set(nodes.map((node) => getComputedStyle(node).fontSize))]);
  expect(mobileFonts).toEqual(["12px"]);
  await first.click();
  await expect(details).toHaveAttribute("inert");
  await expect(details).not.toBeVisible();
  expect(await details.locator("button").count()).toBeGreaterThan(0);
  await first.focus();
  const detailId = await details.getAttribute("id");
  for (let index = 0; index < 10; index++) {
    await page.keyboard.press("Tab");
    expect(await page.evaluate(
      (id) => document.getElementById(id!)!.contains(document.activeElement), detailId,
    )).toBe(false);
  }

  await page.emulateMedia({ reducedMotion: "reduce" });
  await first.click();
  await expect(details).toBeVisible();
  const transitions = await wrapper.evaluate((node) => ({
    property: getComputedStyle(node).transitionProperty,
    durations: getComputedStyle(node).transitionDuration.split(",").map((value) => parseFloat(value)),
    animation: getComputedStyle(node).animationName,
  }));
  expect(transitions.property).toBe("none");
  // The shared reduced-motion reset applies a 0.01ms maximum duration globally.
  expect(transitions.durations.every((duration) => duration <= 0.00001)).toBe(true);
  expect(transitions.animation).toBe("none");
  await expectFits(page, table);
});
