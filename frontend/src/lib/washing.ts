export type WashingKind = "orders" | "stock" | "bills";
export type WashingAction = "dispatch" | "complete" | "return" | "bill" | "payment" | "void" | "reverse";
export type WashingRow = { id: number; [key: string]: unknown };
export type WashingPage = { data: WashingRow[]; total: number; current_page: number; last_page: number };
export type WashingOption = { id: number; name?: string; name_fa?: string; code?: string; branch_id?: number; washing_facility_id?: number; currency_id?: number; decimal_places?: number; balance?: string };
export type WashingLookups = {
  company: { base_currency_id: number | null; timezone: string };
  branches: WashingOption[]; facilities: WashingOption[]; facility_locations: WashingOption[];
  locations: WashingOption[]; currencies: WashingOption[]; accounts: WashingOption[];
};
export type WashingValues = Record<string, string>;
export type WashingLine = { id: number; label: string; expected_sequence: number; amount: string };
export const WASHING_PAGES: Record<string, { kind: WashingKind; permission: string }> = {
  "Washing batches": { kind: "orders", permission: "washing.view" },
  "Washing facility stock": { kind: "stock", permission: "washing.view" },
  "Washing costs": { kind: "bills", permission: "washing-costs.view" },
};
export const washingValue = (row: WashingRow | undefined, key: string): string => String(row?.[key] ?? "");
export const washingList = (row: WashingRow | null | undefined, key: string): WashingRow[] => Array.isArray(row?.[key]) ? row[key] as WashingRow[] : [];
export const washingActions: Record<WashingAction, string> = { dispatch: "Send to washing", complete: "Mark completed", return: "Return to stock", bill: "Record washing bill", payment: "Record payment", void: "Void bill", reverse: "Reverse payment" };
export function washingToday(timezone = "Asia/Kabul"): string {
  const parts = new Intl.DateTimeFormat("en-CA", { timeZone: timezone, year: "numeric", month: "2-digit", day: "2-digit" }).formatToParts();
  const part = (name: string) => parts.find((value) => value.type === name)!.value;
  return `${part("year")}-${part("month")}-${part("day")}`;
}
export function validWashingDate(value: string, today: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || value < "1000-01-01" || value > today) return false;
  const date = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value;
}
export const washingAmountPattern = /^(?:0|[1-9]\d{0,15})(?:\.\d{1,2})?$/;
export const washingRatePattern = /^(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/;
function scaled(value: string, places: number): bigint {
  const [whole, fraction = ""] = value.split(".");
  return BigInt(whole || "0") * BigInt(10) ** BigInt(places) + BigInt(fraction.padEnd(places, "0").slice(0, places) || "0");
}
export const washingMinor = (value: string) => scaled(value, 2);
export function washingFormat(amount: bigint): string {
  if (amount < BigInt(0)) return `-${washingFormat(-amount)}`;
  return `${amount / BigInt(100)}.${String(amount % BigInt(100)).padStart(2, "0")}`;
}
export function washingConverted(amount: string, rate: string, places = 2): bigint {
  const divisor = BigInt(10) ** BigInt(10 - places);
  return ((scaled(amount, 2) * scaled(rate, 8) + divisor / BigInt(2)) / divisor) * BigInt(10) ** BigInt(2 - places);
}
export function validateWashing(action: WashingAction, values: WashingValues, lines: WashingLine[], lookups: WashingLookups, row?: WashingRow): Record<string, string> {
  const errors: Record<string, string> = {};
  const required = (key: string) => { if (!values[key]?.trim()) errors[key] = "This field is required."; };
  const choice = (key: string, options: WashingOption[]) => { required(key); if (values[key] && !options.some((option) => String(option.id) === values[key])) errors[key] = "Choose an option"; };
  const amount = (key: string, value: string, places = 2) => {
    if (!washingAmountPattern.test(value) || washingMinor(value) <= BigInt(0)) errors[key] = "Enter a valid amount.";
    else if (washingMinor(value) % BigInt(10) ** BigInt(2 - places)) errors[key] = "Amount exceeds the currency precision.";
  };
  const rate = (key: string) => { if (!washingRatePattern.test(values[key] ?? "") || scaled(values[key], 8) <= BigInt(0)) errors[key] = "Enter a valid exchange rate."; };
  const code = (key: string) => { if (values[key] && !/^[A-Z0-9_-]{1,40}$/.test(values[key])) errors[key] = "Use an uppercase reference of up to 40 characters."; };
  const today = washingToday(lookups.company.timezone);
  const dates: Partial<Record<WashingAction, string>> = { dispatch: "sent_on", complete: "completed_on", return: "returned_on", bill: "billed_on", payment: "payment_on", void: "voided_on" };
  const dateField = dates[action];
  if (dateField && !validWashingDate(values[dateField] ?? "", today)) errors[dateField] = "Enter a valid date that is not in the future.";
  if (dateField && row && values[dateField] < String(row.sent_on ?? row.billed_on ?? "")) errors[dateField] = "Date cannot be before the original transaction.";
  if (["dispatch", "complete", "return", "bill"].includes(action)) {
    if (!lines.length || lines.length > 50) errors.items = "Select between 1 and 50 carpets.";
    if (new Set(lines.map((line) => line.id)).size !== lines.length) errors.items = "Each carpet can only be selected once.";
  }
  if (["dispatch", "bill"].includes(action)) {
    choice("branch_id", lookups.branches); choice("facility_id", lookups.facilities);
  }
  if (action === "dispatch") {
    code("order_no");
    choice("facility_location_id", lookups.facility_locations.filter((location) => location.washing_facility_id === Number(values.facility_id)));
  }
  if (action === "return") choice("location_id", lookups.locations.filter((location) => location.branch_id === Number(row?.branch_id)));
  const currency = lookups.currencies.find((option) => option.id === Number(values.currency_id));
  const account = lookups.accounts.find((option) => option.id === Number(values.account_id));
  if (action === "bill") {
    code("expense_no"); required("invoice_no");
    if ((values.invoice_no?.length ?? 0) > 80) errors.invoice_no = "Use no more than 80 characters.";
    choice("currency_id", lookups.currencies); rate("rate_to_base");
    amount("amount", values.amount ?? "", currency?.decimal_places ?? 2);
    lines.forEach((line, index) => amount(`allocations.${index}.amount`, line.amount, currency?.decimal_places ?? 2));
    if (!errors.amount && lines.every((line, index) => !errors[`allocations.${index}.amount`]) && lines.reduce((sum, line) => sum + washingMinor(line.amount), BigInt(0)) !== washingMinor(values.amount)) errors.allocations = "Allocated amounts must equal the bill total.";
    if (currency?.id === lookups.company.base_currency_id && values.rate_to_base !== "1" && !errors.rate_to_base && scaled(values.rate_to_base, 8) !== scaled("1", 8)) errors.rate_to_base = "The base currency exchange rate must be 1.";
  }
  if (action === "payment") {
    code("payment_no");
    choice("account_id", lookups.accounts);
    const paymentCurrency = lookups.currencies.find((option) => option.id === account?.currency_id);
    amount("amount", values.amount ?? "", paymentCurrency?.decimal_places ?? 2);
    rate("rate_to_base"); rate("bill_units_per_payment_unit");
    required("reason");
    if (account?.currency_id === Number(row?.currency_id) && !errors.bill_units_per_payment_unit && scaled(values.bill_units_per_payment_unit, 8) !== scaled("1", 8)) errors.bill_units_per_payment_unit = "Use 1 when both currencies are the same.";
    if (account?.currency_id === lookups.company.base_currency_id && !errors.rate_to_base && scaled(values.rate_to_base, 8) !== scaled("1", 8)) errors.rate_to_base = "The base currency exchange rate must be 1.";
    if (!errors.amount && account?.balance && washingAmountPattern.test(account.balance) && washingMinor(values.amount) > washingMinor(account.balance)) errors.amount = "The account has insufficient funds.";
    if (!errors.amount && !errors.bill_units_per_payment_unit && row && washingAmountPattern.test(String(row.remaining_amount)) && washingConverted(values.amount, values.bill_units_per_payment_unit, lookups.currencies.find((option) => option.id === Number(row.currency_id))?.decimal_places ?? 2) > washingMinor(String(row.remaining_amount))) errors.amount = "Payment exceeds the outstanding bill balance.";
  }
  if (["void", "reverse"].includes(action)) required("reason");
  if (["payment", "void", "reverse"].includes(action) && values.reason?.trim() && values.reason.trim().length < 3)
    errors.reason = "Use at least 3 characters.";
  const reasonMax = action === "void" ? 500 : 255;
  if ((values.reason?.length ?? 0) > reasonMax) errors.reason = action === "void" ? "Use no more than 500 characters." : "Use no more than 255 characters.";
  if ((values.notes?.length ?? 0) > 500) errors.notes = "Use no more than 500 characters.";
  return errors;
}
export function washingPayload(action: WashingAction, values: WashingValues, lines: WashingLine[]): Record<string, unknown> {
  const fields: Record<WashingAction, string[]> = {
    dispatch: ["order_no", "branch_id", "facility_id", "facility_location_id", "sent_on", "notes"],
    complete: ["completed_on"], return: ["returned_on", "location_id"],
    bill: ["expense_no", "invoice_no", "branch_id", "facility_id", "currency_id", "rate_to_base", "billed_on", "amount", "notes"],
    payment: ["payment_no", "account_id", "amount", "rate_to_base", "bill_units_per_payment_unit", "payment_on", "reason"],
    void: ["voided_on", "reason"], reverse: ["reason"],
  };
  const payload: Record<string, unknown> = Object.fromEntries(fields[action].map((field) => [field, values[field] ?? ""]));
  if (action === "bill") payload.allocations = lines.map((line) => ({ washing_order_item_id: line.id, amount: line.amount }));
  else if (["dispatch", "complete", "return"].includes(action)) payload.items = lines.map((line) => ({ [action === "dispatch" ? "carpet_id" : "washing_order_item_id"]: line.id, expected_sequence: line.expected_sequence }));
  return payload;
}
