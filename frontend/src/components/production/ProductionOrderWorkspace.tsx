"use client";

import { useEffect, useState } from "react";
import { FiArrowLeft, FiRefreshCw } from "react-icons/fi";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { subscribeBalanceUpdates } from "@/lib/balance-updates";
import { productionToday, records, textValue, type ProductionLookups, type ProductionRecord } from "@/lib/manager-production";
import type { WorkflowContext } from "@/lib/production-workflow";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionIssueEditor } from "./ProductionIssueEditor";
import { ProductionDeliveryEditor } from "./ProductionDeliveryEditor";
import { ProductionAcceptEditor } from "./ProductionAcceptEditor";
import { ProductionSetupEditor } from "./ProductionSetupEditor";
import { ProductionOrderDetail } from "./ProductionOrderDetail";
import { ProductionCommandDialog } from "./ProductionCommandDialog";
import { ProductionSettleEditor } from "./ProductionSettleEditor";
import { paymentFields } from "./production-fields";
import styles from "./production.module.css";

export function ProductionOrderWorkspace({ id, lookups, locale, profile, onClose, onSaved }: {
  id: number; lookups: ProductionLookups; locale: string; profile: Profile; onClose: () => void; onSaved: () => void;
}) {
  const [order, setOrder] = useState<ProductionRecord | null>(null);
  const [context, setContext] = useState<WorkflowContext | null>(null);
  const [revision, setRevision] = useState(0);
  const [action, setAction] = useState<string | null>(null);
  const [error, setError] = useState("");
  const t = (text: string) => productionText(locale, text);
  const can = (permission: string) => profile.permissions.includes(permission);
  useEffect(() => {
    const controller = new AbortController();
    Promise.all([apiFetch<{ data: ProductionRecord }>(`/production/orders/${id}`, { signal: controller.signal }), apiFetch<{ data: WorkflowContext }>(`/production/orders/${id}/workflow`, { signal: controller.signal })])
      .then(([document, workflow]) => { if (!controller.signal.aborted) { setOrder(document.data); setContext(workflow.data); setError(""); } })
      .catch((failure) => { if (!controller.signal.aborted) setError(isApiFailure(failure) ? failure.message : "Could not load records."); });
    return () => controller.abort();
  }, [id, revision]);
  useEffect(() => subscribeBalanceUpdates(() => setRevision((old) => old + 1)), []);
  function saved() { setAction(null); setOrder(null); setContext(null); setRevision((old) => old + 1); onSaved(); }
  function historySaved() { setRevision((old) => old + 1); onSaved(); }
  const complete = order?.state === "completed" || order?.state === "closed";
  const step = complete ? 4 : Number(order?.accepted_quantity) > 0 ? 3 : Number(order?.delivered_quantity) > 0 ? 2 : order?.state === "in_production" ? 1 : 0;
  return <section className={styles.screen} aria-label={t("Production order workspace")} dir={locale === "fa" ? "rtl" : "ltr"}>
    <header className={styles.heading}><div><button type="button" onClick={onClose}><FiArrowLeft aria-hidden="true" />{t("Back to orders")}</button><h1>{textValue(order ?? undefined, "order_no") || t("Production order")}</h1><p className={styles.muted}>{textValue(order ?? undefined, "manager_name")} · {textValue(order ?? undefined, "branch_name")}</p></div>
      <div className={styles.actions}><button type="button" aria-label={t("Refresh order")} onClick={() => setRevision((old) => old + 1)}><FiRefreshCw aria-hidden="true" /></button>
        {can("production.manage") && <button type="button" onClick={() => setAction("setup")}>{t("Production setup")}</button>}
      </div>
    </header>
    {error && <p className={styles.error} role="alert">{t(error)}</p>}{(!order || !context) && !error && <p role="status">{t("Loading...")}</p>}
    {order && context && <>
      <ol className={styles.steps} aria-label={t("Production progress")}>{["Order created", "With manager", "At washing", "In stock", "Complete"].map((label, index) => <li key={label} data-current={index === step} data-done={index <= step}><span>{index + 1}</span>{t(label)}</li>)}</ol>
      <div className={styles.metrics}>{[{ label: "Earned", key: "earned_amount" }, { label: "Already paid", key: "paid_amount" }, { label: "Remaining", key: "balance_due" }].map((item) => <div className={styles.metric} key={item.key}>{t(item.label)}<strong>{textValue(order, item.key)} {textValue(order, "currency_code")}</strong></div>)}</div>
      <p className={styles.notice}>{t("Target quantity")}: <strong>{textValue(order, "target_quantity")}</strong> · {t("Delivered quantity")}: <strong>{textValue(order, "delivered_quantity")}</strong> · {t("Accepted quantity")}: <strong>{textValue(order, "accepted_quantity")}</strong> · <span className={styles.badge}>{t(textValue(order, "state"))}</span></p>
      {!context.defaults && <p className={styles.notice}>{t("Save production setup once to fill the stores and washing destination automatically.")}</p>}
      <div className={styles.workflowActions}>
        {order.state !== "closed" && Number(order.delivered_quantity) < Number(order.target_quantity) && can("materials.move") && <button type="button" onClick={() => setAction("issue")}>{t("Give to manager")}</button>}
        {order.state !== "closed" && Number(order.delivered_quantity) < Number(order.target_quantity) && can("production.receive") && can("washing.send") && <button className={styles.primary} type="button" onClick={() => setAction("receive")}>{t("Receive carpets")}</button>}
        {context.washing_items.length > 0 && can("washing.return") && <button type="button" className={styles.primary} onClick={() => setAction("accept")}>{t("Receive from washing")}</button>}
        {order.state !== "closed" && can("production.pay") && <button type="button" disabled={Number(order.balance_due) <= 0} onClick={() => setAction("settle")}>{t("Pay remaining")}</button>}
      </div>
      <section className={styles.section}><h2>{t("Carpet designs")}</h2><ul className={styles.history}>{records(order, "lines").map((line) => <li key={line.id}><strong>{textValue(line, "name")}</strong><span className={styles.sub}>{textValue(line, "delivered_quantity")}/{textValue(line, "target_quantity")} · {t(textValue(line, "pricing_method"))} · {textValue(line, "rate")} {textValue(order, "currency_code")}</span></li>)}</ul></section>
      {records(order, "deliveries").length > 0 && <section className={styles.section}><h2>{t("Carpet locations")}</h2><ul className={styles.history}>{records(order, "deliveries").flatMap((delivery) => records(delivery, "items").map((item) => {
        const status = textValue(item, "stock_status");
        const location = textValue(item, "location_name") || (["at_washing", "washing_completed"].includes(status) ? textValue(delivery, "facility_name") : "");
        const dimensions = `${textValue(item, "length_cm")} × ${textValue(item, "width_cm")} ${t("cm")}`;
        return <li key={item.id}><strong>{textValue(item, "carpet_code")}</strong><span className={styles.sub}>{[t(status), location, dimensions].filter(Boolean).join(" · ")}</span></li>;
      }))}</ul></section>}
      <details className={styles.advanced}><summary>{t("History and other actions")}</summary><div className={styles.actions}>
        <button type="button" onClick={() => setAction("history")}>{t("Full history")}</button>
        {can("materials.move") && order.state !== "closed" && <button type="button" onClick={() => setAction("return")}>{t("Return supplies")}</button>}
        {can("production.pay") && order.state !== "closed" && <button type="button" onClick={() => setAction("payment")}>{t("Record production payment")}</button>}
      </div></details>
      {(action === "issue" || action === "return") && <ProductionIssueEditor order={order} context={context} lookups={lookups} locale={locale} returning={action === "return"} canPay={can("production.pay")} onClose={() => setAction(null)} onSaved={saved} />}
      {action === "receive" && <ProductionDeliveryEditor simple context={context} order={order} lookups={lookups} locale={locale} canCreateLocations={can("locations.create")} locationFacilityIds={profile.is_administrator || profile.roles.includes("Administrator") ? undefined : profile.facility_ids ?? []} onClose={() => setAction(null)} onSaved={saved} />}
      {action === "accept" && <ProductionAcceptEditor order={order} context={context} lookups={lookups} locale={locale} canBill={can("washing-costs.create")} canPay={can("washing-costs.pay")} canComplete={can("washing.complete")} onClose={() => setAction(null)} onSaved={saved} />}
      {action === "settle" && <ProductionSettleEditor order={order} lookups={lookups} locale={locale} onClose={() => setAction(null)} onSaved={saved} />}
      {action === "payment" && <ProductionCommandDialog title="Record production payment" path={`/production/orders/${id}/pay`} locale={locale} lookups={lookups} onClose={() => setAction(null)} onSaved={saved}
        fields={paymentFields(lookups, true)} initial={{ payment_type: "production", account_id: "", amount: "", rate_to_base: "1", units_per_payment_unit: "1", payment_on: productionToday(lookups), reason: `Production earnings for ${order.order_no}` }} />}
      {action === "setup" && <ProductionSetupEditor lookups={lookups} locale={locale} profile={profile} branch={String(order.branch_id)} onClose={() => setAction(null)} onSaved={saved} />}
      {action === "history" && <ProductionOrderDetail id={id} lookups={lookups} locale={locale} profile={profile} onClose={() => setAction(null)} onSaved={historySaved} />}
    </>}
  </section>;
}
