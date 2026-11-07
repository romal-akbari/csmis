import {
  convertedMinor,
  decimalPattern,
  inventoryDate,
  inventoryToday,
  moneyMinor,
  ratePattern,
  validateInventory,
  type InventoryLine,
  type InventoryLookups,
  type InventoryRow,
  type InventoryValues,
} from "./inventory.ts";

export type CorrectionKind = "drafts" | "adjustments" | "returns" | "refunds";
export type CorrectionAction =
  CorrectionKind | "void" | "post-draft" | "cancel-draft" | "view";
export const CORRECTION_PAGES: Record<
  string,
  { kind: CorrectionKind; permission: string; create: string; action: string }
> = {
  "Purchase drafts": {
    kind: "drafts",
    permission: "weaver-purchases.view",
    create: "weaver-purchases.draft",
    action: "New purchase draft",
  },
  "Stock corrections": {
    kind: "adjustments",
    permission: "stock.view",
    create: "stock.adjust",
    action: "Correct stock",
  },
  "Weaver returns": {
    kind: "returns",
    permission: "weaver-purchases.view",
    create: "weaver-purchases.return",
    action: "Return carpets",
  },
  "Weaver refunds": {
    kind: "refunds",
    permission: "weaver-refunds.view",
    create: "weaver-refunds.create",
    action: "Record refund",
  },
};
export function validateDraft(
  values: InventoryValues,
  lines: InventoryLine[],
  lookups: InventoryLookups,
): Record<string, string> {
  const errors = validateInventory("purchases", values, lines, lookups);
  for (const key of ["weaver_id", "currency_id", "purchase_on", "rate_to_base"])
    if (!values[key]) delete errors[key];
  if (!lines.length) delete errors.items;
  lines.forEach((line, index) => {
    if (!line.unit_price) delete errors[`items.${index}.unit_price`];
  });
  return errors;
}
export function validateCorrection(
  action: CorrectionAction,
  values: InventoryValues,
  lines: InventoryLine[],
  lookups: InventoryLookups,
  purchase?: InventoryRow,
): Record<string, string> {
  const errors: Record<string, string> = {};
  if (action === "view" || action === "post-draft") return errors;
  const reasonMax = action === "refunds" ? 255 : 500;
  if (
    (values.reason?.trim().length ?? 0) < 3 ||
    values.reason.length > reasonMax
  )
    errors.reason = "Enter a reason of at least three characters.";
  if (action === "cancel-draft") return errors;
  const dateKey =
    action === "void"
      ? "voided_on"
      : action === "returns"
        ? "returned_on"
        : action === "refunds"
          ? "refunded_on"
          : "adjusted_on";
  if (
    !inventoryDate(
      values[dateKey] ?? "",
      inventoryToday(lookups.company.timezone),
    )
  )
    errors[dateKey] = "Enter a valid date on or before today.";
  if (action !== "void") {
    const number =
      action === "adjustments"
        ? "adjustment_no"
        : action === "returns"
          ? "return_no"
          : "refund_no";
    if (!(action === "adjustments" && !values[number]) && !/^[A-Z0-9_-]{1,40}$/.test(values[number] ?? ""))
      errors[number] =
        "Use up to 40 uppercase letters, digits, hyphens or underscores.";
  }
  if (action === "returns" || action === "refunds")
    if (!purchase) errors.weaver_purchase_id = "Choose an option";
  if (action === "adjustments") {
    if (!lookups.branches.some((b) => String(b.id) === values.branch_id))
      errors.branch_id = "Choose an option";
    if (!["relocate", "missing", "found"].includes(values.adjustment_type))
      errors.adjustment_type = "Choose an option";
    if (
      values.adjustment_type !== "missing" &&
      !lookups.locations.some(
        (l) =>
          String(l.id) === values.location_id &&
          String(l.branch_id) === values.branch_id,
      )
    )
      errors.location_id = "Choose an option";
  }
  if (
    action === "returns" &&
    values.from_location_id !== "-1" &&
    !lookups.locations.some((l) => String(l.id) === values.from_location_id)
  )
    errors.from_location_id = "Choose an option";
  if (
    ["returns", "adjustments", "void"].includes(action) &&
    (!lines.length ||
      lines.length > 50 ||
      new Set(lines.map((line) => line.carpet_id)).size !== lines.length)
  )
    errors.items = "Select at least one carpet.";
  if (action === "refunds") {
    const account = lookups.accounts.find(
      (a) =>
        String(a.id) === values.account_id,
    );
    const currency = lookups.currencies.find(
      (c) => c.id === account?.currency_id,
    );
    const target = lookups.currencies.find(
      (c) => c.id === Number(purchase?.currency_id),
    );
    if (!account) errors.account_id = "Choose an option";
    for (const key of ["rate_to_base", "purchase_units_per_refund_unit"])
      if (!ratePattern.test(values[key] ?? "") || !/[1-9]/.test(values[key]))
        errors[key] = "Enter a valid exchange rate.";
    if (
      !decimalPattern.test(values.amount ?? "") ||
      moneyMinor(values.amount) <= BigInt(0)
    )
      errors.amount = "Enter a valid amount.";
    else if (
      moneyMinor(values.amount) %
        BigInt(10) ** BigInt(2 - (currency?.decimal_places ?? 2)) !==
      BigInt(0)
    )
      errors.amount = "Amount exceeds the currency precision.";
    if (
      account?.currency_id === lookups.company.base_currency_id &&
      !errors.rate_to_base &&
      Number(values.rate_to_base) !== 1
    )
      errors.rate_to_base = "Base-currency entries must use a rate of 1.";
    if (
      account?.currency_id === Number(purchase?.currency_id) &&
      !errors.purchase_units_per_refund_unit &&
      Number(values.purchase_units_per_refund_unit) !== 1
    )
      errors.purchase_units_per_refund_unit =
        "Same-currency allocations must use a rate of 1.";
    if (purchase && !errors.amount && !errors.purchase_units_per_refund_unit) {
      const amount = convertedMinor(
        values.amount,
        values.purchase_units_per_refund_unit,
        target?.decimal_places ?? 2,
      );
      const credit = -moneyMinor(String(purchase.remaining_amount ?? "0"));
      if (amount <= BigInt(0) || amount > credit)
        errors.amount = "Refund exceeds the credit owed by the weaver.";
    }
  }
  return errors;
}
