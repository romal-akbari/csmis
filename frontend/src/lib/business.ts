import { inventoryDate, inventoryToday, decimalPattern, ratePattern, moneyMinor } from "./inventory.ts";
import type { SalesStockGroup } from "./sales-stock.ts";

export type BusinessResource = "purchases" | "expenses" | "materials" | "material-receipts" | "production" | "partnerships" | "sales" | "supplier-payments" | "customer-receipts";
export type BusinessRow = { id: number; [key: string]: unknown };
export type BusinessOption = { id: number; name?: string; code?: string; [key: string]: unknown };
export type BusinessLookups = { company: { base_currency_id: number | null; timezone: string }; stock_groups?: SalesStockGroup[]; [key: string]: BusinessOption[] | SalesStockGroup[] | { base_currency_id: number | null; timezone: string } | undefined };
export type BusinessLine = { carpet_id: number; expected_sequence: number; label: string; unit_price: string; discount: string; cost_weight: string };
export type BusinessField = { key: string; label: string; type?: "date" | "select" | "textarea" | "money" | "quantity" | "rate" | "percent"; lookup?: string; options?: string[]; optional?: boolean; max?: number };
export const BUSINESS_PAGES: Record<string, { resource: BusinessResource; module: string; create?: string }> = {
  "Carpet purchases": { resource: "purchases", module: "purchasing", create: "Purchase & receive" },
  "Expense records": { resource: "expenses", module: "expenses", create: "Record expense" },
  Materials: { resource: "materials", module: "materials", create: "Add material" },
  "Material receipts": { resource: "material-receipts", module: "materials", create: "Receive materials" },
  "Production orders": { resource: "production", module: "production", create: "Create production order" },
  "Production history": { resource: "production", module: "production" },
  Partnerships: { resource: "partnerships", module: "partnerships", create: "Create partnership" },
  "Sales invoices": { resource: "sales", module: "sales", create: "Sell carpets" },
  "Supplier payments": { resource: "supplier-payments", module: "purchasing" },
  "Customer receipts": { resource: "customer-receipts", module: "sales" },
};
export const choices = (lookups: BusinessLookups, key: string): BusinessOption[] => Array.isArray(lookups[key]) ? lookups[key] as BusinessOption[] : [];
export const value = (row: BusinessRow | undefined, key: string) => String(row?.[key] ?? "");
const choice = (key: string, label: string, lookup: string, optional = false): BusinessField => ({ key, label, type: "select", lookup, optional });
const text = (key: string, label: string, max = 150, optional = false): BusinessField => ({ key, label, max, optional });
const date = (key: string, label: string): BusinessField => ({ key, label, type: "date" });
const amount = (key: string, label: string): BusinessField => ({ key, label, type: "money" });
const currency: BusinessField[] = [choice("currency_id", "Currency", "currencies"), { key: "rate_to_base", label: "Rate to base currency", type: "rate" }];
export const paymentFields: BusinessField[] = [choice("account_id", "Account", "accounts"), amount("amount", "Amount"),
  { key: "rate_to_base", label: "Payment rate to base", type: "rate" },
  { key: "units_per_payment_unit", label: "Document units per payment unit", type: "rate" }, date("payment_on", "Payment date"), text("reason", "Reason", 255)];
