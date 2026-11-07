import { positiveDecimal, records, textValue, type ProductionLookups, type ProductionRecord } from "./manager-production.ts";
import { convertedMinor, decimalPattern, inventoryDate, moneyMinor, ratePattern } from "./inventory.ts";

export type WorkflowContext = { defaults: ProductionRecord | null; materials: ProductionRecord[]; tools: ProductionRecord[]; washing_items: ProductionRecord[] };
export type StockLine = { item_id: string; quantity: string };
export type UsageLine = { material_id: string; used_quantity: string; waste_quantity: string };
export const zeroQuantity = (value: string) => /^(?:0|[1-9]\d{0,11})(?:\.\d{1,3})?$/.test(value);
export const respectsCurrencyPrecision = (value: string, precision = 2) => !/[1-9]/.test((value.split(".")[1] ?? "").slice(precision));
export function quantityMinor(value: string): bigint {
  const [whole, fraction = ""] = value.split(".");
  return BigInt(whole || "0") * BigInt(1000) + BigInt(fraction.padEnd(3, "0"));
}
export function defaultsFor(lookups: ProductionLookups, branch: string | number): ProductionRecord | undefined {
  return records(lookups as unknown as ProductionRecord, "workflow_defaults").find((row) => String(row.branch_id) === String(branch));
}
export function stockChoices(context: WorkflowContext, tools: boolean, held: boolean, location?: string): ProductionRecord[] {
  const grouped = new Map<string, ProductionRecord>();
  for (const lot of tools ? context.tools : context.materials) {
    if (!held && location && String(lot.location_id) !== location) continue;
    const item = String(lot[tools ? "tool_id" : "material_id"]);
    const quantity = textValue(lot, held ? "held_quantity" : "warehouse_quantity");
    if (!positiveDecimal(quantity, 3)) continue;
    const old = grouped.get(item);
    const total = quantityMinor(quantity) + quantityMinor(textValue(old, "quantity") || "0");
    grouped.set(item, { id: Number(item), name: lot.name, unit: lot.unit, quantity: `${total / BigInt(1000)}.${String(total % BigInt(1000)).padStart(3, "0")}` });
  }
  return [...grouped.values()];
}
export function validateStockLines(lines: StockLine[], choices: ProductionRecord[], tools: boolean): Record<string, string> {
  const errors: Record<string, string> = {};
  const selected = lines.filter((row) => row.item_id || row.quantity);
  const ids = selected.map((row) => row.item_id);
  if (new Set(ids).size !== ids.length) errors.items = "Choose each item once.";
  lines.forEach((row, index) => {
    if (!row.item_id && !row.quantity) return;
    const choice = choices.find((item) => String(item.id) === row.item_id);
    if (!choice) errors[`${index}.item_id`] = "Choose a valid option.";
    const valid = tools ? /^[1-9]\d{0,9}$/.test(row.quantity) && BigInt(row.quantity) <= BigInt(1000000000) : positiveDecimal(row.quantity, 3);
    if (!valid) errors[`${index}.quantity`] = "Enter a positive quantity.";
    if (choice && valid && quantityMinor(row.quantity) > quantityMinor(String(choice.quantity))) errors[`${index}.quantity`] = "Quantity exceeds the available stock.";
  });
  return errors;
}
export function validateUsages(usages: UsageLine[], choices: ProductionRecord[]): Record<string, string> {
  const errors: Record<string, string> = {};
  usages.forEach((row, index) => {
    const choice = choices.find((item) => String(item.id) === row.material_id);
    if (!choice || !zeroQuantity(row.used_quantity) || !zeroQuantity(row.waste_quantity)) errors[`usages.${index}`] = "Enter valid material quantities.";
    else if (quantityMinor(row.used_quantity) + quantityMinor(row.waste_quantity) > quantityMinor(String(choice.quantity))) errors[`usages.${index}`] = "Quantity exceeds the materials held by the manager.";
  });
  return errors;
}

