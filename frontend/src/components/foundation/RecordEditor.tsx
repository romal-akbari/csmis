"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { FiX } from "react-icons/fi";
import { isApiFailure } from "@/lib/api";
import {
  foundationPayload,
  initialValues,
  validateFoundation,
  today,
  type FoundationRecord,
  type FormValues,
  type Lookups,
  type Resource,
} from "@/lib/foundation";
import { foundationText } from "@/lib/foundation-i18n";
import styles from "./foundation.module.css";

type Props = {
  resource: Resource;
  row?: FoundationRecord;
  lookups: Lookups;
  locale: string;
  onClose: () => void;
  onSave: (values: Record<string, unknown>) => Promise<void>;
  defaults?: FormValues;
};

export function RecordEditor({
  resource,
  row,
  lookups,
  locale,
  onClose,
  onSave,
  defaults,
}: Props) {
  const [values, setValues] = useState<FormValues>(() =>
    ({ ...initialValues(resource, row), ...(!row ? defaults : {}), ...(resource.key === "accounts" && !row ? { opening_amount: "0", opening_on: today(lookups.company?.[0]?.name) } : {}) }),
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const [busy, setBusy] = useState(false);
  const dialog = useRef<HTMLDialogElement>(null);
  const t = (text: string) => foundationText(locale, text);
  const isLocation = resource.key === "locations";
  const isWashing = values.location_type === "washing";
  const ownerKey = isWashing ? "washing_facility_id" : "branch_id";
  const hiddenOwnerError = isLocation ? errors[isWashing ? "branch_id" : "washing_facility_id"] : "";
  const fields = resource.fields
    .filter((field) => !row || !field.createOnly)
    .filter((field) => !isLocation || !["branch_id", "washing_facility_id"].includes(field.key) || Boolean(values.location_type) && field.key === ownerKey)
    .map((field) => isLocation && field.key === ownerKey || row && field.key === "code" ? { ...field, required: true } : field);

  function changeField(key: string, value: string | boolean | number[]) {
    setValues((current) => ({
      ...current,
      [key]: value,
      ...(isLocation && key === "location_type"
        ? value === "washing" ? { branch_id: "" } : { washing_facility_id: "" }
        : {}),
    }));
    setErrors((current) => {
      const next = { ...current };
      delete next[key];
      if (isLocation && ["location_type", "branch_id", "washing_facility_id"].includes(key)) {
        delete next.branch_id;
        delete next.washing_facility_id;
        delete next.location_type;
      }
      return next;
    });
    setBanner("");
  }
  useEffect(() => {
    const node = dialog.current;
    node?.showModal();
    return () => node?.close();
  }, []);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    const next = validateFoundation(resource, values, Boolean(row), lookups);
    setErrors(next);
    setBanner("");
    if (Object.keys(next).length) return;
    setBusy(true);
    try {
      const payload = foundationPayload(resource, values);
      if (row) resource.fields.filter((f) => f.createOnly).forEach((f) => delete payload[f.key]);
      await onSave(payload);
    } catch (error) {
      if (isApiFailure(error)) {
        setErrors(error.fieldErrors);
        setBanner(error.message);
      } else setBanner("Could not reach the server. Your form has been kept.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <dialog
      ref={dialog}
      className={styles.dialog}
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
      aria-labelledby="foundation-editor-title"
      onCancel={(event) => {
        event.preventDefault();
        if (!busy) onClose();
      }}
    >
      <div className={styles.dialogHeader}>
        <h2 id="foundation-editor-title">
          {t(row ? "Edit record" : "Add record")}
        </h2>
        <button type="button" className={styles.closeIcon} aria-label={t("Close")} disabled={busy} onClick={onClose}>
          <FiX aria-hidden="true" />
        </button>
      </div>
      <p>{t(resource.label)}</p>
      {resource.key === "accounts" && !row && <p className={styles.notice}>
        {t("Enter the money already held in this account. Its opening balance is saved with the account.")}
        {" "}{t("Opening balance conversion uses the saved exchange rate.")}
      </p>}
      {isLocation && <p className={styles.notice}>
        {t(!values.location_type
          ? "Choose a location type to see the required branch or washing facility."
          : isWashing
          ? "Washing locations belong to a washing facility. Choose the facility below."
          : "Warehouses, showrooms and shelves belong to a branch. Choose the branch below.")}
      </p>}
      <form noValidate onSubmit={submit} aria-busy={busy}>
        {banner && (
          <p className={styles.error} role="alert">
            {t(banner)}
          </p>
        )}
        {hiddenOwnerError && <p className={styles.error} role="alert">{t(hiddenOwnerError)}</p>}
        <div className={styles.fields}>
          {fields.map((field) => {
            const id = `foundation-${field.key}`;
            const choices = field.lookup ? (lookups[field.lookup] ?? []) : [];
            return (
              <div className={styles.field} key={field.key}>
                {field.kind === "multi" ? (
                  <span id={`${id}-label`}>
                    {t(field.label)}
                    {field.required && " *"}
                  </span>
                ) : (
                  <label htmlFor={id}>
                    {t(field.label)}
                    {field.required && " *"}
                  </label>
                )}
                {field.kind === "boolean" ? (
                  <input
                    id={id}
                    type="checkbox"
                    checked={Boolean(values[field.key])}
                    disabled={busy}
                    onChange={(e) =>
                      changeField(field.key, e.target.checked)
                    }
                  />
                ) : field.kind === "multi" ? (
                  <div
                    className={styles.checklist}
                    id={id}
                    role="group"
                    aria-labelledby={`${id}-label`}
                    aria-describedby={`${id}-error`}
                  >
                    {choices.map((choice) => (
                      <label key={choice.id}>
                        <input
                          type="checkbox"
                          disabled={busy}
                          checked={(values[field.key] as number[]).includes(
                            choice.id,
                          )}
                          onChange={(event) => {
                            const selected = values[field.key] as number[];
                            changeField(
                              field.key,
                              event.target.checked
                                ? [...selected, choice.id]
                                : selected.filter((id) => id !== choice.id),
                            );
                          }}
                        />
                        {choice.name ?? choice.code}
                      </label>
                    ))}
                    {!choices.length && <span>{t("No records yet.")}</span>}
                  </div>
                ) : field.kind === "select" ? (
                  <select
                    id={id}
                    disabled={busy}
                    value={String(values[field.key])}
                    aria-invalid={Boolean(errors[field.key])}
                    aria-describedby={`${id}-error`}
                    onChange={(e) =>
                      changeField(field.key, e.target.value)
                    }
                  >
                    <option value="">{t("Choose an option")}</option>
                    {field.lookup
                      ? choices.map((choice) => (
                          <option key={choice.id} value={choice.id}>
                            {choice.name ?? choice.code}
                          </option>
                        ))
                      : field.options?.map((option) => (
                          <option key={option} value={option}>
                            {t(option)}
                          </option>
                        ))}
                  </select>
                ) : (
                  <input
                    id={id}
                    disabled={busy}
                    placeholder={field.key === "code" && resource.key !== "currencies" ? t("Generated automatically when saved") : undefined}
                    value={String(values[field.key])}
                    type={
                      field.kind === "password"
                        ? "password"
                        : field.kind === "date"
                          ? "date"
                          : field.key === "email"
                            ? "email"
                            : "text"
                    }
                    autoComplete={
                      field.kind === "password" ? "new-password" : "off"
                    }
                    dir={
                      field.kind === "password" ||
                      field.kind === "date" ||
                      [
                        "code",
                        "username",
                        "email",
                        "phone",
                        "rate",
                        "timezone",
                        "reminder_days_before",
                      ].includes(field.key)
                        ? "ltr"
                        : "auto"
                    }
                    maxLength={field.max}
                    aria-invalid={Boolean(errors[field.key])}
                    aria-describedby={`${id}-error`}
                    onChange={(e) =>
                      changeField(field.key, e.target.value)
                    }
                  />
                )}
                {field.kind === "password" && row && (
                  <small>
                    {t("Leave blank to keep the current password.")}
                  </small>
                )}
                <span
                  id={`${id}-error`}
                  className={styles.fieldError}
                  role="alert"
                >
                  {t(errors[field.key] ?? "")}
                </span>
              </div>
            );
          })}
        </div>
        <div className={styles.formActions}>
          <button type="button" disabled={busy} onClick={onClose}>
            {t("Cancel")}
          </button>
          <button className={styles.primary} disabled={busy}>
            {t(busy ? "Saving..." : "Save")}
          </button>
        </div>
      </form>
    </dialog>
  );
}
