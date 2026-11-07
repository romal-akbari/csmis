"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure } from "@/lib/api";
import {
  inventoryValue,
  type InventoryKind,
  type InventoryLine,
  type InventoryPage,
  type InventoryRow,
} from "@/lib/inventory";
import { inventoryText } from "@/lib/inventory-i18n";
import styles from "./inventory.module.css";

export function InventoryPicker({
  kind,
  branch,
  location,
  lines,
  onLines,
  purchase,
  onPurchase,
  locale,
}: {
  kind: InventoryKind;
  branch: string;
  location: string;
  lines: InventoryLine[];
  onLines: (lines: InventoryLine[]) => void;
  purchase?: InventoryRow;
  onPurchase: (row: InventoryRow) => void;
  locale: string;
}) {
  const t = (text: string) => inventoryText(locale, text);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [result, setResult] = useState<{
    url: string;
    page: InventoryPage;
  } | null>(null);
  const [failure, setFailure] = useState<{
    url: string;
    message: string;
  } | null>(null);
  const payments = kind === "payments";
  const params = new URLSearchParams({
    search: query,
    page: String(page),
    per_page: "10",
    branch_id: branch,
  });
  if (payments) params.set("unpaid", "1");
  else {
    params.set(
      "stock_status",
      kind === "purchases"
        ? "available_for_purchase"
        : kind === "receipts"
          ? "purchased"
          : "in_stock",
    );
    if (location) params.set("location_id", location);
  }
  const path = `/inventory/${payments ? "purchases" : "carpets"}?${params}`;
  const url = `${path}&revision=${revision}`;
  useEffect(() => {
    if (!branch) return;
    const controller = new AbortController();
    apiFetch<InventoryPage>(path, { signal: controller.signal })
      .then((value) => {
        if (!controller.signal.aborted) {
          setResult({ url, page: value });
          setFailure(null);
        }
      })
      .catch((error) => {
        if (!controller.signal.aborted)
          setFailure({
            url,
            message: isApiFailure(error)
              ? error.message
              : "Could not load inventory.",
          });
      });
    return () => controller.abort();
  }, [branch, path, url]);
  const current = result?.url === url ? result.page : null;
  return (
    <section
      className={styles.picker}
      aria-label={t(payments ? "Purchase to settle" : "Selected carpets")}
    >
      <div className={styles.filters}>
        <input
          aria-label={t(payments ? "Purchase search" : "Carpet search")}
          value={search}
          maxLength={100}
          onChange={(event) => setSearch(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === "Enter") {
              event.preventDefault();
              setQuery(search);
              setPage(1);
              setRevision(revision + 1);
            }
          }}
        />
        <button
          type="button"
          disabled={!branch}
          onClick={() => {
            setQuery(search);
            setPage(1);
            setRevision(revision + 1);
          }}
        >
          {t(payments ? "Find purchases" : "Find carpets")}
        </button>
      </div>
      {failure?.url === url ? (
        <p role="alert" className={styles.error}>
          {t(failure.message)}
        </p>
      ) : !branch ? (
        <p>{t("Choose an option")}</p>
      ) : !current ? (
        <p role="status">{t("Loading...")}</p>
      ) : (
        <>
          {current.data.map((row) =>
            payments ? (
              <label className={styles.choice} key={row.id}>
                <input
                  type="radio"
                  name="settlement-purchase"
                  checked={purchase?.id === row.id}
                  onChange={() => onPurchase(row)}
                />
                <span>
                  {inventoryValue(row, "purchase_no")} ·{" "}
                  {inventoryValue(row, "weaver_name")} · {t("Unpaid balance")}:{" "}
                  {inventoryValue(row, "remaining_amount")}{" "}
                  {inventoryValue(row, "currency_code")}
                </span>
              </label>
            ) : (
              <label className={styles.choice} key={row.id}>
                <input
                  type="checkbox"
                  checked={lines.some((line) => line.carpet_id === row.id)}
                  onChange={(event) =>
                    onLines(
                      event.target.checked
                        ? [
                            ...lines,
                            {
                              carpet_id: row.id,
                              expected_sequence: Number(row.sequence_no),
                              unit_price: "",
                              label: `${inventoryValue(row, "carpet_code")} · ${inventoryValue(row, "name")}`,
                            },
                          ]
                        : lines.filter((line) => line.carpet_id !== row.id),
                    )
                  }
                  disabled={
                    lines.length >= 50 &&
                    !lines.some((line) => line.carpet_id === row.id)
                  }
                />
                <span>
                  {inventoryValue(row, "carpet_code")} ·{" "}
                  {inventoryValue(row, "name")}
                </span>
              </label>
            ),
          )}
          {!current.data.length && (
            <p>
              {t(payments ? "No matching purchases." : "No matching carpets.")}
            </p>
          )}
          <div className={styles.pager}>
            <button
              type="button"
              disabled={page === 1}
              onClick={() => setPage(page - 1)}
            >
              {t("Previous")}
            </button>
            <span>
              {page} / {current.last_page}
            </span>
            <button
              type="button"
              disabled={page >= current.last_page}
              onClick={() => setPage(page + 1)}
            >
              {t("Next")}
            </button>
          </div>
        </>
      )}
    </section>
  );
}
