"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure } from "@/lib/api";
import {
  inventoryValue,
  type InventoryLine,
  type InventoryPage,
  type InventoryRow,
} from "@/lib/inventory";
import { inventoryText } from "@/lib/inventory-i18n";
import styles from "./inventory.module.css";

export function CorrectionPicker({
  resource,
  filters,
  selected,
  onSelect,
  lines,
  onLines,
  locale,
}: {
  resource: "purchases" | "carpets";
  filters: Record<string, string>;
  selected?: InventoryRow;
  onSelect: (row: InventoryRow) => void;
  lines: InventoryLine[];
  onLines: (lines: InventoryLine[]) => void;
  locale: string;
}) {
  const t = (text: string) => inventoryText(locale, text);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [result, setResult] = useState<{
    url: string;
    data: InventoryPage;
  } | null>(null);
  const [failure, setFailure] = useState<{
    url: string;
    message: string;
  } | null>(null);
  const params = new URLSearchParams({
    ...filters,
    search: query,
    page: String(page),
    per_page: "10",
  });
  const path = `/inventory/${resource}?${params}`;
  const url = `${path}&revision=${revision}`;
  useEffect(() => {
    const controller = new AbortController();
    apiFetch<InventoryPage>(path, { signal: controller.signal })
      .then((data) => {
        if (!controller.signal.aborted) {
          setResult({ url, data });
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
  }, [path, url]);
  const data = result?.url === url ? result.data : null;
  function find() {
    setQuery(search);
    setPage(1);
    setRevision(revision + 1);
  }
  return (
    <section
      className={styles.picker}
      aria-label={t(
        resource === "carpets" ? "Selected carpets" : "Choose purchase",
      )}
    >
      <div className={styles.filters}>
        <input
          aria-label={t(
            resource === "carpets" ? "Carpet search" : "Purchase search",
          )}
          value={search}
          maxLength={100}
          onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              find();
            }
          }}
        />
        <button type="button" onClick={find}>
          {t(resource === "carpets" ? "Find carpets" : "Find purchases")}
        </button>
      </div>
      {failure?.url === url ? (
        <p role="alert" className={styles.error}>
          {t(failure.message)}
        </p>
      ) : !data ? (
        <p role="status">{t("Loading...")}</p>
      ) : (
        <>
          {data.data.map((row) => (
            <label key={row.id} className={styles.choice}>
              {resource === "purchases" ? (
                <input
                  type="radio"
                  name="correction-purchase"
                  checked={selected?.id === row.id}
                  onChange={() => onSelect(row)}
                />
              ) : (
                <input
                  type="checkbox"
                  checked={lines.some((l) => l.carpet_id === row.id)}
                  disabled={
                    lines.length >= 50 &&
                    !lines.some((l) => l.carpet_id === row.id)
                  }
                  onChange={(e) =>
                    onLines(
                      e.target.checked
                        ? [
                            ...lines,
                            {
                              carpet_id: row.id,
                              expected_sequence: Number(row.sequence_no),
                              unit_price: "",
                              label: inventoryValue(row, "carpet_code"),
                            },
                          ]
                        : lines.filter((l) => l.carpet_id !== row.id),
                    )
                  }
                />
              )}
              <span>
                {resource === "purchases"
                  ? `${inventoryValue(row, "purchase_no")} · ${inventoryValue(row, "weaver_name")} · ${inventoryValue(row, "remaining_amount")} ${inventoryValue(row, "currency_code")}`
                  : `${inventoryValue(row, "carpet_code")} · ${inventoryValue(row, "name")}`}
              </span>
            </label>
          ))}
          {!data.data.length && <p>{t("No records found.")}</p>}
          <div className={styles.pager}>
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage(page - 1)}
            >
              {t("Previous")}
            </button>
            <span>
              {page} / {data.last_page}
            </span>
            <button
              type="button"
              disabled={page >= data.last_page}
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
