"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { productionToday, records, textValue, type ProductionLookups, type ProductionRecord } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionDialog } from "./ProductionDialog";
import { ProductionDeliveryEditor } from "./ProductionDeliveryEditor";
import { ProductionCommandDialog } from "./ProductionCommandDialog";
import { paymentFields } from "./production-fields";
import styles from "./production.module.css";

export function ProductionOrderDetail({ id, lookups, locale, profile, onClose, onSaved }: {
  id: number; lookups: ProductionLookups; locale: string; profile: Profile; onClose: () => void; onSaved: () => void;
}) {
  const [order, setOrder] = useState<ProductionRecord | null>(null);
  const [error, setError] = useState("");
  const [revision, setRevision] = useState(0);
  const [action, setAction] = useState<string | null>(null);
  const t = (text: string) => productionText(locale, text);
  const can = (permission: string) => profile.permissions.includes(permission);
  useEffect(() => {
    const controller = new AbortController();
    apiFetch<{ data: ProductionRecord }>(`/production/orders/${id}`, { signal: controller.signal })
      .then(({ data }) => { if (!controller.signal.aborted) { setOrder(data); setError(""); } })
      .catch((failure) => { if (!controller.signal.aborted) setError(isApiFailure(failure) ? failure.message : "Could not load records."); });
    return () => controller.abort();
  }, [id, revision]);
  function saved() { setAction(null); setRevision((old) => old + 1); onSaved(); }
  if (order && action === "deliver") return <ProductionDeliveryEditor order={order} lookups={lookups} locale={locale} canCreateLocations={can("locations.create")}
    locationFacilityIds={profile.is_administrator || profile.roles.includes("Administrator") ? undefined : profile.facility_ids ?? []}
    onClose={() => setAction(null)} onSaved={saved} />;
  if (order && action === "pay") return <ProductionCommandDialog title="Record production payment" path={`/production/orders/${id}/pay`} locale={locale} lookups={lookups} onClose={() => setAction(null)} onSaved={saved}
    fields={paymentFields(lookups, true)} initial={{ payment_type: "production", account_id: "", amount: "", rate_to_base: "1", units_per_payment_unit: "1", payment_on: productionToday(lookups), reason: "" }} />;
  if (order && action === "close") return <ProductionCommandDialog title="Close order" path={`/production/orders/${id}/close`} locale={locale} lookups={lookups} onClose={() => setAction(null)} onSaved={saved}
    fields={[{ key: "closed_on", label: "Closing date", type: "date" }, { key: "reason", label: "Reason", type: "textarea", maxLength: 500 }]} initial={{ closed_on: productionToday(lookups), reason: "" }} />;
  return <ProductionDialog title="Production order" locale={locale} onClose={onClose}>
    {error && <p className={styles.error} role="alert">{t(error)}</p>}{!order && !error && <p role="status">{t("Loading...")}</p>}
    {order && <>
      <div className={styles.lineHeader}><strong>{textValue(order, "order_no")} · {textValue(order, "manager_name")}</strong><span className={styles.badge}>{t(textValue(order, "state"))}</span></div>
      <div className={styles.actions}>
        {order.state !== "closed" && Number(order.delivered_quantity) < Number(order.target_quantity) && can("production.receive") && can("washing.send") && <button type="button" className={styles.primary} onClick={() => setAction("deliver")}>{t("Send to washing")}</button>}
        {order.state !== "closed" && can("production.pay") && <button type="button" onClick={() => setAction("pay")}>{t("Record production payment")}</button>}
        {order.state !== "closed" && can("production.manage") && <button type="button" onClick={() => setAction("close")}>{t("Close order")}</button>}
      </div>
      <section className={styles.section}><dl className={styles.summary}>{Object.entries({ target_quantity: "Target quantity", delivered_quantity: "Delivered quantity", accepted_quantity: "Accepted quantity", earned_amount: "Earned amount", paid_amount: "Paid amount", balance_due: "Balance due", currency_code: "Currency", payment_trigger: "Payment trigger", description: "Description" }).map(([key, label]) => <div key={key}><dt>{t(label)}</dt><dd>{key === "payment_trigger" ? t(order.payment_trigger === "stock_acceptance" ? "After stock acceptance" : "On completed delivery to washing") : textValue(order, key) || "—"}</dd></div>)}</dl></section>
      <section className={styles.section}><h3>{t("Design lines")}</h3><ul className={styles.history}>{records(order, "lines").map((line) => <li key={line.id}>
        <strong>{textValue(line, "name")}</strong><span className={styles.sub}>{t("Target quantity")}: {textValue(line, "target_quantity")} · {t("Delivered quantity")}: {textValue(line, "delivered_quantity") || "0"}</span>
        <span className={styles.sub}>{t("Pricing method")}: {t(textValue(line, "pricing_method"))} · {textValue(line, "rate")} {textValue(order, "currency_code")}</span>
        {textValue(line, "manual_reason") && <span className={styles.sub}>{textValue(line, "manual_reason")}</span>}
        {records(order, "factors").filter((factor) => Number(factor.line_id) === line.id).map((factor) => <span className={styles.sub} key={factor.id}>{textValue(factor, "label")} · {t(textValue(factor, "operation"))} {textValue(factor, "value")}</span>)}
      </li>)}</ul></section>
      {records(order, "factors").some((factor) => factor.line_id == null) && <section className={styles.section}><h3>{t("Order pricing factors")}</h3>{records(order, "factors").filter((factor) => factor.line_id == null).map((factor) => <p key={factor.id}>{textValue(factor, "label")} · {t(textValue(factor, "operation"))} {textValue(factor, "value")}</p>)}</section>}
      {records(order, "deliveries").length > 0 && <section className={styles.section}><h3>{t("Deliveries")}</h3><ul className={styles.history}>{records(order, "deliveries").map((delivery) => <li key={delivery.id}>
        <strong>{textValue(delivery, "delivery_no") || `#${delivery.id}`} · {textValue(delivery, "delivered_on")}</strong>{delivery.facility_name != null && <span className={styles.sub}>{textValue(delivery, "facility_name")} · {textValue(delivery, "facility_location_name")} · {textValue(delivery, "washing_reference")}</span>}
        {records(delivery, "items").map((item) => <span key={item.id} className={styles.sub}>{textValue(item, "carpet_code")} · {textValue(item, "length_cm")} × {textValue(item, "width_cm")} {t("cm")} · {t(textValue(item, "stock_status"))} · {textValue(item, "location_name") || "—"}</span>)}
      </li>)}</ul></section>}
      {records(order, "payments").length > 0 && <section className={styles.section}><h3>{t("Payments")}</h3><ul className={styles.history}>{records(order, "payments").map((payment) => <li key={payment.id}><strong>{textValue(payment, "amount")} {textValue(payment, "currency_code")}</strong><span className={styles.sub}>{textValue(payment, "account_name")} · {textValue(payment, "payment_on") || textValue(payment, "transaction_date")} · {t(payment.payment_type === "advance" ? "Production advance" : "Production earnings")}{Number(payment.reversed) ? ` · ${t("Reversed")}` : ""}</span></li>)}</ul></section>}
    </>}
  </ProductionDialog>;
}
