import type { Profile } from "@/lib/api";
import { getFoundationPage } from "./navigation";
import { INVENTORY_PAGES } from "./inventory";
import { CORRECTION_PAGES } from "./inventory-corrections";
import { BUSINESS_PAGES } from "./business";
import { WASHING_PAGES } from "./washing";
import { MANAGER_PRODUCTION_PAGES } from "./manager-production";
import { HISTORICAL_PAGES, historicalPage } from "./company-workflow";

/**
 * §17 RBAC — sidebar label to the permission code required to
 * open that module. Labels absent from this map are public
 * (the signed-in user always sees them).
 */
export const NAV_PERMISSIONS: Record<string, string> = {
  Inventory: "stock.view",
  Orders: "orders.view",
  Customers: "customers.view",
  "Weavers & Artisans": "weavers.view",
  "Weaver records": "weavers.view",
  "Supplier records": "suppliers.view",
  Suppliers: "suppliers.view",
  Shipments: "shipments.view",
  "Export Orders": "exports.view",
  Warehouses: "warehouses.view",
  Analytics: "analytics.view",
  Reports: "reports.view",
  Users: "users.view",
  Settings: "settings.view",
};

/** True when the profile holds the exact permission code. */
export function can(profile: Profile | null, code: string): boolean {
  return profile?.permissions.includes(code) ?? false;
}

/** True when a sidebar item may be shown for this profile. */
export function canOpen(profile: Profile | null, label: string): boolean {
  if (label === "Stock overview") return ["stock.view", "materials.view", "washing.view"].some((code) => can(profile, code));
  if (label === "Supplier balances") return can(profile, "suppliers.view") && can(profile, "materials.view");
  if (label === "Historical records") return HISTORICAL_PAGES.some((page) => can(profile, page.permission));
  const historical = historicalPage(label);
  if (historical) return can(profile, historical.permission);
  if (Object.prototype.hasOwnProperty.call(MANAGER_PRODUCTION_PAGES, label)) return can(profile, MANAGER_PRODUCTION_PAGES[label]);
  if (BUSINESS_PAGES[label]) return can(profile, `${BUSINESS_PAGES[label].module}.view`);
  if (WASHING_PAGES[label]) return can(profile, WASHING_PAGES[label].permission);
  if (CORRECTION_PAGES[label])
    return can(profile, CORRECTION_PAGES[label].permission);
  const inventory = INVENTORY_PAGES[label];
  if (inventory) return can(profile, inventory.permission);
  const page = getFoundationPage(label);
  if (page) {
    return (
      (!page.administratorOnly || Boolean(profile?.is_administrator)) &&
      can(profile, page.permission)
    );
  }
  if (label === "Directories")
    return ["geography.view"].some(
      (permission) => can(profile, permission),
    );
  if (label === "Users" && !profile?.is_administrator) return false;
  const code = NAV_PERMISSIONS[label];
  return code === undefined || can(profile, code);
}
