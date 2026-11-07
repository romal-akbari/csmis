export type TrendDay = { date: string; purchased: number; produced?: number; received: number };
export type StockStatus =
  | "in_stock" | "purchased" | "registered" | "missing" | "returned"
  | "at_washing" | "washing_completed" | "production_completed" | "sold";
export type CurrencyTotal = { currency: string; precision: number; amount: string };
export type BusinessSummary = {
  sales?: (CurrencyTotal & { sold: number; receivable: string; overdue: string })[] | null;
  purchases: CurrencyTotal[] | null;
  expenses: CurrencyTotal[] | null;
  accounts: { total: number; items: {
    id: number; name: string; account_type: string; status: string; branch_name: string;
    currency: string; precision: number; balance: string;
  }[] } | null;
  recent_transactions: {
    id: number; transaction_date: string; entry_type: string; direction: string;
    amount: string; description: string; account_name: string; currency: string;
    precision: number; reversed: boolean | number;
  }[] | null;
  branches: { total: number; items: {
    id: number; name: string; code: string; location: string | null;
    status: string; is_main: boolean | number; available?: number;
  }[] } | null;
};
export type Overview = {
  as_of: string;
  timezone: string;
  days: number;
  business?: BusinessSummary;
  metrics: {
    at_washing?: number | null;
    managers?: number | null;
    available: number | null;
    awaiting_receipt: number | null;
    customers: number | null;
    weavers: number | null;
  };
  stock: {
    counts: Record<Exclude<StockStatus, "at_washing" | "washing_completed" | "production_completed">, number>
      & Partial<Record<"at_washing" | "washing_completed" | "production_completed", number>>;
    trend: TrendDay[];
    recent: {
      id: number;
      event_type: string;
      resulting_status: StockStatus;
      occurred_on: string;
      carpet_code: string;
      name: string;
      location_name: string | null;
    }[];
  } | null;
  finance: {
    manager_payables?: { currency: string; precision: number; owed: string; credit: string }[] | null;
    supplier_payables?: { currency: string; precision: number; owed: string }[] | null;
    payables: {
      currency: string; precision: number; owed: string; credit: string;
    }[] | null;
    cash: { currency: string; precision: number; balance: string }[] | null;
    washing_payables?: { currency: string; precision: number; owed: string }[] | null;
  };
};

export const stockLabels: Record<StockStatus, string> = {
  in_stock: "In stock", purchased: "Awaiting receipt", registered: "Registered",
  missing: "Missing", returned: "Returned",
  at_washing: "At washing", washing_completed: "Washing completed",
  production_completed: "Production completed",
  sold: "Sold",
};
export const eventLabels: Record<string, string> = {
  purchase: "Purchased", receipt: "Received", transfer: "Transferred",
  purchase_void: "Purchase cancelled", adjustment: "Stock corrected",
  weaver_return: "Returned to weaver",
  wash_sent: "Sent for washing", wash_completed: "Washing completed",
  wash_returned: "Returned from washing",
  washing_dispatch: "Sent for washing", washing_complete: "Washing completed",
  washing_return: "Returned from washing",
  production_receive: "Production received", production_complete: "Production completed", sale: "Sold",
};
export const overviewLocale = (locale: string) =>
  locale === "fa" ? "fa-AF-u-ca-gregory" : "en-US";
export const countLabel = (value: number, locale: string) =>
  new Intl.NumberFormat(overviewLocale(locale)).format(value);
export const dateLabel = (day: string, locale: string) =>
  new Intl.DateTimeFormat(overviewLocale(locale), {
    month: "short", day: "numeric", timeZone: "UTC",
  }).format(new Date(`${day}T12:00:00Z`));

/** Keep currency amounts exact, including values above Number.MAX_SAFE_INTEGER. */
export function moneyLabel(value: string, precision: number, locale: string) {
  const negative = value.startsWith("-");
  const [whole, fraction = ""] = value.replace(/^-/, "").split(".");
  const formatter = new Intl.NumberFormat(overviewLocale(locale));
  const integer = formatter.format(BigInt(whole));
  const decimal = formatter.formatToParts(1.1)
    .find((part) => part.type === "decimal")?.value ?? ".";
  const digits = fraction.padEnd(precision, "0").slice(0, precision)
    .replace(/\d/g, (digit) => formatter.format(Number(digit)));
  return `${negative ? "−" : ""}${integer}${precision ? decimal + digits : ""}`;
}
