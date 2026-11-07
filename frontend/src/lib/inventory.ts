export type InventoryKind =
  "carpets" | "purchases" | "receipts" | "transfers" | "payments";
export type InventoryRow = { id: number; [key: string]: unknown };
export type InventoryPage = {
  data: InventoryRow[];
  current_page: number;
  last_page: number;
  total: number;
};
export type InventoryOption = {
  id: number;
  name: string;
  name_fa?: string;
  branch_id?: number;
  currency_id?: number;
  balance?: string;
  code?: string;
  decimal_places?: number;
};
export type InventoryLookups = {
  company: { base_currency_id: number | null; timezone: string };
  branches: InventoryOption[];
  locations: InventoryOption[];
  categories: InventoryOption[];
  currencies: InventoryOption[];
  weavers: InventoryOption[];
  accounts: InventoryOption[];
  expense_categories: InventoryOption[];
};
export type InventoryValues = Record<string, string>;
export type InventoryLine = {
  carpet_id: number;
  expected_sequence: number;
  unit_price: string;
  label: string;
};
export const INVENTORY_PAGES: Record<
  string,
  { kind: InventoryKind; permission: string; create: string }
> = {
  "Finished carpets": {
    kind: "carpets",
    permission: "stock.view",
    create: "stock.create",
  },
  Carpets: {
    kind: "carpets",
    permission: "stock.view",
    create: "stock.create",
  },
  "Weaver purchases": {
    kind: "purchases",
    permission: "weaver-purchases.view",
    create: "weaver-purchases.create",
  },
  "Stock receipts": {
    kind: "receipts",
    permission: "stock.view",
    create: "stock.receive",
  },
  "Stock transfers": {
    kind: "transfers",
    permission: "stock.view",
    create: "stock.transfer",
  },
  "Weaver payments": {
    kind: "payments",
    permission: "weaver-payments.view",
    create: "weaver-payments.create",
  },
};
export const inventoryValue = (row: InventoryRow | undefined, key: string) =>
  String(row?.[key] ?? "");
