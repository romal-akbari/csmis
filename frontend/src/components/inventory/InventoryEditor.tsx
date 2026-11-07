"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { FiX } from "react-icons/fi";
import { isApiFailure } from "@/lib/api";
import {
  convertedMinor,
  decimalPattern,
  formatMinor,
  inventoryToday,
  inventoryValue,
  moneyMinor,
  ratePattern,
  validateInventory,
  type InventoryKind,
  type InventoryLine,
  type InventoryLookups,
  type InventoryOption,
  type InventoryRow,
  type InventoryValues,
} from "@/lib/inventory";
import { inventoryText } from "@/lib/inventory-i18n";
import { InventoryPicker } from "./InventoryPicker";
import { validateDraft } from "@/lib/inventory-corrections";
import styles from "./inventory.module.css";

export const inventoryAction = (kind: InventoryKind, edit = false) =>
  kind === "carpets"
    ? edit
      ? "Edit carpet"
      : "Register carpet"
    : kind === "purchases"
      ? "Post purchase"
      : kind === "receipts"
        ? "Receive stock"
        : kind === "transfers"
          ? "Transfer stock"
          : "Record payment";

export function InventoryEditor({
  kind,
  row,
  initialPurchase,
  lookups,
  locale,
  onSave,
  onClose,
  draftMode = false,
  initialLines = [],
  onSaveDraft,
}: {
  kind: InventoryKind;
  row?: InventoryRow;
  initialPurchase?: InventoryRow;
  lookups: InventoryLookups;
  locale: string;
  onSave: (values: Record<string, unknown>, key: string) => Promise<void>;
  onClose: () => void;
  draftMode?: boolean;
  initialLines?: InventoryLine[];
  onSaveDraft?: (values: Record<string, unknown>, key: string) => Promise<void>;
}) {
  const t = (text: string) => inventoryText(locale, text);
  const [values, setValues] = useState<InventoryValues>(() => {
    const today = inventoryToday(lookups.company.timezone);
    const initial: InventoryValues = {
      registration_branch_id: "",
      branch_id: inventoryValue(initialPurchase, "branch_id"),
      rate_to_base: "1",
      purchase_units_per_payment_unit: "1",
      payment_type: "purchase",
      purchase_id: initialPurchase ? String(initialPurchase.id) : "",
      purchase_on: today,
      received_on: today,
      transferred_on: today,
      payment_on: today,
      currency_id: String(lookups.company.base_currency_id ?? ""),
      version: "1",
    };
    if (row)
      Object.entries(row).forEach(([key, value]) => {
        if (draftMode && value === null) initial[key] = "";
        if (value !== null && ["string", "number"].includes(typeof value))
          initial[key] = String(value);
      });
    return initial;
  });
  const [lines, setLines] = useState<InventoryLine[]>(initialLines);
  const [purchase, setPurchase] = useState(initialPurchase);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const [busy, setBusy] = useState(false);
  const submitting = useRef(false);
  const key = useRef<{ payload: string; key: string } | null>(null);
  const dialog = useRef<HTMLDialogElement>(null);
  const registrationBranches =
    row &&
    !lookups.branches.some(
      (branch) => branch.id === Number(row.registration_branch_id),
    )
      ? [
          ...lookups.branches,
          {
            id: Number(row.registration_branch_id),
            name: `${t("Registration branch")} #${row.registration_branch_id}`,
          },
        ]
      : lookups.branches;
  const identityFixed = kind === "carpets" && Number(row?.sequence_no) > 0;
  useEffect(() => {
    const node = dialog.current;
    node?.showModal();
    return () => node?.close();
  }, []);
  const change = (field: string, value: string) => {
    setValues((previous) => ({
      ...previous,
      [field]: value,
      ...(field === "branch_id"
        ? { account_id: "", weaver_id: "", purchase_id: "" }
        : {}),
      ...(field === "from_location_id" ? { to_location_id: "" } : {}),
      ...(field === "account_id"
        ? { purchase_units_per_payment_unit: "1" }
        : {}),
    }));
    setErrors({});
    setBanner("");
    if (["branch_id", "location_id", "from_location_id"].includes(field))
      setLines([]);
    if (field === "branch_id" || field === "payment_type")
      setPurchase(undefined);
  };
  const field = (
    name: string,
    label: string,
    options?: InventoryOption[],
    type = "text",
    maxLength?: number,
  ) => (
    <div className={styles.field} key={name}>
      <label htmlFor={`inventory-${name}`}>{t(label)}</label>
      {options ? (
        <select
          id={`inventory-${name}`}
          disabled={identityFixed && name === "registration_branch_id"}
          value={values[name] ?? ""}
          onChange={(event) => change(name, event.target.value)}
          aria-invalid={Boolean(errors[name])}
          aria-describedby={
            errors[name] ? `inventory-${name}-error` : undefined
          }
        >
          <option value="">{t("Choose an option")}</option>
          {options.map((option) => (
            <option key={option.id} value={option.id}>
              {locale === "fa" && option.name_fa
                ? option.name_fa
                : option.name || option.code}
              {option.code && option.name ? ` · ${option.code}` : ""}
            </option>
          ))}
        </select>
      ) : (
        <input
          id={`inventory-${name}`}
          disabled={identityFixed && name === "carpet_code"}
          placeholder={["transfer_no", "receipt_no"].includes(name) ? t("Generated automatically when saved") : undefined}
          type={type}
          value={values[name] ?? ""}
          maxLength={maxLength}
          inputMode={
            [
              "amount",
              "rate_to_base",
              "purchase_units_per_payment_unit",
              "length_cm",
              "width_cm",
            ].includes(name)
              ? "decimal"
              : undefined
          }
          onChange={(event) => change(name, event.target.value)}
          aria-invalid={Boolean(errors[name])}
          aria-describedby={
            errors[name] ? `inventory-${name}-error` : undefined
          }
        />
      )}
      {errors[name] && (
        <small id={`inventory-${name}-error`}>{t(errors[name])}</small>
      )}
    </div>
  );
  const location = lookups.locations.find(
    (item) =>
      item.id ===
      Number(values[kind === "receipts" ? "location_id" : "from_location_id"]),
  );
  const pickerBranch =
    kind === "purchases" || kind === "payments"
      ? values.branch_id
      : String(location?.branch_id ?? "");
  const account = lookups.accounts.find(
    (item) => item.id === Number(values.account_id),
  );
  const accountCurrency = lookups.currencies.find(
    (item) => item.id === account?.currency_id,
  );
  const purchaseCurrency = lookups.currencies.find(
    (item) => item.id === Number(purchase?.currency_id),
  );
  const total = lines.reduce(
    (sum, line) =>
      decimalPattern.test(line.unit_price)
        ? sum + moneyMinor(line.unit_price)
        : sum,
    BigInt(0),
  );
  async function submit(event?: FormEvent, asDraft = draftMode) {
    event?.preventDefault();
    if (submitting.current) return;
    const next = asDraft
      ? validateDraft(values, lines, lookups)
      : validateInventory(
          kind,
          values,
          lines,
          { ...lookups, branches: registrationBranches },
          purchase,
        );
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
    let payload: Record<string, unknown>;
    if (kind === "carpets") {
      payload = Object.fromEntries(
        [
          "carpet_code",
          "name",
          "registration_branch_id",
          "category_id",
          "size_label",
          "length_cm",
          "width_cm",
          "color",
          "material",
          "notes",
        ].map((name) => [name, values[name]?.trim() || null]),
      );
      if (row) payload.version = Number(values.version);
    } else if (kind === "purchases")
      payload = {
        purchase_no: values.purchase_no,
        branch_id: values.branch_id,
        weaver_id: values.weaver_id,
        purchase_on: values.purchase_on,
        currency_id: values.currency_id,
        rate_to_base: values.rate_to_base,
        notes: values.notes || null,
        items: lines.map((line) => ({
          carpet_id: line.carpet_id,
          unit_price: line.unit_price,
        })),
      };
    else if (kind === "receipts" || kind === "transfers")
      payload = {
        ...(kind === "receipts"
          ? {
              receipt_no: values.receipt_no,
              location_id: values.location_id,
              received_on: values.received_on,
            }
          : {
              transfer_no: values.transfer_no,
              from_location_id: values.from_location_id,
              to_location_id: values.to_location_id,
              transferred_on: values.transferred_on,
            }),
        notes: values.notes || null,
        items: lines.map((line) => ({
          carpet_id: line.carpet_id,
          expected_sequence: line.expected_sequence,
        })),
      };
    else
      payload = {
        payment_no: values.payment_no,
        branch_id: values.branch_id,
        account_id: values.account_id,
        payment_on: values.payment_on,
        payment_type: values.payment_type,
        weaver_id:
          values.payment_type === "purchase"
            ? purchase?.weaver_id
            : values.weaver_id,
        amount: values.amount,
        rate_to_base: values.rate_to_base,
        reason: values.reason,
        ...(values.payment_type === "additional"
          ? { expense_category_id: values.expense_category_id }
          : {}),
        allocations:
          values.payment_type === "purchase"
            ? [
                {
                  weaver_purchase_id: purchase?.id,
                  amount_in_payment_currency: values.amount,
                  purchase_units_per_payment_unit:
                    values.purchase_units_per_payment_unit,
                },
              ]
            : [],
      };
    if (asDraft) {
      for (const name of [
        "weaver_id",
        "currency_id",
        "purchase_on",
        "rate_to_base",
      ])
        if (!payload[name]) payload[name] = null;
      payload.items = lines.map((line) => ({
        carpet_id: line.carpet_id,
        unit_price: line.unit_price || null,
      }));
      if (draftMode && row) payload.version = Number(row.version);
    }
    const serialized = JSON.stringify([asDraft, payload]);
    if (key.current?.payload !== serialized)
      key.current = { payload: serialized, key: crypto.randomUUID() };
    submitting.current = true;
    setBusy(true);
    try {
      await (asDraft && onSaveDraft ? onSaveDraft : onSave)(
        payload,
        key.current.key,
      );
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
  return (
    <dialog
      ref={dialog}
      className={styles.dialog}
      aria-labelledby="inventory-editor-title"
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
      onCancel={(event) => {
        event.preventDefault();
        if (!busy) onClose();
      }}
    >
      <form noValidate onSubmit={submit}>
        <header>
          <h2 id="inventory-editor-title">
            {t(
              draftMode
                ? row
                  ? "Edit purchase draft"
                  : "New purchase draft"
                : inventoryAction(kind, Boolean(row)),
            )}
          </h2>
          <button
            className={styles.close}
            type="button"
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
          </p>
        )}
        <fieldset
          disabled={busy}
          style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}
        >
          <div className={styles.grid}>
            {kind === "carpets" ? (
              <>
                {field("carpet_code", "Carpet code", undefined, "text", 40)}
                {field("name", "Name", undefined, "text", 150)}
                {field(
                  "registration_branch_id",
                  "Registration branch",
                  registrationBranches,
                )}
                {field("category_id", "Carpet categories", lookups.categories)}
                {field("size_label", "Size label", undefined, "text", 60)}
                {field("material", "Material", undefined, "text", 100)}
                {field("length_cm", "Length (cm)")}
                {field("width_cm", "Width (cm)")}
                {field("color", "Color", undefined, "text", 60)}
              </>
            ) : (
              <>
                {kind !== "purchases" && field(
                  kind === "receipts"
                      ? "receipt_no"
                      : kind === "transfers"
                        ? "transfer_no"
                        : "payment_no",
                  kind === "receipts"
                      ? "Receipt reference"
                      : kind === "transfers"
                        ? "Transfer reference"
                        : "Payment reference",
                  undefined,
                  "text",
                  40,
                )}
                {field(
                  kind === "purchases"
                    ? "purchase_on"
                    : kind === "receipts"
                      ? "received_on"
                      : kind === "transfers"
                        ? "transferred_on"
                        : "payment_on",
                  kind === "purchases"
                    ? "Purchase date"
                    : kind === "receipts"
                      ? "Received date"
                      : kind === "transfers"
                        ? "Transfer date"
                        : "Payment date",
                  undefined,
                  "date",
                )}
                {(kind === "purchases" || kind === "payments") &&
                  field("branch_id", "Branch", lookups.branches)}
                {kind === "purchases" && (
                  <>
                    {field("weaver_id", "Weaver", lookups.weavers)}
                    {field("currency_id", "Currency", lookups.currencies)}
                    {field("rate_to_base", "Rate to base currency")}
                  </>
                )}
                {kind === "receipts" &&
                  field("location_id", "Stock location", lookups.locations)}
                {kind === "transfers" && (
                  <>
                    {field(
                      "from_location_id",
                      "From location",
                      lookups.locations,
                    )}
                    {field(
                      "to_location_id",
                      "To location",
                      lookups.locations.filter(
                        (item) => String(item.id) !== values.from_location_id,
                      ),
                    )}
                  </>
                )}
                {kind === "payments" && (
                  <>
                    <div className={styles.field}>
                      <label htmlFor="inventory-payment_type">
                        {t("Payment type")}
                      </label>
                      <select
                        id="inventory-payment_type"
                        value={values.payment_type}
                        onChange={(event) =>
                          change("payment_type", event.target.value)
                        }
                      >
                        <option value="purchase">{t("purchase")}</option>
                        <option value="additional">{t("additional")}</option>
                      </select>
                    </div>
                    {field(
                      "account_id",
                      "Account",
                      lookups.accounts
                        .map((item) => ({
                          ...item,
                          name: `${item.name} · ${lookups.currencies.find((c) => c.id === item.currency_id)?.code ?? ""}`,
                        })),
                    )}
                    {field("amount", "Amount")}
                    {field("rate_to_base", "Rate to base currency")}
                    {values.payment_type === "additional" ? (
                      <>
                        {field("weaver_id", "Weaver", lookups.weavers)}
                        {field(
                          "expense_category_id",
                          "Expense category",
                          lookups.expense_categories,
                        )}
                      </>
                    ) : (
                      field(
                        "purchase_units_per_payment_unit",
                        "Purchase units per payment unit",
                      )
                    )}
                    {field("reason", "Reason", undefined, "text", 255)}
                  </>
                )}
              </>
            )}
            {kind !== "payments" && (
              <label className={`${styles.field} ${styles.wide}`}>
                <span>{t("Notes")}</span>
                <textarea
                  rows={2}
                  maxLength={
                    kind === "carpets" || kind === "purchases" ? 1000 : 500
                  }
                  value={values.notes ?? ""}
                  onChange={(event) => change("notes", event.target.value)}
                />
                {errors.notes && <small>{t(errors.notes)}</small>}
              </label>
            )}
          </div>
          {kind !== "carpets" &&
            (kind !== "payments" || values.payment_type === "purchase") && (
              <>
                <InventoryPicker
                  key={`${kind}:${pickerBranch}:${values.from_location_id ?? ""}`}
                  kind={kind}
                  branch={pickerBranch ?? ""}
                  location={kind === "transfers" ? values.from_location_id : ""}
                  lines={lines}
                  onLines={setLines}
                  purchase={purchase}
                  onPurchase={(selected) => {
                    setPurchase(selected);
                    change("purchase_id", String(selected.id));
                  }}
                  locale={locale}
                />
                {(errors.items || errors.purchase_id || errors.allocations) && (
                  <p role="alert" className={styles.error}>
                    {t(
                      errors.items || errors.purchase_id || errors.allocations,
                    )}
                  </p>
                )}
              </>
            )}
          {lines.length > 0 && (
            <section aria-label={t("Selected carpets")}>
              <h3>
                {t("Selected carpets")} · {lines.length}
              </h3>
              <p className={styles.muted}>
                {t("Each selected carpet is one individually tracked item.")}
              </p>
              {lines.map((line, index) => (
                <div className={styles.line} key={line.carpet_id}>
                  <span>{line.label}</span>
                  {kind === "purchases" ? (
                    <label className={styles.field}>
                      <span>{t("Unit price")}</span>
                      <input
                        aria-label={`${t("Unit price")} ${line.label}`}
                        inputMode="decimal"
                        value={line.unit_price}
                        aria-invalid={Boolean(
                          errors[`items.${index}.unit_price`],
                        )}
                        onChange={(event) =>
                          setLines(
                            lines.map((old) =>
                              old.carpet_id === line.carpet_id
                                ? { ...old, unit_price: event.target.value }
                                : old,
                            ),
                          )
                        }
                      />
                      {errors[`items.${index}.unit_price`] && (
                        <small>{t(errors[`items.${index}.unit_price`])}</small>
                      )}
                    </label>
                  ) : (
                    <span />
                  )}
                  <button
                    type="button"
                    aria-label={`${t("Remove")} ${line.label}`}
                    className={styles.close}
                    onClick={() =>
                      setLines(
                        lines.filter((old) => old.carpet_id !== line.carpet_id),
                      )
                    }
                  >
                    <FiX aria-hidden="true" />
                  </button>
                </div>
              ))}
            </section>
          )}
          {kind === "purchases" && (
            <div className={styles.summary}>
              {t("Purchase total")}:{" "}
              <strong>
                {formatMinor(total)}{" "}
                {
                  lookups.currencies.find(
                    (item) => item.id === Number(values.currency_id),
                  )?.code
                }
              </strong>
            </div>
          )}
          {kind === "payments" && (
            <>
              <div className={styles.summary}>
                <span>
                  {t("Payment currency")}: {accountCurrency?.code || "—"}
                </span>
                {purchase && values.payment_type === "purchase" && (
                  <>
                    <span>
                      {t("Purchase currency")}:{" "}
                      {purchaseCurrency?.code ||
                        inventoryValue(purchase, "currency_code")}
                    </span>
                    <span>
                      {t("Unpaid balance")}:{" "}
                      {inventoryValue(purchase, "remaining_amount")}
                    </span>
                    {decimalPattern.test(values.amount ?? "") &&
                      ratePattern.test(
                        values.purchase_units_per_payment_unit ?? "",
                      ) && (
                        <span>
                          {t("Converted settlement")}:{" "}
                          {formatMinor(
                            convertedMinor(
                              values.amount,
                              values.purchase_units_per_payment_unit,
                              purchaseCurrency?.decimal_places ?? 2,
                            ),
                          )}
                        </span>
                      )}
                  </>
                )}
              </div>
              <p className={styles.muted}>
                {t(
                  values.payment_type === "additional"
                    ? "Bonuses are separate expenses and do not reduce purchase balances."
                    : "Payments affect the selected account. Correct a mistake with a reversal.",
                )}
              </p>
            </>
          )}
          {kind !== "carpets" && kind !== "payments" && (
            <p className={styles.muted}>
              {t(
                draftMode
                  ? "Drafts do not change stock or money until posted."
                  : "Posted purchases and movements are permanent records. Review the details before posting.",
              )}
            </p>
          )}
        </fieldset>
        <div className={styles.actions}>
          <button type="button" disabled={busy} onClick={onClose}>
            {t("Cancel")}
          </button>
          {kind === "purchases" && onSaveDraft && !draftMode && (
            <button
              type="button"
              disabled={busy}
              onClick={() => submit(undefined, true)}
            >
              {t("Save draft")}
            </button>
          )}
          <button className={styles.primary} type="submit" disabled={busy}>
            {t(
              busy
                ? "Saving..."
                : draftMode
                  ? "Save draft"
                  : inventoryAction(kind, Boolean(row)),
            )}
          </button>
        </div>
      </form>
    </dialog>
  );
}
