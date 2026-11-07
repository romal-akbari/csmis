"use client";

import {
  cloneElement,
  useEffect,
  useRef,
  useState,
  type FormEvent,
  type ReactElement,
} from "react";
import { FiX } from "react-icons/fi";
import { isApiFailure } from "@/lib/api";
import { customerLabel, customerText } from "@/lib/customer-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import {
  CUSTOMER_STATUSES,
  CUSTOMER_TYPES,
  emptyCustomer,
  validateCustomer,
  type CustomerFieldErrors,
  type CustomerFormValues,
} from "@/lib/customer-validation";
import type { Customer } from "./types";

type Props = {
  initial: Customer | null;
  today: string;
  onCancel: () => void;
  onSaved: () => void;
  onSubmit: (values: CustomerFormValues) => Promise<void>;
};

function toValues(
  customer: Customer | null,
  today: string,
): CustomerFormValues {
  if (!customer) return emptyCustomer(today);
  return {
    name: customer.name,
    phone: customer.phone ?? "",
    address: customer.address ?? "",
    customer_type: customer.customer_type,
    registration_date: customer.registration_date,
    notes: customer.notes ?? "",
    status: customer.status,
  };
}

function Field({
  id,
  label,
  error,
  full,
  children,
}: {
  id: string;
  label: string;
  error?: string;
  full?: boolean;
  children: ReactElement<Record<string, unknown>>;
}) {
  return (
    <label className={`customers-field${full ? " full" : ""}`}>
      <span>{label}</span>
      {cloneElement(children, {
        id,
        "aria-label": label,
        "aria-invalid": Boolean(error),
        "aria-describedby": error ? `${id}-error` : undefined,
      })}
      {error && (
        <em id={`${id}-error`} className="customers-field-error" role="alert">
          {error}
        </em>
      )}
    </label>
  );
}

