"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure } from "@/lib/api";
import { washingValue, type WashingLine, type WashingPage } from "@/lib/washing";
import { washingText } from "@/lib/washing-i18n";
import styles from "./washing.module.css";

export function WashingPicker({ visits, branch, facility, lines, onLines, locale }: { visits: boolean; branch: string; facility: string; lines: WashingLine[]; onLines: (lines: WashingLine[]) => void; locale: string }) {
  const t = (text: string) => washingText(locale, text);
  const [search, setSearch] = useState(""); const [query, setQuery] = useState(""); const [page, setPage] = useState(1); const [revision, setRevision] = useState(0);
  const [result, setResult] = useState<{ key: string; data: WashingPage } | null>(null);
  const [failure, setFailure] = useState<{ key: string; message: string } | null>(null);
  const params = new URLSearchParams({ branch_id: branch, search: query, page: String(page), per_page: "10" });
  if (visits) { params.set("facility_id", facility); params.set("unbilled", "1"); }
  const path = `/washing/${visits ? "visits" : "eligible-carpets"}?${params}`;
  const key = `${path}&revision=${revision}`;
  const ready = !!branch && (!visits || !!facility);
  useEffect(() => {
    if (!ready) return;
    const controller = new AbortController();
    apiFetch<WashingPage>(path, { signal: controller.signal }).then((data) => { if (!controller.signal.aborted) { setResult({ key, data }); setFailure(null); } }).catch((error) => { if (!controller.signal.aborted) setFailure({ key, message: isApiFailure(error) ? error.message : "Could not load washing records." }); });
    return () => controller.abort();
  }, [key, path, ready]);
  const current = result?.key === key ? result.data : null;
  function find() { setQuery(search.trim()); setPage(1); setRevision((value) => value + 1); }
  return <section className={styles.picker} aria-label={t(visits ? "Selected visits" : "Selected carpets")}>
    <div className={styles.filters}><input aria-label={t(visits ? "Visit search" : "Carpet search")} maxLength={100} value={search} onChange={(event) => setSearch(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter") { event.preventDefault(); find(); } }} /><button type="button" disabled={!ready} onClick={find}>{t(visits ? "Find visits" : "Find carpets")}</button></div>
    <p className={styles.muted}>{t("Select up to 50 carpets per transaction.")}</p>
    {!ready ? <p>{t(visits ? "Select a branch and washing facility first." : "Select a branch first.")}</p> : failure?.key === key ? <p role="alert" className={styles.error}>{t(failure.message)} <button type="button" onClick={find}>{t("Retry")}</button></p> : !current ? <p role="status">{t("Loading...")}</p> : <>
      {current.data.map((row) => { const checked = lines.some((line) => line.id === row.id); const label = `${washingValue(row, "carpet_code")} · ${washingValue(row, "name")}`;
        return <label className={styles.choice} key={row.id}><input type="checkbox" checked={checked} disabled={!checked && lines.length >= 50} onChange={(event) => onLines(event.target.checked ? [...lines, { id: row.id, label, expected_sequence: Number(row.sequence_no ?? row.current_sequence ?? 0), amount: "" }] : lines.filter((line) => line.id !== row.id))} /><span>{label}{visits && <small className={styles.sub}>{washingValue(row, "order_no")} · {washingValue(row, "sent_on")}</small>}</span></label>; })}
      {!current.data.length && <p>{t(visits ? "No eligible visits." : "No eligible carpets.")}</p>}
      <div className={styles.pager}><span>{current.total} {t("records")}</span><button type="button" disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>{t("Previous")}</button><span>{page} / {current.last_page}</span><button type="button" disabled={page >= current.last_page} onClick={() => setPage((value) => value + 1)}>{t("Next")}</button></div>
    </>}
  </section>;
}
