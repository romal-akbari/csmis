"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { isApiFailure } from "@/lib/api";
import {
  changeDirectoryValue,
  directoryInitialValues,
  directoryOptions,
  directoryPayload,
  directoryToday,
  validateDirectory,
  type DirectoryLookups,
  type DirectoryRecord,
  type DirectoryResource,
} from "@/lib/directories";
import { directoryText } from "@/lib/directories-i18n";
import styles from "./directories.module.css";

export function DirectoryEditor({
  resource,
  row,
  lookups,
  locale,
  isAdministrator,
  canArchive,
  onSave,
  onClose,
}: {
  resource: DirectoryResource;
  row?: DirectoryRecord;
  lookups: DirectoryLookups;
  locale: string;
  isAdministrator: boolean;
  canArchive: boolean;
  onSave: (data: Record<string, unknown>) => Promise<void>;
  onClose: () => void;
}) {
  const [values, setValues] = useState(() => {
    const initial = directoryInitialValues(
      resource,
      row,
      lookups.company?.[0]?.name,
    );
    if (resource.key === "areas" && !initial.province_id)
      initial.province_id = String(
        lookups.districts.find((d) => d.id === Number(initial.district_id))
          ?.province_id ?? "",
      );
    return initial;
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const [busy, setBusy] = useState(false);
  const submitting = useRef(false);
  const dialog = useRef<HTMLDialogElement>(null);
  const t = (text: string) => directoryText(locale, text);
  useEffect(() => {
    const node = dialog.current;
    node?.showModal();
    return () => node?.close();
  }, []);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (submitting.current) return;
    const next = validateDirectory(resource, values, lookups, isAdministrator);
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
    submitting.current = true;
    setBusy(true);
    try {
      await onSave(directoryPayload(resource, values));
    } catch (error) {
      setErrors(isApiFailure(error) ? error.fieldErrors : {});
      setBanner(
        isApiFailure(error) ? error.message : "Could not save the record.",
      );
      setBusy(false);
    } finally {
      submitting.current = false;
    }
  }

  return (
    <dialog
      ref={dialog}
      className={styles.dialog}
      aria-labelledby="directory-editor-title"
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
      onCancel={(event) => {
        event.preventDefault();
        if (!busy) onClose();
      }}
    >
      <form noValidate onSubmit={submit}>
        <header className={styles.headingRow}>
          <h2 id="directory-editor-title">
            {t(row ? "Edit record" : "Add record")} · {t(resource.label)}
          </h2>
          <button
            type="button"
            aria-label={t("Close")}
            onClick={onClose}
            disabled={busy}
          >
            ×
          </button>
        </header>
        {banner && (
          <p className={styles.error} role="alert">
            {t(banner)}
          </p>
        )}
        {resource.key === "weavers" && (
          <p className={styles.hint}>
            {t(
              "Create geographic records first: province, district, then area.",
            )}
            {!isAdministrator && (
              <> {t("Manager assignment is required for branch staff.")}</>
            )}
          </p>
        )}
        <div className={styles.fields}>
          {resource.fields.map((field) => {
            const id = `directory-${field.key}`;
            const choices = directoryOptions(field, values, lookups);
            const invalid = Boolean(errors[field.key]);
            const disabled =
              busy ||
              (field.lookup === "districts" && !values.province_id) ||
              (field.lookup === "areas" && !values.district_id);
            const shared = {
              id,
              disabled,
              value: values[field.key],
              "aria-invalid": invalid,
              "aria-describedby": `${id}-error`,
              onChange: (
                event: React.ChangeEvent<
                  HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement
                >,
              ) =>
                setValues((current) =>
                  changeDirectoryValue(current, field.key, event.target.value),
                ),
            };
            return (
              <div
                key={field.key}
                className={`${styles.field} ${field.kind === "textarea" ? styles.wide : ""}`}
              >
                <label htmlFor={id}>
                  {t(field.label)}
                  {(field.required ||
                    (field.key === "manager_employee_id" &&
                      !isAdministrator)) &&
                    " *"}
                </label>
                {field.kind === "select" ? (
                  <select {...shared}>
                    <option value="">
                      {t(
                        field.key === "manager_employee_id" && isAdministrator
                          ? "Unassigned"
                          : "Choose an option",
                      )}
                    </option>
                    {field.lookup
                      ? choices.map((option) => (
                          <option key={option.id} value={option.id}>
                            {locale === "fa"
                              ? option.name_fa || option.name
                              : option.name}
                            {option.status === "inactive"
                              ? ` (${t("Inactive")})`
                              : ""}
                          </option>
                        ))
                      : ["active", "inactive"].map((value) => (
                          <option
                            key={value}
                            value={value}
                            disabled={
                              value === "inactive" &&
                              row?.status === "active" &&
                              !canArchive
                            }
                          >
                            {t(value)}
                          </option>
                        ))}
                  </select>
                ) : field.kind === "textarea" ? (
                  <textarea
                    {...shared}
                    dir="auto"
                    maxLength={field.max}
                    rows={3}
                  />
                ) : (
                  <input
                    {...shared}
                    autoComplete="off"
                    type={field.kind === "date" ? "date" : "text"}
                    inputMode={field.key === "phone" ? "tel" : undefined}
                    dir={
                      field.key === "phone" || field.kind === "date"
                        ? "ltr"
                        : "auto"
                    }
                    max={
                      field.kind === "date"
                        ? directoryToday(lookups.company?.[0]?.name)
                        : undefined
                    }
                    maxLength={field.max}
                  />
                )}
                {invalid && (
                  <span
                    id={`${id}-error`}
                    className={styles.fieldError}
                    role="alert"
                  >
                    {t(errors[field.key])}
                  </span>
                )}
              </div>
            );
          })}
        </div>
        <footer className={styles.actions}>
          <button type="button" onClick={onClose} disabled={busy}>
            {t("Cancel")}
          </button>
          <button className={styles.primary} disabled={busy}>
            {t(busy ? "Saving..." : "Save")}
          </button>
        </footer>
      </form>
    </dialog>
  );
}
