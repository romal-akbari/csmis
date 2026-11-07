"use client";

import { Fragment, useEffect, useRef, useState } from "react";
import { FiMinus, FiPlus } from "react-icons/fi";
import { apiBlob, apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { WASHING_PAGES, washingValue, type WashingAction, type WashingKind, type WashingLookups, type WashingPage, type WashingRow } from "@/lib/washing";
import { washingState, washingText } from "@/lib/washing-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { WashingDetail } from "./WashingDetail";
import { WashingEditor } from "./WashingEditor";
import styles from "./washing.module.css";

const descriptions: Record<WashingKind, string> = { orders: "Track each carpet from dispatch through washing and return to stock.", stock: "Carpets currently in the custody of washing facilities.", bills: "Allocate washing bills once, then record actual payments separately." };
export function WashingScreen({ label, profile }: { label: string; profile: Profile }) {
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState("");
  const exportRequest = useRef<AbortController | null>(null);
  useEffect(() => () => exportRequest.current?.abort(), []);
  const { kind } = WASHING_PAGES[label]; const locale = useAppSelector((state) => state.dashboard.locale); const t = (text: string) => washingText(locale, text);
  const [search, setSearch] = useState(""); const [query, setQuery] = useState(""); const [branch, setBranch] = useState(""); const [facility, setFacility] = useState(""); const [state, setState] = useState(""); const [page, setPage] = useState(1); const [revision, setRevision] = useState(0);
  const [result, setResult] = useState<{ key: string; rows: WashingPage; lookups: WashingLookups } | null>(null); const [failure, setFailure] = useState<{ key: string; message: string } | null>(null);
  const [expanded, setExpanded] = useState<number[]>([]); const [detail, setDetail] = useState<{ kind: "orders" | "bills"; row: WashingRow } | null>(null); const [editor, setEditor] = useState<{ action: WashingAction; row?: WashingRow } | null>(null); const [flash, setFlash] = useState("");
  const params = new URLSearchParams({ search: query, page: String(page), per_page: "15" }); if (branch) params.set("branch_id", branch); if (facility) params.set("facility_id", facility); if (state) params.set(kind === "stock" ? "stock_status" : "state", state);
  const path = `/washing/${kind}?${params}`; const key = `${path}:${revision}`;
  useEffect(() => { const controller = new AbortController(); Promise.all([apiFetch<WashingPage>(path, { signal: controller.signal }), apiFetch<{ data: WashingLookups }>("/washing/lookups", { signal: controller.signal })]).then(([rows, choices]) => { if (!controller.signal.aborted) { setResult({ key, rows, lookups: choices.data }); setFailure(null); } }).catch((error) => { if (!controller.signal.aborted) setFailure({ key, message: isApiFailure(error) ? error.message : "Could not load washing records." }); }); return () => controller.abort(); }, [key, path]);
  const current = result?.key === key ? result : null; const lookups = result?.lookups; const permitted = (permission: string) => profile.permissions.includes(permission);
  const create = kind === "bills" ? "bill" : "dispatch"; const createPermission = kind === "bills" ? "washing-costs.create" : "washing.send";
  function open(row: WashingRow) { setDetail({ kind: kind === "bills" ? "bills" : "orders", row: kind === "stock" ? { id: Number(row.washing_order_id), order_no: row.order_no } : row }); }
  function filter(setter: (value: string) => void, value: string) { setter(value); setPage(1); setExpanded([]); }
  async function save(payload: Record<string, unknown>, idempotencyKey: string) {
    if (!editor) return;
    const { action, row } = editor;
    const route = action === "dispatch" ? "/washing/dispatch" : action === "bill" ? "/washing/bills" : action === "complete" || action === "return" ? `/washing/orders/${row!.id}/${action}` : action === "reverse" ? `/washing/payments/${row!.id}/reverse` : `/washing/bills/${row!.id}/${action === "payment" ? "payments" : "void"}`;
    await apiFetch(route, { method: "POST", headers: { "Idempotency-Key": idempotencyKey }, body: JSON.stringify(payload) });
    setEditor(null); setFlash("Saved successfully."); setRevision((value) => value + 1);
  }
  const details = (row: WashingRow) => {
    const fields: [string, string][] = kind === "orders" ? [["Branch", "branch_name"], ["Washing facility", "facility_name"], ["Facility location", "location_name"], ["Dispatch date", "sent_on"], ["Notes", "notes"]] : kind === "stock" ? [["Branch", "branch_name"], ["Washing facility", "facility_name"], ["Current location", "location_name"], ["Batch reference", "order_no"], ["Dispatch date", "sent_on"], ["Completion date", "completed_on"]] : [["Branch", "branch_name"], ["Washing facility", "facility_name"], ["Invoice number", "invoice_no"], ["Bill date", "billed_on"], ["Total amount", "amount"], ["Current status", "state"], ["Notes", "notes"]];
    return <dl className={styles.summary}>{fields.map(([name, field]) => <div key={field}><dt>{t(name)}</dt><dd>{field === "state" ? t(washingState(washingValue(row, field))) : washingValue(row, field) || "—"}{field === "amount" ? ` ${washingValue(row, "currency_code")}` : ""}</dd></div>)}</dl>;
  };
  const states = kind === "orders" ? ["sent", "partially_completed", "completed", "partially_returned", "returned"] : kind === "stock" ? ["at_washing", "washing_completed"] : ["posted", "void"];
  async function exportStock() {
    if (exportRequest.current || !current) return;
    const controller = new AbortController();
    exportRequest.current = controller;
    setExporting(true);
    setExportError("");
    try {
      const exportParams = new URLSearchParams(params);
      exportParams.set("format", "csv");
      const file = await apiBlob(`/washing/stock?${exportParams}`, controller.signal);
      if (controller.signal.aborted) return;
      const url = URL.createObjectURL(file);
      const link = document.createElement("a");
      link.href = url;
      link.download = "washing-facility-stock.csv";
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) {
      if (!controller.signal.aborted) setExportError(isApiFailure(error) ? error.message : "Could not export washing stock.");
    } finally {
      exportRequest.current = null;
      if (!controller.signal.aborted) setExporting(false);
    }
  }
  return <section className={styles.screen} aria-labelledby="washing-screen-title"><div className={styles.heading}><div><h1 id="washing-screen-title">{t(label)}</h1><p className={styles.muted}>{t(descriptions[kind])}</p></div>{kind !== "stock" && permitted(createPermission) && <button type="button" className={styles.primary} disabled={!current} onClick={() => { setFlash(""); setEditor({ action: create }); }}>{t(create === "bill" ? "Record washing bill" : "Send to washing")}</button>}{kind === "stock" && permitted("washing.export") && <button type="button" disabled={!current || exporting} onClick={() => void exportStock()}>{t(exporting ? "Exporting…" : "Export CSV")}</button>}</div>
    {exportError && <p className={styles.error} role="alert">{t(exportError)}</p>}{flash && <p className={styles.success} role="status">{t(flash)}</p>}
    <form className={styles.filters} onSubmit={(event) => { event.preventDefault(); setQuery(search.trim()); setPage(1); setRevision((value) => value + 1); setExpanded([]); }}><input aria-label={t("Search records")} placeholder={t("Search records")} maxLength={100} value={search} onChange={(event) => setSearch(event.target.value)} /><button type="submit">{t("Search")}</button><select aria-label={t("Branch")} value={branch} onChange={(event) => filter(setBranch, event.target.value)}><option value="">{t("All branches")}</option>{lookups?.branches.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</select><select aria-label={t("Washing facility")} value={facility} onChange={(event) => filter(setFacility, event.target.value)}><option value="">{t("All facilities")}</option>{lookups?.facilities.map((option) => <option key={option.id} value={option.id}>{option.name}</option>)}</select><select aria-label={t("Current status")} value={state} onChange={(event) => filter(setState, event.target.value)}><option value="">{t("All states")}</option>{states.map((value) => <option key={value} value={value}>{t(washingState(value))}</option>)}</select><button type="button" onClick={() => { setSearch(""); setQuery(""); setBranch(""); setFacility(""); setState(""); setPage(1); setExpanded([]); }}>{t("Clear filters")}</button></form>
    {failure?.key === key ? <p className={styles.error} role="alert">{t(failure.message)} <button type="button" onClick={() => setRevision((value) => value + 1)}>{t("Retry")}</button></p> : !current ? <p role="status">{t("Loading...")}</p> : <>
      {kind === "stock" && <div className={styles.totals}><div className={styles.total}><span>{t("Matching carpets")}</span><strong>{current.rows.total}</strong></div></div>}
      <div className={styles.table}><table aria-label={t(label)}><thead><tr><th><span className="sr-only">{t("Expand details")}</span></th><th>{t(kind === "stock" ? "Carpet code" : "Reference")}</th><th>{t(kind === "orders" ? "Progress" : kind === "bills" ? "Balance" : "Current status")}</th></tr></thead><tbody>{current.rows.data.map((row) => { const isExpanded = expanded.includes(row.id); const reference = washingValue(row, kind === "orders" ? "order_no" : kind === "bills" ? "expense_no" : "carpet_code"); return <Fragment key={row.id}><tr className={isExpanded ? styles.active : undefined}><td><button type="button" className={styles.toggle} aria-label={`${t(isExpanded ? "Collapse details" : "Expand details")} ${reference}`} aria-expanded={isExpanded} aria-controls={`washing-row-${row.id}`} onClick={() => setExpanded((values) => isExpanded ? values.filter((value) => value !== row.id) : [...values, row.id])}>{isExpanded ? <FiMinus aria-hidden="true" /> : <FiPlus aria-hidden="true" />}</button></td><td><button type="button" className={styles.reference} onClick={() => open(row)}>{reference}</button><span className={styles.sub}>{washingValue(row, kind === "stock" ? "name" : "facility_name")}</span><button type="button" onClick={() => open(row)}>{t("View")}</button></td><td>{kind === "orders" ? <><span className={styles.badge}>{t(washingState(washingValue(row, "state")))}</span><span className={styles.sub}>{washingValue(row, "item_count")} / {washingValue(row, "completed_count")} / {washingValue(row, "returned_count")}</span></> : kind === "bills" ? <>{washingValue(row, "remaining_amount")} {washingValue(row, "currency_code")}<span className={styles.sub}>{t(washingState(washingValue(row, "state")))}</span></> : <span className={styles.badge}>{t(washingState(washingValue(row, "stock_status")))}</span>}</td></tr><tr className={styles.details} aria-hidden={!isExpanded} inert={!isExpanded}><td colSpan={3}><div id={`washing-row-${row.id}`} className={`${styles.expander} ${isExpanded ? styles.expanded : ""}`}><div className={styles.expandClip}><div className={styles.expandBody}>{details(row)}</div></div></div></td></tr></Fragment>; })}{!current.rows.data.length && <tr><td colSpan={3}>{t("No records found.")}</td></tr>}</tbody></table></div>
      <div className={styles.pager}><span>{current.rows.total} {t("records")} · {current.rows.current_page} / {current.rows.last_page}</span><button type="button" disabled={page <= 1} onClick={() => { setPage((value) => value - 1); setExpanded([]); }}>{t("Previous")}</button><button type="button" disabled={page >= current.rows.last_page} onClick={() => { setPage((value) => value + 1); setExpanded([]); }}>{t("Next")}</button></div>
    </>}
    {detail && !editor && <WashingDetail kind={detail.kind} row={detail.row} locale={locale} profile={profile} revision={revision} onClose={() => setDetail(null)} onAction={(action, row) => setEditor({ action, row })} />}
    {editor && lookups && <WashingEditor action={editor.action} row={editor.row} lookups={lookups} locale={locale} onSave={save} onClose={() => setEditor(null)} />}
  </section>;
}
