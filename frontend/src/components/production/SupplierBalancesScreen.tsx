"use client";

import { useEffect, useState } from "react";
import { FiArrowLeft } from "react-icons/fi";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { subscribeBalanceUpdates } from "@/lib/balance-updates";
import { textValue, type ProductionLookups, type ProductionRecord } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { ProductionSettleEditor } from "./ProductionSettleEditor";
import styles from "./production.module.css";
import supplierStyles from "./SupplierBalances.module.css";

type Page = { data: ProductionRecord[]; total: number; last_page: number };
type Statement = { supplier: ProductionRecord; balances: ProductionRecord[]; purchases: Page; payments: ProductionRecord[] };

export function SupplierBalancesScreen({ profile }: { profile: Profile }) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const t = (text: string) => productionText(locale, text);
  const [supplier, setSupplier] = useState<number | null>(null);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [unpaid, setUnpaid] = useState(false);
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [result, setResult] = useState<Page | null>(null);
  const [statement, setStatement] = useState<Statement | null>(null);
  const [lookups, setLookups] = useState<ProductionLookups | null>(null);
  const [error, setError] = useState("");
  const [pay, setPay] = useState<ProductionRecord | null>(null);
  useEffect(() => subscribeBalanceUpdates(() => setRevision((old) => old + 1)), []);
  useEffect(() => {
    const controller = new AbortController();
    const path = supplier ? `/production/suppliers/${supplier}/statement?page=${page}`
      : `/production/supplier-balances?page=${page}&search=${encodeURIComponent(query)}&unpaid=${unpaid ? 1 : 0}`;
    Promise.all([apiFetch<Page | { data: Statement }>(path, { signal: controller.signal }),
      apiFetch<{ data: ProductionLookups }>("/production/lookups", { signal: controller.signal })])
      .then(([data, choices]) => {
        if (controller.signal.aborted) return;
        if (supplier) setStatement((data as { data: Statement }).data);
        else setResult(data as Page);
        setLookups(choices.data); setError("");
      }).catch((failure) => { if (!controller.signal.aborted) setError(isApiFailure(failure) ? failure.message : "Could not load records."); });
    return () => controller.abort();
  }, [supplier, page, query, unpaid, revision]);
  function open(id: number | null) { setSupplier(id); setPage(1); setStatement(null); setResult(null); setError(""); }
  const rows = supplier ? statement?.purchases : result;
  return <section className={styles.screen} dir={locale === "fa" ? "rtl" : "ltr"}>
    <header className={styles.heading}><div>
      {supplier && <button type="button" onClick={() => open(null)}><FiArrowLeft aria-hidden="true" />{t("Back to suppliers")}</button>}
      <h1>{supplier ? textValue(statement?.supplier, "name") || t("Supplier statement") : t("Supplier balances")}</h1>
      <p className={styles.muted}>{t("Materials and tools purchases, payments and remaining balances. Currencies stay separate.")}</p>
    </div></header>
    {error && <p className={styles.error} role="alert">{t(error)}</p>}
    {!supplier && <form className={styles.search} onSubmit={(event) => { event.preventDefault(); setQuery(search.trim()); setPage(1); setResult(null); }}>
      <input aria-label={t("Search suppliers")} maxLength={100} value={search} onChange={(event) => setSearch(event.target.value)} /><button>{t("Search")}</button>
      <label className={styles.checkbox}><input type="checkbox" checked={unpaid} onChange={(event) => { setUnpaid(event.target.checked); setPage(1); setResult(null); }} />{t("Unpaid only")}</label>
    </form>}
    {statement && <div className={styles.metrics}>{statement.balances.map((balance) => <div className={styles.metric} key={textValue(balance, "currency")}>{t("Unpaid balance")} · {textValue(balance, "currency")}<strong>{textValue(balance, "owed")}</strong><span className={styles.sub}>{t("Paid amount")}: {textValue(balance, "paid_amount")}</span></div>)}</div>}
    <div className={`${styles.table} ${supplierStyles.table}`}><table><thead><tr><th>{t(supplier ? "Reference" : "Supplier")}</th><th>{t("Purchase total")}</th><th>{t("Unpaid balance")}</th><th>{t("Actions")}</th></tr></thead><tbody>
      {rows?.data.map((row) => {
        const canPay = profile.permissions.includes("materials.pay") && Number(row.remaining_amount) > 0;
        return <tr key={supplier ? `${row.document_kind}-${row.document_id}` : `${row.supplier_id}-${row.currency_id}`}>
          <td data-label={t(supplier ? "Reference" : "Supplier")}><div><strong>{textValue(row, supplier ? "purchase_no" : "name")}</strong><span className={styles.sub}>{textValue(row, "currency_code")}</span>{supplier && <span className={styles.sub}>{textValue(row, "purchased_on")} · {textValue(row, "location_name")}</span>}</div></td>
          <td data-label={t("Purchase total")}><div>{textValue(row, "total_amount")}<span className={styles.sub}>{t("Paid amount")}: {textValue(row, "paid_amount")}</span></div></td>
          <td data-label={t("Unpaid balance")}><div>{textValue(row, "remaining_amount")}</div></td>
          <td className={supplierStyles.actionsCell} data-empty={Boolean(supplier && !canPay)} data-label={t("Actions")}><div>{supplier ? canPay && <button type="button" disabled={!lookups} onClick={() => setPay({ ...row, id: Number(row.document_id), balance_due: row.remaining_amount })}>{t("Pay remaining")}</button>
            : <button type="button" onClick={() => open(Number(row.supplier_id))}>{t("View statement")}</button>}</div></td>
        </tr>;
      })}
      {!rows?.data.length && <tr><td colSpan={4}>{t(rows ? "No records yet." : "Loading...")}</td></tr>}
    </tbody></table></div>
    <div className={styles.pager}><span>{page}/{rows?.last_page ?? 1} · {rows?.total ?? 0}</span><button type="button" disabled={page <= 1} onClick={() => setPage((old) => old - 1)}>{t("Previous")}</button><button type="button" disabled={!rows || page >= rows.last_page} onClick={() => setPage((old) => old + 1)}>{t("Next")}</button></div>
    {statement && <section className={styles.section}><h2>{t("Latest supplier payments")}</h2><ul className={styles.history}>{statement.payments.map((payment) => <li key={payment.id}><strong>{textValue(payment, "amount")} {textValue(payment, "currency_code")}</strong><span className={styles.sub}>{textValue(payment, "transaction_date")} · {textValue(payment, "account_name")} · {textValue(payment, "description")}{Number(payment.reversed) ? ` · ${t("Reversed")}` : ""}</span></li>)}</ul>{!statement.payments.length && <p className={styles.muted}>{t("No visible payments yet.")}</p>}</section>}
    {pay && lookups && <ProductionSettleEditor supply receiptKind={String(pay.document_kind)} order={pay} lookups={lookups} locale={locale} onClose={() => setPay(null)} onSaved={() => { setPay(null); setRevision((old) => old + 1); }} />}
  </section>;
}