export function businessFields(resource: BusinessResource, action: string, values: Record<string, string>): BusinessField[] {
  if (["pay", "collect", "settlement-pay"].includes(action)) return [...(action === "pay" && (resource === "production" || resource === "partnerships") ? [{ key: "payment_type", label: "Payment type", type: "select" as const, options: resource === "partnerships" ? ["support"] : ["labor", "advance"] }] : []), ...paymentFields];
  if (action === "move") return [choice("lot_id", "Material lot", "lots"), { key: "movement_type", label: "Movement type", type: "select", options: ["issue", "consume", "return", "waste"] }, date("moved_on", "Movement date"), { key: "quantity", label: "Quantity", type: "quantity" }, text("reason", "Reason", 500)];
  if (action === "progress") return [date("progress_on", "Progress date"), { key: "percent", label: "Completion percent", type: "percent" }, text("notes", "Progress notes", 500)];
  if (action === "charge") return [date("earned_on", "Labor earned date"), amount("amount", "Labor earned"), text("description", "Description", 500)];
  if (action === "contribution") return [date("contributed_on", "Contribution date"), amount("amount", "Partner contribution"), text("description", "Description", 500)];
  if (action === "receive") return [choice("location_id", "Destination stock location", "locations"), date("received_on", "Receiving date")];
  if (action === "void") return [date("voided_on", "Void date"), text("reason", "Reason", 500)];
  if (action === "settle") return [date("settled_on", "Settlement date")];
  if (action === "reverse") return [text("reason", "Reason", 500)];
  if (resource === "materials") return [text("code", "Code", 40, true), text("name", "Name"), { key: "unit", label: "Unit", type: "select", options: ["kg", "g", "m", "unit"] }, text("color", "Color", 60, true)];
  const branch = choice("branch_id", "Branch", "branches");
  if (resource === "purchases") return [branch, { key: "seller_type", label: "Seller type", type: "select", options: ["weaver", "supplier"] },
    choice(values.seller_type === "supplier" ? "supplier_id" : "weaver_id", values.seller_type === "supplier" ? "Supplier" : "Weaver", values.seller_type === "supplier" ? "suppliers" : "weavers"),
    date("purchase_on", "Purchase date"), ...currency, choice("location_id", "Destination stock location", "locations"), text("seller_invoice", "Seller invoice number (optional)", 80, true), text("notes", "Notes", 1000, true)];
  if (resource === "expenses") return [branch, date("expense_on", "Expense date"), choice("expense_category_id", "Expense category", "expense_categories"), text("payee", "Payee"),
    text("description", "Description", 500), ...currency, amount("amount", "Expense amount"), text("invoice_no", "Invoice number (optional)", 80, true)];
  if (resource === "material-receipts") return [branch, choice("material_id", "Material", "materials"), choice("supplier_id", "Supplier", "suppliers", true), choice("location_id", "Destination stock location", "locations"),
    date("received_on", "Receiving date"), { key: "quantity", label: "Quantity", type: "quantity" }, ...currency, amount("unit_cost", "Cost per unit")];
  if (resource === "production" || resource === "partnerships") return [branch, choice("weaver_id", "Weaver", "weavers"), date("ordered_on", "Start date"), date("due_on", "Expected completion date"), ...currency, text("description", "Description", 500),
    ...(resource === "partnerships" ? [{ key: "share_basis", label: "Sharing basis", type: "select" as const, options: ["gross", "net_cost_recovery"] },
      { key: "company_share", label: "Company profit / proceeds share (%)", type: "percent" as const }, { key: "company_loss_share", label: "Company loss share (%)", type: "percent" as const },
      { key: "agreement_terms", label: "Ownership, contributions and agreement terms", type: "textarea" as const, max: 2000 }] : [])];
  if (resource === "sales") return [branch, choice("customer_id", "Customer", "customers"), date("sold_on", "Sale date"), date("due_on", "Payment due date"), ...currency, text("notes", "Notes", 500, true)];
  return [];
}

