import { defineConfig } from "@playwright/test";
import path from "node:path";

const backend = path.resolve(__dirname, "../backend");
export default defineConfig({
  testDir: "./tests/reset-browser",
  globalSetup: "./tests/reset-browser/setup.ts",
  workers: 1,
  timeout: 120_000,
  expect: { timeout: 20_000 },
  reporter: [["list"], ["html", { open: "never", outputFolder: "reset-playwright-report" }]],
  use: { baseURL: "http://127.0.0.1:3103", channel: "chrome", headless: true,
    trace: "retain-on-failure", screenshot: "only-on-failure" },
  webServer: [
    { command: "php artisan serve --host=127.0.0.1 --port=8005", cwd: backend,
      url: "http://127.0.0.1:8005/up", reuseExistingServer: false, timeout: 60_000,
      env: { DB_DATABASE: "csms_reset_browser", DB_CONNECTION: "mysql", DB_URL: "",
        APP_ENV: "testing", APP_CONFIG_CACHE: "bootstrap/cache/reset-test-config.php",
        CSMS_PRIVATE_STORAGE: path.join(backend, "storage/app/reset-browser"),
        CSMS_RESET_BACKUP_DIRECTORY: path.join(backend, "storage/app/reset-browser-backups"),
      } },
    { command: "node node_modules/next/dist/bin/next dev --webpack --hostname 127.0.0.1 --port 3103",
      url: "http://127.0.0.1:3103", reuseExistingServer: false, timeout: 120_000,
      env: { NEXT_DIST_DIR: ".next-reset-e2e", API_ORIGIN: "http://127.0.0.1:8005" } },
  ],
});
