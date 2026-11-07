import type { Profile } from "./api";

export const HISTORICAL_PAGES = [
  { label: "Carpet purchases", permission: "purchasing.view", kind: "business" },
  { label: "Purchase drafts", permission: "weaver-purchases.view", kind: "correction" },
  { label: "Weaver returns", permission: "weaver-purchases.view", kind: "correction" },
  { label: "Weaver payments", permission: "weaver-payments.view", kind: "inventory" },
  { label: "Weaver refunds", permission: "weaver-refunds.view", kind: "correction" },
  { label: "Supplier payments", permission: "purchasing.view", kind: "business" },
  { label: "Weaver records", permission: "weavers.view", kind: "directory" },
  { label: "Production history", permission: "production.view", kind: "business" },
  { label: "Partnerships", permission: "partnerships.view", kind: "business" },
  { label: "Stock receipts", permission: "stock.view", kind: "inventory" },
] as const;

export function historicalPage(label: string) {
  return HISTORICAL_PAGES.find((page) => page.label === label);
}

export function canonicalPage(label: string): string {
  const aliases: Record<string, string> = {
    Carpets: "Finished carpets", "Weaver purchases": "Carpet purchases",
    "Weavers & Artisans": "Weaver records", Suppliers: "Supplier records",
  };
  return aliases[label] ?? label;
}

/** Presentation uses view permissions only; the API independently rejects retired writes. */
export function historyProfile(profile: Profile): Profile {
  return { ...profile, permissions: profile.permissions.filter((permission) => permission.endsWith(".view")) };
}
