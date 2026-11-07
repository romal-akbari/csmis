"use client";

import { useEffect, useState, type FormEvent } from "react";
import { FiPlus, FiRefreshCw } from "react-icons/fi";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { subscribeBalanceUpdates } from "@/lib/balance-updates";
import { BUSINESS_PAGES, value, type BusinessLookups, type BusinessRow } from "@/lib/business";
import { businessText } from "@/lib/business-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { BusinessEditor } from "./BusinessEditor";
import { BusinessDetail } from "./BusinessDetail";
import { PurchaseTable } from "@/components/inventory/PurchaseTable";
import { CorrectionEditor } from "@/components/inventory/CorrectionEditor";
import type { CorrectionAction } from "@/lib/inventory-corrections";
import type { InventoryLookups } from "@/lib/inventory";
import styles from "./business.module.css";

type Page = { data: BusinessRow[]; current_page: number; last_page: number; total: number };
export function BusinessScreen({ label, profile, disableCreate = false }: { label: string; profile: Profile; disableCreate?: boolean }) {
  const { resource, module, create } = BUSINESS_PAGES[label];
  const locale = useAppSelector((s) => s.dashboard.locale);
  const t = (s: string) => businessText(locale, s);
  const allowed = (code: string) => profile.permissions.includes(code);
  const [lookups, setLookups] = useState<BusinessLookups | null>(null);
  const [page, setPage] = useState(1);
  const [rows, setRows] = useState<Page | null>(null);
  const [revision, setRevision] = useState(0);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [error, setError] = useState("");
  const [flash, setFlash] = useState("");
  const [detail, setDetail] = useState<BusinessRow | null>(null);
  const [editor, setEditor] = useState<{ action: string; row?: BusinessRow } | null>(null);
  const [correction, setCorrection] = useState<{ action: CorrectionAction; row: BusinessRow } | null>(null);
  const [inventoryLookups, setInventoryLookups] = useState<InventoryLookups | null>(null);
  const permitted = allowed(`${module}.view`);
  async function correct(action: CorrectionAction, row: BusinessRow) {
    try { const result = await apiFetch<{ data: InventoryLookups }>("/inventory/lookups"); setInventoryLookups(result.data); setCorrection({ action, row }); }
    catch (e) { setError(isApiFailure(e) ? e.message : "Could not load records."); }
  }
  useEffect(() => { if (!permitted) return; return subscribeBalanceUpdates(() => setRevision((r) => r + 1)); }, [permitted]);
  useEffect(() => {
    if (!permitted) return;
    let cancelled = false;
    Promise.all([apiFetch<{ data: BusinessLookups }>("/business/lookups"), apiFetch<Page>(`/business/${resource}?page=${page}&search=${encodeURIComponent(query)}`)])
      .then(([options, pageData]) => { if (!cancelled) { setLookups(options.data); setRows(pageData); setError(""); if (page > pageData.last_page) setPage(pageData.last_page); } })
      .catch((e) => { if (!cancelled) setError(isApiFailure(e) ? e.message : "Could not load records."); });
    return () => { cancelled = true; };
  }, [resource, page, query, revision, permitted]);
  function find(e: FormEvent) { e.preventDefault(); setPage(1); setQuery(search.trim()); setRevision((r) => r + 1); }
  const descriptions: Record<string, string> = { purchases: "Purchase and receiving are saved together. Only received carpets become available stock.", expenses: "Record rent, utilities and other operating costs here. Carpet purchases, materials and washing costs stay in their own workflows.", production: "Issue materials, record consumption or returns, track progress, then receive the finished carpets.", partnerships: "Partner support is an advance against settlement, not an expense." };
  if (!permitted) return <p role="alert">{t("You do not have permission to view this page.")}</p>;
  return <section className={styles.screen} lang={locale} dir={locale === "fa" ? "rtl" : "ltr"} aria-labelledby="business-page-title">
    <header className={styles.heading}><div><h1 id="business-page-title">{t(label)}</h1><p className={styles.muted}>{rows?.total ?? 0} {t("records")}</p></div><div className={styles.actions}>
      <button type="button" aria-label={t("Refresh")} onClick={() => setRevision((r) => r + 1)}><FiRefreshCw aria-hidden="true" /></button>
      {!disableCreate && resource !== "material-receipts" && create && allowed(`${module}.create`) && <button type="button" className={styles.primary} disabled={!lookups} onClick={() => { setFlash(""); setEditor({ action: "create" }); }}><FiPlus aria-hidden="true" /> {t(create)}</button>}
    </div></header>
    {descriptions[resource] && <p className={styles.notice}>{t(descriptions[resource])}</p>}
    {error && <p className={styles.error} role="alert">{t(error)}</p>}{flash && <p className={styles.success} role="status">{t(flash)}</p>}
    <form className={styles.search} onSubmit={find}><input aria-label={t("Search records")} placeholder={t("Search records")} maxLength={100} value={search} onChange={(e) => setSearch(e.target.value)} /><button>{t("Search")}</button></form>
    {resource === "purchases" ? <PurchaseTable rows={rows?.data ?? []} title="Carpet purchases" locale={locale} renderActions={(row) => <div className={styles.actions}>
      <button type="button" onClick={() => setDetail(row)}>{t("View details")}</button>
      {allowed("purchasing.pay") && row.state !== "void" && !String(row.remaining_amount).startsWith("-") && Number(row.remaining_amount) > 0 && <button type="button" onClick={() => setEditor({ action: "pay", row })}>{t("Record payment")}</button>}
      {!!row.weaver_id && Boolean(row.can_void) && allowed("weaver-purchases.cancel") && <button type="button" onClick={() => correct("void", row)}>{t("Void purchase")}</button>}
      {!!row.weaver_id && row.state === "posted" && allowed("weaver-purchases.return") && <button type="button" onClick={() => correct("returns", row)}>{t("Return carpets")}</button>}
      {!!row.weaver_id && String(row.remaining_amount).startsWith("-") && allowed("weaver-refunds.create") && <button type="button" onClick={() => correct("refunds", row)}>{t("Record refund")}</button>}
    </div>} /> : <div className={styles.table}><table><thead><tr><th>{t(resource === "materials" ? "Material" : "Reference")}</th><th>{t(resource === "production" || resource === "partnerships" ? "Status" : resource === "materials" || resource === "material-receipts" ? "Warehouse quantity" : "Amount")}</th><th>{t("Details")}</th><th>{t("Actions")}</th></tr></thead><tbody>
      {rows?.data.map((row) => <tr key={row.id}><td><strong>{value(row, "purchase_no") || value(row, "expense_no") || value(row, "order_no") || value(row, "sale_no") || value(row, "lot_no") || value(row, "name") || `#${row.id}`}</strong><span className={styles.sub}>{value(row, "seller_name") || value(row, "payee") || value(row, "weaver_name") || value(row, "customer_name") || value(row, "material_name") || value(row, "account_name")}</span></td>
        <td>{row.state ? <><span className={styles.badge}>{t(String(row.state))}</span>{row.progress != null && <span className={styles.sub}>{String(row.progress)}%</span>}</> : null}
          {row.warehouse_quantity != null ? <strong>{String(row.warehouse_quantity)} {value(row, "unit")}</strong> : row.total_amount != null || row.amount != null ? <span className={styles.sub}>{value(row, "total_amount") || value(row, "amount")} {value(row, "currency_code")}</span> : null}</td>
        <td>{row.remaining_amount != null ? <>{t("Unpaid balance")}: <strong>{String(row.remaining_amount)} {value(row, "currency_code")}</strong></> : value(row, "location_name") || value(row, "category_name") || value(row, "unit") || value(row, "due_on") || value(row, "transaction_date") || "—"}</td>
        <td>{!["materials", "supplier-payments", "customer-receipts"].includes(resource) ? <button type="button" onClick={() => setDetail(row)}>{t("View details")}</button> : resource !== "materials" && !Number(row.reversed) && allowed(`${module}.reverse`) && allowed("ledger.reverse") ? <button type="button" onClick={() => setEditor({ action: "reverse", row })}>{t("Reverse payment")}</button> : Number(row.reversed) ? t("Reversed") : "—"}</td>
      </tr>)}
      {!rows?.data.length && <tr><td colSpan={4}>{t(rows ? "No records yet." : "Loading...")}</td></tr>}
    </tbody></table></div>}
    <div className={styles.pager}><span>{page} / {rows?.last_page ?? 1} · {rows?.total ?? 0}</span><button disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>{t("Previous")}</button><button disabled={!rows || page >= rows.last_page} onClick={() => setPage((p) => p + 1)}>{t("Next")}</button></div>
    {detail && !editor && <BusinessDetail resource={resource} module={module} row={detail} locale={locale} profile={profile} revision={revision} onClose={() => setDetail(null)} onAction={(action, row) => setEditor({ action, row })} onRefresh={() => setRevision((r) => r + 1)} />}
    {editor && lookups && <BusinessEditor label={label} resource={resource} action={editor.action} row={editor.row} lookups={lookups} locale={locale} canPay={allowed(`${module}.${resource === "sales" ? "collect" : "pay"}`)} onClose={() => setEditor(null)} onSave={async (payload, key) => {
      const path = editor.action === "reverse" ? `/business/payments/${editor.row?.account_transaction_id}/reverse` : `/business/${resource}${editor.row ? `/${editor.row.id}/${editor.action}` : ""}`;
      await apiFetch(path, { method: "POST", body: JSON.stringify(payload), headers: { "Idempotency-Key": key } });
      setEditor(null); setRevision((r) => r + 1); setFlash("Saved successfully.");
    }} />}
    {correction && inventoryLookups && <CorrectionEditor action={correction.action} initialPurchase={correction.row} lookups={inventoryLookups} locale={locale} onClose={() => setCorrection(null)} onSave={async (path, payload, key) => {
      await apiFetch(path, { method: "POST", body: JSON.stringify(payload), headers: { "Idempotency-Key": key } }); setCorrection(null); setRevision((r) => r + 1); setFlash("Saved successfully.");
    }} />}
  </section>;
}