export function validateWorkflowPayment(values: Record<string, string>, lookups: ProductionLookups, documentCurrency: number, today: string, earliest?: string): Record<string, string> {
  const errors: Record<string, string> = {};
  const account = lookups.accounts.find((row) => String(row.id) === values.account_id);
  if (!account) errors.account_id = "Choose an account.";
  if (!decimalPattern.test(values.amount ?? "") || !/[1-9]/.test(values.amount ?? "")) errors.amount = "Enter a positive amount.";
  const currency = lookups.currencies.find((row) => row.id === account?.currency_id);
  if (!respectsCurrencyPrecision(values.amount ?? "", Number(currency?.decimal_places ?? 2))) errors.amount = "Amount exceeds the currency precision.";
  if (account && decimalPattern.test(String(account.balance ?? "")) && decimalPattern.test(values.amount ?? "") && moneyMinor(values.amount) > moneyMinor(String(account.balance))) errors.amount = "Insufficient account balance.";
  for (const key of ["rate_to_base", "units_per_payment_unit"]) if (!ratePattern.test(values[key] ?? "") || !/[1-9]/.test(values[key] ?? "")) errors[key] = "Enter a positive exchange rate.";
  if (account?.currency_id === documentCurrency && !/^1(?:\.0{1,8})?$/.test(values.units_per_payment_unit)) errors.units_per_payment_unit = "Same-currency payments must use a conversion of 1.";
  if (account?.currency_id === lookups.company?.base_currency_id && !/^1(?:\.0{1,8})?$/.test(values.rate_to_base)) errors.rate_to_base = "Base-currency entries must use a rate of 1.";
  if (!inventoryDate(values.payment_on ?? "", today)) errors.payment_on = "Enter a valid date.";
  else if (earliest && values.payment_on < earliest) errors.payment_on = "Payment date cannot precede the document date.";
  if ((values.reason ?? "").trim().length < 3) errors.reason = "Use at least 3 characters.";
  else if ((values.reason ?? "").length > 255) errors.reason = "This text is too long.";
  return errors;
}

export function validateSupportAmounts(amount: string, currency: number, lookups: ProductionLookups, payment?: Record<string, string>, delivery?: Record<string, string>): Record<string, string> {
  const errors: Record<string, string> = {};
  const precision = Number(lookups.currencies.find((row) => row.id === currency)?.decimal_places ?? 2);
  if (!decimalPattern.test(amount) || !/[1-9]/.test(amount)) errors.amount = "Enter a positive amount.";
  else if (!respectsCurrencyPrecision(amount, precision)) errors.amount = "Amount exceeds the currency precision.";
  let funded: bigint | undefined;
  if (payment && decimalPattern.test(payment.amount) && ratePattern.test(payment.units_per_payment_unit) && /[1-9]/.test(payment.units_per_payment_unit)) {
    funded = convertedMinor(payment.amount, payment.units_per_payment_unit, precision);
    if (decimalPattern.test(amount) && funded > moneyMinor(amount)) errors["payment.amount"] = "Payment cannot exceed the assistance amount.";
  }
  if (delivery) {
    if (!decimalPattern.test(delivery.amount) || !/[1-9]/.test(delivery.amount)) errors["delivery.amount"] = "Enter a positive amount.";
    else if (!respectsCurrencyPrecision(delivery.amount, precision)) errors["delivery.amount"] = "Amount exceeds the currency precision.";
    else if (funded !== undefined && moneyMinor(delivery.amount) > funded) errors["delivery.amount"] = "Delivery cannot exceed the funded amount.";
  }
  return errors;
}

export function supplyTotal(lines: { quantity: string; unit_cost: string }[], precision: number): string {
  let total = BigInt(0);
  for (const row of lines) {
    if (!zeroQuantity(row.quantity) || !decimalPattern.test(row.unit_cost)) continue;
    const step = BigInt(10) ** BigInt(2 - precision);
    const divisor = BigInt(1000) * step;
    const amount = ((quantityMinor(row.quantity) * moneyMinor(row.unit_cost) + divisor / BigInt(2)) / divisor) * step;
    total += amount;
  }
  return `${total / BigInt(100)}.${String(total % BigInt(100)).padStart(2, "0")}`;
}
