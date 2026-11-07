"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { subscribeBalanceUpdates } from "@/lib/balance-updates";
import { records, textValue, type ProductionLookups, type ProductionRecord } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { SupplyPurchaseEditor } from "./SupplyPurchaseEditor";
import { ProductionSettleEditor } from "./ProductionSettleEditor";
import { ProductionDialog } from "./ProductionDialog";
import styles from "./production.module.css";

export function SupplyPurchasesScreen({ profile }: { profile: Profile }) {
  const locale = useAppSelector((state) => state.dashboard.locale); const t = (text: string) => productionText(locale, text);
  const [lookups, setLookups] = useState<ProductionLookups | null>(null);
  const [result, setResult] = useState<{ data: ProductionRecord[]; last_page: number; total: number } | null>(null);
  const [revision, setRevision] = useState(0); const [page, setPage] = useState(1); const [query, setQuery] = useState(""); const [search, setSearch] = useState("");
  const [error, setError] = useState(""); const [creating, setCreating] = useState(false); const [detail, setDetail] = useState<number | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    Promise.all([apiFetch<{ data: ProductionLookups }>("/production/lookups", { signal: controller.signal }), apiFetch<{ data: ProductionRecord[]; last_page: number; total: number }>(`/production/supply-purchases?page=${page}&search=${encodeURIComponent(query)}`, { signal: controller.signal })])
      .then(([options, rows]) => { if (!controller.signal.aborted) { setLookups(options.data); setResult(rows); setError(""); } })
      .catch((failure) => { if (!controller.signal.aborted) setError(isApiFailure(failure) ? failure.message : "Could not load records."); });
    return () => controller.abort();
  }, [page, query, revision]);
  useEffect(() => subscribeBalanceUpdates(() => setRevision((old) => old + 1)), []);
  const [pay, setPay] = useState(false);
  const row = result?.data.find((item) => item.id === detail);
  return <section className={styles.screen} dir={locale === "fa" ? "rtl" : "ltr"}>
    <header className={styles.heading}><div><h1>{t("Materials & tools")}</h1><p className={styles.muted}>{t("Purchase, receive and optionally pay in one form.")}</p></div>{profile.permissions.includes("materials.receive") && <button className={styles.primary} disabled={!lookups} onClick={() => setCreating(true)}>{t("Purchase and receive supplies")}</button>}</header>
    {error && <p className={styles.error} role="alert">{t(error)}</p>}
    <form className={styles.search} onSubmit={(event) => { event.preventDefault(); setQuery(search.trim()); setPage(1); }}><input aria-label={t("Search records")} value={search} onChange={(event) => setSearch(event.target.value)} /><button>{t("Search")}</button></form>
    <div className={styles.table}><table><thead><tr><th>{t("Reference")}</th><th>{t("Purchase total")}</th><th>{t("Unpaid balance")}</th><th>{t("Actions")}</th></tr></thead><tbody>{result?.data.map((item) => <tr key={item.id}><td><strong>{textValue(item, "purchase_no")}</strong><span className={styles.sub}>{textValue(item, "supplier_name")} · {textValue(item, "location_name")}</span></td><td>{textValue(item, "total_amount")} {textValue(item, "currency_code")}</td><td>{textValue(item, "remaining_amount")}</td><td><button onClick={() => setDetail(item.id)}>{t("View details")}</button></td></tr>)}{!result?.data.length && <tr><td colSpan={4}>{t(result ? "No records yet." : "Loading...")}</td></tr>}</tbody></table></div>
    <div className={styles.pager}><span>{page}/{result?.last_page ?? 1} · {result?.total ?? 0}</span><button disabled={page <= 1} onClick={() => setPage((old) => old - 1)}>{t("Previous")}</button><button disabled={!result || page >= result.last_page} onClick={() => setPage((old) => old + 1)}>{t("Next")}</button></div>
    {creating && lookups && <SupplyPurchaseEditor lookups={lookups} locale={locale} profile={profile} onClose={() => setCreating(false)} onSaved={() => { setCreating(false); setRevision((old) => old + 1); }} />}
    {row && pay && lookups ? <ProductionSettleEditor supply order={{ ...row, balance_due: row.remaining_amount }} lookups={lookups} locale={locale} onClose={() => setPay(false)} onSaved={() => { setPay(false); setRevision((old) => old + 1); }} /> : row && <ProductionDialog title="Supply purchase" locale={locale} onClose={() => setDetail(null)}><h3>{textValue(row, "purchase_no")}</h3>{profile.permissions.includes("materials.pay") && Number(row.remaining_amount) > 0 && <button onClick={() => setPay(true)}>{t("Pay remaining")}</button>}<p>{textValue(row, "location_name")}</p><ul className={styles.history}>{records(row, "items").map((item) => <li key={`${item.kind}-${item.id}`}><strong>{textValue(item, "name")} · {textValue(item, "quantity")}</strong><span className={styles.sub}>{textValue(item, "lot_no")} · {textValue(item, "total_cost")} {textValue(row, "currency_code")} · {t("Paid amount")}: {textValue(item, "paid_amount")}</span></li>)}</ul></ProductionDialog>}
  </section>;
}
