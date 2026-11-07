"use client";

import { useState, type FormEvent } from "react";
import { options, productionToday, selectOptions, textValue, validateFields, positiveDecimal, type ProductionLookups, type ProductionRecord } from "@/lib/manager-production";
import { respectsCurrencyPrecision, validateWorkflowPayment, type WorkflowContext } from "@/lib/production-workflow";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionDialog } from "./ProductionDialog";
import { ProductionFields } from "./ProductionFields";
import { WorkflowPaymentFields, paymentInitial } from "./WorkflowPaymentFields";
import { useProductionCommand } from "./useProductionCommand";
import styles from "./production.module.css";

export function ProductionAcceptEditor({ order, context, lookups, locale, canBill, canPay, canComplete, onClose, onSaved }: {
  order: ProductionRecord; context: WorkflowContext; lookups: ProductionLookups; locale: string; canBill: boolean; canPay: boolean; canComplete: boolean; onClose: () => void; onSaved: () => void;
}) {
  const visits = context.washing_items.filter((row) => canComplete || row.stock_status === "washing_completed");
  const facilities = [...new Map(visits.map((row) => [String(row.facility_id), { id: Number(row.facility_id), name: String(row.facility_name) }])).values()];
  const initialFacility = facilities.length ? String(facilities[0].id) : "";
  const locations = options(lookups, "locations").filter((row) => Number(row.branch_id) === Number(order.branch_id));
  const [values, setValues] = useState<Record<string, string>>({ facility_id: initialFacility, location_id: String(context.defaults?.finished_location_id ?? (locations.length === 1 ? locations[0].id : "")), returned_on: productionToday(lookups), washing_amount: "", invoice_no: "" });
  const [selected, setSelected] = useState<number[]>(visits.filter((row) => String(row.facility_id) === initialFacility).map((row) => Number(row.washing_order_item_id)));
  const [confirmed, setConfirmed] = useState(false);
  const [later, setLater] = useState(!canBill);
  const [payNow, setPayNow] = useState(false);
  const [payment, setPayment] = useState<Record<string, string>>(() => paymentInitial(lookups, productionToday(lookups), `Washing payment for ${order.order_no}`));
  const command = useProductionCommand(onSaved); const t = (text: string) => productionText(locale, text);
  const items = visits.filter((row) => selected.includes(Number(row.washing_order_item_id)));
  const unbilled = items.some((row) => !Number(row.billed));
  function change(key: string, value: string) {
    setValues((old) => ({ ...old, [key]: value })); command.setErrors({});
    if (key === "facility_id") setSelected(visits.filter((row) => String(row.facility_id) === value).map((row) => Number(row.washing_order_item_id)));
    if (key === "washing_amount") setPayment((old) => ({ ...old, amount: value }));
    if (key === "returned_on") setPayment((old) => ({ ...old, payment_on: value }));
  }
  async function submit(event: FormEvent) {
    event.preventDefault();
    const errors = validateFields([{ key: "location_id", label: "Finished carpet store", type: "select", options: selectOptions(locations) }, { key: "returned_on", label: "Received date", type: "date" }], values, productionToday(lookups));
    if (!items.length) errors.items = "Select the carpets physically received.";
    if (!confirmed) errors.confirmed_received = "Confirm that the washed carpets have physically arrived.";
    if (unbilled && !later && !positiveDecimal(values.washing_amount, 2)) errors.washing_amount = "Enter the actual washing charge.";
    if (!respectsCurrencyPrecision(values.washing_amount, Number(lookups.currencies.find((row) => row.id === order.currency_id)?.decimal_places ?? 2))) errors.washing_amount = "Amount exceeds the currency precision.";
    if (payNow && unbilled && !later) Object.assign(errors, validateWorkflowPayment(payment, lookups, Number(order.currency_id), productionToday(lookups), values.returned_on));
    command.setErrors(errors); if (Object.keys(errors).length) return;
    await command.save(`/production/orders/${order.id}/accept`, { returned_on: values.returned_on, location_id: Number(values.location_id), confirmed_received: confirmed,
      record_cost_later: later, ...(unbilled && !later ? { washing_amount: values.washing_amount, ...(values.invoice_no.trim() ? { invoice_no: values.invoice_no.trim() } : {}) } : {}),
      items: items.map((row) => ({ washing_order_item_id: Number(row.washing_order_item_id), expected_sequence: Number(row.expected_sequence) })),
      ...(payNow && unbilled && !later ? { payment: { ...payment, account_id: Number(payment.account_id) } } : {}) });
  }
  return <ProductionDialog title="Receive from washing" locale={locale} busy={command.busy} dirty={confirmed || Boolean(values.washing_amount)} onClose={onClose}>
    <form noValidate onSubmit={submit}>{command.banner && <p className={styles.error} role="alert">{t(command.banner)}</p>}
      <fieldset disabled={command.busy}><ProductionFields locale={locale} fields={[
        ...(facilities.length > 1 ? [{ key: "facility_id", label: "Washing facility", type: "select" as const, options: selectOptions(facilities) }] : []),
        { key: "location_id", label: "To finished carpet store", type: "select", options: selectOptions(locations) },
      ]} values={values} errors={command.errors} onChange={change} />
        <section className={styles.section}><h3>{t("Carpets received")}</h3>{visits.filter((row) => String(row.facility_id) === values.facility_id).map((row) => <label className={styles.checkbox} key={Number(row.washing_order_item_id)}>
          <input type="checkbox" checked={selected.includes(Number(row.washing_order_item_id))} onChange={(event) => setSelected((old) => event.target.checked ? [...old, Number(row.washing_order_item_id)] : old.filter((id) => id !== Number(row.washing_order_item_id)))} />
          {textValue(row, "carpet_code")} · {textValue(row, "facility_name")}
        </label>)}{!visits.length && <p className={styles.notice}>{t("No carpets are ready to receive with your current permissions.")}</p>}</section>
        {unbilled && canBill && !later && <ProductionFields locale={locale} fields={[{ key: "washing_amount", label: "Total washing charge", type: "decimal" }]} values={values} errors={command.errors} onChange={change} />}
        {unbilled && !later && canPay && <label className={styles.checkbox}><input type="checkbox" checked={payNow} onChange={(event) => setPayNow(event.target.checked)} />{t("Pay washing charge now")}</label>}
        {payNow && unbilled && !later && <WorkflowPaymentFields values={payment} errors={command.errors} lookups={lookups} locale={locale} onChange={(key, value) => setPayment((old) => ({ ...old, [key]: value }))} />}
        <label className={styles.checkbox}><input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />{t("I confirm these carpets are washed and physically received.")}</label>
        {command.errors.confirmed_received && <p className={styles.error} role="alert">{t(command.errors.confirmed_received)}</p>}
        {command.errors.items && <p className={styles.error} role="alert">{t(command.errors.items)}</p>}
        <details className={styles.advanced} ref={(node) => { if (node && (Boolean(command.errors.returned_on || command.errors.invoice_no))) node.open = true; }}><summary>{t("Date and bill options")}</summary>
          <ProductionFields locale={locale} fields={[{ key: "returned_on", label: "Received date", type: "date" }, { key: "invoice_no", label: "Invoice number (optional)", optional: true, maxLength: 80 }]} values={values} errors={command.errors} onChange={change} />
          {canBill && unbilled && <label className={styles.checkbox}><input type="checkbox" checked={later} onChange={(event) => { setLater(event.target.checked); setPayNow(false); }} />{t("Record washing cost later")}</label>}
          {later && unbilled && <p className={styles.notice}>{t("Record the washing bill before selling these carpets.")}</p>}
        </details>
      </fieldset><footer><button className={styles.primary} disabled={command.busy || !visits.length}>{t("Receive from washing")}</button></footer>
    </form>
  </ProductionDialog>;
}
