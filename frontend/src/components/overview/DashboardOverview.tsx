"use client";

import { useEffect, useState } from "react";
import { FiArrowUpRight, FiBox, FiChevronDown, FiClock, FiUsers, FiGrid } from "react-icons/fi";
import type { IconType } from "react-icons";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { subscribeBalanceUpdates } from "@/lib/balance-updates";
import { can, canOpen } from "@/lib/permissions";
import { countLabel, type Overview } from "@/lib/overview";
import { overviewText } from "@/lib/overview-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { ActivityChart, StockChart } from "./OverviewCharts";
import { BalancePanel, QuickAccess, RecentActivity } from "./OverviewPanels";
import { AccountDetails, BranchInformation, FinancialSummary, PendingReports, RecentTransactions, SalesSummary } from "./BusinessSummaryPanels";
import styles from "./overview.module.css";

const metrics: {
  key: keyof Overview["metrics"]; label: string; hint: string;
  page: string; icon: IconType;
}[] = [
  { key: "available", label: "Available carpets", hint: "Ready in your stock",
    page: "Finished carpets", icon: FiBox },
  { key: "at_washing", label: "At washing",
    hint: "Completed carpets with washing facilities", page: "Washing facility stock", icon: FiClock },
  { key: "customers", label: "Active customers", hint: "Your customer directory",
    page: "Customers", icon: FiUsers },
];

export function DashboardOverview({ profile, onOpen }: {
  profile: Profile; onOpen: (label: string) => void;
}) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const t = (text: string) => overviewText(locale, text);
  const [data, setData] = useState<Overview | null>(null);
  const [days, setDays] = useState(30);
  const [attempt, setAttempt] = useState(0);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState("");
  const permitted = can(profile, "dashboard.view");
  useEffect(() => {
    if (!permitted) return;
    return subscribeBalanceUpdates(() => {
      setBusy(true);
      setAttempt((value) => value + 1);
    });
  }, [permitted]);
  useEffect(() => {
    if (!permitted) return;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort("timeout"), 15_000);
    apiFetch<{ data: Overview }>(`/dashboard/overview?days=${days}`, {
      signal: controller.signal,
    }).then((result) => {
      if (controller.signal.aborted) return;
      setData(result.data);
      setError("");
      setBusy(false);
    }).catch((failure: unknown) => {
      if (controller.signal.aborted && controller.signal.reason !== "timeout") return;
      if (isApiFailure(failure) && failure.status === 403) setData(null);
      setError(isApiFailure(failure) && failure.status === 403
        ? "Your account does not have dashboard access."
        : "Could not load the overview. Please try again.");
      setBusy(false);
    }).finally(() => clearTimeout(timeout));
    return () => { clearTimeout(timeout); controller.abort(); };
  }, [permitted, days, attempt]);

  function refresh() {
    setBusy(true);
    setError("");
    setAttempt((value) => value + 1);
  }
  const pages = ["Materials & tools", "Production orders", "Finished carpets", "Washing facility stock", "Sales invoices", "Expense records", "Customers",
    "Production managers", "Supplier balances", "Accounts"].filter((page) => canOpen(profile, page));
  return <section className={styles.overview} lang={locale}
    dir={locale === "fa" ? "rtl" : "ltr"} aria-label={t("Business overview")}>
    {!permitted ? <p className={styles.notice} role="status">
      {t("Your account does not have dashboard access.")}</p> : <>
      {error && <div className={styles.notice} role="alert"><p>{t(error)}</p>
        <button type="button" onClick={refresh} disabled={busy}>{t("Try again")}</button>
      </div>}
      {busy && <p role="status" className={styles.loadingText}>
        {t(data ? "Updating overview…" : "Loading your overview…")}</p>}
      {!data && busy && <div className={styles.skeleton} aria-hidden="true">
        <div /><div /><div /><div /><section /><section />
      </div>}
      {data && <>
        <div className={styles.metrics}>
          {metrics.filter((metric) => typeof data.metrics[metric.key] === "number")
            .map(({ key, label, hint, page, icon: Icon }, index) => (
              <button type="button" key={key} onClick={() => onOpen(page)}
                className={`${styles.metric} ${index === 0 ? styles.featured : ""}`}>
                <div className={styles.metricTop}><span>{t(label)}</span>
                  <span className={styles.metricIcon}><Icon aria-hidden="true" /></span></div>
                <strong className={styles.metricValue}>
                  {countLabel(data.metrics[key]!, locale)}</strong>
                <span className={styles.metricBottom}>{t(hint)}
                  <FiArrowUpRight aria-hidden="true" /></span>
              </button>
            ))}
        </div>
        {data.business && <div className={styles.detailGrid}>
          <FinancialSummary business={data.business} locale={locale} onOpen={onOpen} />
          <SalesSummary business={data.business} locale={locale} onOpen={onOpen} />
        </div>}
        {data.stock && <div className={styles.primaryGrid}>
          <ActivityChart trend={data.stock.trend} locale={locale} days={data.days}
            busy={busy} onDays={(value) => {
              if (value === days) {
                if (error) refresh();
                return;
              }
              setBusy(true);
              setDays(value);
            }} />
          {data.stock && <RecentActivity stock={data.stock} locale={locale} onOpen={onOpen} />}
        </div>}
        <details className={styles.moreOverview}>
          <summary className={styles.moreSummary}>
            <span>{t("More dashboard details")}</span>
            <FiChevronDown aria-hidden="true" />
          </summary>
          <div className={styles.moreContent}>
            {data.stock && <StockChart stock={data.stock} locale={locale} />}
            {data.business && <div className={styles.summaryGrid}>
              <FinancialSummary business={data.business} locale={locale} onOpen={onOpen} />
              <SalesSummary business={data.business} locale={locale} onOpen={onOpen} />
            </div>}
            {(data.finance.manager_payables != null || data.finance.supplier_payables != null || data.finance.cash !== null || data.finance.washing_payables != null) &&
              <BalancePanel finance={data.finance} locale={locale} />}
            {data.business && <div className={styles.businessGrid}>
              {data.business.accounts && <AccountDetails accounts={data.business.accounts} locale={locale} onOpen={onOpen} />}
              {data.business.recent_transactions && <RecentTransactions transactions={data.business.recent_transactions} locale={locale} onOpen={onOpen} />}
              {data.business.branches && <BranchInformation branches={data.business.branches} locale={locale} onOpen={onOpen} />}
            </div>}
            {profile.is_administrator && <PendingReports locale={locale} />}
            {pages.length > 0 && <QuickAccess pages={pages} locale={locale} onOpen={onOpen} />}
          </div>
        </details>
        {!pages.length && <div className={styles.emptyState}>
          <FiGrid aria-hidden="true" /><strong>{t("Your workspace is ready")}</strong>
          <p>{t("Use the navigation to open the pages available to your role.")}</p>
        </div>}
      </>}
    </>}
  </section>;
}
