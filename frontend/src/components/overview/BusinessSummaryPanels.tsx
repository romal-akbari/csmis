import { FiArrowUpRight, FiCreditCard, FiMapPin, FiShoppingBag } from "react-icons/fi";
import { countLabel, dateLabel, moneyLabel, type BusinessSummary } from "@/lib/overview";
import { overviewText } from "@/lib/overview-i18n";
import styles from "./overview.module.css";

type Shared = { locale: string; onOpen: (page: string) => void };

export function FinancialSummary({ business, locale, onOpen }: Shared & { business: BusinessSummary }) {
  const t = (text: string) => overviewText(locale, text);
  const cards = [
    { title: "Total purchases", hint: "Materials and tools purchased for production. All time.",
      values: business.purchases, page: "Materials & tools", empty: "No purchases yet" },
    { title: "Total expenses", hint: "Operating cash expenses after reversals, plus confirmed worker bonuses. Excludes inventory, production advances and washing costs.",
      values: business.expenses, page: "Accounts", empty: "No operating expenses yet" },
  ];
  return <>{cards.filter((card) => card.values !== null).map((card) => (
    <section key={card.title} className={styles.panel}>
      <div className={styles.panelHeading}>
        <div><h2>{t(card.title)}</h2><p>{t(card.hint)}</p></div>
        <button type="button" className={styles.textButton} aria-label={t(card.title)}
          onClick={() => onOpen(card.page)}><FiArrowUpRight aria-hidden="true" /></button>
      </div>
      {card.values!.length ? <dl className={styles.summaryAmounts}>
        {card.values!.map((value) => <div key={value.currency}>
          <dt>{value.currency}</dt>
          <dd><bdi>{moneyLabel(value.amount, value.precision, locale)}</bdi></dd>
        </div>)}
      </dl> : <p className={styles.emptyNote}>{t(card.empty)}</p>}
    </section>
  ))}</>;
}

export function AccountDetails({ accounts, locale, onOpen }: Shared & {
  accounts: NonNullable<BusinessSummary["accounts"]>;
}) {
  const t = (text: string) => overviewText(locale, text);
  return <section className={styles.panel}>
    <div className={styles.panelHeading}>
      <div><h2>{t("Account balances")}</h2><p>{t("Individual cash and bank accounts")}</p></div>
      <button type="button" className={styles.textButton} onClick={() => onOpen("Accounts")}>
        {t("View accounts")}<FiArrowUpRight aria-hidden="true" />
      </button>
    </div>
    {accounts.items.length ? <ul className={styles.businessList}>
      {accounts.items.map((account) => <li key={account.id}>
        <div><strong>{account.name}</strong><small>{t(account.account_type)} · {t(account.status)}</small></div>
        <bdi>{moneyLabel(account.balance, account.precision, locale)} {account.currency}</bdi>
      </li>)}
    </ul> : <div className={styles.emptyState}>
      <FiCreditCard aria-hidden="true" /><strong>{t("No accounts yet")}</strong>
      <p>{t("Create an account and enter its opening balance.")}</p>
    </div>}
    {accounts.total > accounts.items.length && <p className={styles.emptyNote}>
      {t("Showing")} {countLabel(accounts.items.length, locale)} / {countLabel(accounts.total, locale)}
    </p>}
  </section>;
}

export function RecentTransactions({ transactions, locale, onOpen }: Shared & {
  transactions: NonNullable<BusinessSummary["recent_transactions"]>;
}) {
  const t = (text: string) => overviewText(locale, text);
  return <section className={styles.panel}>
    <div className={styles.panelHeading}>
      <div><h2>{t("Recent transactions")}</h2><p>{t("Latest account money movements, including reversals")}</p></div>
      <button type="button" className={styles.textButton} onClick={() => onOpen("Accounts")}>
        {t("View accounts")}<FiArrowUpRight aria-hidden="true" />
      </button>
    </div>
    {transactions.length ? <ul className={styles.businessList}>
      {transactions.map((entry) => <li key={entry.id}>
        <div><strong>{entry.account_name}</strong><small>{t(entry.entry_type)} · {dateLabel(entry.transaction_date, locale)}
          {Boolean(Number(entry.reversed)) && <> · {t("Reversed")}</>}</small>
          <small>{entry.description}</small></div>
        <bdi>{entry.direction === "out" ? "−" : "+"}{moneyLabel(entry.amount, entry.precision, locale)} {entry.currency}</bdi>
      </li>)}
    </ul> : <p className={styles.emptyNote}>{t("No account transactions yet")}</p>}
  </section>;
}

export function BranchInformation({ branches, locale, onOpen }: Shared & {
  branches: NonNullable<BusinessSummary["branches"]>;
}) {
  const t = (text: string) => overviewText(locale, text);
  return <section className={styles.panel}>
    <div className={styles.panelHeading}>
      <div><h2>{t("Branch information")}</h2><p>{t("Branches available to your account")}</p></div>
      <button type="button" className={styles.textButton} onClick={() => onOpen("Branches")}>
        {t("View branches")}<FiArrowUpRight aria-hidden="true" />
      </button>
    </div>
    {branches.items.length ? <ul className={styles.businessList}>
      {branches.items.map((branch) => <li key={branch.id}>
        <div><strong>{branch.name}</strong><small><bdi>{branch.code}</bdi> · {t(branch.status)}</small>
          {branch.location && <small>{branch.location}</small>}</div>
        {branch.available !== undefined && <span>{countLabel(Number(branch.available), locale)} {t("Available carpets")}</span>}
      </li>)}
    </ul> : <div className={styles.emptyState}>
      <FiMapPin aria-hidden="true" /><strong>{t("No branches yet")}</strong>
    </div>}
    {branches.total > branches.items.length && <p className={styles.emptyNote}>
      {t("Showing")} {countLabel(branches.items.length, locale)} / {countLabel(branches.total, locale)}
    </p>}
  </section>;
}

export function PendingReports({ locale }: { locale: string }) {
  const t = (text: string) => overviewText(locale, text);
  return <details className={`${styles.panel} ${styles.pendingReports}`}>
    <summary><FiShoppingBag aria-hidden="true" />{t("Profit/loss reporting")}</summary>
    <p>{t("Full profit/loss reports and exports remain a reporting phase.")}</p>
  </details>;
}

export function SalesSummary({ business, locale, onOpen }: Shared & { business: BusinessSummary }) {
  const t = (text: string) => overviewText(locale, text);
  if (business.sales == null) return null;
  return <section className={styles.panel}><div className={styles.panelHeading}><h2>{t("Sales and receivables")}</h2><button type="button" className={styles.textButton} onClick={() => onOpen("Sales invoices")}>{t("View sales")}<FiArrowUpRight aria-hidden="true" /></button></div>
    {business.sales.length ? business.sales.map((sale) => <dl className={styles.summaryAmounts} key={sale.currency}>
      <div><dt>{t("Total sales")} · {sale.currency}</dt><dd><bdi>{moneyLabel(sale.amount, sale.precision, locale)}</bdi></dd></div>
      <div><dt>{t("Sold carpets")}</dt><dd>{countLabel(Number(sale.sold), locale)}</dd></div>
      <div><dt>{t("Customer receivables")} · {sale.currency}</dt><dd><bdi>{moneyLabel(sale.receivable, sale.precision, locale)}</bdi></dd></div>
      <div><dt>{t("Overdue customer payments")} · {sale.currency}</dt><dd><bdi>{moneyLabel(sale.overdue, sale.precision, locale)}</bdi></dd></div>
    </dl>) : <p className={styles.emptyNote}>{t("No sales yet")}</p>}
  </section>;
}