/** Create/edit dialog with client rules first, server 422s second. */
export function CustomerForm({
  initial,
  today,
  onCancel,
  onSaved,
  onSubmit,
}: Props) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const t = (text: string) => customerText(locale, text);
  const [values, setValues] = useState<CustomerFormValues>(() =>
    toValues(initial, today),
  );
  const [clientErrors, setClientErrors] = useState<CustomerFieldErrors>({});
  const [serverErrors, setServerErrors] = useState<CustomerFieldErrors>({});
  const [banner, setBanner] = useState("");
  const [busy, setBusy] = useState(false);
  const [closing, setClosing] = useState(false);
  const busyRef = useRef(false);
  const closingRef = useRef(false);
  const afterCloseRef = useRef(onCancel);
  const formRef = useRef<HTMLFormElement>(null);
  const dialogRef = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null;
    const dialog = dialogRef.current;
    dialog?.showModal();
    formRef.current?.querySelector<HTMLInputElement>("input")?.focus();
    return () => {
      dialog?.close();
      previous?.focus();
    };
  }, []);

  useEffect(() => {
    if (!closing) return;
    const dialog = dialogRef.current;
    if (!dialog) return;
    let active = true;
    // Keep the native modal (and its focus trap) until the exit has finished.
    // No animations means immediate dismissal, including reduced motion.
    void Promise.allSettled(
      dialog.getAnimations().map((animation) => animation.finished),
    ).then(() => {
      if (!active) return;
      dialog.close();
      afterCloseRef.current();
    });
    return () => {
      active = false;
    };
  }, [closing]);

  const title = initial ? "Edit customer" : "New customer";
  const shown: CustomerFieldErrors = { ...serverErrors, ...clientErrors };

  function close(afterClose: () => void) {
    if (closingRef.current) return;
    closingRef.current = true;
    afterCloseRef.current = afterClose;
    setClosing(true);
  }

  function cancel() {
    if (!busyRef.current) close(onCancel);
  }

  function set<K extends keyof CustomerFormValues>(key: K, value: string) {
    setValues((current) => ({ ...current, [key]: value }));
    setClientErrors((current) => ({ ...current, [key]: undefined }));
    setServerErrors((current) => ({ ...current, [key]: undefined }));
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busyRef.current || closingRef.current) return;
    setBanner("");
    setServerErrors({});

    const found = validateCustomer(values, today);
    setClientErrors(found);
    if (Object.keys(found).length > 0) {
      document.getElementById(`customer-${Object.keys(found)[0]}`)?.focus();
      return;
    }

    busyRef.current = true;
    setBusy(true);
    try {
      await onSubmit(values);
      close(onSaved);
    } catch (error) {
      if (isApiFailure(error)) {
        setServerErrors(error.fieldErrors);
        if (Object.keys(error.fieldErrors).length === 0) {
          setBanner(error.message);
        }
      } else {
        setBanner("Could not reach the server.");
      }
    } finally {
      busyRef.current = false;
      setBusy(false);
    }
  }

  return (
    <dialog
      ref={dialogRef}
      className="customers-form"
      data-state={closing ? "closing" : "open"}
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
      aria-labelledby="customer-form-title"
      onCancel={(event) => {
        event.preventDefault();
        cancel();
      }}
      onClick={(event) => {
        if (event.target !== event.currentTarget || busyRef.current) return;
        const bounds = event.currentTarget.getBoundingClientRect();
        if (
          event.clientX < bounds.left ||
          event.clientX > bounds.right ||
          event.clientY < bounds.top ||
          event.clientY > bounds.bottom
        )
          cancel();
      }}
    >
      <form
        ref={formRef}
        className="customers-form-content"
        onSubmit={submit}
        aria-busy={busy || closing}
        noValidate
      >
        <header className="customers-form-head">
          <h2 id="customer-form-title">{t(title)}</h2>
          <button
            className="customers-modal-close"
            type="button"
            aria-label={t("Close")}
            title={t("Close")}
            onClick={cancel}
            disabled={busy || closing}
          >
            <FiX aria-hidden="true" />
          </button>
        </header>

        <div className="customers-form-body">
          {banner && (
            <p className="customers-banner error" role="alert">
              {t(banner)}
            </p>
          )}

          <div className="customers-grid">
            <Field
              id="customer-name"
              label={t("Name *")}
              error={shown.name && t(shown.name)}
              full
            >
              <input
                value={values.name}
                onChange={(event) => set("name", event.target.value)}
              />
            </Field>

            <Field
              id="customer-phone"
              label={t("Phone")}
              error={shown.phone && t(shown.phone)}
            >
              <input
                dir="ltr"
                value={values.phone}
                maxLength={30}
                placeholder="+93 70 000 0000"
                onChange={(event) => set("phone", event.target.value)}
              />
            </Field>

            <Field
              id="customer-customer_type"
              label={t("Type *")}
              error={shown.customer_type && t(shown.customer_type)}
            >
              <select
                value={values.customer_type}
                onChange={(event) => set("customer_type", event.target.value)}
              >
                {CUSTOMER_TYPES.map((type) => (
                  <option key={type} value={type}>
                    {customerLabel(locale, type)}
                  </option>
                ))}
              </select>
            </Field>

            <Field
              id="customer-registration_date"
              label={t("Registered *")}
              error={shown.registration_date && t(shown.registration_date)}
            >
              <input
                type="date"
                dir="ltr"
                min="1000-01-01"
                max={today}
                value={values.registration_date}
                onChange={(event) =>
                  set("registration_date", event.target.value)
                }
              />
            </Field>

            <Field
              id="customer-status"
              label={t("Status *")}
              error={shown.status && t(shown.status)}
            >
              <select
                value={values.status}
                onChange={(event) => set("status", event.target.value)}
              >
                {CUSTOMER_STATUSES.map((status) => (
                  <option key={status} value={status}>
                    {customerLabel(locale, status)}
                  </option>
                ))}
              </select>
            </Field>

            <Field
              id="customer-address"
              label={t("Address")}
              error={shown.address && t(shown.address)}
              full
            >
              <input
                value={values.address}
                onChange={(event) => set("address", event.target.value)}
              />
            </Field>

            <Field
              id="customer-notes"
              label={t("Notes")}
              error={shown.notes && t(shown.notes)}
              full
            >
              <textarea
                rows={3}
                value={values.notes}
                onChange={(event) => set("notes", event.target.value)}
              />
            </Field>
          </div>
        </div>
        <footer className="customers-form-actions">
          <button
            className="secondary-button"
            type="button"
            onClick={cancel}
            disabled={busy || closing}
          >
            {t("Cancel")}
          </button>
          <button
            className="primary-button"
            type="submit"
            disabled={busy || closing}
          >
            {t(busy ? "Saving..." : initial ? "Save changes" : "Add customer")}
          </button>
        </footer>
      </form>
    </dialog>
  );
}
