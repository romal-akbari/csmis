"use client";

import { FiPlus, FiX } from "react-icons/fi";
import { emptyFactor, type PricingFactor } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionFields } from "./ProductionFields";
import styles from "./production.module.css";

export function PricingFactors({ factors, prefix, errors, locale, onChange }: {
  factors: PricingFactor[]; prefix: string; errors: Record<string, string>; locale: string; onChange: (factors: PricingFactor[]) => void;
}) {
  const t = (text: string) => productionText(locale, text);
  return <div>
    {factors.map((factor, index) => <div className={styles.line} key={index}>
      <div className={styles.lineHeader}><strong>{t("Add pricing factor")} {index + 1}</strong>
        <button type="button" className={styles.iconButton} aria-label={`${t("Remove factor")} ${index + 1}`} onClick={() => onChange(factors.filter((_, item) => item !== index))}><FiX aria-hidden="true" /></button>
      </div>
      <ProductionFields locale={locale} values={factor} errors={Object.fromEntries(Object.entries(errors).filter(([key]) => key.startsWith(`${prefix}.${index}.`)).map(([key, error]) => [key.slice(`${prefix}.${index}.`.length), error]))}
        onChange={(key, value) => onChange(factors.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: value, ...(key === "kind" && value === "multiplier" ? { operation: "add" } : {}) } : item))}
        fields={[
          { key: "label", label: "Factor label", maxLength: 100 },
          { key: "kind", label: "Factor type", type: "select", options: [
            { value: "fixed", label: "Fixed amount" }, { value: "per_piece", label: "Per piece" }, { value: "per_area", label: "Per square metre" },
              { value: "rate_quantity", label: "Rate × quantity" }, { value: "percentage", label: "Percentage" }, { value: "multiplier", label: "Multiplier" },
          ] },
          { key: "operation", label: "Operation", type: "select", options: factor.kind === "multiplier" ? [{ value: "add", label: "Multiply" }] : [{ value: "add", label: "Add" }, { value: "deduct", label: "Deduct" }] },
          { key: "value", label: "Factor value", type: "decimal" },
          ...(factor.kind === "rate_quantity" ? [{ key: "quantity", label: "Quantity", type: "decimal" as const }] : []),
        ]} />
    </div>)}
    {errors[prefix] && <p className={styles.error} role="alert">{t(errors[prefix])}</p>}
    <button type="button" disabled={factors.length >= 20} onClick={() => onChange([...factors, emptyFactor()])}><FiPlus aria-hidden="true" />{t("Add pricing factor")}</button>
  </div>;
}
