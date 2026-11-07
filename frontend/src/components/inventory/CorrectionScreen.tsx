"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import {
  CORRECTION_PAGES,
  type CorrectionAction,
} from "@/lib/inventory-corrections";
import {
  inventoryValue,
  type InventoryLookups,
  type InventoryPage,
  type InventoryRow,
} from "@/lib/inventory";
import { inventoryText } from "@/lib/inventory-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { InventoryEditor } from "./InventoryEditor";
import { CorrectionEditor } from "./CorrectionEditor";
import { ReverseWeaverPayment } from "./InventoryDetail";
import styles from "./inventory.module.css";

const columns = {
  drafts: [
    ["purchase_no", "Purchase reference"],
    ["state", "Status"],
    ["purchase_on", "Purchase date"],
  ],
  adjustments: [
    ["adjustment_no", "Correction reference"],
    ["adjustment_type", "Correction type"],
    ["adjusted_on", "Correction date"],
    ["reason", "Reason"],
  ],
  returns: [
    ["return_no", "Return reference"],
    ["purchase_no", "Purchase reference"],
    ["weaver_name", "Weaver"],
    ["returned_on", "Return date"],
    ["returned_amount", "Returned amount"],
    ["purchase_currency", "Currency"],
  ],
  refunds: [
    ["refund_no", "Refund reference"],
    ["purchase_no", "Purchase reference"],
    ["amount", "Amount"],
    ["currency_code", "Currency"],
    ["refunded_on", "Refund date"],
    ["amount_in_purchase_currency", "Converted settlement"],
    ["purchase_currency", "Purchase currency"],
  ],
};
export function CorrectionScreen({
  label,
  profile,
}: {
  label: string;
  profile: Profile;
}) {
  const config = CORRECTION_PAGES[label];
  const { kind } = config;
  const locale = useAppSelector((s) => s.dashboard.locale);
  const t = (text: string) => inventoryText(locale, text);
  const [rows, setRows] = useState<InventoryPage | null>(null);
  const [lookups, setLookups] = useState<InventoryLookups | null>(null);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [state, setState] = useState("draft");
  const [revision, setRevision] = useState(0);
  const [error, setError] = useState("");
  const [flash, setFlash] = useState("");
  const [draft, setDraft] = useState<{ row?: InventoryRow } | null>(null);
  const [dialog, setDialog] = useState<{
    action: CorrectionAction;
    row?: InventoryRow;
  } | null>(null);
  const [reversal, setReversal] = useState<InventoryRow | null>(null);
  const [opening, setOpening] = useState(false);
  const can = (code: string) => profile.permissions.includes(code);
  useEffect(() => {
    const controller = new AbortController();
    const params = new URLSearchParams({
      search: query,
      page: String(page),
      per_page: "15",
      ...(kind === "drafts" ? { state } : {}),
    });
    Promise.all([
      apiFetch<InventoryPage>(`/inventory/corrections/${kind}?${params}`, {
        signal: controller.signal,
      }),
      apiFetch<{ data: InventoryLookups }>("/inventory/lookups", {
        signal: controller.signal,
      }),
    ])
      .then(([result, choices]) => {
        if (!controller.signal.aborted) {
          setRows(result);
          setLookups(choices.data);
          setError("");
        }
      })
      .catch((failure) => {
        if (!controller.signal.aborted)
          setError(
            isApiFailure(failure)
              ? failure.message
              : "Could not load inventory.",
          );
      });
    return () => controller.abort();
  }, [kind, query, page, revision, state]);
  function reload() {
    setRows(null);
    setError("");
    setRevision((n) => n + 1);
  }
  async function save(
    path: string,
    payload: Record<string, unknown>,
    key: string,
    method = "POST",
  ) {
    await apiFetch(path, {
      method,
      body: JSON.stringify(payload),
      headers: { "Idempotency-Key": key },
    });
    setDraft(null);
    setDialog(null);
    setFlash("Saved successfully.");
    reload();
  }
  async function edit(row: InventoryRow) {
    setOpening(true);
    setError("");
    try {
      const result = await apiFetch<{ data: InventoryRow }>(
        `/inventory/corrections/drafts/${row.id}`,
      );
      setDraft({ row: result.data });
    } catch (failure) {
      setError(
        isApiFailure(failure) ? failure.message : "Could not load inventory.",
      );
    } finally {
      setOpening(false);
    }
  }
  return (
    <section
      className={styles.screen}
      aria-labelledby="correction-screen-title"
    >
      <div className={styles.heading}>
        <div>
          <h1 id="correction-screen-title">{t(label)}</h1>
          <p className={styles.muted}>
            {rows ? `${rows.total} ${t("records")}` : ""}
          </p>
        </div>
        {can(config.create) && (
          <button
            className={styles.primary}
            disabled={!lookups}
            onClick={() =>
              kind === "drafts" ? setDraft({}) : setDialog({ action: kind })
            }
          >
            {t(config.action)}
          </button>
        )}
      </div>
      {flash && (
        <p className={styles.success} role="status">
          {t(flash)}
        </p>
      )}
      {error && (
        <p className={styles.error} role="alert">
          {t(error)} <button onClick={reload}>{t("Retry")}</button>
        </p>
      )}
      <form
        className={styles.filters}
        onSubmit={(e) => {
          e.preventDefault();
          setQuery(search);
          setPage(1);
          reload();
        }}
      >
        <input
          aria-label={t("Search records")}
          maxLength={100}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <button>{t("Search")}</button>
        {kind === "drafts" && (
          <select
            aria-label={t("Status")}
            value={state}
            onChange={(e) => {
              setState(e.target.value);
              setPage(1);
              setRows(null);
            }}
          >
            {["draft", "posted", "cancelled", "all"].map((s) => (
              <option key={s} value={s}>
                {t(s)}
              </option>
            ))}
          </select>
        )}
        <button
          type="button"
          onClick={() => {
            setSearch("");
            setQuery("");
            setPage(1);
            reload();
          }}
        >
          {t("Clear")}
        </button>
      </form>
      {!rows ? (
        !error && <p role="status">{t("Loading...")}</p>
      ) : (
        <>
          <div className={styles.table} tabIndex={0}>
            <table>
              <thead>
                <tr>
                  {columns[kind].map(([key, text]) => (
                    <th key={key}>{t(text)}</th>
                  ))}
                  <th>{t("Actions")}</th>
                </tr>
              </thead>
              <tbody>
                {rows.data.map((row) => (
                  <tr key={row.id}>
                    {columns[kind].map(([key]) => (
                      <td key={key}>
                        {["state", "adjustment_type"].includes(key)
                          ? t(inventoryValue(row, key))
                          : inventoryValue(row, key) || "—"}
                      </td>
                    ))}
                    <td>
                      <div className={styles.actions}>
                        <button
                          disabled={opening}
                          onClick={() => setDialog({ action: "view", row })}
                        >
                          {t("View")}
                        </button>
                        {kind === "drafts" &&
                          row.state === "draft" &&
                          can(config.create) && (
                            <>
                              <button
                                disabled={opening}
                                onClick={() => edit(row)}
                              >
                                {t("Edit draft")}
                              </button>
                              {can("weaver-purchases.create") && (
                                <button
                                  onClick={() =>
                                    setDialog({ action: "post-draft", row })
                                  }
                                >
                                  {t("Post draft")}
                                </button>
                              )}
                              <button
                                onClick={() =>
                                  setDialog({ action: "cancel-draft", row })
                                }
                              >
                                {t("Cancel draft")}
                              </button>
                            </>
                          )}
                        {kind === "refunds" &&
                          (Number(row.reversed) ? (
                            <span className={styles.badge}>
                              {t("Reversed")}
                            </span>
                          ) : (
                            can("weaver-refunds.reverse") &&
                            can("ledger.reverse") && (
                              <button onClick={() => setReversal(row)}>
                                {t("Reverse refund")}
                              </button>
                            )
                          ))}
                      </div>
                    </td>
                  </tr>
                ))}
                {!rows.data.length && (
                  <tr>
                    <td colSpan={columns[kind].length + 1}>
                      {t("No records found.")}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
          <div className={styles.pager}>
            <button
              disabled={page <= 1}
              onClick={() => {
                setPage(page - 1);
                setRows(null);
              }}
            >
              {t("Previous")}
            </button>
            <span>
              {page} / {rows.last_page}
            </span>
            <button
              disabled={page >= rows.last_page}
              onClick={() => {
                setPage(page + 1);
                setRows(null);
              }}
            >
              {t("Next")}
            </button>
          </div>
        </>
      )}
      {draft && lookups && (
        <InventoryEditor
          kind="purchases"
          row={draft.row}
          draftMode
          lookups={lookups}
          locale={locale}
          initialLines={((draft.row?.items ?? []) as InventoryRow[]).map(
            (i) => ({
              carpet_id: Number(i.carpet_id),
              expected_sequence: 0,
              unit_price: inventoryValue(i, "unit_price"),
              label: `${inventoryValue(i, "carpet_code")} · ${inventoryValue(i, "name")}`,
            }),
          )}
          onClose={() => setDraft(null)}
          onSave={(payload, key) =>
            save(
              `/inventory/corrections/drafts${draft.row ? `/${draft.row.id}` : ""}`,
              payload,
              key,
              draft.row ? "PUT" : "POST",
            )
          }
        />
      )}
      {dialog && lookups && (
        <CorrectionEditor
          key={`${dialog.action}:${dialog.row?.id ?? "new"}`}
          action={dialog.action}
          resource={kind}
          row={dialog.row}
          lookups={lookups}
          locale={locale}
          onSave={save}
          onClose={() => setDialog(null)}
        />
      )}
      {reversal && (
        <ReverseWeaverPayment
          row={reversal}
          locale={locale}
          actionLabel="Reverse refund"
          onClose={() => setReversal(null)}
          onSave={async (reason, key) => {
            await apiFetch(
              `/foundation/ledger/${reversal.account_transaction_id}/reverse`,
              {
                method: "POST",
                body: JSON.stringify({ reason }),
                headers: { "Idempotency-Key": key },
              },
            );
            setReversal(null);
            setFlash("Refund reversed.");
            reload();
          }}
        />
      )}
    </section>
  );
}
