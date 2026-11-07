"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import {
  DIRECTORY_RESOURCES,
  EMPTY_DIRECTORY_LOOKUPS,
  directoryInitialValues,
  directoryPayload,
  type DirectoryKey,
  type DirectoryLookups,
  type DirectoryRecord,
} from "@/lib/directories";
import { directoryText } from "@/lib/directories-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { DirectoryEditor } from "./DirectoryEditor";
import { WeaverActivity } from "@/components/business/WeaverActivity";
import styles from "./directories.module.css";

type Result = {
  data: DirectoryRecord[];
  current_page: number;
  last_page: number;
  total: number;
};

function ConfirmAction({
  row,
  restoring,
  locale,
  busy,
  onClose,
  onConfirm,
}: {
  row: DirectoryRecord;
  restoring: boolean;
  locale: string;
  busy: boolean;
  onClose: () => void;
  onConfirm: () => void;
}) {
  const dialog = useRef<HTMLDialogElement>(null);
  const t = (text: string) => directoryText(locale, text);
  useEffect(() => {
    const node = dialog.current;
    node?.showModal();
    return () => node?.close();
  }, []);
  return (
    <dialog
      ref={dialog}
      className={styles.dialog}
      aria-labelledby="directory-confirm-title"
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
      onCancel={(event) => {
        event.preventDefault();
        if (!busy) onClose();
      }}
    >
      <h2 id="directory-confirm-title">
        {t(restoring ? "Restore record?" : "Archive record?")}
      </h2>
      <p>
        <strong>{String(row.name)}</strong>
      </p>
      <p>
        {t(
          restoring
            ? "This record will become active again."
            : "This record will become inactive. Its history will be preserved.",
        )}
      </p>
      <div className={styles.actions}>
        <button autoFocus disabled={busy} onClick={onClose}>
          {t("Cancel")}
        </button>
        <button className={styles.primary} disabled={busy} onClick={onConfirm}>
          {t(busy ? "Saving..." : restoring ? "Restore" : "Archive")}
        </button>
      </div>
    </dialog>
  );
}

