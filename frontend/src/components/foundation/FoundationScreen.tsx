"use client";

import { useEffect, useState, type FormEvent } from "react";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { subscribeBalanceUpdates } from "@/lib/balance-updates";
import {
  FOUNDATION_RESOURCES,
  foundationPayload,
  initialValues,
  type FoundationRecord,
  type Lookups,
} from "@/lib/foundation";
import { foundationText } from "@/lib/foundation-i18n";
import type { FoundationPage } from "@/lib/navigation";
import { canOpen } from "@/lib/permissions";
import { useAppSelector } from "@/store/dashboard-store";
import { RecordEditor } from "./RecordEditor";
import { LedgerPanel } from "./LedgerPanel";
import { SettingsPanel } from "./SettingsPanel";
import styles from "./foundation.module.css";

type Result = {
  data: FoundationRecord[];
  current_page: number;
  last_page: number;
  total: number;
};
type Props = {
  profile: Profile;
  page: FoundationPage;
};

export function FoundationScreen({ profile, page: destination }: Props) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const permitted = (permission: string) =>
    profile.permissions.includes(permission);
  const active = destination.key;
  const allowed = canOpen(profile, destination.label);
  const [lookups, setLookups] = useState<Lookups>({});
  const [rows, setRows] = useState<Result | null>(null);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [banner, setBanner] = useState("");
  const [flash, setFlash] = useState("");
  const [editor, setEditor] = useState<{ row?: FoundationRecord } | null>(null);
  const [account, setAccount] = useState<FoundationRecord | null>(null);
  const [funding, setFunding] = useState(false);
  const [statusFilter, setStatusFilter] = useState("all");
  const [archiveId, setArchiveId] = useState<number | null>(null);
  const [archiving, setArchiving] = useState(false);
  const resource = FOUNDATION_RESOURCES.find((r) => r.key === active);
  const archivable = [
    "facilities",
    "categories",
    "expense-categories",
  ].includes(active);
  const t = (text: string) => foundationText(locale, text);

  useEffect(() => {
    if (!allowed || active !== "accounts" || account) return;
    return subscribeBalanceUpdates(() => setRevision((value) => value + 1));
  }, [active, allowed, account]);

  useEffect(() => {
    if (!allowed) return;
    let cancelled = false;
    apiFetch<{ data: Lookups }>("/foundation/lookups")
      .then((r) => {
        if (!cancelled) setLookups(r.data);
      })
      .catch((e) => {
        if (!cancelled)
          setBanner(isApiFailure(e) ? e.message : "Could not load options.");
      });
    return () => {
      cancelled = true;
    };
  }, [revision, allowed]);

  useEffect(() => {
    if (!allowed || active === "settings" || account) return;
    let cancelled = false;
    const params = new URLSearchParams({
      page: String(page),
      per_page: "10",
      search: query,
    });
    if (archivable) params.set("status", statusFilter);
    apiFetch<Result>(`/foundation/${active}?${params}`)
      .then((data) => {
        if (!cancelled) {
          if (page > data.last_page) {
            setPage(data.last_page);
            return;
          }
          setRows(data);
          setBanner("");
        }
      })
      .catch((e) => {
        if (!cancelled)
          setBanner(isApiFailure(e) ? e.message : "Could not load records.");
      });
    return () => {
      cancelled = true;
    };
  }, [
    active,
    query,
    page,
    revision,
    account,
    archivable,
    statusFilter,
    allowed,
  ]);

  function searchRecords(event: FormEvent) {
    event.preventDefault();
    setPage(1);
    setQuery(search.trim());
    setRows(null);
    setRevision((value) => value + 1);
  }

  async function archiveRecord(row: FoundationRecord) {
    if (!resource || archiving) return;
    if (archiveId !== row.id) {
      setArchiveId(row.id);
      return;
    }
    setArchiving(true);
    setBanner("");
    try {
      await apiFetch(`/foundation/${resource.key}/${row.id}`, {
        method: "PUT",
        body: JSON.stringify(
          foundationPayload(resource, {
            ...initialValues(resource, row),
            status: "inactive",
          }),
        ),
      });
      setArchiveId(null);
      setFlash(t("Record archived."));
      setRows(null);
      setRevision((value) => value + 1);
    } catch (error) {
      setBanner(
        isApiFailure(error) ? error.message : t("Could not archive record."),
      );
    } finally {
      setArchiving(false);
    }
  }

  function renderCell(row: FoundationRecord, key: string): string {
    const value = row[key];
    if (key === "status") return t(String(value));
    const field = resource?.fields.find((f) => f.key === key);
    if (field?.options) return t(String(value ?? "—"));
    if (field?.lookup && field.kind === "select") {
      const option = lookups[field.lookup]?.find((r) => r.id === Number(value));
      return option?.name ?? option?.code ?? String(value ?? "—");
    }
    if (field?.kind === "boolean") return Number(value) ? "✓" : "—";
    if (Array.isArray(value)) return String(value.length);
    return String(value ?? "—");
  }

  const editable =
    resource &&
    permitted(`${resource.permission}.update`) &&
    (!resource.adminOnly || profile.is_administrator);
  const creatable =
    resource &&
    permitted(`${resource.permission}.create`) &&
    (!resource.adminOnly || profile.is_administrator);
  const columns =
    resource?.fields
      .filter(
        (f) =>
          !["password", "description"].includes(f.key) && f.kind !== "multi" && !f.createOnly,
      )
      .slice(0, 4) ?? [];
  if (archivable && !columns.some((field) => field.key === "status")) {
    columns.push({ key: "status", label: "Status" });
  }

  if (!allowed)
    return (
      <p role="alert">{t("You do not have permission to view this page.")}</p>
    );

  return (
    <section
      className={styles.screen}
      aria-labelledby="foundation-page-title"
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
    >
      <header className={styles.headingRow}>
        <div>
          <h1 id="foundation-page-title">{t(destination.label)}</h1>
        </div>
        <div className={styles.actions}>
          {active === "accounts" && !account && <button type="button" onClick={() => {
            setRows(null);
            setRevision((value) => value + 1);
          }}>{t("Refresh balances")}</button>}
          {creatable && !account && (
            <button className={styles.primary} onClick={() => setEditor({})}>
              {t("Add record")}
            </button>
          )}
        </div>
      </header>
      {banner && (
        <p className={styles.error} role="alert">
          {t(banner)}
        </p>
      )}
      {flash && (
        <p className={styles.notice} role="status">
          {flash}
        </p>
      )}
      {account ? (
        <LedgerPanel
          account={account}
          profile={profile}
          locale={locale}
          timezone={lookups.company?.[0]?.name}
          funding={funding}
          onBack={() => {
            setAccount(null);
            setRevision((value) => value + 1);
          }}
        />
      ) : active === "settings" ? (
        <SettingsPanel profile={profile} lookups={lookups} locale={locale} />
      ) : (
        <>
          {active === "accounts" && permitted("ledger.create") && permitted("ledger.view") && (
            <p className={styles.notice}>
              {t("Enter an opening balance when creating an account. Later movements are recorded in its ledger.")}
            </p>
          )}
          {resource && (
            <form className={styles.search} onSubmit={searchRecords}>
              <label htmlFor="foundation-search">{t("Search")}</label>
              <input
                id="foundation-search"
                maxLength={100}
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
              <button>{t("Search")}</button>
              {archivable && (
                <>
                  <label htmlFor="foundation-status">{t("Status")}</label>
                  <select
                    id="foundation-status"
                    value={statusFilter}
                    onChange={(event) => {
                      setStatusFilter(event.target.value);
                      setPage(1);
                      setRows(null);
                      setArchiveId(null);
                    }}
                  >
                    <option value="all">{t("All statuses")}</option>
                    <option value="active">{t("active")}</option>
                    <option value="inactive">{t("inactive")}</option>
                  </select>
                </>
              )}
            </form>
          )}
          <div className={styles.tableWrap}>
            <table>
              <thead>
                <tr>
                  {resource
                    ? columns.map((f) => <th key={f.key}>{t(f.label)}</th>)
                    : ["Date", "Actor", "Action", "Entity", "Changes"].map(
                        (title) => <th key={title}>{t(title)}</th>,
                      )}
                  {resource && <th>{t("Action")}</th>}
                </tr>
              </thead>
              <tbody>
                {rows?.data.map((row) => (
                  <tr key={row.id}>
                    {resource ? (
                      <>
                        {columns.map((field) => (
                          <td key={field.key}>{renderCell(row, field.key)}</td>
                        ))}
                        <td>
                          <div className={styles.actions}>
                            {editable &&
                              !(
                                active === "roles" &&
                                row.name === "Administrator"
                              ) && (
                                <button onClick={() => setEditor({ row })}>
                                  {t("Edit")}
                                </button>
                              )}
                            {active === "accounts" &&
                              permitted("ledger.view") && (
                                <button onClick={() => { setFunding(false); setAccount(row); }}>
                                  {t("View ledger")}
                                </button>
                              )}
                            {active === "accounts" && permitted("ledger.view") &&
                              permitted("ledger.create") && row.status === "active" && (
                                <button onClick={() => { setFunding(true); setAccount(row); }}>
                                  {t("Add money")}
                                </button>
                              )}
                            {editable &&
                              archivable &&
                              row.status === "active" && (
                                <>
                                  <button
                                    disabled={archiving}
                                    onClick={() => void archiveRecord(row)}
                                  >
                                    {t(
                                      archiveId === row.id
                                        ? "Confirm archive"
                                        : "Archive",
                                    )}
                                  </button>
                                  {archiveId === row.id && (
                                    <button
                                      disabled={archiving}
                                      onClick={() => setArchiveId(null)}
                                    >
                                      {t("Cancel")}
                                    </button>
                                  )}
                                </>
                              )}
                          </div>
                          {active === "accounts" && (
                            <small>
                              {t("Balance")}: {String(row.balance)}
                            </small>
                          )}
                        </td>
                      </>
                    ) : (
                      <>
                        <td>{String(row.created_at)}</td>
                        <td>{String(row.user_id ?? "—")}</td>
                        <td>{String(row.action)}</td>
                        <td>
                          {String(row.table_name)} #{String(row.record_id)}
                        </td>
                        <td>
                          <details>
                            <summary>{t("Changes")}</summary>
                            <pre>
                              {JSON.stringify(
                                {
                                  before: row.old_values,
                                  after: row.new_values,
                                },
                                null,
                                2,
                              )}
                            </pre>
                          </details>
                        </td>
                      </>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!rows && !banner && <p role="status">{t("Loading...")}</p>}
          {rows?.data.length === 0 && <p>{t("No records yet.")}</p>}
          <div className={styles.pager}>
            <button disabled={page <= 1} onClick={() => setPage(page - 1)}>
              {t("Previous")}
            </button>
            <span>
              {page} / {rows?.last_page ?? 1} ({rows?.total ?? 0})
            </span>
            <button
              disabled={!rows || page >= rows.last_page}
              onClick={() => setPage(page + 1)}
            >
              {t("Next")}
            </button>
          </div>
        </>
      )}
      {editor && resource && (
        <RecordEditor
          resource={resource}
          row={editor.row}
          lookups={lookups}
          locale={locale}
          onClose={() => setEditor(null)}
          onSave={async (data) => {
            await apiFetch(
              `/foundation/${active}${editor.row ? `/${editor.row.id}` : ""}`,
              {
                method: editor.row ? "PUT" : "POST",
                body: JSON.stringify(data),
              },
            );
            setEditor(null);
            setRevision(revision + 1);
            setFlash(t("Record saved."));
          }}
        />
      )}
    </section>
  );
}
