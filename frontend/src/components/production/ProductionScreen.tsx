"use client";

import { useEffect, useState, type FormEvent } from "react";
import { FiPlus, FiRefreshCw } from "react-icons/fi";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { subscribeBalanceUpdates } from "@/lib/balance-updates";
import { MANAGER_PRODUCTION_PAGES, productionToday, textValue, type ProductionLookups, type ProductionRecord } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { ProductionOrderEditor } from "./ProductionOrderEditor";
import { ProductionOrderDetail } from "./ProductionOrderDetail";
import { ProductionOrderWorkspace } from "./ProductionOrderWorkspace";
import { ProductionSetupEditor } from "./ProductionSetupEditor";
import { ProductionCommandDialog } from "./ProductionCommandDialog";
import { ProductionResourceDetail } from "./ProductionResourceDetail";
import { ManagerStatement } from "./ManagerStatement";
import { assistanceFields, managerFields, receiptFields } from "./production-fields";
import styles from "./production.module.css";

type Result = { data: ProductionRecord[]; total: number; current_page: number; last_page: number };
const resources: Record<string, { resource: string; create: string; permission: string }> = {
  "Production orders": { resource: "orders", create: "Create production order", permission: "production.create" },
  "Production managers": { resource: "managers", create: "Add manager", permission: "production.create" },
  Tools: { resource: "tools", create: "Add tool", permission: "materials.create" },
  "Tool receipts": { resource: "tool-receipts", create: "Receive tools", permission: "materials.receive" },
  "Worker assistance": { resource: "assistance", create: "Create assistance", permission: "production.manage" },
};
export function ProductionScreen({ label, profile, disableCreate = false }: { label: string; profile: Profile; disableCreate?: boolean }) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const t = (text: string) => productionText(locale, text);
  const config = resources[label];
  const permitted = profile.permissions.includes(MANAGER_PRODUCTION_PAGES[label]);
  const [lookups, setLookups] = useState<ProductionLookups | null>(null);
  const [result, setResult] = useState<Result | null>(null);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [revision, setRevision] = useState(0);
  const [error, setError] = useState("");
  const [flash, setFlash] = useState("");
  const [creating, setCreating] = useState(false);
  const [detail, setDetail] = useState<ProductionRecord | null>(null);
  const [editing, setEditing] = useState<ProductionRecord | null>(null);
  const [setup, setSetup] = useState(false);
  useEffect(() => {
    if (!permitted) return;
    const controller = new AbortController();
    Promise.all([
      apiFetch<{ data: ProductionLookups }>("/production/lookups", { signal: controller.signal }),
      apiFetch<Result>(`/production/${config.resource}?page=${page}&search=${encodeURIComponent(query)}`, { signal: controller.signal }),
    ]).then(([options, rows]) => {
      if (controller.signal.aborted) return;
      setLookups(options.data); setResult(rows); setError("");
      if (page > Math.max(1, rows.last_page)) setPage(Math.max(1, rows.last_page));
    }).catch((failure) => { if (!controller.signal.aborted) setError(isApiFailure(failure) ? failure.message : "Could not load records."); });
    return () => controller.abort();
  }, [config.resource, permitted, page, query, revision]);
  useEffect(() => permitted ? subscribeBalanceUpdates(() => setRevision((old) => old + 1)) : undefined, [permitted]);
  function saved() { setCreating(false); setEditing(null); setRevision((old) => old + 1); setFlash("Saved successfully."); }
  function find(event: FormEvent) { event.preventDefault(); setPage(1); setQuery(search.trim()); setRevision((old) => old + 1); }
  if (!permitted) return <p role="alert">{t("You do not have permission to view this page.")}</p>;
  const today = lookups ? productionToday(lookups) : "";
  const isOrders = config.resource === "orders";
  if (isOrders && lookups && detail) return <ProductionOrderWorkspace id={detail.id} lookups={lookups} locale={locale} profile={profile} onClose={() => setDetail(null)} onSaved={saved} />;
  return <section className={styles.screen} lang={locale} dir={locale === "fa" ? "rtl" : "ltr"} aria-labelledby="production-page-title">
    <header className={styles.heading}><div><h1 id="production-page-title">{t(label)}</h1><p className={styles.muted}>{result?.total ?? 0} {t("records")}</p></div><div className={styles.actions}>
      <button type="button" aria-label={t("Refresh")} onClick={() => setRevision((old) => old + 1)}><FiRefreshCw aria-hidden="true" /></button>
      {isOrders && profile.permissions.includes("production.manage") && <button type="button" disabled={!lookups} onClick={() => setSetup(true)}>{t("Production setup")}</button>}
      {!disableCreate && config.resource !== "tool-receipts" && profile.permissions.includes(config.permission) && <button type="button" className={styles.primary} disabled={!lookups} onClick={() => { setFlash(""); setCreating(true); }}><FiPlus aria-hidden="true" />{t(config.create)}</button>}
    </div></header>
    <>
      {isOrders && <p className={styles.notice}>{t("Orders track designs and quantities. Register finished carpets only when the manager delivers them.")}</p>}
      {error && <p className={styles.error} role="alert">{t(error)}</p>}{flash && <p className={styles.success} role="status">{t(flash)}</p>}
      <form className={styles.search} onSubmit={find}><input aria-label={t("Search records")} placeholder={t("Search records")} value={search} maxLength={100} onChange={(event) => setSearch(event.target.value)} /><button>{t("Search")}</button></form>
      <div className={styles.table}><table><thead><tr><th>{t(isOrders ? "Order reference" : config.resource === "managers" || config.resource === "tools" ? "Name" : "Reference")}</th><th>{t(isOrders ? "Production progress" : config.resource === "managers" || config.resource === "tools" ? "Status" : "Amount")}</th><th className={styles.desktop}>{t("Details")}</th><th>{t("Actions")}</th></tr></thead><tbody>
        {result?.data.map((row) => <tr key={row.id}>
          <td><strong>{textValue(row, "order_no") || textValue(row, "lot_no") || textValue(row, "assistance_no") || textValue(row, "name") || `#${row.id}`}</strong><span className={styles.sub}>{textValue(row, "manager_name") || textValue(row, "beneficiary_name") || textValue(row, "tool_name") || textValue(row, "code")}</span></td>
          <td>{isOrders ? <><span className={styles.badge}>{t(textValue(row, "state"))}</span><span className={styles.sub}>{t("Delivered quantity")}: {textValue(row, "delivered_quantity")}/{textValue(row, "target_quantity")}</span></>
            : row.status ? <span className={styles.badge}>{t(textValue(row, "status"))}</span> : <><strong>{textValue(row, "amount") || textValue(row, "total_cost")} {textValue(row, "currency_code")}</strong><span className={styles.sub}>{textValue(row, "quantity")}{row.quantity != null ? ` · ${t("Quantity")}` : ""}</span></>}</td>
          <td className={styles.desktop}>{isOrders ? <>{t("Balance due")}: {textValue(row, "balance_due")} {textValue(row, "currency_code")}</> : config.resource === "tools" ? <>{t("Warehouse quantity")}: {textValue(row, "warehouse_quantity")}</> : textValue(row, "branch_name") || lookups?.branches.find((branch) => branch.id === row.branch_id)?.name || textValue(row, "beneficiary_name") || textValue(row, "received_on") || "—"}</td>
          <td><div className={styles.actions}>{config.resource !== "tools" && <button type="button" onClick={() => setDetail(row)}>{t(config.resource === "managers" ? "View statement" : "View details")}</button>}
            {["managers", "tools"].includes(config.resource) && profile.permissions.includes(config.resource === "managers" ? "production.manage" : "materials.create") && <button type="button" onClick={() => setEditing(row)}>{t("Edit")}</button>}
          </div></td>
        </tr>)}
        {!result?.data.length && <tr><td colSpan={4}>{t(result ? "No records yet." : "Loading...")}</td></tr>}
      </tbody></table></div>
      <div className={styles.pager}><span>{page} / {result?.last_page ?? 1} · {result?.total ?? 0}</span><button type="button" disabled={page <= 1} onClick={() => setPage((old) => old - 1)}>{t("Previous")}</button><button type="button" disabled={!result || page >= result.last_page} onClick={() => setPage((old) => old + 1)}>{t("Next")}</button></div>
    </>
    {lookups && creating && (isOrders ? <ProductionOrderEditor lookups={lookups} locale={locale} onClose={() => setCreating(false)} onSaved={(result) => { saved(); if (result.data?.id) setDetail({ id: result.data.id }); }} />
      : config.resource === "managers" ? <ProductionCommandDialog title={config.create} path="/production/managers" fields={managerFields(lookups)} initial={{ code: "", name: "", branch_id: "", phone: "", address: "" }} transform={(values) => ({ ...values, branch_id: Number(values.branch_id), status: "active" })} lookups={lookups} locale={locale} onClose={() => setCreating(false)} onSaved={saved} />
        : config.resource === "tools" ? <ProductionCommandDialog title={config.create} path="/production/tools" fields={[{ key: "code", label: "Code", maxLength: 40, optional: true }, { key: "name", label: "Name", maxLength: 150 }]} initial={{ code: "", name: "" }} transform={(values) => ({ ...values, status: "active" })} lookups={lookups} locale={locale} onClose={() => setCreating(false)} onSaved={saved} />
          : config.resource === "tool-receipts" ? <ProductionCommandDialog title={config.create} path="/production/tool-receipts" fields={(values) => receiptFields(lookups, values)} initial={{ tool_id: "", branch_id: "", location_id: "", supplier_id: "", quantity: "", unit_cost: "", currency_id: String(lookups.company?.base_currency_id ?? ""), rate_to_base: "1", received_on: today }} lookups={lookups} locale={locale} onClose={() => setCreating(false)} onSaved={saved} />
            : <ProductionCommandDialog title={config.create} path="/production/assistance" fields={(values) => assistanceFields(lookups, values)} initial={{ branch_id: "", manager_id: "", beneficiary_name: "", beneficiary_phone: "", assistance_type: "", currency_id: String(lookups.company?.base_currency_id ?? ""), rate_to_base: "1", amount: "", assistance_on: today, reason: "", responsible_party: "", recovery_terms: "" }} lookups={lookups} locale={locale} onClose={() => setCreating(false)} onSaved={saved} />)}
    {lookups && detail && (isOrders ? <ProductionOrderDetail id={detail.id} lookups={lookups} locale={locale} profile={profile} onClose={() => setDetail(null)} onSaved={saved} />
      : config.resource === "managers" ? <ManagerStatement manager={detail} lookups={lookups} locale={locale} profile={profile} onClose={() => setDetail(null)} onSaved={saved} />
        : <ProductionResourceDetail resource={config.resource as "assistance" | "tool-receipts"} id={detail.id} lookups={lookups} locale={locale} profile={profile} onClose={() => setDetail(null)} onSaved={saved} />)}
    {lookups && editing && <ProductionCommandDialog title={config.resource === "managers" ? "Edit manager" : "Edit tool"} method="PATCH" path={`/production/${config.resource}/${editing.id}`} locale={locale} lookups={lookups} onClose={() => setEditing(null)} onSaved={saved}
      fields={[...(config.resource === "managers" ? managerFields(lookups) : [{ key: "code", label: "Code", maxLength: 40, optional: true }, { key: "name", label: "Name", maxLength: 150 }]), { key: "status", label: "Status", type: "select", options: [{ value: "active", label: "Active" }, { value: "inactive", label: "Inactive" }] }]}
      initial={{ code: textValue(editing, "code"), name: textValue(editing, "name"), branch_id: textValue(editing, "branch_id"), phone: textValue(editing, "phone"), address: textValue(editing, "address"), status: textValue(editing, "status") }} />}
    {lookups && setup && <ProductionSetupEditor lookups={lookups} locale={locale} profile={profile} onClose={() => setSetup(false)} onSaved={() => { setSetup(false); saved(); }} />}
  </section>;
}
