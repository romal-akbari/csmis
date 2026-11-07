import { execFileSync } from "node:child_process";
import path from "node:path";

export default function setup() {
  const cwd = path.resolve(process.cwd(), "../backend");
  execFileSync("php", ["scripts/prepare-test-database.php", "csms_reset_browser"],
    { cwd, stdio: "inherit" });
  execFileSync("php", ["scripts/seed-reset-browser.php"], { cwd, stdio: "inherit" });
}
