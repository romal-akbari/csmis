"use client";

import { useState } from "react";
import type { Profile } from "@/lib/api";
import { productionText } from "@/lib/manager-production-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { BusinessScreen } from "@/components/business/BusinessScreen";
import { ProductionScreen } from "./ProductionScreen";
import styles from "./production.module.css";

export function SupplyStockScreen({ profile }: { profile: Profile }) {
  const [tab, setTab] = useState("Materials");
  const locale = useAppSelector((state) => state.dashboard.locale);
  return <section><header className={`${styles.screen} ${styles.heading}`}><h1>{productionText(locale, "Materials & tools stock")}</h1></header><div className={`${styles.screen} ${styles.tabs}`}>{["Materials", "Tools", "Material receipts", "Tool receipts"].map((label) => <button key={label} aria-pressed={label === tab} onClick={() => setTab(label)}>{productionText(locale, label)}</button>)}</div>
    {["Materials", "Material receipts"].includes(tab) ? <BusinessScreen key={tab} label={tab} profile={profile} disableCreate={tab === "Material receipts"} /> : <ProductionScreen key={tab} label={tab} profile={profile} disableCreate={tab === "Tool receipts"} />}
  </section>;
}
