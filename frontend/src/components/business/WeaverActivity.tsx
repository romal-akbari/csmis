"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure } from "@/lib/api";
import { value, type BusinessRow } from "@/lib/business";
import { businessText } from "@/lib/business-i18n";
import { BusinessDialog } from "./BusinessDialog";
import styles from "./business.module.css";

type Statement = { weaver: { name: string }; purchases: BusinessRow[] | null; production: BusinessRow[]; transactions: BusinessRow[]; base_currency: string };
export function WeaverActivity({ id, locale, onClose }: { id: number; locale: string; onClose: () => void }) {
  const [data, setData] = useState<Statement | null>(null);
  const [error, setError] = useState("");
  const t = (s: string) => businessText(locale, s);
  useEffect(() => { let cancelled = false; apiFetch<{ data: Statement }>(`/business/weavers/${id}/statement`).then((r) => { if (!cancelled) setData(r.data); }).catch((e) => { if (!cancelled) setError(isApiFailure(e) ? e.message : "Could not load records."); }); return () => { cancelled = true; }; }, [id]);
  return <BusinessDialog title="Weaver activity" locale={locale} onClose={onClose}>
    {error && <p className={styles.error} role="alert">{t(error)}</p>}{!data && !error && <p role="status">{t("Loading...")}</p>}
    {data && <><h3>{data.weaver.name}</h3>
      {data.purchases && <section className={styles.section}><h3>{t("Carpet purchases")}</h3>{data.purchases.length ? <ul className={styles.history}>{data.purchases.map((p) => <li key={p.id}><strong>{value(p, "purchase_no")}</strong><span className={styles.sub}>{t("Total")}: {value(p, "total_amount")} · {t("Paid amount")}: {value(p, "paid_amount")} · {t("Unpaid balance")}: {value(p, "remaining_amount")} {value(p, "currency_code")}</span></li>)}</ul> : <p>{t("No records yet.")}</p>}</section>}
      {data.production.length > 0 && <section className={styles.section}><h3>{t("Production orders")} / {t("Partnerships")}</h3><ul className={styles.history}>{data.production.map((order) => <li key={order.id}><strong>{value(order, "order_no")} · {t(value(order, "state"))}</strong>
        {order.remaining != null && <span className={styles.sub}>{t("Labor earned")}: {value(order, "earned")} · {t("Paid labor / advances")}: {value(order, "paid")} · {t("Labor balance (negative means advance)")}: {value(order, "remaining")} {value(order, "currency_code")}</span>}
        {order.settlement_remaining_base != null && <span className={styles.sub}>{t("Remaining settlement balance")}: {value(order, "settlement_remaining_base")} {data.base_currency}</span>}
      </li>)}</ul></section>}
      <section className={styles.section}><h3>{t("Payments and refunds")}</h3>{data.transactions.length ? data.transactions.map((p) => <div className={styles.payment} key={p.id}><strong>{p.direction === "out" ? "−" : "+"}{value(p, "amount")} {value(p, "currency_code")} · {value(p, "account_name")}</strong><span className={styles.sub}>{t(value(p, "source"))} · {value(p, "transaction_date")}{Number(p.reversed) ? ` · ${t("Reversed")}` : ""}</span><span>{value(p, "description")}</span></div>) : <p>{t("No records yet.")}</p>}</section>
    </>}
  </BusinessDialog>;
}
