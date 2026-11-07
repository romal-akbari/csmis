"use client";

import { useState, type FormEvent, type ReactNode } from "react";
import { decimalPattern, moneyMinor, ratePattern } from "@/lib/inventory";
import { productionToday, validateFields, type ProductionField, type ProductionLookups } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionDialog } from "./ProductionDialog";
import { ProductionFields } from "./ProductionFields";
import { useProductionCommand } from "./useProductionCommand";
import styles from "./production.module.css";

export function ProductionCommandDialog({ title, path, fields, initial, lookups, locale, onClose, onSaved, help, transform, children, method = "POST" }: {
  title: string; path: string; fields: ProductionField[] | ((values: Record<string, string>) => ProductionField[]);
  initial: Record<string, string>; lookups: ProductionLookups; locale: string; onClose: () => void; onSaved: () => void;
  help?: string; transform?: (values: Record<string, string>) => Record<string, unknown>; children?: ReactNode; method?: string;
}) {
  const [values, setValues] = useState(initial);
  const command = useProductionCommand(onSaved);
  const t = (text: string) => productionText(locale, text);
  const activeFields = typeof fields === "function" ? fields(values) : fields;
  async function submit(event: FormEvent) {
    event.preventDefault();
    const errors = validateFields(activeFields, values, productionToday(lookups));
    if (values.code && !/^[A-Z0-9_-]+$/.test(values.code)) errors.code = "Use uppercase letters, numbers, - or _.";
    for (const key of ["reason", "acknowledgement", "recovery_terms"]) {
      if (activeFields.some((field) => field.key === key) && (values[key]?.trim().length ?? 0) < 3) errors[key] = "Use at least 3 characters.";
    }
    for (const key of ["rate_to_base", "units_per_payment_unit"]) {
      if (activeFields.some((field) => field.key === key) && !ratePattern.test(values[key] ?? "")) errors[key] = "Enter a positive exchange rate.";
    }
    const account = lookups.accounts.find((row) => String(row.id) === values.account_id);
    const currency = lookups.currencies.find((row) => String(row.id) === String(account?.currency_id ?? values.currency_id));
    for (const key of ["amount", "unit_cost"]) {
      if (!activeFields.some((field) => field.key === key)) continue;
      if (!decimalPattern.test(values[key] ?? "") || !/[1-9]/.test(values[key] ?? "")) errors[key] = "Enter a positive amount.";
      if (Number(currency?.decimal_places) === 0 && /[1-9]/.test((values[key] ?? "").split(".")[1] ?? "")) errors[key] = "Amount exceeds the currency precision.";
    }
    if (lookups.company?.base_currency_id && Number(account?.currency_id ?? values.currency_id) === lookups.company.base_currency_id && values.rate_to_base && !/^1(?:\.0{1,8})?$/.test(values.rate_to_base)) errors.rate_to_base = "Base-currency entries must use a rate of 1.";
    if (account && !/\/(recover|return)$/.test(path) && decimalPattern.test(values.amount ?? "") && decimalPattern.test(String(account.balance ?? "")) && moneyMinor(values.amount) > moneyMinor(String(account.balance))) errors.amount = "Insufficient account balance.";
    command.setErrors(errors);
    if (Object.keys(errors).length) return;
    const payload = Object.fromEntries(activeFields.filter((field) => values[field.key]?.trim() || field.optional).map((field) => [field.key,
      !values[field.key]?.trim() ? null : field.key.endsWith("_id") || field.key === "quantity" && field.type === "integer" ? Number(values[field.key]) : values[field.key].trim()]));
    await command.save(path, transform ? transform(values) : payload, method);
  }
  return <ProductionDialog title={title} locale={locale} busy={command.busy} dirty={JSON.stringify(values) !== JSON.stringify(initial)} onClose={onClose}>
    {help && <p className={styles.notice}>{t(help)}</p>}{children}
    <form noValidate onSubmit={submit} aria-busy={command.busy}>
      {command.banner && <p className={styles.error} role="alert">{t(command.banner)}</p>}
      <fieldset disabled={command.busy}><ProductionFields fields={activeFields} values={values} errors={command.errors} locale={locale} onChange={(key, value) => { setValues((old) => ({ ...old, [key]: value })); command.setErrors({}); }} /></fieldset>
      <footer><button type="submit" className={styles.primary} disabled={command.busy}>{t(command.busy ? "Saving..." : title)}</button></footer>
    </form>
  </ProductionDialog>;
}
