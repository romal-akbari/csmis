"use client";

import { useState, type FormEvent } from "react";
import { moneyMinor, decimalPattern } from "@/lib/inventory";
import { productionToday, type ProductionLookups, type ProductionRecord } from "@/lib/manager-production";
import { validateWorkflowPayment } from "@/lib/production-workflow";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionDialog } from "./ProductionDialog";
import { WorkflowPaymentFields, paymentInitial } from "./WorkflowPaymentFields";
import { useProductionCommand } from "./useProductionCommand";
import styles from "./production.module.css";

export function ProductionSettleEditor({ order, lookups, locale, onClose, onSaved, supply = false, receiptKind }: { order: ProductionRecord; supply?: boolean; receiptKind?: string; lookups: ProductionLookups; locale: string; onClose: () => void; onSaved: () => void }) {
  const [values, setValues] = useState<Record<string, string>>(() => paymentInitial(lookups, productionToday(lookups), `Payment for ${supply ? order.purchase_no : order.order_no}`, String(order.balance_due)));
  const command = useProductionCommand(onSaved); const t = (text: string) => productionText(locale, text);
  async function submit(event: FormEvent) {
    event.preventDefault();
    const errors = validateWorkflowPayment(values, lookups, Number(order.currency_id), productionToday(lookups), String(supply ? order.purchased_on : order.ordered_on));
    const account = lookups.accounts.find((row) => String(row.id) === values.account_id);
    if (account?.currency_id === order.currency_id && decimalPattern.test(values.amount) && moneyMinor(values.amount) > moneyMinor(String(order.balance_due))) errors.amount = supply ? "Payment cannot exceed the purchase balance." : "Payment cannot exceed the remaining earned balance.";
    command.setErrors(errors); if (Object.keys(errors).length) return;
    const path = receiptKind === "material-receipt" ? `/business/material-receipts/${order.id}/pay`
      : receiptKind === "tool-receipt" ? `/production/tool-receipts/${order.id}/pay`
        : supply ? `/production/supply-purchases/${order.id}/pay` : `/production/orders/${order.id}/settle`;
    await command.save(path, { ...values, account_id: Number(values.account_id) });
  }
  return <ProductionDialog title="Pay remaining" locale={locale} busy={command.busy} onClose={onClose}>
    <p className={styles.notice}>{t("Remaining")}: <strong>{String(order.balance_due)} {String(order.currency_code)}</strong></p>
    <form noValidate onSubmit={submit}>{command.banner && <p className={styles.error} role="alert">{t(command.banner)}</p>}<fieldset disabled={command.busy}>
      <WorkflowPaymentFields values={values} errors={command.errors} lookups={lookups} locale={locale} onChange={(key, value) => setValues((old) => ({ ...old, [key]: value }))} />
    </fieldset><footer><button className={styles.primary} disabled={command.busy}>{t("Pay remaining")}</button></footer></form>
  </ProductionDialog>;
}
