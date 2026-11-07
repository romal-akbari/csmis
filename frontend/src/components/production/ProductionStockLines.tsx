"use client";

import { FiPlus, FiX } from "react-icons/fi";
import { textValue, type ProductionRecord } from "@/lib/manager-production";
import type { StockLine } from "@/lib/production-workflow";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionFields } from "./ProductionFields";
import styles from "./production.module.css";

export function ProductionStockLines({ title, tools = false, lines, choices, errors, locale, onChange }: {
  title: string; tools?: boolean; lines: StockLine[]; choices: ProductionRecord[]; errors: Record<string, string>; locale: string; onChange: (lines: StockLine[]) => void;
}) {
  const t = (text: string) => productionText(locale, text);
  return <section className={styles.section} aria-label={t(title)}><h3>{t(title)}</h3>
    {lines.map((line, index) => <div className={styles.line} key={index}>
      <div className={styles.lineHeader}><strong>{t(tools ? "Tool" : "Material")} {index + 1}</strong><button type="button" className={styles.iconButton} aria-label={`${t("Remove")} ${index + 1}`} onClick={() => onChange(lines.filter((_, i) => i !== index))}><FiX aria-hidden="true" /></button></div>
      <ProductionFields values={line} errors={Object.fromEntries(Object.entries(errors).filter(([key]) => key.startsWith(`${index}.`)).map(([key, value]) => [key.slice(`${index}.`.length), value]))} locale={locale}
        onChange={(key, value) => onChange(lines.map((row, i) => i === index ? { ...row, [key]: value } : row))}
        fields={[{ key: "item_id", label: tools ? "Tool" : "Material", type: "select", options: choices.map((item) => ({ value: String(item.id), label: `${textValue(item, "name")} · ${textValue(item, "quantity")} ${textValue(item, "unit")}` })) }, { key: "quantity", label: "Quantity", type: tools ? "integer" : "decimal" }]} />
    </div>)}
    {!choices.length && <p className={styles.muted}>{t("No stock is available at this source. Purchase and receive supplies first.")}</p>}
    {errors.items && <p className={styles.error} role="alert">{t(errors.items)}</p>}
    <button type="button" disabled={!choices.length || lines.length >= 50} onClick={() => onChange([...lines, { item_id: "", quantity: "" }])}><FiPlus aria-hidden="true" />{t(tools ? "Add tool" : "Add material")}</button>
  </section>;
}