export function DirectoryScreen({
  profile,
  initialTab,
  onExit,
  onlyResource,
}: {
  profile: Profile;
  initialTab?: DirectoryKey;
  onExit: () => void;
  onlyResource?: DirectoryKey;
}) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const t = (text: string) => directoryText(locale, text);
  const permitted = (permission: string) =>
    profile.permissions.includes(permission);
  const resources = DIRECTORY_RESOURCES.filter((resource) =>
    permitted(`${resource.permission}.view`) && (onlyResource ? resource.key === onlyResource : !["weavers", "suppliers"].includes(resource.key)),
  );
  const [active, setActive] = useState<DirectoryKey>(() =>
    resources.some((r) => r.key === initialTab)
      ? initialTab!
      : (resources[0]?.key ?? "weavers"),
  );
  const resource = resources.find((r) => r.key === active);
  const hasResource = Boolean(resource);
  const [rows, setRows] = useState<Result | null>(null);
  const [lookups, setLookups] = useState<DirectoryLookups>(
    EMPTY_DIRECTORY_LOOKUPS,
  );
  const [editor, setEditor] = useState<{ row?: DirectoryRecord } | null>(null);
  const [activity, setActivity] = useState<number | null>(null);
  const [confirmation, setConfirmation] = useState<DirectoryRecord | null>(
    null,
  );
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState("active");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [busy, setBusy] = useState(false);
  const [banner, setBanner] = useState("");
  const [flash, setFlash] = useState("");
  const editable =
    resource &&
    permitted(`${resource.permission}.update`) &&
    (!resource.adminOnly || profile.is_administrator);
  const creatable =
    resource &&
    permitted(`${resource.permission}.create`) &&
    (!resource.adminOnly || profile.is_administrator);
  const archivable =
    resource &&
    permitted(`${resource.permission}.delete`) &&
    (!resource.adminOnly || profile.is_administrator);

  useEffect(() => {
    if (!hasResource) return;
    const controller = new AbortController();
    const params = new URLSearchParams({
      search: query,
      status,
      page: String(page),
      per_page: "10",
    });
    apiFetch<Result>(`/directories/${active}?${params}`, {
      signal: controller.signal,
    })
      .then((result) => {
        if (!controller.signal.aborted) {
          const lastPage = Math.max(1, result.last_page);
          if (page > lastPage) {
            setRows(null);
            setPage(lastPage);
            return;
          }
          setRows(result);
          setBanner("");
        }
      })
      .catch((error) => {
        if (!controller.signal.aborted) {
          setRows(null);
          setBanner(
            isApiFailure(error) ? error.message : "Could not load records.",
          );
        }
      });
    return () => controller.abort();
  }, [active, hasResource, query, status, page, revision]);

  function changeTab(key: DirectoryKey) {
    setActive(key);
    setRows(null);
    setPage(1);
    setSearch("");
    setQuery("");
    setStatus("active");
    setBanner("");
    setFlash("");
    setEditor(null);
    setConfirmation(null);
  }
  function searchRecords(event: FormEvent) {
    event.preventDefault();
    setPage(1);
    setQuery(search.trim());
    setRows(null);
    setRevision((n) => n + 1);
  }
  async function openEditor(row?: DirectoryRecord) {
    if (busy) return;
    setBusy(true);
    setBanner("");
    try {
      const options = await apiFetch<{ data: DirectoryLookups }>(
        `/directories/lookups${active === "weavers" && row ? `?weaver_id=${row.id}` : ""}`,
      );
      const fresh = row
        ? (
            await apiFetch<{ data: DirectoryRecord }>(
              `/directories/${active}/${row.id}`,
            )
          ).data
        : undefined;
      setLookups({ ...EMPTY_DIRECTORY_LOOKUPS, ...options.data });
      setEditor({ row: fresh });
    } catch (error) {
      setBanner(
        isApiFailure(error) ? error.message : "Could not load options.",
      );
    } finally {
      setBusy(false);
    }
  }
  async function save(data: Record<string, unknown>) {
    await apiFetch(
      `/directories/${active}${editor?.row ? `/${editor.row.id}` : ""}`,
      { method: editor?.row ? "PUT" : "POST", body: JSON.stringify(data) },
    );
    setEditor(null);
    setRows(null);
    setRevision((n) => n + 1);
    setFlash(t("Record saved."));
  }
  async function confirmAction() {
    if (!confirmation || !resource || busy) return;
    setBusy(true);
    setBanner("");
    const restoring = confirmation.status === "inactive";
    try {
      const path = `/directories/${active}/${confirmation.id}`;
      if (restoring) {
        const fresh = (await apiFetch<{ data: DirectoryRecord }>(path)).data;
        const values = {
          ...directoryInitialValues(resource, fresh),
          status: "active",
        };
        await apiFetch(path, {
          method: "PUT",
          body: JSON.stringify(directoryPayload(resource, values)),
        });
      } else await apiFetch(path, { method: "DELETE" });
      setConfirmation(null);
      setRows(null);
      setPage(1);
      setRevision((n) => n + 1);
      setFlash(t(restoring ? "Record restored." : "Record archived."));
    } catch (error) {
      setConfirmation(null);
      setBanner(
        isApiFailure(error)
          ? Object.values(error.fieldErrors).join(" ") || error.message
          : "Could not update the record.",
      );
    } finally {
      setBusy(false);
    }
  }
  function cell(row: DirectoryRecord, key: string): string {
    if (key === "status") return t(String(row[key]));
    if (key === "province_name")
      return String(
        row[key] ??
          lookups.provinces.find(
            (option) => option.id === Number(row.province_id),
          )?.name ??
          "—",
      );
    return String(row[key] ?? "—");
  }

  return (
    <section
      className={styles.screen}
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
      aria-label={t("Directories")}
    >
      <header className={styles.headingRow}>
        <div>
          <h1>{t(onlyResource ? resource?.label ?? "Directories" : "Directories")}</h1>
        </div>
        {!onlyResource && <button onClick={onExit}>{t("Back to dashboard")}</button>}
      </header>
      {!onlyResource && <nav className={styles.tabs} aria-label={t("Directories")}>
        {resources.map((item) => (
          <button
            key={item.key}
            disabled={busy}
            aria-current={active === item.key ? "page" : undefined}
            onClick={() => changeTab(item.key)}
          >
            {t(item.label)}
          </button>
        ))}
      </nav>}
      {banner && (
        <div className={styles.error} role="alert">
          {t(banner)}
          <button
            type="button"
            onClick={() => {
              setBanner("");
              setRows(null);
              setRevision((n) => n + 1);
            }}
          >
            {t("Retry")}
          </button>
        </div>
      )}
      {flash && (
        <p className={styles.notice} role="status">
          {flash}
        </p>
      )}
      {!resource ? (
        <p>{t("No accessible directories.")}</p>
      ) : (
        <>
          <div className={styles.headingRow}>
            <h2>{t(resource.label)}</h2>
            {creatable && (
              <button
                className={styles.primary}
                disabled={busy}
                onClick={() => openEditor()}
              >
                {t(busy ? "Loading..." : "Add record")}
              </button>
            )}
          </div>
          <form className={styles.filters} onSubmit={searchRecords}>
            <label htmlFor="directory-search">{t("Search")}</label>
            <input
              id="directory-search"
              maxLength={100}
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
            <button>{t("Search")}</button>
            <label htmlFor="directory-status">{t("Status")}</label>
            <select
              id="directory-status"
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
                setRows(null);
              }}
            >
              <option value="all">{t("All statuses")}</option>
              <option value="active">{t("active")}</option>
              <option value="inactive">{t("inactive")}</option>
            </select>
          </form>
          <div className={styles.tableWrap}>
            <table aria-label={t(resource.label)}>
              <thead>
                <tr>
                  {resource.columns.map((column) => (
                    <th key={column.key}>{t(column.label)}</th>
                  ))}
                  <th>{t("Action")}</th>
                </tr>
              </thead>
              <tbody>
                {rows?.data.map((row) => (
                  <tr key={row.id}>
                    {resource.columns.map((column) => (
                      <td
                        key={column.key}
                        dir={column.key === "phone" ? "ltr" : undefined}
                      >
                        {cell(row, column.key)}
                      </td>
                    ))}
                    <td>
                      <div className={styles.rowActions}>
                        {active === "weavers" && <button type="button" onClick={() => setActivity(row.id)}>{t("View activity")}</button>}
                        {editable && (
                          <button
                            disabled={busy}
                            onClick={() => openEditor(row)}
                          >
                            {t("Edit")}
                          </button>
                        )}
                        {row.status === "inactive"
                          ? editable && (
                              <button
                                disabled={busy}
                                onClick={() => setConfirmation(row)}
                              >
                                {t("Restore")}
                              </button>
                            )
                          : archivable && (
                              <button
                                disabled={busy}
                                onClick={() => setConfirmation(row)}
                              >
                                {t("Archive")}
                              </button>
                            )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {!rows && !banner && <p role="status">{t("Loading...")}</p>}
          {rows?.data.length === 0 && <p>{t("No records found.")}</p>}
          <div className={styles.pager}>
            <button
              disabled={page <= 1 || !rows}
              onClick={() => {
                setPage((n) => n - 1);
                setRows(null);
              }}
            >
              {t("Previous")}
            </button>
            <span>
              {page} / {rows?.last_page ?? 1} ({rows?.total ?? 0})
            </span>
            <button
              disabled={!rows || page >= rows.last_page}
              onClick={() => {
                setPage((n) => n + 1);
                setRows(null);
              }}
            >
              {t("Next")}
            </button>
          </div>
        </>
      )}
      {editor && resource && (
        <DirectoryEditor
          resource={resource}
          row={editor.row}
          lookups={lookups}
          locale={locale}
          isAdministrator={Boolean(profile.is_administrator)}
          canArchive={Boolean(archivable)}
          onSave={save}
          onClose={() => setEditor(null)}
        />
      )}
      {confirmation && (
        <ConfirmAction
          row={confirmation}
          restoring={confirmation.status === "inactive"}
          locale={locale}
          busy={busy}
          onClose={() => setConfirmation(null)}
          onConfirm={confirmAction}
        />
      )}
      {activity !== null && <WeaverActivity id={activity} locale={locale} onClose={() => setActivity(null)} />}
    </section>
  );
}
