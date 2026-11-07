import { decimalPattern, inventoryDate, inventoryToday, ratePattern } from "./inventory.ts";

export type ProductionRecord = { id: number; [key: string]: unknown };
export type ProductionOption = ProductionRecord & { name?: string; code?: string };
export type ProductionLookups = {
  company?: { base_currency_id: number | null; timezone: string };
  managers: ProductionOption[];
  branches: ProductionOption[];
  categories: ProductionOption[];
  currencies: ProductionOption[];
  accounts: ProductionOption[];
  facilities: ProductionOption[];
  facility_locations: ProductionOption[];
  legacy_production_exists: boolean;
  [key: string]: unknown;
};
export type PricingFactor = { label: string; kind: string; operation: string; value: string; quantity: string };
export type ProductionLine = {
  name: string; category_id: string; target_quantity: string; length_cm: string; width_cm: string;
  pricing_method: string; rate: string; manual_reason: string; notes: string; factors: PricingFactor[];
};
export type ProductionField = {
  key: string; label: string; type?: "select" | "date" | "textarea" | "decimal" | "integer";
  options?: { value: string; label: string }[]; optional?: boolean; maxLength?: number;
  disabled?: boolean; placeholder?: string;
};
export const MANAGER_PRODUCTION_PAGES: Record<string, string> = {
  "Materials & tools": "materials.view",
  "Materials & tools stock": "materials.view",
  "Production orders": "production.view",
  "Production managers": "production.view",
  Tools: "materials.view",
  "Tool receipts": "materials.view",
  "Worker assistance": "production.view",
};
export const textValue = (row: ProductionRecord | undefined, key: string): string => String(row?.[key] ?? "");
export const records = (row: ProductionRecord | null | undefined, key: string): ProductionRecord[] => Array.isArray(row?.[key]) ? row[key] as ProductionRecord[] : [];
export const options = (lookups: ProductionLookups, key: string): ProductionOption[] => Array.isArray(lookups[key]) ? lookups[key] as ProductionOption[] : [];
export const selectOptions = (rows: ProductionOption[]) => rows.map((row) => ({ value: String(row.id), label: String(row.name ?? row.code ?? row.id) }));
export const productionToday = (lookups: ProductionLookups): string => inventoryToday(lookups.company?.timezone ?? "Asia/Kabul");
export const emptyFactor = (): PricingFactor => ({ label: "", kind: "fixed", operation: "add", value: "", quantity: "1" });
export const emptyLine = (): ProductionLine => ({ name: "", category_id: "", target_quantity: "1", length_cm: "", width_cm: "", pricing_method: "area", rate: "", manual_reason: "", notes: "", factors: [] });
export function positiveDecimal(value: string, precision = 3): boolean {
  return new RegExp(`^(?:0|[1-9]\\d{0,11})(?:\\.\\d{1,${precision}})?$`).test(value) && /[1-9]/.test(value);
}
export function validateFields(fields: ProductionField[], values: Record<string, string>, today: string): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const field of fields) {
    const value = values[field.key]?.trim() ?? "";
    if (!value) { if (!field.optional) errors[field.key] = "This field is required."; continue; }
    if (field.key === "code" && !/^[A-Z0-9_-]+$/.test(value)) errors.code = "Use uppercase letters, numbers, - or _.";
    if (field.options && !field.options.some((option) => option.value === value)) errors[field.key] = "Choose a valid option.";
    if (field.maxLength && [...value].length > field.maxLength) errors[field.key] = "This text is too long.";
    if (field.type === "decimal" && !positiveDecimal(value, 8)) errors[field.key] = "Enter a positive number.";
    if (field.type === "integer" && !/^[1-9]\d{0,5}$/.test(value)) errors[field.key] = "Enter a whole number greater than zero.";
    if (field.type === "date" && !inventoryDate(value, field.key === "due_on" ? "9999-12-31" : today)) errors[field.key] = "Enter a valid date.";
  }
  return errors;
}
export function validateOrder(values: Record<string, string>, lines: ProductionLine[], factors: PricingFactor[], today: string, currencyPrecision = 2): Record<string, string> {
  const errors: Record<string, string> = {};
  if (values.due_on < values.ordered_on) errors.due_on = "The due date cannot precede the start date.";
  if (!ratePattern.test(values.rate_to_base ?? "") || !/[1-9]/.test(values.rate_to_base ?? "")) errors.rate_to_base = "Enter a positive exchange rate.";
  if (!lines.length || lines.length > 50) errors.lines = "Add 1 to 50 design lines.";
  function checkFactors(items: PricingFactor[], prefix: string) {
    if (items.length > 20) errors[prefix] = "Use at most 20 pricing factors.";
    items.forEach((factor, index) => {
      if (!factor.label.trim()) errors[`${prefix}.${index}.label`] = "This field is required.";
      if (!positiveDecimal(factor.value, 6)) errors[`${prefix}.${index}.value`] = "Enter a positive number.";
      if (factor.kind === "multiplier" && factor.operation !== "add") errors[`${prefix}.${index}.operation`] = "Use Multiply for a multiplier.";
      if (factor.kind === "rate_quantity" && !positiveDecimal(factor.quantity, 6)) errors[`${prefix}.${index}.quantity`] = "Enter a positive number.";
    });
  }
  lines.forEach((line, index) => {
    const prefix = `lines.${index}`;
    if (!line.name.trim()) errors[`${prefix}.name`] = "This field is required.";
    if (!line.category_id) errors[`${prefix}.category_id`] = "Choose a valid option.";
    if (!/^[1-9]\d{0,4}$/.test(line.target_quantity) || Number(line.target_quantity) > 10000) errors[`${prefix}.target_quantity`] = "Use a quantity from 1 to 10000.";
    if (!decimalPattern.test(line.rate)) errors[`${prefix}.rate`] = "Enter a nonnegative amount.";
    if (/[1-9]/.test((line.rate.split(".")[1] ?? "").slice(currencyPrecision))) errors[`${prefix}.rate`] = "Amount exceeds the currency precision.";
    for (const dimension of ["length_cm", "width_cm"] as const) if (line[dimension] && !positiveDecimal(line[dimension], 2)) errors[`${prefix}.${dimension}`] = "Enter a positive number.";
    if (Boolean(line.length_cm) !== Boolean(line.width_cm)) errors[`${prefix}.width_cm`] = "Enter both planned dimensions, or leave both blank.";
    if (line.pricing_method === "manual" && line.manual_reason.trim().length < 10) errors[`${prefix}.manual_reason`] = "Explain the agreed price in at least 10 characters.";
    checkFactors(line.factors, `${prefix}.factors`);
  });
  checkFactors(factors, "factors");
  if (!inventoryDate(values.ordered_on, today)) errors.ordered_on = "Enter a valid date.";
  return errors;
}
export function factorPayload(factor: PricingFactor) {
  return { label: factor.label.trim(), kind: factor.kind, operation: factor.operation, value: factor.value, ...(factor.kind === "rate_quantity" ? { quantity: factor.quantity } : {}) };
}
