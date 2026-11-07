"use client";

import { useState, type FormEvent } from "react";
import { productionToday, validateFields, type ProductionLookups, type ProductionRecord } from "@/lib/manager-production";
import { validateSupportAmounts, validateWorkflowPayment } from "@/lib/production-workflow";
import { productionText } from "@/lib/manager-production-i18n";
import { assistanceFields } from "./production-fields";
import { ProductionDialog } from "./ProductionDialog";
import { ProductionFields } from "./ProductionFields";
import { WorkflowPaymentFields, paymentInitial } from "./WorkflowPaymentFields";
import { useProductionCommand } from "./useProductionCommand";
import styles from "./production.module.css";

export function WorkerSupportEditor({ manager, lookups, locale, canPay, onClose, onSaved }: { manager: ProductionRecord; lookups: ProductionLookups; locale: string; canPay: boolean; onClose: () => void; onSaved: () => void }) {
  const today = productionToday(lookups);
  const [values, setValues] = useState<Record<string, string>>({ branch_id: String(manager.branch_id), manager_id: String(manager.id), beneficiary_name: "", beneficiary_phone: "", assistance_type: "advance", currency_id: String(lookups.company?.base_currency_id ?? ""), rate_to_base: "1", amount: "", assistance_on: today, reason: "", responsible_party: "worker", recovery_terms: "" });
  const [payNow, setPayNow] = useState(false);
  const [payment, setPayment] = useState<Record<string, string>>(() => paymentInitial(lookups, today, "Worker support through manager"));
  const [delivered, setDelivered] = useState(false);
  const [ack, setAck] = useState<Record<string, string>>({ amount: "", delivered_on: today, acknowledgement: "" });
  const command = useProductionCommand(onSaved); const t = (text: string) => productionText(locale, text);
  const fields = assistanceFields(lookups, values);
  async function submit(event: FormEvent) {
    event.preventDefault();
    const errors = validateFields(fields, values, today);
    Object.assign(errors, validateSupportAmounts(values.amount, Number(values.currency_id), lookups, payNow ? payment : undefined, delivered && payNow ? ack : undefined));
    if (Number(values.currency_id) === lookups.company?.base_currency_id && !/^1(?:\.0{1,8})?$/.test(values.rate_to_base)) errors.rate_to_base = "Base-currency entries must use a rate of 1.";
    if (values.beneficiary_name.trim().length < 2) errors.beneficiary_name = "Use at least 2 characters.";
    for (const key of values.assistance_type === "advance" ? ["reason", "recovery_terms"] : ["reason"]) if (values[key].trim().length < 3) errors[key] = "Use at least 3 characters.";
    if (payNow) {
      Object.assign(errors, Object.fromEntries(Object.entries(validateWorkflowPayment(payment, lookups, Number(values.currency_id), today, values.assistance_on)).map(([key, value]) => [`payment.${key}`, value])));
    }
    if (delivered && payNow) {
      Object.assign(errors, Object.fromEntries(Object.entries(validateFields([{ key: "amount", label: "Delivered amount", type: "decimal" }, { key: "delivered_on", label: "Acknowledgement date", type: "date" }, { key: "acknowledgement", label: "Acknowledgement", maxLength: 500 }], ack, today)).map(([key, value]) => [`delivery.${key}`, value])));
      if (ack.acknowledgement.trim().length < 3) errors["delivery.acknowledgement"] = "Use at least 3 characters.";
    }
    command.setErrors(errors); if (Object.keys(errors).length) return;
    const payload = { ...values, branch_id: Number(values.branch_id), manager_id: manager.id, currency_id: Number(values.currency_id), ...(values.assistance_type === "bonus" ? { responsible_party: undefined, recovery_terms: undefined } : {}), ...(payNow ? { payment: { ...payment, account_id: Number(payment.account_id) } } : {}), ...(payNow && delivered ? { delivery: ack } : {}) };
    await command.save(`/production/managers/${manager.id}/assistance`, payload);
  }
  return <ProductionDialog title="Worker support" locale={locale} busy={command.busy} onClose={onClose}>
    <p className={styles.notice}>{t("Personal support stays separate from production earnings. Funding reduces cash once; acknowledgement only confirms delivery.")}</p>
    <form noValidate onSubmit={submit}>{command.banner && <p className={styles.error} role="alert">{t(command.banner)}</p>}<fieldset disabled={command.busy}>
      <ProductionFields fields={fields.filter((field) => !["branch_id", "manager_id", "currency_id", "rate_to_base", "assistance_on"].includes(field.key))} values={values} errors={command.errors} locale={locale} onChange={(key, value) => setValues((old) => ({ ...old, [key]: value }))} />
      {canPay && <label className={styles.checkbox}><input type="checkbox" checked={payNow} onChange={(event) => { setPayNow(event.target.checked); setPayment((old) => ({ ...old, amount: values.amount })); }} />{t("Pay now")}</label>}
      {payNow && <><WorkflowPaymentFields values={payment} errors={Object.fromEntries(Object.entries(command.errors).filter(([key]) => key.startsWith("payment.")).map(([key, value]) => [key.slice(8), value]))} lookups={lookups} locale={locale} onChange={(key, value) => setPayment((old) => ({ ...old, [key]: value }))} />
        <label className={styles.checkbox}><input type="checkbox" checked={delivered} onChange={(event) => { setDelivered(event.target.checked); setAck((old) => ({ ...old, amount: values.amount })); }} />{t("The manager has delivered this support to the worker")}</label>
        {delivered && <ProductionFields fields={[{ key: "amount", label: "Delivered amount", type: "decimal" }, { key: "delivered_on", label: "Acknowledgement date", type: "date" }, { key: "acknowledgement", label: "Acknowledgement", maxLength: 500 }]} values={ack} errors={Object.fromEntries(Object.entries(command.errors).filter(([key]) => key.startsWith("delivery.")).map(([key, value]) => [key.slice(9), value]))} locale={locale} onChange={(key, value) => setAck((old) => ({ ...old, [key]: value }))} />}
      </>}
      <details className={styles.advanced} ref={(node) => { if (node && (["currency_id", "rate_to_base", "assistance_on"].some((key) => Boolean(command.errors[key])))) node.open = true; }}><summary>{t("Date and currency")}</summary><ProductionFields fields={fields.filter((field) => ["currency_id", "rate_to_base", "assistance_on"].includes(field.key))} values={values} errors={command.errors} locale={locale} onChange={(key, value) => setValues((old) => ({ ...old, [key]: value }))} /></details>
    </fieldset><footer><button className={styles.primary} disabled={command.busy}>{t("Save worker support")}</button></footer></form>
  </ProductionDialog>;
}
