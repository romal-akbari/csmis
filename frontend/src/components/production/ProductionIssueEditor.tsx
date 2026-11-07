"use client";

import { useState, type FormEvent } from "react";
import { productionToday, options, selectOptions, validateFields, type ProductionLookups, type ProductionRecord } from "@/lib/manager-production";
import { stockChoices, validateStockLines, validateWorkflowPayment, type StockLine, type WorkflowContext } from "@/lib/production-workflow";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionDialog } from "./ProductionDialog";
import { ProductionFields } from "./ProductionFields";
import { ProductionStockLines } from "./ProductionStockLines";
import { WorkflowPaymentFields, paymentInitial } from "./WorkflowPaymentFields";
import { useProductionCommand } from "./useProductionCommand";
import styles from "./production.module.css";

export function ProductionIssueEditor({ order, context, lookups, locale, returning = false, canPay, onClose, onSaved }: {
  order: ProductionRecord; context: WorkflowContext; lookups: ProductionLookups; locale: string; returning?: boolean; canPay: boolean; onClose: () => void; onSaved: () => void;
}) {
  const locations = options(lookups, "locations").filter((item) => Number(item.branch_id) === Number(order.branch_id));
  const [values, setValues] = useState<Record<string, string>>({ location_id: String(context.defaults?.materials_location_id ?? (locations.length === 1 ? locations[0].id : "")), moved_on: productionToday(lookups), reason: `${returning ? "Return supplies for" : "Give supplies for"} ${order.order_no}` });
  const [materials, setMaterials] = useState<StockLine[]>([]);
  const [tools, setTools] = useState<StockLine[]>([]);
  const [advance, setAdvance] = useState(false);
  const [payment, setPayment] = useState<Record<string, string>>(() => paymentInitial(lookups, productionToday(lookups), `Advance for ${order.order_no}`));
  const command = useProductionCommand(onSaved); const t = (text: string) => productionText(locale, text);
  const materialChoices = stockChoices(context, false, returning, values.location_id);
  const toolChoices = stockChoices(context, true, returning, values.location_id);
  const title = returning ? "Return supplies" : "Give to manager";
  async function submit(event: FormEvent) {
    event.preventDefault();
    const errors = { ...validateFields([{ key: "moved_on", label: "Date", type: "date" }, { key: "reason", label: "Reason" }], values, productionToday(lookups)),
      ...Object.fromEntries(Object.entries(validateStockLines(materials, materialChoices, false)).map(([key, value]) => [`materials.${key}`, value])),
      ...Object.fromEntries(Object.entries(validateStockLines(tools, toolChoices, true)).map(([key, value]) => [`tools.${key}`, value])) };
    if (!returning && (materials.length || tools.length) && !values.location_id) errors.location_id = "Choose the materials store.";
    if (!materials.length && !tools.length && !advance) errors.materials = "Enter materials, tools or a production advance.";
    if (advance) {
      Object.assign(errors, validateWorkflowPayment(payment, lookups, Number(order.currency_id), productionToday(lookups), String(order.ordered_on)));
    }
    command.setErrors(errors); if (Object.keys(errors).length) return;
    await command.save(`/production/orders/${order.id}/issue`, { ...values, location_id: values.location_id ? Number(values.location_id) : null, movement_type: returning ? "return" : "issue",
      materials: materials.map((row) => ({ material_id: Number(row.item_id), quantity: row.quantity })), tools: tools.map((row) => ({ tool_id: Number(row.item_id), quantity: Number(row.quantity) })),
      ...(advance ? { advance: { ...payment, account_id: Number(payment.account_id) } } : {}) });
  }
  return <ProductionDialog title={title} locale={locale} busy={command.busy} dirty={Boolean(materials.length || tools.length || advance)} onClose={onClose}>
    <p className={styles.notice}>{t(returning ? "Unused materials and usable tools return to their original store." : "Give materials, reusable tools and an optional advance in one action.")}</p>
    <form noValidate onSubmit={submit}>{command.banner && <p className={styles.error} role="alert">{t(command.banner)}</p>}
      <fieldset disabled={command.busy}>{!returning && <ProductionFields locale={locale} values={values} errors={command.errors} onChange={(key, value) => { setValues((old) => ({ ...old, [key]: value })); setMaterials([]); setTools([]); }} fields={[{ key: "location_id", label: "From store", type: "select", options: selectOptions(locations) }]} />}
        <ProductionStockLines title="Materials" choices={materialChoices} lines={materials} onChange={setMaterials} locale={locale} errors={Object.fromEntries(Object.entries(command.errors).filter(([key]) => key.startsWith("materials.")).map(([key, value]) => [key.slice(10), value]))} />
        <ProductionStockLines title="Tools" tools choices={toolChoices} lines={tools} onChange={setTools} locale={locale} errors={Object.fromEntries(Object.entries(command.errors).filter(([key]) => key.startsWith("tools.")).map(([key, value]) => [key.slice(6), value]))} />
        {command.errors.materials && <p className={styles.error} role="alert">{t(command.errors.materials)}</p>}
        {!returning && canPay && <label className={styles.checkbox}><input type="checkbox" checked={advance} onChange={(event) => setAdvance(event.target.checked)} />{t("Give a production advance now")}</label>}
        {advance && <WorkflowPaymentFields values={payment} errors={command.errors} lookups={lookups} locale={locale} onChange={(key, value) => setPayment((old) => ({ ...old, [key]: value }))} />}
        <details className={styles.advanced}><summary>{t("Date and notes")}</summary><ProductionFields locale={locale} values={values} errors={command.errors} onChange={(key, value) => setValues((old) => ({ ...old, [key]: value }))} fields={[{ key: "moved_on", label: "Movement date", type: "date" }, { key: "reason", label: "Reason", type: "textarea", maxLength: 500 }]} /></details>
      </fieldset><footer><button className={styles.primary} disabled={command.busy}>{t(title)}</button></footer>
    </form>
  </ProductionDialog>;
}
