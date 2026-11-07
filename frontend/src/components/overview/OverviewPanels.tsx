import { FiArrowUpRight, FiLayers, FiArrowRight, FiBox } from "react-icons/fi";
import type { Overview } from "@/lib/overview";
import { dateLabel, eventLabels, moneyLabel, stockLabels } from "@/lib/overview";
import { overviewText } from "@/lib/overview-i18n";
import styles from "./overview.module.css";

type Shared = { locale: string; onOpen: (label: string) => void };

export function RecentActivity({ stock, locale, onOpen }: Shared & {
  stock: NonNullable<Overview["stock"]>;
}) {
  const t = (text: string) => overviewText(locale, text);
  return <section className={`${styles.panel} ${styles.recent}`}>
    <div className={styles.panelHeading}>
      <div><h2>{t("Recent stock activity")}</h2>
        <p>{t("The latest movements in your stock")}</p></div>
      <button type="button" className={styles.textButton}
        onClick={() => onOpen("Finished carpets")}>
        {t("View carpets")}<FiArrowUpRight aria-hidden="true" />
      </button>
    </div>
    {stock.recent.length ? <div className={styles.recentList}>
      {stock.recent.map((event) => <div className={styles.event} key={event.id}>
        <span className={styles.eventIcon} aria-hidden="true"><FiLayers /></span>
        <div className={styles.eventIdentity}>
          <strong><bdi>{event.carpet_code}</bdi></strong><span>{event.name}</span>
        </div>
        <div className={styles.eventAction}>
          <span>{t(eventLabels[event.event_type] ?? event.event_type)}</span>
          <small>{event.location_name ?? t(stockLabels[event.resulting_status])}</small>
        </div>
        <time dateTime={event.occurred_on}>{dateLabel(event.occurred_on, locale)}</time>
      </div>)}
    </div> : <div className={styles.emptyState}>
      <FiBox aria-hidden="true" /><strong>{t("No stock movements yet")}</strong>
      <p>{t("Create a production order and receive completed carpets through washing.")}</p>
    </div>}
  </section>;
}

export function BalancePanel({ finance, locale }: {
  finance: Overview["finance"]; locale: string;
}) {
  const t = (text: string) => overviewText(locale, text);
  const currencies = [...new Set([
    ...(finance.cash ?? []).map((row) => row.currency),
    ...(finance.manager_payables ?? []).map((row) => row.currency),
    ...(finance.supplier_payables ?? []).map((row) => row.currency),
    ...(finance.washing_payables ?? []).map((row) => row.currency),
  ])].sort();
  return <section className={`${styles.panel} ${styles.balances}`}>
    <div className={styles.panelHeading}><div>
      <h2>{t("Current balances")}</h2><p>{t("Kept separate by currency")}</p>
    </div></div>
    {currencies.length ? <div className={styles.balanceList}>
      {currencies.map((currency) => {
        const cash = finance.cash?.find((row) => row.currency === currency);
        const payable = finance.manager_payables?.find((row) => row.currency === currency);
        const supplierPayable = finance.supplier_payables?.find((row) => row.currency === currency);
        const washingPayable = finance.washing_payables?.find((row) => row.currency === currency);
        return <div key={currency} className={styles.currencyGroup}>
          <span className={styles.currencyCode}>{currency}</span>
          <dl>
            {cash && <div><dt>{t("Account balances")}</dt>
              <dd><bdi>{moneyLabel(cash.balance, cash.precision, locale)}</bdi></dd></div>}
            {payable && <>
              <div><dt>{t("To pay production managers")}</dt>
                <dd><bdi>{moneyLabel(payable.owed, payable.precision, locale)}</bdi></dd></div>
              <div><dt>{t("Production advances")}</dt>
                <dd><bdi>{moneyLabel(payable.credit, payable.precision, locale)}</bdi></dd></div>
            </>}
            {supplierPayable && <div><dt>{t("To pay suppliers")}</dt><dd><bdi>{moneyLabel(supplierPayable.owed, supplierPayable.precision, locale)}</bdi></dd></div>}
            {washingPayable && <div><dt>{t("To pay washing facilities")}</dt>
              <dd><bdi>{moneyLabel(washingPayable.owed, washingPayable.precision, locale)}</bdi></dd></div>}
          </dl>
        </div>;
      })}
    </div> : <div className={styles.emptyState}>
      <FiLayers aria-hidden="true" /><strong>{t("No financial activity yet")}</strong>
      <p>{t("Balances appear after purchases or account entries are posted.")}</p>
    </div>}
  </section>;
}

export function QuickAccess({ pages, locale, onOpen }: Shared & { pages: string[] }) {
  const t = (text: string) => overviewText(locale, text);
  if (!pages.length) return null;
  return <section className={styles.quickAccess}>
    <div><span className={styles.eyebrow}>{t("Quick access")}</span>
      <h2>{t("Your daily workspace")}</h2></div>
    <div className={styles.quickLinks}>{pages.map((page) => (
      <button key={page} type="button" onClick={() => onOpen(page)}>
        {t(page)}<FiArrowRight aria-hidden="true" />
      </button>
    ))}</div>
  </section>;
}
