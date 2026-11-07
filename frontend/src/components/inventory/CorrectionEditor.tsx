"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { FiX } from "react-icons/fi";
import { apiFetch, isApiFailure } from "@/lib/api";
import {
  convertedMinor,
  decimalPattern,
  ratePattern,
  formatMinor,
  inventoryToday,
  inventoryValue,
  moneyMinor,
  validateInventory,
  type InventoryLine,
  type InventoryLookups,
  type InventoryOption,
  type InventoryRow,
  type InventoryValues,
} from "@/lib/inventory";
import {
  validateCorrection,
  type CorrectionAction,
  type CorrectionKind,
} from "@/lib/inventory-corrections";
import { inventoryText } from "@/lib/inventory-i18n";
import { CorrectionPicker } from "./CorrectionPicker";
import styles from "./inventory.module.css";

const titles: Record<CorrectionAction, string> = {
  drafts: "Edit purchase draft",
  adjustments: "Correct stock",
  returns: "Return carpets",
  refunds: "Record refund",
  void: "Void purchase",
  "post-draft": "Post draft",
  "cancel-draft": "Cancel draft",
  view: "View record",
};
export function CorrectionEditor({
  action,
  resource,
  row,
  initialPurchase,
  lookups,
  locale,
  onSave,
  onClose,
}: {
  action: CorrectionAction;
  resource?: CorrectionKind;
  row?: InventoryRow;
  initialPurchase?: InventoryRow;
  lookups: InventoryLookups;
  locale: string;
  onSave: (
    path: string,
    payload: Record<string, unknown>,
    key: string,
  ) => Promise<void>;
  onClose: () => void;
}) {
  const t = (text: string) => inventoryText(locale, text);
  const [values, setValues] = useState<InventoryValues>(() => {
    const today = inventoryToday(lookups.company.timezone);
    return {
      adjusted_on: today,
      returned_on: today,
      refunded_on: today,
      voided_on: today,
      adjustment_type: "relocate",
      rate_to_base: "1",
      purchase_units_per_refund_unit: "1",
    };
  });
  const [purchase, setPurchase] = useState(initialPurchase);
  const [detail, setDetail] = useState<InventoryRow | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const [lines, setLines] = useState<InventoryLine[]>([]);
  const [busy, setBusy] = useState(false);
  const [revision, setRevision] = useState(0);
  const submitting = useRef(false);
  const key = useRef<{ json: string; key: string } | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const node = dialog.current;
    node?.showModal();
    return () => node?.close();
  }, []);
  const path =
    action === "void" || action === "returns"
      ? purchase
        ? `/inventory/purchases/${purchase.id}`
        : ""
      : row
        ? `/inventory/corrections/${resource ?? "drafts"}/${row.id}`
        : "";
  useEffect(() => {
    if (!path) return;
    const controller = new AbortController();
    apiFetch<{ data: InventoryRow }>(path, { signal: controller.signal })
      .then((response) => {
        if (!controller.signal.aborted) {
          setDetail(response.data);
          setBanner("");
        }
      })
      .catch((error) => {
        if (!controller.signal.aborted)
          setBanner(
            isApiFailure(error) ? error.message : "Could not load inventory.",
          );
      });
    return () => controller.abort();
  }, [path, revision]);
  const items = (
    Array.isArray(detail?.items) ? detail.items : []
  ) as InventoryRow[];
  const change = (name: string, value: string) => {
    setValues((old) => ({
      ...old,
      [name]: value,
      ...(["branch_id", "adjustment_type"].includes(name)
        ? { location_id: "" }
        : {}),
    }));
    setErrors({});
    setBanner("");
    if (["branch_id", "from_location_id", "adjustment_type"].includes(name))
      setLines([]);
  };
  const choose = (selected: InventoryRow) => {
    setPurchase(selected);
    setDetail(null);
    setLines([]);
    change("account_id", "");
  };
  const field = (
    name: string,
    label: string,
    options?: InventoryOption[],
    type = "text",
    maxLength?: number,
  ) => (
    <div className={styles.field}>
      <label htmlFor={`correction-${name}`}>{t(label)}</label>
      {options ? (
        <select
          id={`correction-${name}`}
          value={values[name] ?? ""}
          aria-invalid={Boolean(errors[name])}
          onChange={(e) => change(name, e.target.value)}
        >
          <option value="">{t("Choose an option")}</option>
          {options.map((o) => (
            <option key={o.id} value={o.id}>
              {o.name || o.code}
            </option>
          ))}
        </select>
      ) : (
        <input
          id={`correction-${name}`}
          type={type}
          placeholder={name === "adjustment_no" ? t("Generated automatically when saved") : undefined}
          maxLength={maxLength}
          value={values[name] ?? ""}
          aria-invalid={Boolean(errors[name])}
          inputMode={
            [
              "amount",
              "rate_to_base",
              "purchase_units_per_refund_unit",
            ].includes(name)
              ? "decimal"
              : undefined
          }
          onChange={(e) => change(name, e.target.value)}
        />
      )}
      {errors[name] && <small>{t(errors[name])}</small>}
    </div>
  );
  async function submit(event: FormEvent) {
    event.preventDefault();
    if (submitting.current) return;
    const allLines =
      action === "void"
        ? items.map((i) => ({
            carpet_id: Number(i.carpet_id),
            expected_sequence: Number(i.sequence_no),
            unit_price: "",
            label: inventoryValue(i, "carpet_code"),
          }))
        : lines;
    const next = validateCorrection(
      action,
      values,
      allLines,
      lookups,
      purchase,
    );
    if (
      action === "void" &&
      (!detail ||
        items.some(
          (i) =>
            i.stock_status !== "purchased" ||
            Number(i.acquisition_item_id) !== i.id,
        ))
    )
      next.items =
        "Only an unreceived purchase can be voided. Use a return for received carpets.";
    if (action === "post-draft") {
      if (!detail) return;
      if (!lookups.locations.some((l) => String(l.id) === values.location_id && Number(l.branch_id) === Number(detail.branch_id))) next.location_id = "Choose an option";
      const draftValues = Object.fromEntries(
        Object.entries(detail)
          .filter(([, v]) => v !== null)
          .map(([k, v]) => [k, String(v)]),
      );
      Object.assign(
        next,
        validateInventory(
          "purchases",
          draftValues,
          items.map((i) => ({
            carpet_id: Number(i.carpet_id),
            expected_sequence: 0,
            unit_price: inventoryValue(i, "unit_price"),
            label: inventoryValue(i, "carpet_code"),
          })),
          lookups,
        ),
      );
    }
    setErrors(next);
    setBanner("");
    if (Object.keys(next).length) {
      requestAnimationFrame(() =>
        dialog.current
          ?.querySelector<HTMLElement>("[aria-invalid=true]")
          ?.focus(),
      );
      return;
    }
    let target = `/inventory/corrections/${action}`;
    let payload: Record<string, unknown>;
    const stockItems = allLines.map((i) => ({
      carpet_id: i.carpet_id,
      expected_sequence: i.expected_sequence,
    }));
    if (action === "post-draft" || action === "cancel-draft") {
      if (!detail) return;
      target = `/inventory/corrections/drafts/${detail.id}/${action === "post-draft" ? "post" : "cancel"}`;
      payload = {
        version: detail.version,
        ...(action === "post-draft" ? { location_id: Number(values.location_id) } : {}),
        ...(action === "cancel-draft" ? { reason: values.reason } : {}),
      };
    } else if (action === "void") {
      target = `/inventory/purchases/${purchase!.id}/void`;
      payload = {
        voided_on: values.voided_on,
        reason: values.reason,
        items: stockItems,
      };
    } else if (action === "adjustments")
      payload = {
        adjustment_no: values.adjustment_no,
        branch_id: values.branch_id,
        adjustment_type: values.adjustment_type,
        location_id:
          values.adjustment_type === "missing" ? null : values.location_id,
        adjusted_on: values.adjusted_on,
        reason: values.reason,
        items: stockItems,
      };
    else if (action === "returns")
      payload = {
        return_no: values.return_no,
        weaver_purchase_id: purchase!.id,
        from_location_id:
          values.from_location_id === "-1" ? null : values.from_location_id,
        returned_on: values.returned_on,
        reason: values.reason,
        items: stockItems,
      };
    else
      payload = {
        refund_no: values.refund_no,
        weaver_purchase_id: purchase!.id,
        account_id: values.account_id,
        amount: values.amount,
        rate_to_base: values.rate_to_base,
        purchase_units_per_refund_unit: values.purchase_units_per_refund_unit,
        refunded_on: values.refunded_on,
        reason: values.reason,
      };
    const json = JSON.stringify([target, payload]);
    if (key.current?.json !== json)
      key.current = { json, key: crypto.randomUUID() };
    submitting.current = true;
    setBusy(true);
    try {
      await onSave(target, payload, key.current.key);
    } catch (error) {
      setErrors(isApiFailure(error) ? error.fieldErrors : {});
      setBanner(
        isApiFailure(error) ? error.message : "Could not save the record.",
      );
    } finally {
      submitting.current = false;
      setBusy(false);
    }
  }
  const snapshot = ["view", "post-draft", "cancel-draft", "void"].includes(
    action,
  );
  const eligible = items.filter(
    (i) =>
      Number(i.acquisition_item_id) === i.id &&
      (values.from_location_id === "-1"
        ? i.stock_status === "purchased"
        : i.stock_status === "in_stock" &&
          String(i.location_id) === values.from_location_id),
  );
  return (
    <dialog
      ref={dialog}
      className={styles.dialog}
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
      aria-labelledby="correction-editor-title"
      onCancel={(e) => {
        e.preventDefault();
        if (!busy) onClose();
      }}
    >
      <form noValidate onSubmit={submit}>
        <header>
          <h2 id="correction-editor-title">{t(titles[action])}</h2>
          <button
            type="button"
            className={styles.close}
            aria-label={t("Close")}
            disabled={busy}
            onClick={onClose}
          >
            <FiX aria-hidden="true" />
          </button>
        </header>
        {banner && (
          <p role="alert" className={styles.error}>
            {t(banner)}
            {path && (
              <button type="button" onClick={() => setRevision(revision + 1)}>
                {t("Retry")}
              </button>
            )}
          </p>
        )}
        {Object.keys(errors).length > 0 && (
          <div role="alert" className={styles.error}>
            {[...new Set(Object.values(errors))].map((error) => (
              <p key={error}>{t(error)}</p>
            ))}
          </div>
        )}
        <fieldset
          disabled={busy}
          style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}
        >
          {snapshot ? (
            !detail ? (
              !banner && <p role="status">{t("Loading...")}</p>
            ) : (
              <>
                <div className={styles.summary}>
                  <strong>
                    {inventoryValue(detail, "return_no") ||
                      inventoryValue(detail, "adjustment_no") ||
                      inventoryValue(detail, "refund_no") ||
                      inventoryValue(detail, "purchase_no") ||
                      inventoryValue(purchase, "purchase_no")}
                  </strong>
                  <span>
                    {inventoryValue(detail, "purchase_on") ||
                      inventoryValue(detail, "adjusted_on") ||
                      inventoryValue(detail, "returned_on") ||
                      inventoryValue(detail, "refunded_on")}
                  </span>
                  {detail.weaver_purchase_id != null && (
                    <span>
                      {t("Purchase reference")}:{" "}
                      {inventoryValue(detail, "purchase_no")}
                    </span>
                  )}
                  {detail.amount != null && (
                    <span>
                      {t("Amount")}: {inventoryValue(detail, "amount")}{" "}
                      {inventoryValue(detail, "currency_code")}
                    </span>
                  )}
                  {detail.amount_in_purchase_currency != null && (
                    <span>
                      {t("Converted settlement")}:{" "}
                      {inventoryValue(detail, "amount_in_purchase_currency")}{" "}
                      {inventoryValue(detail, "purchase_currency")}
                    </span>
                  )}
                  <span>
                    {inventoryValue(detail, "reason") ||
                      inventoryValue(detail, "cancel_reason")}
                  </span>
                  {detail.weaver_id != null && (
                    <span>
                      {t("Weaver")}:{" "}
                      {inventoryValue(detail, "weaver_name") ||
                        lookups.weavers.find(
                          (w) => w.id === Number(detail.weaver_id),
                        )?.name ||
                        "—"}
                    </span>
                  )}
                  {detail.currency_id != null && (
                    <span>
                      {t("Currency")}:{" "}
                      {inventoryValue(detail, "currency_code") ||
                        lookups.currencies.find(
                          (c) => c.id === Number(detail.currency_id),
                        )?.code}
                    </span>
                  )}
                </div>
                {items.length > 0 && (
                  <div className={styles.table} tabIndex={0}>
                    <table>
                      <thead>
                        <tr>
                          <th>{t("Carpet code")}</th>
                          <th>{t("Name")}</th>
                          {items.some((i) => "unit_price" in i) && (
                            <th>{t("Unit price")}</th>
                          )}
                        </tr>
                      </thead>
                      <tbody>
                        {items.map((i) => (
                          <tr key={Number(i.carpet_id)}>
                            <td>{inventoryValue(i, "carpet_code")}</td>
                            <td>{inventoryValue(i, "name")}</td>
                            {items.some((i) => "unit_price" in i) && (
                              <td>{inventoryValue(i, "unit_price") || "—"}</td>
                            )}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </>
            )
          ) : (
            <>
              {(action === "returns" || action === "refunds") && (
                <CorrectionPicker
                  resource="purchases"
                  filters={action === "refunds" ? { credit: "1" } : {}}
                  selected={purchase}
                  onSelect={choose}
                  lines={[]}
                  onLines={() => {}}
                  locale={locale}
                />
              )}
              <div className={styles.grid}>
                {action === "adjustments" && (
                  <>
                    {field(
                      "adjustment_no",
                      "Correction reference",
                      undefined,
                      "text",
                      40,
                    )}
                    {field("branch_id", "Branch", lookups.branches)}
                    <div className={styles.field}>
                      <label htmlFor="correction-adjustment_type">
                        {t("Correction type")}
                      </label>
                      <select
                        id="correction-adjustment_type"
                        value={values.adjustment_type}
                        onChange={(e) =>
                          change("adjustment_type", e.target.value)
                        }
                      >
                        {["relocate", "missing", "found"].map((type) => (
                          <option key={type} value={type}>
                            {t(type)}
                          </option>
                        ))}
                      </select>
                    </div>
                    {values.adjustment_type !== "missing" &&
                      field(
                        "location_id",
                        "To location",
                        lookups.locations.filter(
                          (l) => String(l.branch_id) === values.branch_id,
                        ),
                      )}
                    {field("adjusted_on", "Correction date", undefined, "date")}
                  </>
                )}
                {action === "returns" && (
                  <>
                    {field(
                      "return_no",
                      "Return reference",
                      undefined,
                      "text",
                      40,
                    )}
                    {field("from_location_id", "From location", [
                      {
                        id: -1,
                        name: t("Not received (cancel purchase items)"),
                      },
                      ...lookups.locations,
                    ])}
                    {field("returned_on", "Return date", undefined, "date")}
                  </>
                )}
                {action === "refunds" && (
                  <>
                    {field(
                      "refund_no",
                      "Refund reference",
                      undefined,
                      "text",
                      40,
                    )}
                    {field(
                      "account_id",
                      "Account",
                      lookups.accounts
                        .filter(
                          (a) =>
                            Number(a.branch_id) === Number(purchase?.branch_id),
                        )
                        .map((a) => ({
                          ...a,
                          name: `${a.name} · ${lookups.currencies.find((c) => c.id === a.currency_id)?.code ?? ""}`,
                        })),
                    )}
                    {field("amount", "Amount")}
                    {field("refunded_on", "Refund date", undefined, "date")}
                    {field("rate_to_base", "Rate to base currency")}
                    {field(
                      "purchase_units_per_refund_unit",
                      "Purchase units per refund unit",
                    )}
                  </>
                )}
              </div>
              {action === "adjustments" && values.branch_id && (
                <CorrectionPicker
                  key={`${values.branch_id}:${values.adjustment_type}`}
                  resource="carpets"
                  filters={{
                    branch_id: values.branch_id,
                    stock_status:
                      values.adjustment_type === "found"
                        ? "missing"
                        : "in_stock",
                  }}
                  selected={undefined}
                  onSelect={() => {}}
                  lines={lines}
                  onLines={setLines}
                  locale={locale}
                />
              )}
              {action === "returns" && purchase && (
                <section className={styles.picker}>
                  <h3>{t("Selected carpets")}</h3>
                  {!detail ? (
                    <p role="status">{t("Loading...")}</p>
                  ) : (
                    <>
                      {eligible.map((i) => (
                        <label className={styles.choice} key={i.id}>
                          <input
                            type="checkbox"
                            checked={lines.some(
                              (l) => l.carpet_id === Number(i.carpet_id),
                            )}
                            onChange={(e) =>
                              setLines(
                                e.target.checked
                                  ? [
                                      ...lines,
                                      {
                                        carpet_id: Number(i.carpet_id),
                                        expected_sequence: Number(
                                          i.sequence_no,
                                        ),
                                        unit_price: inventoryValue(
                                          i,
                                          "unit_price",
                                        ),
                                        label: inventoryValue(i, "carpet_code"),
                                      },
                                    ]
                                  : lines.filter(
                                      (l) =>
                                        l.carpet_id !== Number(i.carpet_id),
                                    ),
                              )
                            }
                          />
                          <span>
                            {inventoryValue(i, "carpet_code")} ·{" "}
                            {inventoryValue(i, "unit_price")}{" "}
                            {inventoryValue(purchase, "currency_code")}
                          </span>
                        </label>
                      ))}
                      {!eligible.length && (
                        <p>
                          {t("No eligible carpets at this source location.")}
                        </p>
                      )}
                    </>
                  )}
                </section>
              )}
              {action === "returns" && lines.length > 0 && (
                <p className={styles.summary}>
                  {t("Returned amount")}:{" "}
                  <strong>
                    {formatMinor(
                      lines.reduce(
                        (total, line) =>
                          total +
                          (decimalPattern.test(line.unit_price)
                            ? moneyMinor(line.unit_price)
                            : BigInt(0)),
                        BigInt(0),
                      ),
                    )}{" "}
                    {inventoryValue(purchase, "currency_code")}
                  </strong>
                </p>
              )}
              {action === "refunds" &&
                purchase &&
                decimalPattern.test(values.amount ?? "") &&
                ratePattern.test(
                  values.purchase_units_per_refund_unit ?? "",
                ) && (
                  <p className={styles.summary}>
                    {t("Converted settlement")}:{" "}
                    <strong>
                      {formatMinor(
                        convertedMinor(
                          values.amount,
                          values.purchase_units_per_refund_unit,
                          lookups.currencies.find(
                            (c) => c.id === Number(purchase.currency_id),
                          )?.decimal_places ?? 2,
                        ),
                      )}{" "}
                      {inventoryValue(purchase, "currency_code")}
                    </strong>
                  </p>
                )}
              {purchase && (
                <div className={styles.summary}>
                  {t(
                    Number(purchase.remaining_amount) < 0
                      ? "Credit owed by weaver"
                      : "Unpaid balance",
                  )}
                  :{" "}
                  <strong>
                    {formatMinor(
                      moneyMinor(String(purchase.remaining_amount)) < BigInt(0)
                        ? -moneyMinor(String(purchase.remaining_amount))
                        : moneyMinor(String(purchase.remaining_amount)),
                    )}{" "}
                    {inventoryValue(purchase, "currency_code")}
                  </strong>
                </div>
              )}
            </>
          )}
          {action === "post-draft" && field("location_id", "Destination stock location", lookups.locations.filter((location) => Number(location.branch_id) === Number(detail?.branch_id)))}
          {action === "void" &&
            field("voided_on", "Cancellation date", undefined, "date")}
          {!["view", "post-draft"].includes(action) &&
            field(
              "reason",
              "Reason",
              undefined,
              "text",
              action === "refunds" ? 255 : 500,
            )}
          {action === "void" && (
            <p className={styles.muted}>
              {t(
                "Only unreceived purchases can be voided. Actual payments remain recorded as credit owed by the weaver.",
              )}
            </p>
          )}
          {action === "returns" && (
            <p className={styles.muted}>
              {t(
                "Returns credit the original purchase price. Record an actual refund separately when money is received.",
              )}
            </p>
          )}
          {action === "adjustments" && (
            <p className={styles.muted}>
              {t(
                "Corrections preserve history and do not change purchase cost or cash.",
              )}
            </p>
          )}
        </fieldset>
        <div className={styles.actions}>
          {action !== "view" && (
            <button
              className={styles.primary}
              disabled={busy || (snapshot && !detail)}
            >
              {t(busy ? "Saving..." : titles[action])}
            </button>
          )}
        </div>
      </form>
    </dialog>
  );
}
