"use client";

import { useState, type FormEvent } from "react";
import { CUSTOMER_STATUSES, CUSTOMER_TYPES } from "@/lib/customer-validation";
import { customerLabel, customerText } from "@/lib/customer-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { EMPTY_FILTERS, type CustomerFilters } from "./types";

export function CustomerFilterBar({ filters, onChange }: { filters: CustomerFilters; onChange: (next: CustomerFilters) => void }) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const t = (text: string) => customerText(locale, text);
  const [text, setText] = useState(filters.search);
  function search(event: FormEvent) { event.preventDefault(); onChange({ ...filters, search: text.trim() }); }
  return <div className="customers-filters">
    <form className="customers-search" onSubmit={search}>
      <input aria-label={t("Search customers")} placeholder={t("Search by name or phone...")} value={text} maxLength={100} onChange={(event) => setText(event.target.value)} />
      <button className="customers-action" type="submit">{t("Search")}</button>
    </form>
    <select aria-label={t("Filter by status")} value={filters.status} onChange={(event) => onChange({ ...filters, status: event.target.value })}>
      <option value="">{t("Any status")}</option>
      {CUSTOMER_STATUSES.map((value) => <option key={value} value={value}>{customerLabel(locale, value)}</option>)}
    </select>
    <select aria-label={t("Filter by customer type")} value={filters.customer_type} onChange={(event) => onChange({ ...filters, customer_type: event.target.value })}>
      <option value="">{t("Any type")}</option>
      {CUSTOMER_TYPES.map((value) => <option key={value} value={value}>{customerLabel(locale, value)}</option>)}
    </select>
    <select aria-label={t("Directory records")} value={filters.archived} onChange={(event) => onChange({ ...filters, archived: event.target.value })}>
      <option value="">{t("Current customers")}</option><option value="only">{t("Archived customers")}</option>
    </select>
    <button className="customers-action" type="button" onClick={() => { setText(""); onChange(EMPTY_FILTERS); }}>{t("Clear")}</button>
  </div>;
}
