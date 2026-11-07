"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import {
  INVENTORY_PAGES,
  inventoryValue,
  type InventoryKind,
  type InventoryLookups,
  type InventoryPage,
  type InventoryRow,
} from "@/lib/inventory";
import { inventoryText } from "@/lib/inventory-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { InventoryEditor, inventoryAction } from "./InventoryEditor";
import { InventoryDetail, ReverseWeaverPayment } from "./InventoryDetail";
import styles from "./inventory.module.css";
import { CorrectionEditor } from "./CorrectionEditor";
import { PurchaseTable } from "./PurchaseTable";

const columns: Record<Exclude<InventoryKind, "purchases">, [string, string][]> = {
  carpets: [
    ["carpet_code", "Carpet code"],
    ["name", "Name"],
    ["category_name", "Carpet categories"],
    ["stock_status", "Current status"],
    ["branch_name", "Branch"],
    ["location_name", "Current location"],
  ],
  receipts: [
    ["receipt_no", "Receipt reference"],
    ["received_on", "Date"],
    ["location_name", "Stock location"],
  ],
  transfers: [
    ["transfer_no", "Transfer reference"],
    ["transferred_on", "Date"],
    ["from_location_name", "From location"],
    ["to_location_name", "To location"],
  ],
  payments: [
    ["payment_no", "Payment reference"],
    ["weaver_name", "Weaver"],
    ["payment_type", "Payment type"],
    ["payment_on", "Date"],
    ["amount", "Amount"],
    ["currency_code", "Currency"],
  ],
};

