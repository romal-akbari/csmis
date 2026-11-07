"use client";

import { Fragment, useEffect, useId, useRef, useState, type ReactNode } from "react";
import { FiFileText, FiMinus, FiPlus } from "react-icons/fi";
import { inventoryValue, type InventoryRow } from "@/lib/inventory";
import { businessText } from "@/lib/business-i18n";
import styles from "./inventory.module.css";

export function PurchaseTable({ rows, locale, renderActions, title = "Weaver purchases" }: {
  rows: InventoryRow[];
  locale: string;
  renderActions: (row: InventoryRow) => ReactNode;
  title?: string;
}) {
  const tableId = useId();
  const container = useRef<HTMLDivElement>(null);
  const [columnCount, setColumnCount] = useState(7);
  const [expanded, setExpanded] = useState<Set<number>>(() => new Set());
  useEffect(() => {
    const node = container.current;
    if (!node) return;
    // Match the CSS container breakpoints: a seven-column span would create
    // phantom columns after summary cells are hidden on smaller workspaces.
    const observer = new ResizeObserver(([entry]) => {
      const width = entry.contentRect.width;
      setColumnCount(width <= 520 ? 3 : width <= 760 ? 5 : 7);
    });
    observer.observe(node);
    return () => observer.disconnect();
  }, []);
  const t = (text: string) => businessText(locale, text);
  const balance = (row: InventoryRow) => {
    const amount = inventoryValue(row, "remaining_amount");
    return amount.startsWith("-") ? <>
      {t("Credit owed by weaver")}: <bdi>{amount.slice(1)}</bdi>
    </> : <bdi>{amount || "—"}</bdi>;
  };

  return <div ref={container} className={`${styles.table} ${styles.purchaseTableWrap}`}>
    <table className={styles.purchaseTable} aria-label={t(title)}>
      <thead><tr>
        <th className={styles.purchaseToggleCell} scope="col">
          <span className={styles.visuallyHidden}>{t("Purchase details")}</span>
        </th>
        <th className={styles.purchaseReference} scope="col">{t("Purchase reference")}</th>
        <th className={styles.purchaseWeaver} scope="col">{t(title === "Weaver purchases" ? "Weaver" : "Seller")}</th>
        <th className={styles.purchaseTotal} scope="col">{t("Purchase total")}</th>
        <th className={styles.purchaseBalance} scope="col">{t("Unpaid balance")}</th>
        <th className={styles.purchaseStatus} scope="col">{t("Status")}</th>
        <th className={styles.purchaseActions} scope="col">{t("Actions")}</th>
      </tr></thead>
      <tbody>{rows.map((row) => {
        const open = expanded.has(row.id);
        const panelId = `${tableId}-purchase-${row.id}`;
        const reference = inventoryValue(row, "purchase_no");
        return <Fragment key={row.id}>
          <tr className={styles.purchaseRow} data-expanded={open}>
            <td className={styles.purchaseToggleCell}>
              <button type="button" className={styles.purchaseToggle}
                aria-expanded={open} aria-controls={panelId}
                aria-label={`${t(open ? "Hide purchase details" : "Show purchase details")} ${reference}`}
                title={t(open ? "Hide purchase details" : "Show purchase details")}
                onClick={() => setExpanded((previous) => {
                  const next = new Set(previous);
                  if (next.has(row.id)) next.delete(row.id);
                  else next.add(row.id);
                  return next;
                })}>
                {open ? <FiMinus aria-hidden="true" /> : <FiPlus aria-hidden="true" />}
              </button>
            </td>
            <td className={styles.purchaseReference}><bdi>{reference}</bdi></td>
            <td className={styles.purchaseWeaver}>{inventoryValue(row, "seller_name") || inventoryValue(row, "weaver_name") || "—"}</td>
            <td className={styles.purchaseTotal}>
              <bdi>{inventoryValue(row, "total_amount") || "—"}</bdi>{" "}
              <span className={styles.purchaseCurrency}><bdi>{inventoryValue(row, "currency_code")}</bdi></span>
            </td>
            <td className={styles.purchaseBalance}>{balance(row)}</td>
            <td className={styles.purchaseStatus}><span className={styles.badge}>{t(inventoryValue(row, "state"))}</span></td>
            <td className={styles.purchaseActions}>{renderActions(row)}</td>
          </tr>
          <tr id={panelId} aria-hidden={!open} inert={!open} className={styles.purchaseDetailRow}>
            <td colSpan={columnCount}>
              <div className={styles.purchaseDisclosure} data-purchase-disclosure data-open={open}>
                <div className={styles.purchaseDisclosureInner}>
                  <div className={styles.purchaseDetailContent} role="region"
                    aria-label={`${t("Purchase details")} ${reference}`}>
                    <h3 className={styles.purchaseDetailHeading}>
                      <FiFileText aria-hidden="true" />{t("Purchase details")}
                    </h3>
                    <dl className={styles.purchaseDetails}>
                      <div className={styles.purchaseDetailWeaver}><dt>{t(title === "Weaver purchases" ? "Weaver" : "Seller")}</dt><dd>{inventoryValue(row, "seller_name") || inventoryValue(row, "weaver_name") || "—"}</dd></div>
                      {[["purchase_on", "Date"], ["currency_code", "Currency"], ["paid_amount", "Paid amount"],
                        ["returned_amount", "Returned amount"], ["refunded_amount", "Refunded amount"]].map(([key, label]) =>
                        <div key={key}><dt>{t(label)}</dt><dd><bdi>{inventoryValue(row, key) || "—"}</bdi></dd></div>)}
                      <div className={styles.purchaseDetailBalance}><dt>{t("Unpaid balance")}</dt><dd>{balance(row)}</dd></div>
                      <div className={styles.purchaseDetailStatus}><dt>{t("Status")}</dt><dd><span className={styles.badge}>{t(inventoryValue(row, "state"))}</span></dd></div>
                    </dl>
                    <div className={styles.purchaseExpandedActions}>{renderActions(row)}</div>
                  </div>
                </div>
              </div>
            </td>
          </tr>
        </Fragment>;
      })}
      {!rows.length && <tr><td colSpan={columnCount}>{t("No records found.")}</td></tr>}
      </tbody>
    </table>
  </div>;
}
