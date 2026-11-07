"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { options, productionToday, records, textValue, type ProductionField, type ProductionLookups, type ProductionRecord } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionDialog } from "./ProductionDialog";
import { ProductionCommandDialog } from "./ProductionCommandDialog";
import { WorkerSupportEditor } from "./WorkerSupportEditor";
import { ProductionResourceDetail } from "./ProductionResourceDetail";
import styles from "./production.module.css";

export function ManagerStatement({ manager, lookups, locale, profile, onClose, onSaved }: {
  manager: ProductionRecord; lookups: ProductionLookups; locale: string; profile: Profile; onClose: () => void; onSaved: () => void;
}) {
  const [data, setData] = useState<ProductionRecord | null>(null);
  const [error, setError] = useState("");
  const [revision, setRevision] = useState(0);
  const [action, setAction] = useState<"materials" | "tools" | "support" | null>(null);
  const [assistanceId, setAssistanceId] = useState<number | null>(null);
  const t = (text: string) => productionText(locale, text);
  useEffect(() => {
    const controller = new AbortController();
    apiFetch<{ data: ProductionRecord }>(`/production/managers/${manager.id}/statement`, { signal: controller.signal })
      .then(({ data }) => { if (!controller.signal.aborted) { setData(data); setError(""); } })
      .catch((failure) => { if (!controller.signal.aborted) setError(isApiFailure(failure) ? failure.message : "Could not load records."); });
    return () => controller.abort();
  }, [manager.id, revision]);
  function saved() { setAction(null); setRevision((old) => old + 1); onSaved(); }
  if (assistanceId) return <ProductionResourceDetail resource="assistance" id={assistanceId} lookups={lookups} locale={locale} profile={profile} onClose={() => setAssistanceId(null)} onSaved={saved} />;
  if (action === "support") return <WorkerSupportEditor manager={manager} lookups={lookups} locale={locale} canPay={profile.permissions.includes("production.pay")} onClose={() => setAction(null)} onSaved={saved} />;
  if (action) {
    const tools = action === "tools";
    return <ProductionCommandDialog title={tools ? "Record tool movement" : "Record material movement"} locale={locale} lookups={lookups}
      path={`/production/managers/${manager.id}/${action}`} onClose={() => setAction(null)} onSaved={saved}
      help={tools ? "Return only usable tools with condition good. Record damaged or lost tools with their own movement type." : undefined}
      initial={{ lot_id: "", movement_type: "issue", quantity: "", moved_on: productionToday(lookups), condition: "good", reason: "", production_order_id: "" }}
      fields={(values) => {
        const lots = options(lookups, tools ? "tool_lots" : "material_lots").filter((lot) => Number(lot.branch_id) === Number(manager.branch_id));
        const fields: ProductionField[] = [
          { key: "lot_id", label: tools ? "Tool lot" : "Material lot", type: "select", options: lots.map((lot) => {
            const held = records(data, tools ? "tools" : "materials").find((item) => Number(item.lot_id) === lot.id);
            const sourceQuantity = values.movement_type === "issue" ? lot.warehouse_quantity : held?.held_quantity ?? "0";
            return { value: String(lot.id), label: `${lot.lot_no} · ${lot.tool_name ?? lot.material_name ?? lot.name ?? ""} · ${t(values.movement_type === "issue" ? "Warehouse quantity" : "With manager")}: ${sourceQuantity ?? "—"}` };
          }) },
          { key: "movement_type", label: "Movement type", type: "select", options: tools ? [{ value: "issue", label: "Issue tools" }, { value: "return", label: "Return tools" }, { value: "damage", label: "Damaged tools" }, { value: "loss", label: "Lost tools" }]
            : [{ value: "issue", label: "Issue materials" }, { value: "consume", label: "Consume in production" }, { value: "return", label: "Return to warehouse" }, { value: "waste", label: "Record waste" }] },
          { key: "quantity", label: "Quantity", type: tools ? "integer" : "decimal" }, { key: "moved_on", label: "Movement date", type: "date" },
          ...(tools ? [{ key: "condition", label: "Condition", maxLength: 255 }] : []),
          ...(!tools && ["consume", "waste"].includes(values.movement_type) ? [{ key: "production_order_id", label: "Production order", type: "select" as const,
            options: options(lookups, "production_orders").filter((order) => Number(order.manager_id) === manager.id).map((order) => ({ value: String(order.id), label: String(order.order_no) })) }] : []),
          { key: "reason", label: "Reason", type: "textarea", maxLength: 500 },
        ];
        return fields;
      }} />;
  }
  return <ProductionDialog title="Manager statement" locale={locale} onClose={onClose}>
    <h3>{textValue(manager, "name")}</h3><p className={styles.notice}>{t("Balances are shown separately by currency. Materials, tools and assistance are not combined with production earnings.")}</p>
    {profile.permissions.includes("production.manage") && <div className={styles.actions}><button type="button" onClick={() => setAction("support")}>{t("Worker support")}</button></div>}
    {profile.permissions.includes("materials.move") && <div className={styles.actions}><button type="button" onClick={() => setAction("materials")}>{t("Record material movement")}</button><button type="button" onClick={() => setAction("tools")}>{t("Record tool movement")}</button></div>}
    {error && <p role="alert" className={styles.error}>{t(error)}</p>}{!data && !error && <p role="status">{t("Loading...")}</p>}
    {data && <>
      {[{ key: "materials", title: "Materials in custody", name: "material_name" }, { key: "tools", title: "Tools in custody", name: "tool_name" }].map((section) => <section className={styles.section} key={section.key}>
        <h3>{t(section.title)}</h3>{records(data, section.key).length ? <ul className={styles.history}>{records(data, section.key).map((lot) => <li key={Number(lot.lot_id)}>
          <strong>{textValue(lot, "lot_no")} · {textValue(lot, section.name)}</strong><span className={styles.sub}>{t("Quantity")}: {textValue(lot, "held_quantity")} {textValue(lot, "unit")}</span>
          {section.key === "tools" && <span className={styles.sub}>{t("Damaged tools")}: {textValue(lot, "damaged_quantity")} · {t("Lost tools")}: {textValue(lot, "lost_quantity")}</span>}
        </li>)}</ul> : <p className={styles.muted}>{t("No records yet.")}</p>}
      </section>)}
      {[{ key: "material_movements", title: "Material movement history", name: "material_name" }, { key: "tool_movements", title: "Tool movement history", name: "tool_name" }].map((section) => records(data, section.key).length > 0 && <section className={styles.section} key={section.key}>
        <h3>{t(section.title)}</h3><p className={styles.muted}>{t("Latest 100 movements")}</p><ul className={styles.history}>{records(data, section.key).map((movement) => <li key={movement.id}>
          <strong>{textValue(movement, "lot_no")} · {textValue(movement, section.name)}</strong>
          <span className={styles.sub}>{textValue(movement, "moved_on")} · {t(textValue(movement, "movement_type"))} · {textValue(movement, "quantity")} {textValue(movement, "unit")}{movement.order_no ? ` · ${textValue(movement, "order_no")}` : ""}</span>
          <span className={styles.sub}>{textValue(movement, "reason")}{movement.condition ? ` · ${textValue(movement, "condition")}` : ""}</span>
        </li>)}</ul>
      </section>)}
      <section className={styles.section}><h3>{t("Earnings and payments")}</h3>{records(data, "production_orders").length ? <ul className={styles.history}>{records(data, "production_orders").map((order) => <li key={order.id}>
        <strong>{textValue(order, "order_no")} · {textValue(order, "currency_code")}</strong><span className={styles.sub}>{t("Earned amount")}: {textValue(order, "earned_amount") || "—"} · {t("Paid amount")}: {textValue(order, "paid_amount") || "—"} · {t("Balance due")}: {textValue(order, "balance_due") || "—"}</span>
      </li>)}</ul> : <p className={styles.muted}>{t("No records yet.")}</p>}</section>
      <section className={styles.section}><h3>{t("Assistance balances")}</h3>{records(data, "assistance").length ? <ul className={styles.history}>{records(data, "assistance").map((assistance) => <li key={assistance.id}>
        <strong>{textValue(assistance, "assistance_no")} · {textValue(assistance, "beneficiary_name")} · {textValue(assistance, "currency_code")}</strong>
        <button type="button" onClick={() => setAssistanceId(assistance.id)}>{t("View details")}</button><span className={styles.sub}>{t("With manager")}: {textValue(assistance, "with_manager_amount")} · {t("Outstanding amount")}: {textValue(assistance, "advance_outstanding")}</span>
      </li>)}</ul> : <p className={styles.muted}>{t("No records yet.")}</p>}</section>
    </>}
  </ProductionDialog>;
}
