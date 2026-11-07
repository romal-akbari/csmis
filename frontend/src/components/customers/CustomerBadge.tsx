import type { CustomerStatus, CustomerType } from "./types";
import { customerText } from "@/lib/customer-i18n";
import { useAppSelector } from "@/store/dashboard-store";

const TYPE_LABELS: Record<CustomerType, string> = {
  retail: "Retail",
  wholesale: "Wholesale",
  vip: "VIP",
  other: "Other",
};

const STATUS_LABELS: Record<CustomerStatus, string> = {
  active: "Active",
  inactive: "Inactive",
};

/** Pill showing the customer type (retail / wholesale / vip / other). */
export function TypeBadge({ value }: { value: CustomerType }) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const label = TYPE_LABELS[value] ?? value;
  return (
    <span className={`customers-badge type-${value}`}>{customerText(locale, label)}</span>
  );
}

/** Pill showing whether the record is active or inactive. */
export function StatusBadge({ value }: { value: CustomerStatus }) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const label = STATUS_LABELS[value] ?? value;
  return (
    <span className={`customers-badge flag-${value}`}>{customerText(locale, label)}</span>
  );
}
