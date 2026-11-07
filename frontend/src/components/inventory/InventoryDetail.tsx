"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { FiX } from "react-icons/fi";
import { apiBlob, apiFetch, isApiFailure } from "@/lib/api";
import {
  inventoryValue,
  type InventoryKind,
  type InventoryRow,
} from "@/lib/inventory";
import { inventoryText } from "@/lib/inventory-i18n";
import styles from "./inventory.module.css";

function PrivateImage({
  path,
  alt,
  locale,
}: {
  path: string;
  alt: string;
  locale: string;
}) {
  const [source, setSource] = useState("");
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    const controller = new AbortController();
    let objectUrl = "";
    apiBlob(path, controller.signal)
      .then((blob) => {
        if (!controller.signal.aborted) {
          objectUrl = URL.createObjectURL(blob);
          setSource(objectUrl);
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) setFailed(true);
      });
    return () => {
      controller.abort();
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [path]);
  if (failed)
    return <p role="alert">{inventoryText(locale, "Could not load image.")}</p>;
  // The source is an authenticated, short-lived blob; Next image optimization cannot fetch it.
  return source ? (
    // eslint-disable-next-line @next/next/no-img-element
    <img src={source} alt={alt} />
  ) : (
    <p role="status">{inventoryText(locale, "Loading...")}</p>
  );
}

