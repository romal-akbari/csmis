"use client";

import { useRef, useState, type FormEvent } from "react";
import { FiX } from "react-icons/fi";
import { apiFetch, isApiFailure } from "@/lib/api";
import { inventoryToday, formatMinor, moneyMinor, decimalPattern } from "@/lib/inventory";
import { ACTION_TITLES, BUSINESS_PAGES, businessFields, choices, paymentFields, validateBusiness, value,
  type BusinessField, type BusinessLine, type BusinessLookups, type BusinessResource, type BusinessRow } from "@/lib/business";
import { salesStockTotal, validateSalesStock, type SalesStockGroup, type SalesStockLine } from "@/lib/sales-stock";
import { businessText } from "@/lib/business-i18n";
import { BusinessDialog } from "./BusinessDialog";
import { SalesStockPicker } from "./SalesStockPicker";
import styles from "./business.module.css";

export function BusinessEditor({ label, resource, action, row, lookups, locale, canPay, onSave, onClose }: {
  label: string; resource: BusinessResource; action: string; row?: BusinessRow; lookups: BusinessLookups; locale: string; canPay: boolean;
  onSave: (data: Record<string, unknown>, key: string) => Promise<void>; onClose: () => void;
}) {
  const today = inventoryToday(lookups.company.timezone);
  const initial = { branch_id: value(row, "branch_id"), seller_type: "weaver", currency_id: value(row, "currency_id") || String(lookups.company.base_currency_id ?? ""), rate_to_base: "1", units_per_payment_unit: "1",
    payment_type: resource === "partnerships" ? "support" : "labor", movement_type: "issue", unit: "kg",
    purchase_on: today, expense_on: today, received_on: today, ordered_on: today, sold_on: today, due_on: today,
    payment_on: today, moved_on: today, progress_on: today, earned_on: today, contributed_on: today, settled_on: today, voided_on: today };
  const [values, setValues] = useState<Record<string, string>>(initial);
  const [payment, setPayment] = useState<Record<string, string>>({ rate_to_base: "1", units_per_payment_unit: "1", payment_on: today, reason: resource === "sales" ? "Customer payment" : "Initial payment" });
  const [lines, setLines] = useState<BusinessLine[]>([]);
  const [salesLines, setSalesLines] = useState<SalesStockLine[]>([]);
  const [stockGroups, setStockGroups] = useState<SalesStockGroup[]>(lookups.stock_groups ?? []);
  const [refreshingStock, setRefreshingStock] = useState(false);
  const [search, setSearch] = useState("");
  const [payNow, setPayNow] = useState(false);
  const [busy, setBusy] = useState(false);
  const [banner, setBanner] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const key = useRef<{ payload: string; key: string } | null>(null);
  const submitting = useRef(false);
  const t = (s: string) => businessText(locale, s);
  const fields = businessFields(resource, action, values);
  const title = action === "create" ? BUSINESS_PAGES[label].create! : ACTION_TITLES[action];
  const salesCreate = action === "create" && resource === "sales";
  const hasLines = action === "create" && ["purchases", "production", "partnerships"].includes(resource);
  const upfront = action === "create" && ["purchases", "expenses", "material-receipts", "sales"].includes(resource);
  const production = ["production", "partnerships"].includes(resource);
  const recovering = resource === "partnerships" && action === "settlement-pay"
    && String((row?.settlement as Record<string, unknown> | undefined)?.balance_due_base ?? "").startsWith("-");
  const eligible = choices(lookups, "carpets").filter((c) => String(c.custody_branch_id) === values.branch_id
    && (production ? c.stock_status === "registered" && !c.production_order_id && !c.acquisition_item_id : ["registered", "returned"].includes(String(c.stock_status)) && !c.production_order_id));
  const branchStockGroups = stockGroups.filter((group) => String(group.branch_id) === values.branch_id);
  const dirty = lines.length > 0 || salesLines.length > 0 || payNow || JSON.stringify(values) !== JSON.stringify(initial);
  const total = lines.reduce((sum, line) => decimalPattern.test(line.unit_price) && decimalPattern.test(line.discount)
    ? sum + moneyMinor(line.unit_price) - (resource === "sales" ? moneyMinor(line.discount) : BigInt(0)) : sum, BigInt(0));
  const salesTotal = salesStockTotal(salesLines);

  async function refreshStock() {
    setRefreshingStock(true); setBanner("");
    try {
      const result = await apiFetch<{ data: BusinessLookups }>("/business/lookups");
      setStockGroups(result.data.stock_groups ?? []);
    } catch (error) {
      setBanner(isApiFailure(error) ? error.message : "Could not reach the server. Your form has been kept.");
    } finally { setRefreshingStock(false); }
  }

  function change(name: string, next: string) {
    setValues((old) => ({ ...old, [name]: next, ...(name === "branch_id" ? { location_id: "", lot_id: "" } : {}), ...(name === "seller_type" ? { weaver_id: "", supplier_id: "" } : {}) }));
    if (name === "branch_id") { setLines([]); setSalesLines([]); }
    setErrors({}); setBanner("");
  }
  function field(f: BusinessField, isPayment = false) {
    const state = isPayment ? payment : values;
    const errorKey = isPayment ? `payment.${f.key}` : f.key;
    const id = `business-${isPayment ? "payment-" : ""}${f.key}`;
    const branch = values.branch_id || value(row, "branch_id");
    let options = f.lookup ? choices(lookups, f.lookup) : [];
    if (f.lookup === "locations" || f.lookup === "lots") options = options.filter((o) => String(o.branch_id) === branch);
    const update = (next: string) => { if (isPayment) { setPayment((old) => ({ ...old, [f.key]: next })); setErrors({}); setBanner(""); } else change(f.key, next); };
    return <div key={id} className={`${styles.field} ${f.type === "textarea" || (f.max ?? 0) > 300 ? styles.wide : ""}`}>
      <label htmlFor={id}>{t(f.label)}{!f.optional && <span aria-hidden="true"> *</span>}</label>
      {f.type === "select" ? <select id={id} value={state[f.key] ?? ""} aria-invalid={!!errors[errorKey]} onChange={(e) => update(e.target.value)}>
        <option value="">{t("Choose an option")}</option>
        {f.options?.map((o) => <option key={o} value={o}>{t(o)}</option>)}
        {options.map((o) => <option key={o.id} value={o.id}>{o.lot_no ? `${o.lot_no} · ` : ""}{o.name || o.code}{f.lookup === "accounts" ? ` · ${choices(lookups, "currencies").find((c) => c.id === o.currency_id)?.code ?? ""}${o.balance != null ? ` · ${o.balance}` : ""}` : ""}</option>)}
      </select> : f.type === "textarea" || (f.max ?? 0) > 300 ? <textarea id={id} maxLength={f.max} value={state[f.key] ?? ""} aria-invalid={!!errors[errorKey]} onChange={(e) => update(e.target.value)} />
        : <input id={id} placeholder={f.key === "code" ? t("Generated automatically when saved") : undefined} type={f.type === "date" ? "date" : "text"} inputMode={["money", "rate", "percent", "quantity"].includes(f.type ?? "") ? "decimal" : undefined}
          max={f.type === "date" && f.key !== "due_on" ? today : undefined} maxLength={f.max ?? 40} value={state[f.key] ?? ""} aria-invalid={!!errors[errorKey]} onChange={(e) => update(e.target.value)} />}
      {errors[errorKey] && <small role="alert">{t(errors[errorKey])}</small>}
    </div>;
  }
  async function submit(event: FormEvent) {
    event.preventDefault();
    if (submitting.current) return;
    const next = validateBusiness(fields, values, lines, resource, action, lookups, recovering ? "in" : undefined, salesCreate);
    if (salesCreate) {
      const currency = choices(lookups, "currencies").find((item) => String(item.id) === values.currency_id);
      Object.assign(next, validateSalesStock(salesLines, branchStockGroups, Number(currency?.decimal_places ?? 2)));
    }
    if (payNow) Object.entries(validateBusiness(paymentFields, payment, [], resource, "payment", lookups)).forEach(([k, v]) => next[`payment.${k}`] = v);
    setErrors(next); setBanner("");
    if (Object.keys(next).length) return;
    const payload: Record<string, unknown> = Object.fromEntries(fields.filter((f) => values[f.key]?.trim()).map((f) => [f.key, f.lookup ? Number(values[f.key]) : values[f.key].trim()]));
    if (hasLines) payload.items = lines.map((l) => production ? { carpet_id: l.carpet_id, cost_weight: l.cost_weight } : { carpet_id: l.carpet_id, unit_price: l.unit_price });
    if (salesCreate) payload.stock_groups = salesLines.map(({ stock_key, quantity, unit_price, discount }) => ({ stock_key, quantity: Number(quantity), unit_price, discount }));
    if (action === "create" && production) payload.model = resource === "partnerships" ? "partnership" : "company_funded";
    if (payNow) payload.payment = { ...payment, account_id: Number(payment.account_id) };
    const serialized = JSON.stringify(payload);
    if (key.current?.payload !== serialized) key.current = { payload: serialized, key: crypto.randomUUID() };
    submitting.current = true; setBusy(true);
    try { await onSave(payload, key.current.key); }
    catch (error) { if (isApiFailure(error)) { setErrors(error.fieldErrors); setBanner(error.message); } else setBanner("Could not reach the server. Your form has been kept."); }
    finally { submitting.current = false; setBusy(false); }
  }
  return <BusinessDialog title={title} locale={locale} busy={busy} dirty={dirty} onClose={onClose}>
    {resource === "purchases" && action === "create" && <p className={styles.notice}>{t("Purchase and receiving are saved together. Only received carpets become available stock.")}</p>}
    {resource === "partnerships" && <p className={styles.notice}>{t("Partner support is an advance against settlement, not an expense.")}</p>}
    {recovering && <p className={styles.notice}>{t("The partner owes the company. This receipt increases the selected account balance.")}</p>}
    {resource === "partnerships" && action === "create" && values.share_basis === "gross" && <p className={styles.notice}>{t("Proceeds sharing leaves costs with the party that contributed them. Loss percentages apply to the cost-recovery model.")}</p>}
    {row && <p className={styles.muted}>{value(row, "purchase_no") || value(row, "expense_no") || value(row, "order_no") || value(row, "sale_no") || value(row, "lot_no")} · {value(row, "currency_code")}{row.remaining_amount != null ? ` · ${t("Unpaid balance")}: ${row.remaining_amount}` : ""}</p>}
    <form noValidate onSubmit={submit} aria-busy={busy}>
      {banner && <p className={styles.error} role="alert">{t(banner)}</p>}
      <fieldset disabled={busy}><div className={styles.grid}>{fields.map((f) => field(f))}</div>
        {salesCreate && <><SalesStockPicker groups={branchStockGroups} lines={salesLines} errors={errors} locale={locale} refreshing={refreshingStock} branchSelected={Boolean(values.branch_id)}
          onRefresh={refreshStock} onChange={(next) => { setSalesLines(next); setErrors({}); setBanner(""); }} />
          <p><strong>{t("Total")}: {formatMinor(salesTotal)} {choices(lookups, "currencies").find((c) => String(c.id) === values.currency_id)?.code}</strong></p>
        </>}
        {hasLines && <section className={styles.picker}>
          <h3>{t("Select carpets")}</h3>
          {production && <p className={styles.muted}>{t("Equal weights split production cost equally; use different weights for different carpet sizes or agreed cost shares.")}</p>}
          <input type="search" aria-label={t("Find a carpet")} placeholder={t("Find a carpet")} value={search} onChange={(e) => setSearch(e.target.value)} />
          <div className={styles.options}>{eligible.filter((c) => `${c.carpet_code} ${c.name}`.toLowerCase().includes(search.toLowerCase())).map((c) => <label key={c.id} className={styles.option}>
            <input type="checkbox" checked={lines.some((l) => l.carpet_id === c.id)} disabled={!lines.some((l) => l.carpet_id === c.id) && lines.length >= 50}
              onChange={(e) => { setErrors({}); setLines((old) => e.target.checked ? [...old, { carpet_id: c.id, expected_sequence: Number(c.sequence_no), label: `${c.carpet_code} · ${c.name}`, unit_price: "", discount: "0", cost_weight: "1" }] : old.filter((l) => l.carpet_id !== c.id)); }} />
            <span>{String(c.carpet_code)} · {c.name}{!!c.location_name && <small className={styles.sub}>{String(c.location_name)}</small>}</span>
          </label>)}</div>
          {!eligible.length && <p className={styles.muted}>{t("No eligible carpets. Register carpets first, or choose the correct branch.")}</p>}
          {errors.items && <p className={styles.error} role="alert">{t(errors.items)}</p>}
          {!!lines.length && <h3>{t("Selected carpets")} ({lines.length}/50)</h3>}
          {lines.map((line, i) => <div key={line.carpet_id} className={styles.line}><div className={styles.lineHeader}><strong>{line.label}</strong><button type="button" aria-label={`${t("Remove")} ${line.label}`} onClick={() => setLines((old) => old.filter((l) => l.carpet_id !== line.carpet_id))}><FiX /></button></div>
            <div className={styles.grid}>{(production ? ["cost_weight"] : resource === "sales" ? ["unit_price", "discount"] : ["unit_price"]).map((key) => <div key={key} className={styles.field}>
              <label htmlFor={`line-${i}-${key}`}>{t(key === "cost_weight" ? "Cost weight" : key === "discount" ? "Discount" : "Unit price")}</label>
              <input id={`line-${i}-${key}`} inputMode="decimal" value={line[key as keyof BusinessLine]} aria-invalid={!!errors[`items.${i}.${key}`]}
                onChange={(e) => setLines((old) => old.map((l) => l.carpet_id === line.carpet_id ? { ...l, [key]: e.target.value } : l))} />
              {errors[`items.${i}.${key}`] && <small role="alert">{t(errors[`items.${i}.${key}`])}</small>}
            </div>)}</div>
          </div>)}
          {!production && <p><strong>{t("Total")}: {formatMinor(total)} {choices(lookups, "currencies").find((c) => String(c.id) === values.currency_id)?.code}</strong></p>}
        </section>}
        {upfront && canPay && <section className={styles.paymentBox}><label className={styles.option}><input type="checkbox" checked={payNow} onChange={(e) => setPayNow(e.target.checked)} />{t(resource === "sales" ? "Collect now" : "Pay now")}</label>
          {payNow ? <><h3>{t("Payment details")}</h3><div className={styles.grid}>{paymentFields.map((f) => field(f, true))}</div></> : <p className={styles.muted}>{t("Pay later")}</p>}
        </section>}
      </fieldset>
      <footer><button type="submit" className={styles.primary} disabled={busy}>{t(busy ? "Saving..." : title)}</button></footer>
    </form>
  </BusinessDialog>;
}
