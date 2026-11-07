"use client";

import { useEffect, useState } from "react";
import { apiBlob, apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { ACTION_TITLES, type BusinessResource, type BusinessRow } from "@/lib/business";
import { businessText } from "@/lib/business-i18n";
import { BusinessDialog } from "./BusinessDialog";
import styles from "./business.module.css";

const captions: Record<string, string> = {
  purchase_no: "Purchase reference", expense_no: "Expense reference", order_no: "Order reference", sale_no: "Sale reference", lot_no: "Material lot", seller_invoice: "Seller invoice number (optional)",
  description: "Description", payee: "Payee", amount: "Amount", currency_code: "Currency", due_on: "Due date", ordered_on: "Start date", expense_on: "Expense date", purchase_on: "Purchase date", sold_on: "Sale date", received_on: "Receiving date",
  state: "Status", quantity: "Quantity", warehouse_quantity: "Warehouse quantity", unit_cost: "Cost per unit", total_cost: "Total cost", total_cost_base: "Total cost (base currency)",
  company_share: "Company profit / proceeds share (%)", company_loss_share: "Company loss share (%)", share_basis: "Sharing basis", agreement_terms: "Ownership, contributions and agreement terms", notes: "Notes",
  total_amount: "Total", remaining_amount: "Unpaid balance", paid_amount: "Paid amount",
  labor_earned: "Labor earned", labor_paid: "Paid labor / advances", labor_balance: "Labor balance (negative means advance)",
};

export function BusinessDetail({ resource, module, row, locale, profile, revision, onClose, onAction, onRefresh }: { resource: BusinessResource; module: string; row: BusinessRow; locale: string; profile: Profile; revision: number; onClose: () => void; onAction: (action: string, row: BusinessRow) => void; onRefresh: () => void }) {
  const [detail, setDetail] = useState<BusinessRow | null>(null);
  const [error, setError] = useState("");
  const [uploading, setUploading] = useState(false);
  const t = (s: string) => businessText(locale, s);
  const allowed = (permission: string) => profile.permissions.includes(permission);
  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: BusinessRow }>(`/business/${resource}/${row.id}`).then((r) => { if (!cancelled) { setDetail(r.data); setError(""); } }).catch((e) => { if (!cancelled) setError(isApiFailure(e) ? e.message : "Could not load records."); });
    return () => { cancelled = true; };
  }, [resource, row.id, revision]);
  const data = detail ? { ...row, ...detail } : row;
  const array = (key: string) => Array.isArray(data[key]) ? data[key] as BusinessRow[] : [];
  const actions: string[] = [];
  const production = resource === "production" || resource === "partnerships";
  const received = data.state === "received";
  if (["purchases", "expenses", "material-receipts"].includes(resource) && allowed(`${module}.pay`) && data.state !== "void" && Number(data.remaining_amount) > 0) actions.push("pay");
  if (resource === "sales" && allowed("sales.collect") && Number(data.remaining_amount) > 0) actions.push("collect");
  if (resource === "expenses" && allowed("expenses.void") && !data.void && Number(data.paid_amount ?? 0) === 0) actions.push("void");
  if (production && !received && allowed(`${module}.manage`)) {
    actions.push("progress", resource === "partnerships" ? "contribution" : "charge");
  }
  if (production && !received && allowed("materials.move")) actions.unshift("move");
  if (production && !received && allowed(resource === "partnerships" ? "partnerships.manage" : "production.receive")) actions.push("receive");
  if (production && allowed(`${module}.pay`) && !data.settlement) actions.push("pay");
  if (resource === "partnerships") {
    if (!data.settlement && allowed("partnerships.settle")) actions.push("settle");
    if (data.settlement && Number((data.settlement as Record<string, unknown>).settlement_remaining_base) > 0 && allowed("partnerships.pay")) actions.push("settlement-pay");
  }
  async function download(file: BusinessRow) {
    try { const blob = await apiBlob(`/business/expenses/${row.id}/attachments/${file.id}`); const url = URL.createObjectURL(blob); const anchor = document.createElement("a"); anchor.href = url; anchor.download = String(file.original_name); anchor.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }
    catch (e) { setError(isApiFailure(e) ? e.message : "Could not download the file."); }
  }
  function payments(key: string, title = "Payments") {
    const entries = array(key);
    return entries.length > 0 && <section className={styles.section}><h3>{t(title)}</h3>{entries.map((p) => <div key={p.id} className={styles.payment}>
      <strong>{String(p.amount)} {String(p.currency_code)} · {String(p.account_name)}</strong><span className={styles.sub}>{String(p.transaction_date)} · {p.direction === "in" ? "↑" : "↓"} {String(p.description)}</span>
      {Number(p.reversed) ? <span className={styles.badge}>{t("Reversed")}</span> : allowed(`${module}.reverse`) && allowed("ledger.reverse") && <button type="button" onClick={() => onAction("reverse", p)}>{t("Reverse payment")}</button>}
    </div>)}</section>;
  }
  const preview = data.settlement_preview as Record<string, unknown> | undefined;
  const settlement = data.settlement as Record<string, unknown> | undefined;
  return <BusinessDialog title="View details" locale={locale} onClose={onClose} busy={uploading}>
    {error && <p className={styles.error} role="alert">{t(error)}</p>}
    {!detail && !error && <p role="status">{t("Loading...")}</p>}
    {detail && <>
      <div className={styles.actions}>{actions.map((action) => <button type="button" key={action} onClick={() => onAction(action, data)}>{t(ACTION_TITLES[action])}</button>)}</div>
      <section className={styles.section}><dl className={styles.summary}>{Object.entries(captions).filter(([k]) => data[k] != null && typeof data[k] !== "object" && data[k] !== "").map(([k, caption]) => <div key={k} className={["notes", "description", "agreement_terms"].includes(k) ? styles.wide : undefined}><dt>{t(caption)}</dt><dd>{["state", "share_basis"].includes(k) ? t(String(data[k])) : String(data[k])}</dd></div>)}</dl></section>
      {data.balance != null && <section className={styles.section}><dl className={styles.summary}>{["total_amount", "paid_amount", "remaining_amount"].map((k) => <div key={k}><dt>{t(captions[k])}</dt><dd>{String((data.balance as Record<string, unknown>)[k])}</dd></div>)}</dl></section>}
      {array("items").length > 0 && <section className={styles.section}><h3>{t("Carpets")}</h3><ul className={styles.history}>{array("items").map((item, i) => <li key={i}><strong>{String(item.carpet_code)} · {String(item.name)}</strong>
        {!!item.unit_price && <span className={styles.sub}>{t("Unit price")}: {String(item.unit_price)}{item.discount != null ? ` · ${t("Discount")}: ${item.discount}` : ""}</span>}
        {!!item.cost_weight && <span className={styles.sub}>{t("Cost weight")}: {String(item.cost_weight)}</span>}
        {!!item.stock_status && <span className={styles.sub}>{t(String(item.stock_status))} · {String(item.location_name ?? "—")}</span>}
      </li>)}</ul></section>}
      {array("movements").length > 0 && <section className={styles.section}><h3>{t("Material movements")}</h3><ul className={styles.history}>{array("movements").map((m) => <li key={m.id}><strong>{t(String(m.movement_type))} · {String(m.quantity)} {String(m.unit ?? "")}</strong><span className={styles.sub}>{String(m.moved_on)} · {String(m.lot_no ?? m.order_no ?? "")} · {String(m.reason)}</span></li>)}</ul></section>}
      {array("progress_history").length > 0 && <section className={styles.section}><h3>{t("Progress history")}</h3><ul className={styles.history}>{array("progress_history").map((p) => <li key={p.id}>{String(p.progress_on)} · <strong>{String(p.percent)}%</strong> · {String(p.notes)}</li>)}</ul></section>}
      {["labor_charges", "contributions"].map((key) => array(key).length > 0 && <section key={key} className={styles.section}><h3>{t(key === "labor_charges" ? "Labor charges" : "Partner contributions")}</h3><ul className={styles.history}>{array(key).map((p) => <li key={p.id}><strong>{String(p.amount)} {String(data.currency_code ?? "")}</strong> · {String(p.description)}</li>)}</ul></section>)}
      {payments("payments")}
      {resource === "partnerships" && (settlement || preview) && <section className={styles.section}><h3>{t(settlement ? "Final settlement" : "Settlement preview")}</h3>
        <p className={styles.notice}>{t("All amounts in the settlement are in the company base currency. Settlement requires all carpets sold and customer balances collected.")}</p>
        {!settlement && <p className={styles.badge}>{t(preview?.ready ? "Ready for settlement" : "Waiting for receiving, sales and full customer collection")}</p>}
        <dl className={styles.summary}>{Object.entries({ revenue_base: "Revenue (base currency)", company_cost_base: "Company costs (base currency)", partner_cost_base: "Partner costs (base currency)", company_entitlement_base: "Company entitlement", partner_entitlement_base: "Partner entitlement", partner_paid_base: "Support already paid", balance_due_base: "Partner balance due", settlement_paid_base: "Settlement payments recorded", settlement_remaining_base: "Remaining settlement balance" }).filter(([key]) => (settlement ?? preview)?.[key] != null).map(([key, label]) => <div key={key}><dt>{t(label)}</dt><dd>{String((settlement ?? preview)?.[key] ?? "—")}</dd></div>)}</dl><p className={styles.muted}>{t("A negative partner balance is money the partner must return.")}</p>
      </section>}
      {payments("settlement_payments", "Final settlement")}
      {resource === "expenses" && <section className={styles.section}><h3>{t("Receipt attachments")}</h3>{array("attachments").map((file) => <p key={file.id}><button type="button" onClick={() => download(file)}>{t("Download")} · {String(file.original_name)}</button></p>)}
        {allowed("expenses.create") && <label className={styles.field}>{t("Upload receipt")}<input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" disabled={uploading} onChange={async (e) => {
          const file = e.target.files?.[0]; if (!file) return;
          const body = new FormData(); body.set("file", file); setUploading(true); setError("");
          try { await apiFetch(`/business/expenses/${row.id}/attachments`, { method: "POST", body }); onRefresh(); }
          catch (error) { setError(isApiFailure(error) ? error.message : "Could not upload the file."); }
          finally { setUploading(false); }
        }} /></label>}
      </section>}
    </>}
  </BusinessDialog>;
}