export function InventoryDetail({
  kind,
  row,
  locale,
  canEditImages,
  onClose,
}: {
  kind: InventoryKind;
  row: InventoryRow;
  locale: string;
  canEditImages: boolean;
  onClose: () => void;
}) {
  const t = (text: string) => inventoryText(locale, text);
  const dialog = useRef<HTMLDialogElement>(null);
  const [data, setData] = useState<InventoryRow | null>(null);
  const [error, setError] = useState("");
  const [flash, setFlash] = useState("");
  const [revision, setRevision] = useState(0);
  const [file, setFile] = useState<File | null>(null);
  const [alt, setAlt] = useState("");
  const [busy, setBusy] = useState(false);
  const activeRequest = useRef(false);
  const uploadKey = useRef("");
  const fileInput = useRef<HTMLInputElement>(null);
  useEffect(() => {
    const node = dialog.current;
    node?.showModal();
    return () => node?.close();
  }, []);
  useEffect(() => {
    const controller = new AbortController();
    apiFetch<{ data: InventoryRow }>(`/inventory/${kind}/${row.id}`, {
      signal: controller.signal,
    })
      .then((response) => {
        if (!controller.signal.aborted) {
          setData(response.data);
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
  }, [kind, row.id, revision]);
  const list = (key: string) =>
    (Array.isArray(data?.[key]) ? data[key] : []) as InventoryRow[];
  async function upload(event: FormEvent) {
    event.preventDefault();
    if (activeRequest.current) return;
    if (
      !file ||
      file.size > 2 * 1024 * 1024 ||
      !["image/jpeg", "image/png", "image/webp"].includes(file.type)
    ) {
      setError("Choose an image up to 2 MB.");
      return;
    }
    activeRequest.current = true;
    setBusy(true);
    setError("");
    if (!uploadKey.current) uploadKey.current = crypto.randomUUID();
    const form = new FormData();
    form.append("image", file);
    form.append("alt_text", alt);
    try {
      await apiFetch(`/inventory/carpets/${row.id}/images`, {
        method: "POST",
        body: form,
        headers: { "Idempotency-Key": uploadKey.current },
      });
      setFlash("Image saved.");
      setRevision(revision + 1);
      setFile(null);
      setAlt("");
      uploadKey.current = "";
      if (fileInput.current) fileInput.current.value = "";
    } catch (failure) {
      setError(
        isApiFailure(failure)
          ? Object.values(failure.fieldErrors).join(" ") || failure.message
          : "Could not save the record.",
      );
    } finally {
      activeRequest.current = false;
      setBusy(false);
    }
  }
  async function removeImage(id: number) {
    if (activeRequest.current) return;
    activeRequest.current = true;
    setBusy(true);
    setError("");
    try {
      await apiFetch(`/inventory/carpets/${row.id}/images/${id}`, {
        method: "DELETE",
        headers: { "Idempotency-Key": `delete-image-${row.id}-${id}` },
      });
      setRevision(revision + 1);
      setFlash("Image deleted.");
    } catch (failure) {
      setError(
        isApiFailure(failure) ? failure.message : "Could not save the record.",
      );
    } finally {
      activeRequest.current = false;
      setBusy(false);
    }
  }
  const title = [
    "carpet_code",
    "purchase_no",
    "receipt_no",
    "transfer_no",
    "payment_no",
  ]
    .map((key) => inventoryValue(row, key))
    .find(Boolean);
  return (
    <dialog
      ref={dialog}
      className={styles.dialog}
      aria-labelledby="inventory-detail-title"
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
      onCancel={(event) => {
        event.preventDefault();
        if (!busy) onClose();
      }}
    >
      <header>
        <h2 id="inventory-detail-title">{title}</h2>
        <button
          type="button"
          className={styles.close}
          disabled={busy}
          aria-label={t("Close")}
          onClick={onClose}
        >
          <FiX aria-hidden="true" />
        </button>
      </header>
      {error && (
        <p role="alert" className={styles.error}>
          {t(error)}{" "}
          <button
            type="button"
            disabled={busy}
            onClick={() => setRevision(revision + 1)}
          >
            {t("Retry")}
          </button>
        </p>
      )}
      {flash && (
        <p role="status" className={styles.success}>
          {t(flash)}
        </p>
      )}
      {!data ? (
        !error && <p role="status">{t("Loading...")}</p>
      ) : (
        <>
          {kind === "carpets" ? (
            <>
              <p>
                {inventoryValue(data, "name")} ·{" "}
                {inventoryValue(data, "material")}
              </p>
              <div className={styles.summary}>
                <span>
                  {t("Current status")}:{" "}
                  {t(inventoryValue(data, "stock_status"))}
                </span>
                <span>
                  {t("Current location")}:{" "}
                  {inventoryValue(data, "location_name") || inventoryValue(row, "location_name") || "—"}
                </span>
                <span>{t("Business model")}: {t(inventoryValue(data, "business_model"))}</span>
              </div>
              <h3>{t("Movement history")}</h3>
              <div className={styles.table} tabIndex={0}>
                <table>
                  <thead>
                    <tr>
                      {[
                        "Sequence",
                        "Date",
                        "Action",
                        "Stock location",
                        "Handled by",
                      ].map((label) => (
                        <th key={label}>{t(label)}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {list("events").map((item) => (
                      <tr key={item.id}>
                        <td>{inventoryValue(item, "sequence_no")}</td>
                        <td>{inventoryValue(item, "occurred_on")}</td>
                        <td>{t(inventoryValue(item, "event_type"))}</td>
                        <td>{inventoryValue(item, "location_name") || "—"}</td>
                        <td>{inventoryValue(item, "handled_by_name")}</td>
                      </tr>
                    ))}
                    {!list("events").length && (
                      <tr>
                        <td colSpan={5}>{t("No records found.")}</td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>
              <h3>{t("Washing visits")}</h3>
              {list("washing_visits").length ? (
                <div className={styles.washingVisits}>
                  {list("washing_visits").map((visit) => (
                    <article key={visit.id}>
                      <div className={styles.washingVisitHeading}>
                        <strong><bdi>{inventoryValue(visit, "order_no")}</bdi></strong>
                        <span className={styles.badge}>{t(inventoryValue(visit, "returned_on")
                          ? "wash_returned" : inventoryValue(visit, "completed_on")
                            ? "wash_completed" : "wash_sent")}</span>
                      </div>
                      <p>{t("Washing facility")}: {inventoryValue(visit, "facility_name")}</p>
                      <dl className={styles.washingVisitDates}>
                        {[["sent_on", "Sent date"], ["completed_on", "Completed date"],
                          ["returned_on", "Returned date"]].map(([field, label]) => (
                          <div key={field}><dt>{t(label)}</dt>
                            <dd><bdi>{inventoryValue(visit, field) || "—"}</bdi></dd></div>
                        ))}
                      </dl>
                    </article>
                  ))}
                </div>
              ) : <p className={styles.muted}>{t("No washing visits yet.")}</p>}
              {Array.isArray(data.washing_costs) && <>
                <h3>{t("Washing costs")}</h3>
                {list("washing_costs").length ? <div className={styles.summary}>
                  {list("washing_costs").map((cost) => <span key={inventoryValue(cost, "currency_id")}>
                    {t("Allocated cost")}: <bdi>{inventoryValue(cost, "total_amount")} {inventoryValue(cost, "currency_code")}</bdi>
                  </span>)}
                </div> : <p className={styles.muted}>{t("No washing costs allocated yet.")}</p>}
              </>}
              <h3>{t("Carpet images")}</h3>
              <div className={styles.gallery}>
                {list("images").map((item) => (
                  <figure key={item.id}>
                    <PrivateImage
                      path={`/inventory/carpets/${row.id}/images/${item.id}`}
                      alt={
                        inventoryValue(item, "alt_text") ||
                        inventoryValue(row, "name")
                      }
                      locale={locale}
                    />
                    <figcaption>{inventoryValue(item, "alt_text")}</figcaption>
                    {canEditImages && (
                      <button
                        disabled={busy}
                        type="button"
                        onClick={() => removeImage(item.id)}
                      >
                        {t("Delete image")}
                      </button>
                    )}
                  </figure>
                ))}
              </div>
              {!list("images").length && (
                <p className={styles.muted}>{t("No images yet.")}</p>
              )}
              {canEditImages && (
                <form onSubmit={upload}>
                  <fieldset
                    disabled={busy || list("images").length >= 8}
                    style={{ border: 0, padding: 0, minWidth: 0 }}
                  >
                    <p className={styles.muted}>
                      {t(
                        "Choose a JPEG, PNG or WebP image up to 2 MB and 4000 × 4000 pixels.",
                      )}
                    </p>
                    <div className={styles.grid}>
                      <label className={styles.field}>
                        <span>{t("Upload image")}</span>
                        <input
                          ref={fileInput}
                          type="file"
                          accept="image/jpeg,image/png,image/webp"
                          onChange={(event) => {
                            setFile(event.target.files?.[0] ?? null);
                            uploadKey.current = "";
                          }}
                        />
                      </label>
                      <label className={styles.field}>
                        <span>{t("Image description")}</span>
                        <input
                          maxLength={255}
                          value={alt}
                          onChange={(event) => {
                            setAlt(event.target.value);
                            uploadKey.current = "";
                          }}
                        />
                      </label>
                    </div>
                    <button type="submit" disabled={!file}>
                      {t(busy ? "Saving..." : "Upload")}
                    </button>
                  </fieldset>
                </form>
              )}
            </>
          ) : kind === "payments" ? (
            <>
              <p>{inventoryValue(row, "reason")}</p>
              <div className={styles.summary}>
                {inventoryValue(row, "amount")}{" "}
                {inventoryValue(row, "currency_code")} ·{" "}
                {t(inventoryValue(row, "payment_type"))}
              </div>
              <div className={styles.table} tabIndex={0}>
                <table>
                  <thead>
                    <tr>
                      {[
                        "Purchase reference",
                        "Allocated amount",
                        "Converted settlement",
                        "Purchase units per payment unit",
                      ].map((label) => (
                        <th key={label}>{t(label)}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {list("allocations").map((item) => (
                      <tr key={item.id}>
                        <td>{inventoryValue(item, "purchase_no")}</td>
                        <td>
                          {inventoryValue(item, "amount_in_payment_currency")}
                        </td>
                        <td>
                          {inventoryValue(item, "amount_in_purchase_currency")}
                        </td>
                        <td>
                          {inventoryValue(
                            item,
                            "purchase_units_per_payment_unit",
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          ) : (
            <>
              {kind === "purchases" && (
                <div className={styles.summary}>
                  <span>
                    {t("Purchase total")}: {inventoryValue(row, "total_amount")}{" "}
                    {inventoryValue(row, "currency_code")}
                  </span>
                  <span>
                    {t(
                      Number(row.remaining_amount) < 0
                        ? "Credit owed by weaver"
                        : "Unpaid balance",
                    )}
                    :{" "}
                    {inventoryValue(row, "remaining_amount").replace(/^-/, "")}
                  </span>
                  <span>
                    {t("Net purchase amount")}:{" "}
                    {inventoryValue(row, "net_amount")}
                  </span>
                  <span>
                    {t("Returned amount")}:{" "}
                    {inventoryValue(row, "returned_amount")}
                  </span>
                  <span>
                    {t("Refunded amount")}:{" "}
                    {inventoryValue(row, "refunded_amount")}
                  </span>
                </div>
              )}
              {kind === "purchases" && data.void != null && (
                <p>
                  {t("Void purchase")}:{" "}
                  {String((data.void as Record<string, unknown>).reason ?? "")}
                </p>
              )}
              <p>{inventoryValue(data, "notes")}</p>
              <div className={styles.table} tabIndex={0}>
                <table>
                  <thead>
                    <tr>
                      <th>{t("Carpet code")}</th>
                      <th>{t("Name")}</th>
                      {kind === "purchases" && <th>{t("Unit price")}</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {list("items").map((item) => (
                      <tr key={item.id}>
                        <td>{inventoryValue(item, "carpet_code")}</td>
                        <td>{inventoryValue(item, "name")}</td>
                        {kind === "purchases" && (
                          <td>{inventoryValue(item, "unit_price")}</td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </>
      )}
    </dialog>
  );
}

export function ReverseWeaverPayment({
  row,
  locale,
  onSave,
  onClose,
  actionLabel = "Reverse payment",
}: {
  row: InventoryRow;
  locale: string;
  onSave: (reason: string, key: string) => Promise<void>;
  onClose: () => void;
  actionLabel?: string;
}) {
  const t = (text: string) => inventoryText(locale, text);
  const dialog = useRef<HTMLDialogElement>(null);
  const key = useRef("");
  const [reason, setReason] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const active = useRef(false);
  useEffect(() => {
    const node = dialog.current;
    node?.showModal();
    return () => node?.close();
  }, []);
  async function submit(event: FormEvent) {
    event.preventDefault();
    if (active.current) return;
    if (reason.trim().length < 3 || reason.length > 255) {
      setError("Use 3 to 255 characters.");
      return;
    }
    active.current = true;
    setBusy(true);
    setError("");
    if (!key.current) key.current = crypto.randomUUID();
    try {
      await onSave(reason.trim(), key.current);
    } catch (failure) {
      setError(
        isApiFailure(failure)
          ? Object.values(failure.fieldErrors)[0] || failure.message
          : "Could not save the record.",
      );
    } finally {
      active.current = false;
      setBusy(false);
    }
  }
  return (
    <dialog
      ref={dialog}
      className={styles.dialog}
      aria-labelledby="reverse-payment-title"
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
      onCancel={(event) => {
        event.preventDefault();
        if (!busy) onClose();
      }}
    >
      <form noValidate onSubmit={submit}>
        <header>
          <h2 id="reverse-payment-title">
            {t(actionLabel)} ·{" "}
            {inventoryValue(row, "payment_no") ||
              inventoryValue(row, "refund_no")}
          </h2>
          <button
            type="button"
            className={styles.close}
            disabled={busy}
            aria-label={t("Close")}
            onClick={onClose}
          >
            <FiX aria-hidden="true" />
          </button>
        </header>
        <p>
          {inventoryValue(row, "amount")} {inventoryValue(row, "currency_code")}
        </p>
        <label className={styles.field}>
          <span>{t("Reversal reason")}</span>
          <input
            value={reason}
            maxLength={255}
            disabled={busy}
            aria-invalid={Boolean(error)}
            onChange={(event) => {
              setReason(event.target.value);
              key.current = "";
            }}
          />
        </label>
        {error && (
          <p role="alert" className={styles.error}>
            {t(error)}
          </p>
        )}
        <div className={styles.actions}>
          <button type="button" disabled={busy} onClick={onClose}>
            {t("Cancel")}
          </button>
          <button className={styles.primary} disabled={busy}>
            {t(busy ? "Saving..." : actionLabel)}
          </button>
        </div>
      </form>
    </dialog>
  );
}
