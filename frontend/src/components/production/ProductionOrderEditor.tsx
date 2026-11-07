"use client";

import { useState, type FormEvent } from "react";
import { FiPlus, FiX } from "react-icons/fi";
import { emptyLine, factorPayload, productionToday, selectOptions, validateFields, validateOrder, type PricingFactor, type ProductionField, type ProductionLine, type ProductionLookups } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { defaultsFor } from "@/lib/production-workflow";
import { ProductionDialog } from "./ProductionDialog";
import { ProductionFields } from "./ProductionFields";
import { PricingFactors } from "./PricingFactors";
import { useProductionCommand } from "./useProductionCommand";
import styles from "./production.module.css";

export function ProductionOrderEditor({ lookups, locale, onClose, onSaved }: {
  lookups: ProductionLookups; locale: string; onClose: () => void; onSaved: (result: { data?: { id: number } }) => void;
}) {
  const today = productionToday(lookups);
  const firstManager = lookups.managers.length === 1 ? lookups.managers[0] : undefined;
  const firstBranch = String(firstManager?.branch_id ?? (lookups.branches.length === 1 ? lookups.branches[0].id : ""));
  const initial = { manager_id: String(firstManager?.id ?? ""), branch_id: firstBranch, currency_id: String(lookups.company?.base_currency_id ?? ""), rate_to_base: "1", ordered_on: today, due_on: today, description: "", payment_trigger: String(defaultsFor(lookups, firstBranch)?.payment_trigger ?? "stock_acceptance") };
  const [values, setValues] = useState<Record<string, string>>(initial);
  const newLine = () => ({ ...emptyLine(), category_id: lookups.categories.length === 1 ? String(lookups.categories[0].id) : "" });
  const [lines, setLines] = useState<ProductionLine[]>([newLine()]);
  const [factors, setFactors] = useState<PricingFactor[]>([]);
  const command = useProductionCommand(onSaved);
  const t = (text: string) => productionText(locale, text);
  const fields: ProductionField[] = [
    { key: "branch_id", label: "Branch", type: "select", options: selectOptions(lookups.branches) },
    { key: "manager_id", label: "Manager", type: "select", options: selectOptions(lookups.managers) },
    { key: "ordered_on", label: "Start date", type: "date" }, { key: "due_on", label: "Expected completion date", type: "date" },
    { key: "currency_id", label: "Currency", type: "select", options: selectOptions(lookups.currencies) }, { key: "rate_to_base", label: "Rate to base currency", type: "decimal" },
    { key: "payment_trigger", label: "When does the manager earn payment?", type: "select", options: [{ value: "completion", label: "On completed delivery to washing" }, { value: "stock_acceptance", label: "After stock acceptance" }] },
    { key: "description", label: "Description", type: "textarea", maxLength: 1000 },
  ];
  function update(key: string, value: string) {
    const manager = key === "manager_id" ? lookups.managers.find((row) => String(row.id) === value) : undefined;
    setValues((old) => ({ ...old, [key]: value, ...(key === "branch_id" ? { manager_id: "" } : {}), ...(manager ? { branch_id: String(manager.branch_id), payment_trigger: String(defaultsFor(lookups, String(manager.branch_id))?.payment_trigger ?? "stock_acceptance") } : {}) }));
    command.setErrors({});
  }
  function updateLine(index: number, key: string, value: string) {
    setLines((old) => old.map((line, lineIndex) => lineIndex === index ? { ...line, [key]: value } : line));
    command.setErrors({});
  }
  async function submit(event: FormEvent) {
    event.preventDefault();
    const submission = { ...values, description: values.description.trim() || `Production: ${lines.map((line) => line.name.trim()).join(", ")}`.slice(0, 1000) };
    const precision = Number(lookups.currencies.find((currency) => String(currency.id) === values.currency_id)?.decimal_places ?? 2);
    const errors = { ...validateFields(fields, submission, today), ...validateOrder(submission, lines, factors, today, precision) };
    if (Number(values.currency_id) === lookups.company?.base_currency_id && !/^1(?:\.0{1,8})?$/.test(values.rate_to_base)) errors.rate_to_base = "Base-currency entries must use a rate of 1.";
    command.setErrors(errors);
    if (Object.keys(errors).length) { document.querySelector<HTMLElement>('dialog [aria-invalid="true"]')?.focus(); return; }
    await command.save("/production/orders", {
      ...submission, manager_id: Number(values.manager_id), branch_id: Number(values.branch_id), currency_id: Number(values.currency_id),
      lines: lines.map((line) => ({ name: line.name.trim(), category_id: Number(line.category_id), target_quantity: Number(line.target_quantity),
        ...(line.length_cm ? { length_cm: line.length_cm } : {}), ...(line.width_cm ? { width_cm: line.width_cm } : {}),
        pricing_method: line.pricing_method, rate: line.rate, ...(line.manual_reason ? { manual_reason: line.manual_reason.trim() } : {}),
        ...(line.notes ? { notes: line.notes.trim() } : {}), factors: line.factors.map(factorPayload) })), factors: factors.map(factorPayload),
    });
  }
  return <ProductionDialog title="Create production order" locale={locale} busy={command.busy} dirty={JSON.stringify(values) !== JSON.stringify(initial) || lines.some((line) => line.name || line.rate) || factors.length > 0} onClose={onClose}>
    <p className={styles.notice}>{t("Orders track designs and quantities. Register finished carpets only when the manager delivers them.")}</p>
    <form noValidate onSubmit={submit} aria-busy={command.busy}>
      {command.banner && <p className={styles.error} role="alert">{t(command.banner)}</p>}
      <fieldset disabled={command.busy}>
        <ProductionFields fields={fields.filter((field) => field.key === "manager_id")} values={values} errors={command.errors} locale={locale} onChange={update} />
        {values.branch_id && <p className={styles.muted}>{t("Branch")}: {String(lookups.branches.find((row) => String(row.id) === values.branch_id)?.name ?? "")} · {lookups.currencies.find((row) => String(row.id) === values.currency_id)?.code}</p>}
        <section className={styles.section}><h3>{t("Design lines")}</h3>
          {lines.map((line, index) => <div className={styles.line} key={index}>
            <div className={styles.lineHeader}><strong>{t("Design line")} {index + 1}</strong><button type="button" className={styles.iconButton} aria-label={`${t("Remove design")} ${index + 1}`} disabled={lines.length === 1} onClick={() => setLines((old) => old.filter((_, item) => item !== index))}><FiX aria-hidden="true" /></button></div>
            <ProductionFields locale={locale} values={line as unknown as Record<string, string>} onChange={(key, value) => updateLine(index, key, value)}
              errors={Object.fromEntries(Object.entries(command.errors).filter(([key]) => key.startsWith(`lines.${index}.`)).map(([key, error]) => [key.slice(`lines.${index}.`.length), error]))}
              fields={[
                { key: "name", label: "Design name", maxLength: 150 }, { key: "category_id", label: "Carpet category", type: "select", options: selectOptions(lookups.categories) },
                { key: "target_quantity", label: "Target quantity", type: "integer" },
                { key: "pricing_method", label: "Pricing method", type: "select", options: [{ value: "area", label: "Per square metre" }, { value: "piece", label: "Per piece" }, { value: "fixed", label: "Fixed agreed total" }, { value: "manual", label: "Manual agreed total" }] },
                { key: "rate", label: "Agreed rate / amount", type: "decimal" },
                ...(line.pricing_method === "manual" ? [{ key: "manual_reason", label: "Pricing notes", type: "textarea" as const, maxLength: 500 }] : []),
              ]} />
            <details className={styles.advanced} ref={(node) => { if (node && (Object.keys(command.errors).some((key) => key.startsWith(`lines.${index}.`) && /length_cm|width_cm|notes|factors/.test(key)))) node.open = true; }}><summary>{t("Planned dimensions and design adjustments")}</summary>
              <ProductionFields locale={locale} values={line as unknown as Record<string, string>} errors={Object.fromEntries(Object.entries(command.errors).filter(([key]) => key.startsWith(`lines.${index}.`)).map(([key, error]) => [key.slice(`lines.${index}.`.length), error]))} onChange={(key, value) => updateLine(index, key, value)} fields={[{ key: "length_cm", label: "Length (cm)", type: "decimal", optional: true }, { key: "width_cm", label: "Width (cm)", type: "decimal", optional: true }, { key: "notes", label: "Notes", type: "textarea", optional: true, maxLength: 500 }]} />
              <PricingFactors factors={line.factors} prefix={`lines.${index}.factors`} locale={locale} errors={command.errors} onChange={(next) => setLines((old) => old.map((item, lineIndex) => lineIndex === index ? { ...item, factors: next } : item))} />
            </details>
          </div>)}
          {command.errors.lines && <p className={styles.error} role="alert">{t(command.errors.lines)}</p>}
          <button type="button" disabled={lines.length >= 50} onClick={() => setLines((old) => [...old, newLine()])}><FiPlus aria-hidden="true" />{t("Add design")}</button>
        </section>
        <details className={styles.advanced} ref={(node) => { if (node && (Object.keys(command.errors).some((key) => key.startsWith("factors")))) node.open = true; }}><summary>{t("Additional charges and deductions")}</summary><p className={styles.muted}>{t("Factors apply in the displayed order. Fixed totals and rate × quantity factors are spread across the agreed target quantity. Partial deliveries use cumulative calculations to preserve the final agreed amount. Use a percentage deduction to reduce a price; a multiplier multiplies the current subtotal.")}</p><PricingFactors factors={factors} prefix="factors" locale={locale} errors={command.errors} onChange={setFactors} /></details>
        <details className={styles.advanced} ref={(node) => { if (node && (fields.some((field) => field.key !== "manager_id" && command.errors[field.key]))) node.open = true; }}><summary>{t("Dates, currency and order notes")}</summary><ProductionFields fields={fields.filter((field) => field.key !== "manager_id")} values={values} errors={command.errors} locale={locale} onChange={update} /></details>
      </fieldset>
      <footer><button type="submit" className={styles.primary} disabled={command.busy}>{t(command.busy ? "Saving..." : "Save order")}</button></footer>
    </form>
  </ProductionDialog>;
}