export function validateBusiness(fields: BusinessField[], values: Record<string, string>, lines: BusinessLine[], resource: BusinessResource, action: string, lookups: BusinessLookups, paymentDirection?: "in" | "out", groupedSales = false): Record<string, string> {
  const errors: Record<string, string> = {};
  const payment = fields.some((f) => f.key === "account_id");
  const account = choices(lookups, "accounts").find((a) => String(a.id) === values.account_id);
  const currency = choices(lookups, "currencies").find((c) => String(c.id) === String(payment ? account?.currency_id : values.currency_id));
  const precision = Number(currency?.decimal_places ?? 2);
  const respectsPrecision = (v: string) => precision === 2 || !/[1-9]/.test((v.split(".")[1] ?? "").slice(precision));
  for (const f of fields) {
    const v = values[f.key]?.trim() ?? "";
    if (!v && !f.optional) { errors[f.key] = "This field is required."; continue; }
    if (!v) continue;
    if (f.lookup && !choices(lookups, f.lookup).some((o) => String(o.id) === v)) errors[f.key] = "Choose a valid option.";
    if (f.options && !f.options.includes(v)) errors[f.key] = "Choose a valid option.";
    if (f.max && [...v].length > f.max) errors[f.key] = "This text is too long.";
    if (f.type === "money" && (!decimalPattern.test(v) || moneyMinor(v) <= BigInt(0))) errors[f.key] = "Enter a positive amount.";
    if (f.type === "money" && decimalPattern.test(v) && !respectsPrecision(v)) errors[f.key] = "Amount exceeds the currency precision.";
    if (f.type === "quantity" && (!/^(?:0|[1-9]\d{0,11})(?:\.\d{1,3})?$/.test(v) || !/[1-9]/.test(v))) errors[f.key] = "Enter a positive quantity with up to 3 decimals.";
    if (f.type === "rate" && (!ratePattern.test(v) || !/[1-9]/.test(v))) errors[f.key] = "Enter a positive exchange rate.";
    if (f.type === "percent" && (!/^\d{1,3}(?:\.\d{1,2})?$/.test(v) || Number(v) > 100 || (f.key === "percent" && !/^\d+$/.test(v)))) errors[f.key] = "Use a percentage from 0 to 100.";
    if (f.type === "date" && !inventoryDate(v, f.key === "due_on" ? "9999-12-31" : inventoryToday(lookups.company.timezone))) errors[f.key] = "Enter a valid date.";
  }
  if (values.due_on && values.due_on < (values.ordered_on || values.sold_on || "")) errors.due_on = "The due date cannot precede the start date.";
  if (values.code && !/^[A-Z0-9_-]+$/.test(values.code)) errors.code = "Use uppercase letters, numbers, - or _.";
  if (values.currency_id === String(lookups.company.base_currency_id) && values.rate_to_base && !payment && values.rate_to_base !== "1" && !/^1\.0{1,8}$/.test(values.rate_to_base)) errors.rate_to_base = "Base-currency entries must use a rate of 1.";
  if (payment && account?.currency_id === lookups.company.base_currency_id && values.rate_to_base && values.rate_to_base !== "1" && !/^1\.0{1,8}$/.test(values.rate_to_base)) errors.rate_to_base = "Base-currency entries must use a rate of 1.";
  if (payment && paymentDirection !== "in" && action !== "collect" && resource !== "sales" && decimalPattern.test(values.amount ?? "") && decimalPattern.test(String(account?.balance ?? "")) && moneyMinor(values.amount) > moneyMinor(String(account?.balance))) errors.amount = "Insufficient account balance.";
  if (values.agreement_terms && values.agreement_terms.trim().length < 10) errors.agreement_terms = "Describe the agreement in at least 10 characters.";
  if (action === "create" && ["purchases", "production", "partnerships", "sales"].includes(resource) && !(groupedSales && resource === "sales")) {
    if (!lines.length || lines.length > 50 || new Set(lines.map((l) => l.carpet_id)).size !== lines.length) errors.items = "Select 1 to 50 different carpets.";
    lines.forEach((line, i) => {
      if (["production", "partnerships"].includes(resource)) {
        if (!/^(?:0|[1-9]\d{0,8})(?:\.\d{1,3})?$/.test(line.cost_weight) || !/[1-9]/.test(line.cost_weight)) errors[`items.${i}.cost_weight`] = "Enter a positive cost weight.";
      } else {
        if (!decimalPattern.test(line.unit_price) || moneyMinor(line.unit_price) <= BigInt(0)) errors[`items.${i}.unit_price`] = "Enter a positive amount.";
        else if (!respectsPrecision(line.unit_price)) errors[`items.${i}.unit_price`] = "Amount exceeds the currency precision.";
        if (resource === "sales" && (!decimalPattern.test(line.discount) || (decimalPattern.test(line.unit_price) && moneyMinor(line.discount) >= moneyMinor(line.unit_price)))) errors[`items.${i}.discount`] = "Discount must be smaller than the price.";
        else if (resource === "sales" && !respectsPrecision(line.discount)) errors[`items.${i}.discount`] = "Amount exceeds the currency precision.";
      }
    });
  }
  return errors;
}

export const ACTION_TITLES: Record<string, string> = { pay: "Record payment", collect: "Record customer receipt", move: "Move materials", progress: "Update progress", charge: "Record labor earned", contribution: "Record partner contribution", receive: "Receive finished carpets", void: "Void expense", settle: "Finalize partnership settlement", "settlement-pay": "Pay / recover partnership balance", reverse: "Reverse payment" };
