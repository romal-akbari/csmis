"use client";

import { useId } from "react";
import type { ProductionField } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import styles from "./production.module.css";

export function ProductionFields({ fields, values, errors, locale, onChange }: {
  fields: ProductionField[]; values: Record<string, string>; errors: Record<string, string>; locale: string;
  onChange: (key: string, value: string) => void;
}) {
  const prefix = useId();
  const t = (text: string) => productionText(locale, text);
  return <div className={styles.grid}>{fields.map((field) => {
    const id = `${prefix}-${field.key}`;
    const shared = { id, value: values[field.key] ?? "", disabled: field.disabled, "aria-invalid": Boolean(errors[field.key]), "aria-describedby": errors[field.key] ? `${id}-error` : undefined,
      onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) => onChange(field.key, event.target.value) };
    return <div key={field.key} className={`${styles.field} ${field.type === "textarea" ? styles.wide : ""}`}>
      <label htmlFor={id}>{t(field.label)}{!field.optional && <span aria-hidden="true"> *</span>}</label>
      {field.type === "select" ? <select {...shared}><option value="">{t(field.placeholder ?? "Choose an option")}</option>{field.options?.map((option) => <option key={option.value} value={option.value}>{t(option.label)}</option>)}</select>
        : field.type === "textarea" ? <textarea {...shared} maxLength={field.maxLength ?? 1000} />
          : <input {...shared} placeholder={field.key === "code" ? t("Generated automatically when saved") : field.placeholder} type={field.type === "date" ? "date" : "text"} inputMode={field.type === "decimal" ? "decimal" : field.type === "integer" ? "numeric" : undefined} maxLength={field.maxLength ?? 150} />}
      {errors[field.key] && <small id={`${id}-error`} role="alert">{t(errors[field.key])}</small>}
    </div>;
  })}</div>;
}
