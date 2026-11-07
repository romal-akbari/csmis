"use client";

import { useRef, useState, type FormEvent } from "react";
import { FiPlus, FiX } from "react-icons/fi";
import { apiFetch, type Profile } from "@/lib/api";
import { RecordEditor } from "@/components/foundation/RecordEditor";
import type { Resource } from "@/lib/foundation";
import { defaultsFor, respectsCurrencyPrecision, supplyTotal, validateWorkflowPayment } from "@/lib/production-workflow";
import { decimalPattern, moneyMinor, ratePattern } from "@/lib/inventory";
import { options, positiveDecimal, productionToday, selectOptions, validateFields, type ProductionLookups, type ProductionOption } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionDialog } from "./ProductionDialog";
import { ProductionFields } from "./ProductionFields";
import { WorkflowPaymentFields, paymentInitial } from "./WorkflowPaymentFields";
import { useProductionCommand } from "./useProductionCommand";
import styles from "./production.module.css";

type Line = { kind: string; item_id: string; quantity: string; unit_cost: string };
const empty = (): Line => ({ kind: "material", item_id: "", quantity: "", unit_cost: "" });
export function SupplyPurchaseEditor({ lookups, locale, profile, onClose, onSaved }: { lookups: ProductionLookups; locale: string; profile: Profile; onClose: () => void; onSaved: () => void }) {
  const [local, setLocal] = useState(lookups);
  const branch = lookups.branches.length === 1 ? String(lookups.branches[0].id) : "";
  const [values, setValues] = useState<Record<string, string>>({ branch_id: branch, location_id: String(defaultsFor(lookups, branch)?.materials_location_id ?? ""), supplier_id: "", currency_id: String(lookups.company?.base_currency_id ?? ""), rate_to_base: "1", purchased_on: productionToday(lookups) });
  const [lines, setLines] = useState<Line[]>([empty()]);
  const [payNow, setPayNow] = useState(false);
  const [payment, setPayment] = useState<Record<string, string>>(() => paymentInitial(lookups, productionToday(lookups), "Payment for materials and tools"));
  const [adding, setAdding] = useState<{ kind: string; index: number } | null>(null);
  const catalogRetry = useRef<{ payload: string; key: string } | null>(null);
  const command = useProductionCommand(onSaved); const t = (text: string) => productionText(locale, text);
  const precision = Number(local.currencies.find((row) => String(row.id) === values.currency_id)?.decimal_places ?? 2);
  const total = supplyTotal(lines, precision);
  const locations = options(local, "locations").filter((row) => String(row.branch_id) === values.branch_id);
  function change(key: string, value: string) {
    setValues((old) => ({ ...old, [key]: value, ...(key === "branch_id" ? { location_id: String(defaultsFor(local, value)?.materials_location_id ?? "") } : {}) })); command.setErrors({});
  }
  function changeLines(next: Line[]) { setLines(next); command.setErrors({}); if (payNow) setPayment((old) => ({ ...old, amount: supplyTotal(next, precision) })); }
  async function submit(event: FormEvent) {
    event.preventDefault();
    const errors = validateFields([{ key: "branch_id", label: "Branch", type: "select", options: selectOptions(local.branches) }, { key: "location_id", label: "Store", type: "select", options: selectOptions(locations) }, { key: "purchased_on", label: "Date", type: "date" }], values, productionToday(local));
    if (!local.currencies.some((row) => String(row.id) === values.currency_id)) errors.currency_id = "Choose a valid option.";
    if (!options(local, "suppliers").some((row) => String(row.id) === values.supplier_id)) errors.supplier_id = "Choose a valid option.";
    if (!ratePattern.test(values.rate_to_base) || !/[1-9]/.test(values.rate_to_base)) errors.rate_to_base = "Enter a positive exchange rate.";
    if (Number(values.currency_id) === local.company?.base_currency_id && !/^1(?:\.0{1,8})?$/.test(values.rate_to_base)) errors.rate_to_base = "Base-currency entries must use a rate of 1.";
    if (!lines.length) errors.items = "Add at least one material or tool.";
    lines.forEach((line, index) => {
      const choices = options(local, line.kind === "tool" ? "tools" : "materials");
      if (!choices.some((row) => String(row.id) === line.item_id)) errors[`items.${index}.item_id`] = "Choose a valid option.";
      if (!positiveDecimal(line.quantity, 3) || line.kind === "tool" && !/^[1-9]\d{0,8}$/.test(line.quantity)) errors[`items.${index}.quantity`] = "Enter a positive quantity.";
      if (positiveDecimal(line.unit_cost, 2) && !respectsCurrencyPrecision(line.unit_cost, precision)) errors[`items.${index}.unit_cost`] = "Amount exceeds the currency precision.";
      if (!positiveDecimal(line.unit_cost, 2)) errors[`items.${index}.unit_cost`] = "Enter a positive amount.";
    });
    if (payNow) Object.assign(errors, validateWorkflowPayment(payment, local, Number(values.currency_id), productionToday(local), values.purchased_on));
    if (payNow && local.accounts.find((row) => String(row.id) === payment.account_id)?.currency_id === Number(values.currency_id) && decimalPattern.test(payment.amount) && moneyMinor(payment.amount) > moneyMinor(total)) errors.amount = "Payment cannot exceed the purchase total.";
    command.setErrors(errors); if (Object.keys(errors).length) return;
    await command.save("/production/supply-purchases", { ...values, branch_id: Number(values.branch_id), location_id: Number(values.location_id), supplier_id: values.supplier_id ? Number(values.supplier_id) : null, currency_id: Number(values.currency_id),
      items: lines.map((line) => ({ ...line, item_id: Number(line.item_id) })), ...(payNow ? { payment: { ...payment, account_id: Number(payment.account_id) } } : {}) });
  }
  if (adding) {
    const material = adding.kind === "material";
    const resource: Resource = { key: material ? "materials" : "tools", label: material ? "Materials" : "Tools", permission: "materials", fields: [
      { key: "code", label: "Code", max: 40 }, { key: "name", label: "Name", required: true, max: 150 },
      ...(material ? [{ key: "unit", label: "Unit", kind: "select" as const, options: ["kg", "g", "m", "unit"], required: true }] : [{ key: "status", label: "Status", kind: "select" as const, options: ["active", "inactive"], required: true }]),
    ] };
    return <RecordEditor resource={resource} lookups={{}} locale={locale} onClose={() => setAdding(null)} onSave={async (payload) => {
      const body = JSON.stringify(payload);
      if (catalogRetry.current?.payload !== body) catalogRetry.current = { payload: body, key: crypto.randomUUID() };
      const result = await apiFetch<{ data: ProductionOption }>(material ? "/business/materials" : "/production/tools", { method: "POST", body, headers: { "Idempotency-Key": catalogRetry.current.key } });
      const item = { ...result.data, name: String(payload.name), ...(material ? { unit: payload.unit } : {}) };
      setLocal((old) => ({ ...old, [material ? "materials" : "tools"]: [...options(old, material ? "materials" : "tools"), item] }));
      setLines((old) => old.map((line, index) => index === adding.index ? { ...line, item_id: String(item.id) } : line)); setAdding(null);
    }} />;
  }
  return <ProductionDialog title="Purchase and receive supplies" locale={locale} busy={command.busy} dirty={lines.some((row) => row.item_id || row.quantity)} onClose={onClose}>
    <form noValidate onSubmit={submit}>{command.banner && <p className={styles.error} role="alert">{t(command.banner)}</p>}<fieldset disabled={command.busy}>
      <ProductionFields locale={locale} values={values} errors={command.errors} onChange={change} fields={[
        { key: "branch_id", label: "Branch", type: "select", options: selectOptions(local.branches) }, { key: "location_id", label: "Receive into store", type: "select", options: selectOptions(locations) },
        { key: "supplier_id", label: "Supplier", type: "select", options: selectOptions(options(local, "suppliers")) },
      ]} />
      {lines.map((line, index) => <section className={styles.line} key={index}><div className={styles.lineHeader}><strong>{t("Item")} {index + 1}</strong><button type="button" className={styles.iconButton} aria-label={`${t("Remove")} ${index + 1}`} disabled={lines.length === 1} onClick={() => changeLines(lines.filter((_, i) => i !== index))}><FiX aria-hidden="true" /></button></div>
        <ProductionFields locale={locale} values={line} errors={Object.fromEntries(Object.entries(command.errors).filter(([key]) => key.startsWith(`items.${index}.`)).map(([key, value]) => [key.slice(`items.${index}.`.length), value]))}
          onChange={(key, value) => changeLines(lines.map((item, i) => i === index ? { ...item, [key]: value, ...(key === "kind" ? { item_id: "" } : {}) } : item))}
          fields={[{ key: "kind", label: "Item type", type: "select", options: [{ value: "material", label: "Material" }, { value: "tool", label: "Tool" }] },
            { key: "item_id", label: line.kind === "tool" ? "Tool" : "Material", type: "select", options: selectOptions(options(local, line.kind === "tool" ? "tools" : "materials")) },
            { key: "quantity", label: "Quantity", type: line.kind === "tool" ? "integer" : "decimal" }, { key: "unit_cost", label: "Cost per unit", type: "decimal" }]} />
        {profile.permissions.includes("materials.create") && <button type="button" onClick={() => setAdding({ kind: line.kind, index })}>{t(line.kind === "tool" ? "Add new tool" : "Add new material")}</button>}
      </section>)}
      <button type="button" onClick={() => changeLines([...lines, empty()])} disabled={lines.length >= 50}><FiPlus aria-hidden="true" />{t("Add item")}</button>
      <p className={styles.notice}>{t("Purchase total")}: <strong>{total} {local.currencies.find((row) => String(row.id) === values.currency_id)?.code}</strong></p>
      {profile.permissions.includes("materials.pay") && <label className={styles.checkbox}><input type="checkbox" checked={payNow} onChange={(event) => { setPayNow(event.target.checked); setPayment((old) => ({ ...old, amount: total })); }} />{t("Pay now")}</label>}
      {payNow && <WorkflowPaymentFields values={payment} errors={command.errors} lookups={local} locale={locale} onChange={(key, value) => setPayment((old) => ({ ...old, [key]: value }))} />}
      <details className={styles.advanced} ref={(node) => { if (node && (["purchased_on", "currency_id", "rate_to_base"].some((key) => Boolean(command.errors[key])))) node.open = true; }}><summary>{t("Date and currency")}</summary><ProductionFields locale={locale} values={values} errors={command.errors} onChange={change} fields={[{ key: "purchased_on", label: "Purchase date", type: "date" }, { key: "currency_id", label: "Currency", type: "select", options: selectOptions(local.currencies) }, { key: "rate_to_base", label: "Rate to base currency", type: "decimal" }]} /></details>
    </fieldset><footer><button className={styles.primary} disabled={command.busy}>{t("Purchase and receive supplies")}</button></footer></form>
  </ProductionDialog>;
}
