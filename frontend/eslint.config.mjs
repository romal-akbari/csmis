import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTypescript from "eslint-config-next/typescript";

export default defineConfig([
  ...nextVitals,
  ...nextTypescript,
  globalIgnores([".next/**", ".next-*/**", "test-results/**", "playwright-report/**", "reset-playwright-report/**", "out/**", "next-env.d.ts", ".verification/**"]),
]);
