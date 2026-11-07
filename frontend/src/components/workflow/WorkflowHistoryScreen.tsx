"use client";

import { useState } from "react";
import type { Profile } from "@/lib/api";
import { HISTORICAL_PAGES, historyProfile } from "@/lib/company-workflow";
import { productionText } from "@/lib/manager-production-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { BusinessScreen } from "@/components/business/BusinessScreen";
import { InventoryScreen } from "@/components/inventory/InventoryScreen";
import { CorrectionScreen } from "@/components/inventory/CorrectionScreen";
import { DirectoryScreen } from "@/components/directories/DirectoryScreen";
import styles from "@/components/production/production.module.css";

export function WorkflowHistoryScreen({ profile, initial }: { profile: Profile; initial?: string }) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const t = (text: string) => productionText(locale, text);
  const pages = HISTORICAL_PAGES.filter((page) => profile.permissions.includes(page.permission));
  const [selected, setSelected] = useState<string>(() => pages.find((page) => page.label === initial)?.label ?? pages[0]?.label ?? "");
  const page = pages.find((item) => item.label === selected);
  const viewer = historyProfile(profile);
  return <section dir={locale === "fa" ? "rtl" : "ltr"}>
    <header className={`${styles.screen} ${styles.heading}`}>
      <div><h1>{t("Historical records")}</h1><p className={styles.muted}>{t("Earlier workflows are read-only. New carpets are received through Production orders.")}</p></div>
      <select aria-label={t("History section")} value={selected} onChange={(event) => setSelected(event.target.value)}>{pages.map((item) => <option key={item.label} value={item.label}>{t(item.label)}</option>)}</select>
    </header>
    {page?.kind === "business" && <BusinessScreen key={selected} label={selected} profile={viewer} disableCreate />}
    {page?.kind === "inventory" && <InventoryScreen key={selected} label={selected} profile={viewer} />}
    {page?.kind === "correction" && <CorrectionScreen key={selected} label={selected} profile={viewer} />}
    {page?.kind === "directory" && <DirectoryScreen key={selected} profile={viewer} initialTab="weavers" onlyResource="weavers" onExit={() => {}} />}
  </section>;
}
