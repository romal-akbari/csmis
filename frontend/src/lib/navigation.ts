import { FOUNDATION_RESOURCES } from "./foundation";

export type FoundationPage = {
  key: string;
  label: string;
  permission: string;
  administratorOnly: boolean;
};

export const FOUNDATION_PAGES: FoundationPage[] = [
  ...FOUNDATION_RESOURCES.map((resource) => ({
    key: resource.key,
    label: resource.label,
    permission: `${resource.permission}.view`,
    administratorOnly: ["users", "roles"].includes(resource.key),
  })),
  {
    key: "settings",
    label: "Company settings",
    permission: "settings.view",
    administratorOnly: false,
  },
  {
    key: "audit",
    label: "Audit history",
    permission: "audit.view",
    administratorOnly: true,
  },
];

const aliases: Record<string, string> = {
  Settings: "Company settings",
  Users: "Users and access",
  Warehouses: "Stock locations",
};

export function getFoundationPage(label: string): FoundationPage | undefined {
  return FOUNDATION_PAGES.find(
    (page) => page.label === (aliases[label] ?? label),
  );
}

/** Sidebar flyouts contain implemented pages only. */
export const SIDEBAR_SUBMENUS: Record<string, string[]> = {
  Inventory: [
    "Branches",
    "Stock locations",
    "Stock overview",
    "Finished carpets",
    "Stock transfers",
    "Stock corrections",
    "Materials & tools stock",
  ],
  Purchases: ["Materials & tools", "Supplier records", "Supplier balances"],
  Production: ["Production orders", "Production managers", "Carpet categories"],
  Washing: ["Washing batches", "Washing facility stock", "Washing facilities", "Washing costs"],
  Sales: ["Customers", "Sales invoices", "Customer receipts"],
  Expenses: ["Expense records", "Expense categories"],
  Finance: [
    "Accounts",
    "Currencies",
    "Exchange rates",

  ],
  Users: ["Users and access", "Roles and permissions"],
  Settings: ["Company settings", "Employees", "Directories", "Historical records", "Audit history"],
};