export function InventoryScreen({
  label,
  profile,
}: {
  label: string;
  profile: Profile;
}) {
  const configuration = INVENTORY_PAGES[label];
  const { kind } = configuration;
  const visibleColumns = kind === "purchases" ? [] : columns[kind];
  const locale = useAppSelector((state) => state.dashboard.locale);
  const t = (text: string) => inventoryText(locale, text);
  const [rows, setRows] = useState<InventoryPage | null>(null);
  const [lookups, setLookups] = useState<InventoryLookups | null>(null);
  const [error, setError] = useState("");
  const [flash, setFlash] = useState("");
  const [editor, setEditor] = useState<{
    kind: InventoryKind;
    row?: InventoryRow;
    purchase?: InventoryRow;
  } | null>(null);
  const [detail, setDetail] = useState<InventoryRow | null>(null);
  const [reversal, setReversal] = useState<InventoryRow | null>(null);
  const [correction, setCorrection] = useState<{
    action: "void" | "returns" | "refunds";
    purchase: InventoryRow;
  } | null>(null);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [branch, setBranch] = useState("");
  const [stock, setStock] = useState("");
  const [location, setLocation] = useState("");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const permitted = (code: string) => profile.permissions.includes(code);
  useEffect(() => {
    const controller = new AbortController();
    const params = new URLSearchParams({
      search: query,
      page: String(page),
      per_page: "15",
    });
    if (branch) params.set("branch_id", branch);
    if (stock) params.set("stock_status", stock);
    if (location) params.set("location_id", location);
    Promise.all([
      apiFetch<InventoryPage>(`/inventory/${kind}?${params}`, {
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
  }, [kind, query, branch, stock, location, page, revision]);
  function reload() {
    setRows(null);
    setError("");
    setRevision((value) => value + 1);
  }
  async function save(data: Record<string, unknown>, key: string) {
    if (!editor) return;
    await apiFetch(
      `/inventory/${editor.kind}${editor.row ? `/${editor.row.id}` : ""}`,
      {
        method: editor.row ? "PUT" : "POST",
        body: JSON.stringify(data),
        headers: { "Idempotency-Key": key },
      },
    );
    setEditor(null);
    setFlash("Saved successfully.");
    reload();
  }
  function renderActions(row: InventoryRow) {
    return (
      <div className={styles.actions}>
        <button type="button" onClick={() => setDetail(row)}>
          {t("View")}
        </button>
        {kind === "carpets" && permitted("stock.update") && (
          <button
            type="button"
            disabled={!lookups}
            onClick={() => setEditor({ kind, row })}
          >
            {t("Edit")}
          </button>
        )}
        {kind === "purchases" &&
          permitted("weaver-payments.create") &&
          Number(row.remaining_amount) > 0 && (
            <button
              type="button"
              disabled={!lookups}
              onClick={() => setEditor({ kind: "payments", purchase: row })}
            >
              {t("Record payment")}
            </button>
          )}
        {kind === "purchases" &&
          row.state === "posted" &&
          permitted("weaver-purchases.cancel") && (
            <button
              type="button"
              disabled={!lookups}
              onClick={() => setCorrection({ action: "void", purchase: row })}
            >
              {t("Void purchase")}
            </button>
          )}
        {kind === "purchases" &&
          row.state === "posted" &&
          permitted("weaver-purchases.return") && (
            <button
              type="button"
              disabled={!lookups}
              onClick={() =>
                setCorrection({
                  action: "returns",
                  purchase: row,
                })
              }
            >
              {t("Return carpets")}
            </button>
          )}
        {kind === "purchases" &&
          Number(row.remaining_amount) < 0 &&
          permitted("weaver-refunds.create") && (
            <button
              type="button"
              disabled={!lookups}
              onClick={() =>
                setCorrection({
                  action: "refunds",
                  purchase: row,
                })
              }
            >
              {t("Record refund")}
            </button>
          )}
        {kind === "payments" &&
          (Number(row.reversed) ? (
            <span className={styles.badge}>{t("Reversed")}</span>
          ) : (
            permitted("weaver-payments.reverse") &&
            permitted("ledger.reverse") && (
              <button
                type="button"
                onClick={() => setReversal(row)}
              >
                {t("Reverse payment")}
              </button>
            )
          ))}
      </div>
    );
  }
  return (
    <section className={styles.screen} aria-labelledby="inventory-screen-title">
      <div className={styles.heading}>
        <div>
          <h1 id="inventory-screen-title">{t(label)}</h1>
          <p className={styles.muted}>
            {rows ? `${rows.total} ${t("records")}` : ""}
          </p>
        </div>
        {kind !== "carpets" && permitted(configuration.create) && (
          <button
            className={styles.primary}
            disabled={!lookups}
            type="button"
            onClick={() => {
              setFlash("");
              setEditor({ kind });
            }}
          >
            {t(inventoryAction(kind))}
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
          {t(error)}{" "}
          <button type="button" onClick={reload}>
            {t("Retry")}
          </button>
        </p>
      )}
      <form
        className={styles.filters}
        onSubmit={(event) => {
          event.preventDefault();
          setQuery(search);
          setPage(1);
          reload();
        }}
      >
        <input
          aria-label={t("Search records")}
          maxLength={100}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
        />
        <button type="submit">{t("Search")}</button>
        {kind === "carpets" && (
          <>
            <select
              aria-label={t("Branch")}
              value={branch}
              onChange={(event) => {
                setBranch(event.target.value);
                setLocation("");
                setPage(1);
                setRows(null);
              }}
            >
              <option value="">{t("All branches")}</option>
              {lookups?.branches.map((item) => (
                <option key={item.id} value={item.id}>
                  {item.name}
                </option>
              ))}
            </select>
            <select
              aria-label={t("Current location")}
              value={location}
              onChange={(event) => {
                setLocation(event.target.value);
                setPage(1);
                setRows(null);
              }}
            >
              <option value="">{t("All locations")}</option>
              {lookups?.locations
                .filter((item) => !branch || String(item.branch_id) === branch)
                .map((item) => (
                  <option key={item.id} value={item.id}>
                    {item.name}
                  </option>
                ))}
            </select>
            <select
              aria-label={t("Current status")}
              value={stock}
              onChange={(event) => {
                setStock(event.target.value);
                setPage(1);
                setRows(null);
              }}
            >
              <option value="">{t("All stock")}</option>
              {[
                "registered",
                "purchased",
                "at_washing",
                "washing_completed",
                "in_stock",
                "missing",
                "returned",
              ].map((value) => (
                <option key={value} value={value}>
                  {t(value)}
                </option>
              ))}
            </select>
          </>
        )}
        <button
          type="button"
          onClick={() => {
            setSearch("");
            setQuery("");
            setStock("");
            setBranch("");
            setLocation("");
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
          {kind === "purchases" ? (
            <PurchaseTable rows={rows.data} locale={locale} renderActions={renderActions} />
          ) : (
            <div className={styles.table} tabIndex={0} aria-label={t(label)}>
              <table>
                <thead>
                  <tr>
                    {visibleColumns.map(([key, heading]) => (
                      <th key={key}>{t(heading)}</th>
                    ))}
                    <th>{t("Actions")}</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.data.map((row) => (
                    <tr key={row.id}>
                      {visibleColumns.map(([key]) => (
                        <td key={key}>
                          {key === "stock_status" ||
                          key === "payment_type" ||
                          key === "state" ? (
                            <span className={styles.badge}>
                              {t(inventoryValue(row, key))}
                            </span>
                          ) : key === "remaining_amount" &&
                            Number(row.remaining_amount) < 0 ? (
                            <span>
                              {t("Credit owed by weaver")}:{" "}
                              {inventoryValue(row, key).slice(1)}
                            </span>
                          ) : (
                            inventoryValue(row, key) || "—"
                          )}
                        </td>
                      ))}
                      <td>{renderActions(row)}</td>
                    </tr>
                  ))}
                  {!rows.data.length && (
                    <tr>
                      <td colSpan={visibleColumns.length + 1}>
                        {t("No records found.")}
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          )}
          <div className={styles.pager}>
            <button
              type="button"
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
              type="button"
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
      {editor && lookups && (
        <InventoryEditor
          key={`${editor.kind}:${editor.row?.id ?? "new"}`}
          kind={editor.kind}
          row={editor.row}
          initialPurchase={editor.purchase}
          lookups={lookups}
          locale={locale}
          onSave={save}
          onSaveDraft={
            kind === "purchases" && permitted("weaver-purchases.draft")
              ? async (payload, key) => {
                  await apiFetch("/inventory/corrections/drafts", {
                    method: "POST",
                    body: JSON.stringify(payload),
                    headers: { "Idempotency-Key": key },
                  });
                  setEditor(null);
                  setFlash(
                    "Draft saved. Open Purchase drafts to edit or post it.",
                  );
                  reload();
                }
              : undefined
          }
          onClose={() => setEditor(null)}
        />
      )}
      {detail && (
        <InventoryDetail
          kind={kind}
          row={detail}
          locale={locale}
          canEditImages={permitted("stock.update")}
          onClose={() => setDetail(null)}
        />
      )}
      {reversal && (
        <ReverseWeaverPayment
          row={reversal}
          locale={locale}
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
            setFlash("Payment reversed.");
            reload();
          }}
        />
      )}
      {correction && lookups && (
        <CorrectionEditor
          action={correction.action}
          initialPurchase={correction.purchase}
          lookups={lookups}
          locale={locale}
          onClose={() => setCorrection(null)}
          onSave={async (path, payload, key) => {
            await apiFetch(path, {
              method: "POST",
              body: JSON.stringify(payload),
              headers: { "Idempotency-Key": key },
            });
            setCorrection(null);
            setFlash("Saved successfully.");
            reload();
          }}
        />
      )}
    </section>
  );
}
