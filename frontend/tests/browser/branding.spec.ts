import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";
import type { Locator } from "@playwright/test";
import type { BrandingAssets, BrandingSlot } from "../../src/lib/branding";
import { expect, test, openSidebarPage, type Page } from "./fixtures";

const slots: BrandingSlot[] = [
  "story_light", "story_dark", "logo_light", "logo_dark",
  "footer_signature_light", "footer_signature_dark",
];
const cards = [
  { heading: "Sidebar story image", prefix: "story", file: "afghan-carpets-16.4375rem-x-7.6875rem-no-crop-no-stretch.png" },
  { heading: "Dashboard logo", prefix: "logo", file: "brand-lockup.png" },
  { heading: "Footer signature artwork", prefix: "footer_signature", file: "footer-landscape.png" },
] as const;

function renderedImage(page: Page, slot: BrandingSlot): Locator {
  const theme = slot.endsWith("_dark") ? "dark" : "light";
  if (slot.startsWith("story_")) return page.locator(`.sidebar-landscape-${theme}`);
  if (slot.startsWith("logo_")) return page.locator(`.brand-lockup[data-artwork-theme="${theme}"]`);
  return page.locator(`.footer-signature[data-artwork-theme="${theme}"]`);
}

function uploadFile(file = "brand-lockup.png") {
  return {
    name: `browser-${file}`,
    mimeType: "image/png",
    buffer: readFileSync(path.resolve(process.cwd(), "public/images", file)),
  };
}

async function login(page: Page, username = "browser_admin") {
  await page.goto("/");
  await page.getByLabel("Username", { exact: true }).fill(username);
  await page.getByLabel("Password", { exact: true }).fill("BrowserOnlyTest!42");
  await page.getByRole("button", { name: "Sign in", exact: true }).click();
  await expect(page.locator(".profile-button")).toBeVisible();
}

async function authorization(page: Page) {
  // The fixture and this check keep all uploads away from the live application.
  expect(new URL(page.url()).origin).toBe("http://127.0.0.1:3102");
  const token = await page.evaluate(() => localStorage.getItem("csms_token"));
  expect(token).toBeTruthy();
  return { Authorization: `Bearer ${token}`, Accept: "application/json" };
}

async function readAssets(page: Page): Promise<BrandingAssets> {
  const response = await page.request.get("/api/branding", { headers: await authorization(page) });
  expect(response.status()).toBe(200);
  return (await response.json()).data;
}

async function restoreAll(page: Page) {
  const headers = await authorization(page);
  for (const slot of slots) {
    const response = await page.request.delete(`/api/foundation/branding/${slot}`, { headers });
    expect(response.status()).toBe(200);
  }
}

async function openBranding(page: Page) {
  await openSidebarPage(page, "Company settings");
  await expect(page.getByRole("heading", { name: "Dashboard branding", exact: true })).toBeVisible();
  for (const { heading } of cards) {
    await expect(page.getByRole("article", { name: heading, exact: true })).toBeVisible();
    await expect(page.getByRole("article", { name: heading, exact: true })
      .getByRole("tab", { name: "Light mode", exact: true })).toBeEnabled();
  }
}

async function expectDecoded(image: Locator) {
  await expect.poll(() => image.evaluate((node) => {
    const image = node as HTMLImageElement;
    return image.complete && image.naturalWidth > 0;
  })).toBe(true);
}

