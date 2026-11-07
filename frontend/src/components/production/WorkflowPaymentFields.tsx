"use client";

import { selectOptions, type ProductionLookups } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionFields } from "./ProductionFields";
import styles from "./production.module.css";

export function paymentInitial(lookups: ProductionLookups, today: string, reason: string, amount = "") {
  return { account_id: lookups.accounts.length === 1 ? String(lookups.accounts[0].id) : "", amount, rate_to_base: "1", units_per_payment_unit: "1", payment_on: today, reason };
}
export function WorkflowPaymentFields({ values, errors, lookups, locale, onChange }: {
  values: Record<string, string>; errors: Record<string, string>; lookups: ProductionLookups; locale: string; onChange: (key: string, value: string) => void;
}) {
  const t = (text: string) => productionText(locale, text);
  return <section className={styles.section}>
    <ProductionFields locale={locale} values={values} errors={errors} onChange={onChange} fields={[
      { key: "account_id", label: "Pay from account", type: "select", options: selectOptions(lookups.accounts) }, { key: "amount", label: "Payment amount", type: "decimal" },
    ]} />
    <details className={styles.advanced} ref={(node) => { if (node && (["payment_on", "rate_to_base", "units_per_payment_unit", "reason"].some((key) => Boolean(errors[key])))) node.open = true; }}><summary>{t("Payment date and currency conversion")}</summary>
      <ProductionFields locale={locale} values={values} errors={errors} onChange={onChange} fields={[
        { key: "payment_on", label: "Payment date", type: "date" }, { key: "rate_to_base", label: "Payment rate to base", type: "decimal" },
        { key: "units_per_payment_unit", label: "Document units per payment unit", type: "decimal" }, { key: "reason", label: "Reason", maxLength: 255 },
      ]} />
    </details>
  </section>;
}