export const decimalPattern = /^(?:0|[1-9]\d{0,15})(?:\.\d{1,2})?$/;
export const ratePattern = /^(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/;
const scaled = (value: string, places: number) => {
  const negative = value.startsWith("-");
  const [whole, decimal = ""] = (negative ? value.slice(1) : value).split(".");
  return (
    (negative ? BigInt(-1) : BigInt(1)) *
    (BigInt(whole || "0") * BigInt(10) ** BigInt(places) +
      BigInt(decimal.padEnd(places, "0").slice(0, places) || "0"))
  );
};
export const moneyMinor = (amount: string) => scaled(amount, 2);
export function convertedMinor(
  amount: string,
  rate: string,
  places = 2,
): bigint {
  const divisor = BigInt(10) ** BigInt(10 - places);
  return (
    ((scaled(amount, 2) * scaled(rate, 8) + divisor / BigInt(2)) / divisor) *
    BigInt(10) ** BigInt(2 - places)
  );
}
export function formatMinor(amount: bigint): string {
  if (amount < BigInt(0)) return `-${formatMinor(-amount)}`;
  return `${amount / BigInt(100)}.${String(amount % BigInt(100)).padStart(2, "0")}`;
}
export function inventoryToday(timezone = "Asia/Kabul"): string {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: timezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts();
  const get = (type: string) => parts.find((part) => part.type === type)!.value;
  return `${get("year")}-${get("month")}-${get("day")}`;
}
export function inventoryDate(value: string, today: string): boolean {
  const parsed = new Date(`${value}T00:00:00Z`);
  return (
    /^\d{4}-\d{2}-\d{2}$/.test(value) &&
    value >= "1000-01-01" &&
    value <= today &&
    !Number.isNaN(parsed.getTime()) &&
    parsed.toISOString().slice(0, 10) === value
  );
}
export function validateInventory(
  kind: InventoryKind,
  values: InventoryValues,
  lines: InventoryLine[],
  lookups: InventoryLookups,
  purchase?: InventoryRow,
): Record<string, string> {
  const errors: Record<string, string> = {};
  const required = (key: string) => {
    if (!values[key]?.trim()) errors[key] = "This field is required.";
  };
  const selection = (key: string, options: InventoryOption[]) => {
    required(key);
    if (
      values[key] &&
      !options.some((option) => String(option.id) === values[key])
    )
      errors[key] = "Choose an option";
  };
  const money = (key: string, value: string, positive = true, places = 2) => {
    if (
      !decimalPattern.test(value) ||
      (positive && moneyMinor(value) <= BigInt(0))
    )
      errors[key] = "Enter a valid amount.";
    else if (moneyMinor(value) % BigInt(10) ** BigInt(2 - places) !== BigInt(0))
      errors[key] = "Amount exceeds the currency precision.";
  };
  const rate = (key: string) => {
    if (
      !ratePattern.test(values[key] ?? "") ||
      scaled(values[key], 8) <= BigInt(0)
    )
      errors[key] = "Enter a valid exchange rate.";
  };
  const currency = lookups.currencies.find(
    (c) => c.id === Number(values.currency_id),
  );
  const account = lookups.accounts.find(
    (a) => a.id === Number(values.account_id),
  );
  if (kind === "carpets") {
    ["carpet_code", "name"].forEach(required);
    selection("registration_branch_id", lookups.branches);
    selection("category_id", lookups.categories);
    if (values.name?.trim().length < 2 || values.name?.length > 150)
      errors.name = "Use 2 to 150 characters.";
    if (Boolean(values.length_cm) !== Boolean(values.width_cm))
      errors.width_cm = "Enter both length and width.";
    for (const key of ["length_cm", "width_cm"])
      if (
        values[key] &&
        (!/^\d{1,8}(?:\.\d{1,2})?$/.test(values[key]) ||
          moneyMinor(values[key]) <= BigInt(0))
      )
        errors[key] = "Enter a positive dimension.";
    for (const [key, max] of [
      ["size_label", 60],
      ["color", 60],
      ["material", 100],
      ["notes", 1000],
    ] as const)
      if (values[key]?.length > max) errors[key] = "This text is too long.";
  } else {
    const dateKey =
      kind === "purchases"
        ? "purchase_on"
        : kind === "receipts"
          ? "received_on"
          : kind === "transfers"
            ? "transferred_on"
            : "payment_on";
    if (
      !inventoryDate(
        values[dateKey] ?? "",
        inventoryToday(lookups.company.timezone),
      )
    )
      errors[dateKey] = "Enter a valid date on or before today.";
    if (kind === "purchases") {
      selection("branch_id", lookups.branches);
      selection("weaver_id", lookups.weavers);
      selection("currency_id", lookups.currencies);
      rate("rate_to_base");
      lines.forEach((line, index) =>
        money(
          `items.${index}.unit_price`,
          line.unit_price,
          false,
          currency?.decimal_places ?? 2,
        ),
      );
    }
    if (kind === "receipts") selection("location_id", lookups.locations);
    if (kind === "transfers") {
      selection("from_location_id", lookups.locations);
      selection("to_location_id", lookups.locations);
      if (values.from_location_id === values.to_location_id)
        errors.to_location_id = "Choose a different destination.";
    }
    if (kind !== "payments" && !lines.length)
      errors.items = "Select at least one carpet.";
    if (
      lines.length > 50 ||
      new Set(lines.map((line) => line.carpet_id)).size !== lines.length
    )
      errors.items = "Select at most 50 distinct carpets.";
    if (kind === "payments") {
      selection("branch_id", lookups.branches);
      selection(
        "account_id",
        lookups.accounts,
      );
      required("reason");
      rate("rate_to_base");
      if (!["purchase", "additional"].includes(values.payment_type))
        errors.payment_type = "Choose an option";
      if (values.reason?.trim().length < 3 || values.reason?.length > 255)
        errors.reason = "Use 3 to 255 characters.";
      const paymentCurrency = lookups.currencies.find(
        (c) => c.id === account?.currency_id,
      );
      money(
        "amount",
        values.amount ?? "",
        true,
        paymentCurrency?.decimal_places ?? 2,
      );
      if (
        account?.balance &&
        !errors.amount &&
        moneyMinor(values.amount) > moneyMinor(account.balance)
      )
        errors.amount = "Insufficient account balance.";
      if (values.payment_type === "additional") {
        selection("expense_category_id", lookups.expense_categories);
        selection("weaver_id", lookups.weavers);
      } else {
        required("purchase_id");
        rate("purchase_units_per_payment_unit");
        if (
          !purchase ||
          String(purchase.id) !== values.purchase_id ||
          String(purchase.branch_id) !== values.branch_id
        )
          errors.purchase_id = "Choose an option";
        if (
          purchase &&
          values.payment_on < inventoryValue(purchase, "purchase_on")
        )
          errors.payment_on = "Payment date cannot precede purchase date.";
        const purchaseCurrency = lookups.currencies.find(
          (c) => c.id === Number(purchase?.currency_id),
        );
        if (
          purchase &&
          !errors.amount &&
          !errors.purchase_units_per_payment_unit
        ) {
          const converted = convertedMinor(
            values.amount,
            values.purchase_units_per_payment_unit,
            purchaseCurrency?.decimal_places ?? 2,
          );
          if (
            converted <= BigInt(0) ||
            converted > moneyMinor(inventoryValue(purchase, "remaining_amount"))
          )
            errors.amount = "Payment exceeds the unpaid purchase balance.";
          if (
            account?.currency_id === Number(purchase.currency_id) &&
            scaled(values.purchase_units_per_payment_unit, 8) !==
              BigInt(100000000)
          )
            errors.purchase_units_per_payment_unit =
              "Same-currency allocations must use a rate of 1.";
        }
      }
      if (
        account?.currency_id === lookups.company.base_currency_id &&
        values.rate_to_base &&
        !errors.rate_to_base &&
        scaled(values.rate_to_base, 8) !== BigInt(100000000)
      )
        errors.rate_to_base = "Base-currency entries must use a rate of 1.";
    }
    const maxNotes = kind === "purchases" ? 1000 : 500;
    if (values.notes?.length > maxNotes)
      errors.notes = "This text is too long.";
  }
  const code =
    kind === "carpets"
      ? "carpet_code"
      : kind === "purchases"
        ? "purchase_no"
        : kind === "receipts"
          ? "receipt_no"
          : kind === "transfers"
            ? "transfer_no"
            : "payment_no";
  if (!(["purchases", "receipts", "transfers"].includes(kind) && !values[code]) && !/^[A-Z0-9_-]{1,40}$/.test(values[code] ?? ""))
    errors[code] =
      "Use up to 40 uppercase letters, digits, hyphens or underscores.";
  if (
    kind === "purchases" &&
    currency?.id === lookups.company.base_currency_id &&
    values.rate_to_base &&
    !errors.rate_to_base &&
    scaled(values.rate_to_base, 8) !== BigInt(100000000)
  )
    errors.rate_to_base = "Base-currency entries must use a rate of 1.";
  return errors;
}
