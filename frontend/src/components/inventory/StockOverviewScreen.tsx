"use client";

import { useEffect, useState } from "react";
import { FiArrowLeft, FiRefreshCw } from "react-icons/fi";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { productionText } from "@/lib/manager-production-i18n";
import { washingState } from "@/lib/washing-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import styles from "@/components/production/production.module.css";
import stockStyles from "./StockOverview.module.css";

type Location = { id: number; code: string; name: string; location_type: string; branch_name: string | null; facility_name: string | null; tool_quantity: string | number | null; carpet_quantity: number | null };
type Tool = { id: number; code: string; name: string; quantity: string | number };
type Carpet = { id: number; carpet_code: string; name: string; length_cm: string | null; width_cm: string | null; stock_status: string };
type Page<T> = { data: T[]; total: number; last_page: number };
type Detail = { location: Location; tools: Page<Tool> | null; carpets: Page<Carpet> | null };
type Response = Page<Location> | { data: Detail };

export function StockOverviewScreen({ profile }: { profile: Profile }) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const t = (text: string) => productionText(locale, text);
  const hasTools = profile.permissions.includes("materials.view");
  const hasCarpets = profile.permissions.some((code) => ["stock.view", "washing.view"].includes(code));
  const [location, setLocation] = useState<number | null>(null);
  const [tab, setTab] = useState<"tools" | "carpets">(hasTools ? "tools" : "carpets");
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [detailPage, setDetailPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [response, setResponse] = useState<{ key: string; value?: Response; error?: string } | null>(null);
  const path = location ? `/inventory/stock-overview/${location}?${tab}_page=${detailPage}`
    : `/inventory/stock-overview?page=${page}&search=${encodeURIComponent(query)}`;
  const key = `${path}:${revision}`;

  useEffect(() => {
    const controller = new AbortController();
    apiFetch<Response>(path, { signal: controller.signal })
      .then((value) => { if (!controller.signal.aborted) setResponse({ key, value }); })
      .catch((error) => { if (!controller.signal.aborted) setResponse({ key, error: isApiFailure(error) ? error.message : "Could not load records." }); });
    return () => controller.abort();
  }, [key, path]);

  const current = response?.key === key ? response : null;
  const loading = !current;
  const list = !location ? current?.value as Page<Location> | undefined : undefined;
  const detail = location ? (current?.value as { data: Detail } | undefined)?.data : undefined;
  const savedDetail = response?.value && !("last_page" in response.value) ? response.value.data : undefined;
  const locationInfo = detail?.location ?? (savedDetail?.location.id === location ? savedDetail.location : undefined);
  const items = detail?.[tab];
  const pagination = location ? items : list;
  const currentPage = location ? detailPage : page;
  function turnPage(next: number) { if (location) setDetailPage(next); else setPage(next); }
  function open(id: number | null) { setLocation(id); setDetailPage(1); setTab(hasTools ? "tools" : "carpets"); }

  return <section className={styles.screen} dir={locale === "fa" ? "rtl" : "ltr"} aria-busy={loading}>
    <header className={styles.heading}><div>
      {location && <button type="button" onClick={() => open(null)}><FiArrowLeft aria-hidden="true" />{t("Back to stock overview")}</button>}
      <h1>{locationInfo?.name ?? t("Stock overview")}</h1>
      <p className={styles.muted}>{locationInfo ? `${locationInfo.code} · ${locationInfo.branch_name ?? locationInfo.facility_name ?? ""}` : t("View tools and carpets held at each stock location.")}</p>
    </div><button type="button" onClick={() => setRevision((old) => old + 1)} aria-label={t("Refresh")}><FiRefreshCw aria-hidden="true" />{t("Refresh")}</button></header>
    {current?.error && <p role="alert" className={styles.error}>{t(current.error)}</p>}
    {!location && <form className={styles.search} onSubmit={(event) => { event.preventDefault(); setQuery(search.trim()); setPage(1); }}>
      <input aria-label={t("Search stock locations")} placeholder={t("Search stock locations")} maxLength={100} value={search} onChange={(event) => setSearch(event.target.value)} /><button>{t("Search")}</button>
    </form>}
    {location && <div className={`${styles.tabs} ${stockStyles.tabs}`} aria-label={t("Stock overview")}>
      {hasTools && <button type="button" aria-pressed={tab === "tools"} onClick={() => { setTab("tools"); setDetailPage(1); }}>{t("Tools")} {locationInfo ? `(${locationInfo.tool_quantity})` : ""}</button>}
      {hasCarpets && <button type="button" aria-pressed={tab === "carpets"} onClick={() => { setTab("carpets"); setDetailPage(1); }}>{t("Carpets")} {locationInfo ? `(${locationInfo.carpet_quantity})` : ""}</button>}
    </div>}
    <div className={`${styles.table} ${stockStyles.table}`}><table>
      <thead>{!location ? <tr><th>{t("Stock location")}</th><th>{t("Branch")}/{t("Washing facility")}</th>{hasTools && <th>{t("Tools in stock")}</th>}{hasCarpets && <th>{t("Carpets at location")}</th>}<th>{t("Actions")}</th></tr>
        : tab === "tools" ? <tr><th>{t("Tool")}</th><th>{t("Code")}</th><th>{t("Quantity")}</th></tr>
          : <tr><th>{t("Carpet")}</th><th>{t("Dimensions")}</th><th>{t("Status")}</th></tr>}</thead>
      <tbody>
        {list?.data.map((row) => <tr key={row.id}>
          <td data-label={t("Stock location")}><strong>{row.name}</strong><span className={styles.sub}>{row.code} · {t(row.location_type)}</span></td>
          <td data-label={`${t("Branch")}/${t("Washing facility")}`}>{row.branch_name ?? row.facility_name ?? "—"}</td>
          {hasTools && <td data-label={t("Tools in stock")}>{row.tool_quantity}</td>}{hasCarpets && <td data-label={t("Carpets at location")}>{row.carpet_quantity}</td>}
          <td data-label={t("Actions")}><button type="button" onClick={() => open(row.id)} aria-label={`${t("Details")} · ${row.name}`}>{t("Details")}</button></td>
        </tr>)}
        {detail && tab === "tools" && detail.tools?.data.map((row) => <tr key={row.id}><td data-label={t("Tool")}><strong>{row.name}</strong></td><td data-label={t("Code")}>{row.code}</td><td data-label={t("Quantity")}>{row.quantity}</td></tr>)}
        {detail && tab === "carpets" && detail.carpets?.data.map((row) => <tr key={row.id}><td data-label={t("Carpet")}><strong>{row.carpet_code}</strong><span className={styles.sub}>{row.name}</span></td><td data-label={t("Dimensions")}><span>{row.length_cm && row.width_cm ? <><bdi>{row.length_cm} × {row.width_cm}</bdi> {t("cm")}</> : "—"}</span></td><td data-label={t("Status")}><span className={styles.badge}>{t(washingState(row.stock_status))}</span></td></tr>)}
        {!pagination?.data.length && <tr><td colSpan={!location ? 3 + Number(hasTools) + Number(hasCarpets) : 3}>{t(loading ? "Loading..." : current?.error ? "Could not load records." : !location ? "No records yet." : tab === "tools" ? "No tools at this location." : "No carpets at this location.")}</td></tr>}
      </tbody>
    </table></div>
    <div className={styles.pager}><span>{currentPage}/{pagination?.last_page ?? 1} · {pagination?.total ?? 0}</span><button type="button" disabled={loading || currentPage <= 1} onClick={() => turnPage(currentPage - 1)}>{t("Previous")}</button><button type="button" disabled={loading || !pagination || currentPage >= pagination.last_page} onClick={() => turnPage(currentPage + 1)}>{t("Next")}</button></div>
  </section>;
}
