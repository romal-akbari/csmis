import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "./tests/browser",
  globalSetup: "./tests/browser/setup.ts",
  workers: 1,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  reporter: [["list"], ["html", { open: "never" }]],
  use: {
    baseURL: "http://127.0.0.1:3102",
    channel: "chrome",
    headless: true,
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  webServer: [
    {
      command: "php artisan serve --host=127.0.0.1 --port=8004",
      cwd: "../backend",
      url: "http://127.0.0.1:8004/up",
      env: {
        DB_DATABASE: "csms_phase1_browser",
        DB_CONNECTION: "mysql",
        DB_URL: "",
        APP_ENV: "testing",
        APP_CONFIG_CACHE: "bootstrap/cache/browser-test-config.php",
      },
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      command:
        "node node_modules/next/dist/bin/next dev --webpack --hostname 127.0.0.1 --port 3102",
      url: "http://127.0.0.1:3102",
      env: { NEXT_DIST_DIR: ".next-e2e", API_ORIGIN: "http://127.0.0.1:8004" },
      reuseExistingServer: false,
      timeout: 120_000,
    },
  ],
});
