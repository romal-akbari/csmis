import { decimalPattern, moneyMinor } from "./inventory.ts";

export type SalesStockGroup = {
  stock_key: string;
  name: string;
  production_order_no: string | null;
  length_cm: string | null;
  width_cm: string | null;
  location_name: string;
  location_id: number;
  branch_id: number;
  available_quantity: number;
};
export type SalesStockLine = { stock_key: string; label: string; quantity: string; unit_price: string; discount: string };

const wholeQuantity = (value: string) => /^[1-9]\d{0,4}$/.test(value) && Number(value) <= 50;

export function salesStockTotal(lines: SalesStockLine[]): bigint {
  return lines.reduce((sum, line) => {
    if (!wholeQuantity(line.quantity) || !decimalPattern.test(line.unit_price) || !decimalPattern.test(line.discount)) return sum;
    const price = moneyMinor(line.unit_price), discount = moneyMinor(line.discount);
    return discount > price ? sum : sum + BigInt(line.quantity) * (price - discount);
  }, BigInt(0));
}

export function validateSalesStock(lines: SalesStockLine[], groups: SalesStockGroup[], precision = 2): Record<string, string> {
  const errors: Record<string, string> = {};
  if (!lines.length) errors.stock_groups = "Select a production design.";
  if (lines.length > 50 || new Set(lines.map((line) => line.stock_key)).size !== lines.length) errors.stock_groups = "Select each production design only once, up to 50 carpets.";
  let quantity = 0;
  const respectsPrecision = (value: string) => !/[1-9]/.test((value.split(".")[1] ?? "").slice(precision));
  lines.forEach((line, index) => {
    const prefix = `stock_groups.${index}`;
    const group = groups.find((item) => item.stock_key === line.stock_key);
    if (!group) errors[`${prefix}.stock_key`] = "This production design is no longer available. Refresh stock.";
    if (!wholeQuantity(line.quantity)) errors[`${prefix}.quantity`] = "Enter a whole quantity from 1 to 50.";
    else {
      quantity += Number(line.quantity);
      if (group && Number(line.quantity) > group.available_quantity) errors[`${prefix}.quantity`] = "Quantity exceeds the available stock.";
    }
    if (!decimalPattern.test(line.unit_price) || moneyMinor(line.unit_price) <= BigInt(0)) errors[`${prefix}.unit_price`] = "Enter a positive amount.";
    else if (!respectsPrecision(line.unit_price)) errors[`${prefix}.unit_price`] = "Amount exceeds the currency precision.";
    if (!decimalPattern.test(line.discount) || decimalPattern.test(line.unit_price) && moneyMinor(line.discount) >= moneyMinor(line.unit_price)) errors[`${prefix}.discount`] = "Discount must be smaller than the price.";
    else if (!respectsPrecision(line.discount)) errors[`${prefix}.discount`] = "Amount exceeds the currency precision.";
  });
  if (quantity > 50) errors.stock_groups = "Select no more than 50 carpets per invoice.";
  return errors;
}
