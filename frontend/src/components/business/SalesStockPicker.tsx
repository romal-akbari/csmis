"use client";

import { useId, useState } from "react";
import { FiRefreshCw, FiX } from "react-icons/fi";
import type { SalesStockGroup, SalesStockLine } from "@/lib/sales-stock";
import { businessText } from "@/lib/business-i18n";
import styles from "./business.module.css";
import salesStyles from "./SalesStockPicker.module.css";

export function SalesStockPicker({ groups, lines, errors, locale, refreshing, branchSelected, onChange, onRefresh }: {
  groups: SalesStockGroup[]; lines: SalesStockLine[]; errors: Record<string, string>; locale: string; refreshing: boolean; branchSelected: boolean;
  onChange: (lines: SalesStockLine[]) => void; onRefresh: () => void;
}) {
  const prefix = useId();
  const [search, setSearch] = useState("");
  const t = (text: string) => businessText(locale, text);
  function metadata(group: SalesStockGroup) {
    return <span className={styles.sub}>{group.production_order_no ? `${group.production_order_no} · ` : ""}{group.location_name}
      {group.length_cm && group.width_cm && <> · <bdi>{group.length_cm} × {group.width_cm}</bdi> {t("cm")}</>}</span>;
  }
  return <section className={styles.picker} aria-label={t("Select production designs")}>
    <h3>{t("Select production designs")}</h3>
    <p className={styles.muted}>{t("Choose a production design, enter the quantity and set the price per carpet.")}</p>
    <div className={salesStyles.search}>
      <input type="search" maxLength={100} aria-label={t("Find a production design")} placeholder={t("Find a production design")} value={search} onChange={(event) => setSearch(event.target.value)} />
      <button type="button" onClick={onRefresh} disabled={refreshing}><FiRefreshCw aria-hidden="true" />{t(refreshing ? "Loading..." : "Refresh stock")}</button>
    </div>
    <div className={styles.options} aria-busy={refreshing}>{groups.filter((group) => `${group.name} ${group.production_order_no ?? ""} ${group.location_name}`.toLocaleLowerCase().includes(search.toLocaleLowerCase())).map((group) => {
      const selected = lines.some((line) => line.stock_key === group.stock_key);
      return <label key={group.stock_key} className={`${styles.option} ${salesStyles.stockOption}`}>
        <input type="checkbox" checked={selected} onChange={(event) => onChange(event.target.checked
          ? [...lines, { stock_key: group.stock_key, label: group.name, quantity: "1", unit_price: "", discount: "0" }]
          : lines.filter((line) => line.stock_key !== group.stock_key))} />
        <span className={salesStyles.design}><strong>{group.name}</strong>{metadata(group)}</span>
        <span className={salesStyles.availableBadge}><span>{t("Available quantity")}</span><strong>{group.available_quantity}</strong></span>
      </label>;
    })}</div>
    {!groups.length && <p className={styles.muted}>{t(branchSelected ? "No finished carpets are available for this branch." : "Choose a branch to see available carpets.")}</p>}
    {errors.stock_groups && <p role="alert" className={styles.error}>{t(errors.stock_groups)}</p>}
    {lines.map((line, index) => {
      const group = groups.find((item) => item.stock_key === line.stock_key);
      const groupError = errors[`stock_groups.${index}`];
      return <section className={styles.line} key={line.stock_key} aria-label={`${t("Production design")} · ${line.label}`}>
        <div className={styles.lineHeader}><strong>{line.label}</strong><button type="button" aria-label={`${t("Remove")} ${line.label}`} onClick={() => onChange(lines.filter((item) => item.stock_key !== line.stock_key))}><FiX aria-hidden="true" /></button></div>
        {group && metadata(group)}
        {groupError && <p role="alert" className={styles.error}>{t(groupError)}</p>}
        {errors[`stock_groups.${index}.stock_key`] && <p role="alert" className={styles.error}>{t(errors[`stock_groups.${index}.stock_key`])}</p>}
        <div className={`${styles.grid} ${salesStyles.prices}`}>{(["quantity", "unit_price", "discount"] as const).map((key) => {
          const id = `${prefix}-${index}-${key}`;
          const error = errors[`stock_groups.${index}.${key}`];
          return <div className={styles.field} key={key}>
            <label className={key === "quantity" ? salesStyles.quantityLabel : undefined} htmlFor={id}>
              {t(key === "quantity" ? "Quantity" : key === "unit_price" ? "Unit price" : "Discount per carpet")}
              {key === "quantity" && group && <span className={salesStyles.quantityHint}>{t("Available quantity")}: {group.available_quantity}</span>}
            </label>
            <input id={id} type="text" inputMode={key === "quantity" ? "numeric" : "decimal"} maxLength={key === "quantity" ? 5 : 19} value={line[key]}
              aria-invalid={Boolean(error)} aria-describedby={error ? `${id}-error` : undefined}
              onChange={(event) => onChange(lines.map((item) => item.stock_key === line.stock_key ? { ...item, [key]: event.target.value } : item))} />
            {error && <small role="alert" id={`${id}-error`}>{t(error)}</small>}
          </div>;
        })}</div>
      </section>;
    })}
  </section>;
}