test("branding saves all theme variants, updates artwork immediately, persists, and restores safely", async ({ page }) => {
  test.setTimeout(180_000);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await login(page);
  await restoreAll(page);
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  try {
    await page.reload();
    await openBranding(page);
    await page.getByRole("region", { name: "Dashboard branding", exact: true })
      .screenshot({ path: "test-results/branding-settings-light.png", animations: "disabled" });
    const saved: Partial<Record<BrandingSlot, string>> = {};
    const uploads: string[] = [];
    page.on("request", (request) => {
      if (request.method() === "POST" && /\/api\/foundation\/branding\//.test(request.url()))
        uploads.push(request.url());
    });

    for (const { heading, prefix, file } of cards) {
      const card = page.getByRole("article", { name: heading, exact: true });
      for (const theme of ["light", "dark"] as const) {
        const slot = `${prefix}_${theme}` as BrandingSlot;
        await card.getByRole("tab", { name: theme === "dark" ? "Dark mode" : "Light mode", exact: true }).click();
        const source = await renderedImage(page, slot).getAttribute("src");
        const uploadCount = uploads.length;
        await card.getByLabel("Choose image", { exact: true }).setInputFiles(uploadFile(file));
        await expect(card.getByRole("button", { name: "Save image", exact: true })).toBeEnabled();
        // Selecting a local preview must leave the saved dashboard artwork intact.
        await expect(renderedImage(page, slot)).toHaveAttribute("src", source!);
        expect((await readAssets(page))[slot]).toBeNull();
        expect(uploads).toHaveLength(uploadCount);
        const responsePromise = page.waitForResponse((response) =>
          response.request().method() === "POST" && response.url().endsWith(`/api/foundation/branding/${slot}`));
        await card.getByRole("button", { name: "Save image", exact: true }).click();
        const response = await responsePromise;
        expect(response.ok()).toBe(true);
        const assets: BrandingAssets = (await response.json()).data;
        const image = assets[slot]!;
        expect(image.url).toMatch(new RegExp(`/api/branding/images/${slot}/`));
        saved[slot] = image.url;
        await expect(renderedImage(page, slot)).toHaveAttribute("src", image.url);
        await expectDecoded(renderedImage(page, slot));
        await expect(renderedImage(page, slot)).toHaveAttribute("data-custom-branding", "true");
        // Versioned artwork loads without exposing the user's Bearer token in its URL.
        const publicImage = await page.request.get(image.url);
        expect(publicImage.status()).toBe(200);
        expect(publicImage.headers()["content-type"]).toMatch(/^image\//);
        expect(image.url).not.toContain("token");
      }
    }

    for (const theme of ["dark", "light"] as const) {
      await page.locator(".theme-toggle").click();
      await expect(page.locator("html")).toHaveAttribute("data-theme", theme);
      for (const { prefix } of cards) {
        const slot = `${prefix}_${theme}` as BrandingSlot;
        await expect(renderedImage(page, slot)).toHaveAttribute("src", saved[slot]!);
        await expect(renderedImage(page, slot)).toBeVisible();
        const other = `${prefix}_${theme === "dark" ? "light" : "dark"}` as BrandingSlot;
        await expect(renderedImage(page, other)).not.toBeVisible();
      }
    }

    await page.reload();
    await expect(page.locator(".profile-button")).toBeVisible();
    for (const slot of slots) await expect(renderedImage(page, slot)).toHaveAttribute("src", saved[slot]!);
    await openBranding(page);
    const story = page.getByRole("article", { name: "Sidebar story image", exact: true });
    await story.getByRole("tab", { name: "Light mode", exact: true }).click();
    await story.getByLabel("Choose image", { exact: true }).setInputFiles(uploadFile());
    await story.getByRole("button", { name: "Cancel selection", exact: true }).click();
    await expect(renderedImage(page, "story_light")).toHaveAttribute("src", saved.story_light!);
    await story.getByRole("button", { name: "Restore default", exact: true }).click();
    await expect(story.getByText("Restore the default image?", { exact: true })).toBeVisible();
    await story.getByRole("button", { name: "Cancel", exact: true }).click();
    expect((await readAssets(page)).story_light?.url).toBe(saved.story_light);
    await story.getByRole("button", { name: "Restore default", exact: true }).click();
    await story.getByRole("button", { name: "Confirm restore", exact: true }).click();
    await expect(renderedImage(page, "story_light")).toHaveAttribute("src", "/images/sidebar-story-full.png");
    await expect(renderedImage(page, "story_dark")).toHaveAttribute("src", saved.story_dark!);
    const restored = await readAssets(page);
    expect(restored.story_light).toBeNull();
    for (const slot of slots.filter((slot) => slot !== "story_light")) expect(restored[slot]?.url).toBe(saved[slot]);
    expect(errors).toEqual([]);
  } finally {
    await restoreAll(page);
  }
});

test("image validation and failed uploads preserve the saved artwork and allow cancellation", async ({ page }) => {
  test.setTimeout(120_000);
  await login(page);
  await openBranding(page);
  const card = page.getByRole("article", { name: "Dashboard logo", exact: true });
  const image = renderedImage(page, "logo_light");
  const source = await image.getAttribute("src");
  const writes: string[] = [];
  page.on("request", (request) => {
    if (request.method() === "POST" && /\/api\/foundation\/branding\//.test(request.url())) writes.push(request.url());
  });
  await card.getByLabel("Choose image", { exact: true }).setInputFiles({
    name: "unsafe.svg", mimeType: "image/svg+xml", buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"/>'),
  });
  await expect(card.getByRole("alert")).toContainText(/PNG|JPEG|JPG|WebP/i);
  await expect(image).toHaveAttribute("src", source!);
  await card.getByLabel("Choose image", { exact: true }).setInputFiles({
    name: "too-large.png", mimeType: "image/png", buffer: Buffer.alloc(5 * 1024 * 1024 + 1),
  });
  await expect(card.getByRole("alert")).toContainText(/5\s?MB/i);
  expect(writes).toEqual([]);

  const endpoint = "**/api/foundation/branding/logo_light";
  await page.route(endpoint, (route) => route.request().method() === "POST"
    ? route.fulfill({ status: 422, json: { message: "The test server rejected this image.", errors: { image: ["The test server rejected this image."] } } })
    : route.continue());
  await card.getByLabel("Choose image", { exact: true }).setInputFiles(uploadFile());
  await card.getByRole("button", { name: "Save image", exact: true }).click();
  await expect(card.getByRole("alert")).toContainText("The test server rejected this image.");
  await expect(image).toHaveAttribute("src", source!);
  await expect(card.getByRole("button", { name: "Save image", exact: true })).toBeEnabled();
  await card.getByRole("button", { name: "Cancel selection", exact: true }).click();
  await page.unroute(endpoint);

  // Bypass the browser checks: the real backend must inspect the file contents.
  const rejected = await page.request.post("/api/foundation/branding/logo_light", {
    headers: await authorization(page),
    multipart: { image: { name: "forged.png", mimeType: "image/png", buffer: Buffer.from("This is not an image.") } },
  });
  expect(rejected.status()).toBe(422);
  expect((await rejected.json()).errors.image).toBeTruthy();
  await expect(image).toHaveAttribute("src", source!);
});

function setReadOnlyFixtureRole(role: "Accountant" | "Administrator") {
  // Only this isolated user is changed; the normal fixture restores it as well.
  execFileSync("php", ["-r", `
    require 'vendor/autoload.php';
    $application = require 'bootstrap/app.php';
    $application->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
    config(['database.connections.mysql.database' => 'csms_phase1_browser',
      'database.connections.mysql.url' => null, 'database.default' => 'mysql']);
    Illuminate\\Support\\Facades\\DB::purge('mysql');
    if (Illuminate\\Support\\Facades\\DB::connection()->getDatabaseName() !== 'csms_phase1_browser')
      throw new RuntimeException('Refusing to update a non-browser database.');
    $fixtureUser = App\\Models\\User::where('username', 'browser_sidebar')->firstOrFail();
    $fixtureRole = App\\Models\\Role::where('name', '${role}')->firstOrFail();
    $fixtureUser->roles()->sync([$fixtureRole->id]);
    $fixtureUser->tokens()->delete();
  `], { cwd: path.resolve(process.cwd(), "../backend"), stdio: "pipe" });
}

test("read-only company settings show branding without permitting uploads or restores, including on mobile RTL", async ({ page }) => {
  test.setTimeout(120_000);
  setReadOnlyFixtureRole("Accountant");
  try {
    await login(page, "browser_sidebar");
    await openBranding(page);
    for (const { heading } of cards) {
      const card = page.getByRole("article", { name: heading, exact: true });
      await expect(card.getByLabel("Choose image", { exact: true })).toHaveCount(0);
      await expect(card.getByRole("button", { name: "Save image", exact: true })).toHaveCount(0);
      await expect(card.getByRole("button", { name: "Restore default", exact: true })).toHaveCount(0);
    }
    expect(Object.keys(await readAssets(page)).sort()).toEqual([...slots].sort());
    const headers = await authorization(page);
    const upload = await page.request.post("/api/foundation/branding/story_light", {
      headers, multipart: { image: uploadFile() },
    });
    expect(upload.status()).toBe(403);
    const restore = await page.request.delete("/api/foundation/branding/story_light", { headers });
    expect(restore.status()).toBe(403);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole("combobox", { name: "Display language" }).selectOption("fa");
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    await page.locator(".theme-toggle").click();
    await expect(page.locator("html")).toHaveAttribute("data-theme", "dark");
    for (const card of await page.getByRole("article").all()) {
      const bounds = await card.boundingBox();
      expect(bounds).not.toBeNull();
      expect(bounds!.x).toBeGreaterThanOrEqual(-1);
      expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(391);
    }
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: "test-results/branding-read-only-dari-mobile.png", fullPage: true, animations: "disabled" });
  } finally {
    setReadOnlyFixtureRole("Administrator");
  }
});
