"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { washingList, washingValue, type WashingAction, type WashingRow } from "@/lib/washing";
import { washingState, washingText } from "@/lib/washing-i18n";
import { WashingDialog } from "./WashingDialog";
import styles from "./washing.module.css";

export function WashingDetail({ kind, row, locale, profile, revision, onClose, onAction }: { kind: "orders" | "bills"; row: WashingRow; locale: string; profile: Profile; revision: number; onClose: () => void; onAction: (action: WashingAction, row: WashingRow) => void }) {
  const t = (text: string) => washingText(locale, text);
  const [response, setResponse] = useState<{ key: string; data: WashingRow } | null>(null); const [failure, setFailure] = useState<{ key: string; message: string } | null>(null); const [retry, setRetry] = useState(0);
  const path = `/washing/${kind}/${row.id}`; const key = `${path}:${revision}:${retry}`;
  useEffect(() => { const controller = new AbortController(); apiFetch<{ data: WashingRow }>(path, { signal: controller.signal }).then((result) => { if (!controller.signal.aborted) { setResponse({ key, data: result.data }); setFailure(null); } }).catch((error) => { if (!controller.signal.aborted) setFailure({ key, message: isApiFailure(error) ? error.message : "Could not load washing records." }); }); return () => controller.abort(); }, [key, path]);
  const data = response?.key === key ? response.data : null;
  const permitted = (permission: string) => profile.permissions.includes(permission);
  const title = washingValue(row, kind === "orders" ? "order_no" : "expense_no") || t(kind === "orders" ? "View batch" : "View bill");
  const entry = (label: string, value: unknown, wide = false) => value !== undefined && value !== null && value !== "" ? <div className={wide ? styles.wide : undefined} key={label}><dt>{t(label)}</dt><dd>{String(value)}</dd></div> : null;
  return <WashingDialog title={title} locale={locale} onClose={onClose}>
    {failure?.key === key ? <p role="alert" className={styles.error}>{t(failure.message)} <button type="button" onClick={() => setRetry((value) => value + 1)}>{t("Retry")}</button></p> : !data ? <p role="status">{t("Loading...")}</p> : <>
      <dl className={styles.summary}>{entry("Branch", data.branch_name)}{entry("Washing facility", data.facility_name)}{entry("Current status", t(washingState(washingValue(data, "state"))))}{kind === "orders" ? <>{entry("Facility location", data.location_name)}{entry("Dispatch date", data.sent_on)}{entry("Sent / completed / returned", `${data.item_count ?? washingList(data, "items").length} / ${data.completed_count ?? 0} / ${data.returned_count ?? 0}`)}</> : <>{entry("Invoice number", data.invoice_no)}{entry("Bill date", data.billed_on)}{entry("Total amount", `${data.amount} ${data.currency_code}`)}{entry("Outstanding balance", `${data.remaining_amount} ${data.currency_code}`)}</>}{entry("Notes", data.notes, true)}</dl>
      {kind === "orders" && <>
        <div className={styles.actions}>{permitted("washing.complete") && washingList(data, "items").some((item) => item.state === "sent") && <button type="button" className={styles.primary} onClick={() => onAction("complete", data)}>{t("Mark completed")}</button>}{permitted("washing.return") && washingList(data, "items").some((item) => item.state === "completed") && <button type="button" onClick={() => onAction("return", data)}>{t("Return to stock")}</button>}</div>
        <h3>{t("Washing progress")}</h3>{washingList(data, "items").map((item) => <article className={styles.item} key={item.id}><strong>{washingValue(item, "carpet_code")} · {washingValue(item, "name")}</strong> <span className={styles.badge}>{t(washingState(washingValue(item, "state")))}</span><dl className={styles.summary}>{entry("Weaver", item.weaver_name)}{entry("Originating purchase", item.purchase_no)}{entry("Source location", item.source_location_name ?? item.source_location_id)}{entry("Current location", item.location_name ?? item.current_location_name)}{entry("Current status", t(washingState(washingValue(item, "stock_status"))))}{entry("Completion date", item.completed_on)}{entry("Return date", item.returned_on)}{permitted("washing-costs.view") && entry("Washing costs", item.washing_cost ?? item.allocated_cost)}</dl></article>)}
      </>}
      {kind === "bills" && <>
        <div className={styles.actions}>{data.state === "posted" && permitted("washing-costs.pay") && Number(data.remaining_amount) > 0 && <button type="button" className={styles.primary} onClick={() => onAction("payment", data)}>{t("Record payment")}</button>}{data.state === "posted" && permitted("washing-costs.void") && !washingList(data, "payments").some((payment) => !Number(payment.reversed)) && <button type="button" onClick={() => onAction("void", data)}>{t("Void bill")}</button>}</div>
        {data.state === "posted" && permitted("washing-costs.void") && washingList(data, "payments").some((payment) => !Number(payment.reversed)) && <p className={styles.muted}>{t("Reverse active payments before voiding this bill.")}</p>}
        <h3>{t("Cost allocations")}</h3>{washingList(data, "allocations").map((item) => <article className={styles.item} key={item.id}><strong>{washingValue(item, "carpet_code")} · {washingValue(item, "carpet_name")}</strong><dl className={styles.summary}>{entry("Batch reference", item.order_no)}{entry("Dispatch date", item.sent_on)}{entry("Amount", `${item.amount} ${data.currency_code}`)}</dl></article>)}
        <h3>{t("Payment history")}</h3>{washingList(data, "payments").length === 0 && <p className={styles.muted}>{t("No records found.")}</p>}{washingList(data, "payments").map((payment) => <article className={styles.item} key={payment.id}><strong>{washingValue(payment, "payment_no")}</strong>{!!Number(payment.reversed) && <span className={styles.badge}>{t("Reversed")}</span>}<dl className={styles.summary}>{entry("Account", payment.account_name)}{entry("Amount", `${payment.amount} ${payment.currency_code}`)}{entry("Payment date", payment.payment_on)}{entry("Converted settlement", `${payment.amount_in_bill_currency} ${data.currency_code}`)}{entry("Bill units per payment unit", payment.bill_units_per_payment_unit)}</dl>{!Number(payment.reversed) && permitted("washing-costs.reverse") && <button type="button" onClick={() => onAction("reverse", { ...payment, branch_id: data.branch_id, bill_id: data.id })}>{t("Reverse payment")}</button>}</article>)}
        {!!data.void && <p className={styles.notice}>{t("Void")}: {washingValue(data.void as WashingRow, "voided_on")} · {washingValue(data.void as WashingRow, "reason")}</p>}
      </>}
    </>}
  </WashingDialog>;
}
