"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { productionToday, records, textValue, type ProductionLookups, type ProductionRecord } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionDialog } from "./ProductionDialog";
import { ProductionCommandDialog } from "./ProductionCommandDialog";
import { paymentFields } from "./production-fields";
import styles from "./production.module.css";

export function ProductionResourceDetail({ resource, id, lookups, locale, profile, onClose, onSaved }: {
  resource: "assistance" | "tool-receipts"; id: number; lookups: ProductionLookups; locale: string; profile: Profile; onClose: () => void; onSaved: () => void;
}) {
  const [data, setData] = useState<ProductionRecord | null>(null);
  const [error, setError] = useState("");
  const [revision, setRevision] = useState(0);
  const [action, setAction] = useState<string | null>(null);
  const t = (text: string) => productionText(locale, text);
  const assistance = resource === "assistance";
  const titles: Record<string, string> = { fund: "Fund assistance", deliver: "Confirm delivery", recover: "Record recovery", return: "Return unused funds", pay: "Record payment" };
  useEffect(() => {
    const controller = new AbortController();
    apiFetch<{ data: ProductionRecord }>(`/production/${resource}/${id}`, { signal: controller.signal })
      .then(({ data }) => { if (!controller.signal.aborted) { setData(data); setError(""); } })
      .catch((failure) => { if (!controller.signal.aborted) setError(isApiFailure(failure) ? failure.message : "Could not load records."); });
    return () => controller.abort();
  }, [resource, id, revision]);
  if (action) return <ProductionCommandDialog title={titles[action]} path={`/production/${resource}/${id}/${action}`} locale={locale} lookups={lookups}
    onClose={() => setAction(null)} onSaved={() => { setAction(null); setRevision((old) => old + 1); onSaved(); }}
    help={assistance ? "Funding reduces cash once. Delivery acknowledgement does not create a second payment." : undefined}
    fields={action === "deliver" ? [{ key: "amount", label: "Amount", type: "decimal" }, { key: "delivered_on", label: "Acknowledgement date", type: "date" }, { key: "acknowledgement", label: "Acknowledgement", type: "textarea", maxLength: 500 }] : paymentFields(lookups)}
    initial={{ amount: "", account_id: "", rate_to_base: "1", units_per_payment_unit: "1", payment_on: productionToday(lookups), delivered_on: productionToday(lookups), acknowledgement: "", reason: "" }} />;
  const fields = assistance
    ? { assistance_no: "Assistance reference", manager_name: "Manager", beneficiary_name: "Beneficiary name", assistance_type: "Assistance type", responsible_party: "Debtor", recovery_terms: "Recovery terms", amount: "Amount", currency_code: "Currency", funded_amount: "Funded amount", delivered_amount: "Delivered amount", returned_amount: "Returned amount", recovered_amount: "Recovered amount", with_manager_amount: "With manager", advance_outstanding: "Outstanding amount", reason: "Reason" }
    : { lot_no: "Tool lot", tool_name: "Tool", quantity: "Quantity", warehouse_quantity: "Warehouse quantity", unit_cost: "Cost per unit", total_cost: "Total cost", remaining_amount: "Unpaid balance", currency_code: "Currency", received_on: "Receiving date" };
  return <ProductionDialog title={assistance ? "Worker assistance" : "Tool receipts"} locale={locale} onClose={onClose}>
    {error && <p className={styles.error} role="alert">{t(error)}</p>}{!data && !error && <p role="status">{t("Loading...")}</p>}
    {data && <>
      <div className={styles.actions}>
        {assistance ? <>
          {profile.permissions.includes("production.pay") && <><button type="button" onClick={() => setAction("fund")}>{t("Fund assistance")}</button>{data.assistance_type === "advance" && <button type="button" onClick={() => setAction("recover")}>{t("Record recovery")}</button>}<button type="button" onClick={() => setAction("return")}>{t("Return unused funds")}</button></>}
          {profile.permissions.includes("production.manage") && <button type="button" onClick={() => setAction("deliver")}>{t("Confirm delivery")}</button>}
        </> : profile.permissions.includes("materials.pay") && Number(data.remaining_amount) > 0 && <button type="button" onClick={() => setAction("pay")}>{t("Record payment")}</button>}
      </div>
      <section className={styles.section}><dl className={styles.summary}>{Object.entries(fields).filter(([key]) => data[key] != null).map(([key, label]) => <div key={key}><dt>{t(label)}</dt><dd>{["assistance_type", "responsible_party"].includes(key) ? t(textValue(data, key)) : textValue(data, key)}</dd></div>)}</dl></section>
      {assistance && <p className={styles.notice}>{t("Funding reduces cash once. Delivery acknowledgement does not create a second payment.")}</p>}
      {["payments", "fundings", "deliveries", "recoveries", "returns"].map((key) => records(data, key).length > 0 && <section className={styles.section} key={key}><h3>{t(key)}</h3><ul className={styles.history}>{records(data, key).map((entry) => <li key={entry.id}>
        <strong>{textValue(entry, "amount")} {textValue(entry, "currency_code") || textValue(data, "currency_code")}{Number(entry.reversed) ? ` · ${t("Reversed")}` : ""}</strong><span className={styles.sub}>{textValue(entry, "transaction_date") || textValue(entry, "delivered_on")}{entry.account_name ? ` · ${textValue(entry, "account_name")}` : ""} · {textValue(entry, "description") || textValue(entry, "acknowledgement")}</span>
      </li>)}</ul></section>)}
    </>}
  </ProductionDialog>;
}
