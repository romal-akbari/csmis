"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { subscribeBalanceUpdates } from "@/lib/balance-updates";
import {
  today,
  validDate,
  validDecimal,
  type FoundationRecord,
} from "@/lib/foundation";
import { foundationText } from "@/lib/foundation-i18n";
import styles from "./foundation.module.css";

type Page = {
  data: FoundationRecord[];
  current_page: number;
  last_page: number;
  total: number;
};
type Ledger = { entries: Page; balance: string; currency: string };
type Props = {
  account: FoundationRecord;
  profile: Profile;
  locale: string;
  timezone?: string;
  funding?: boolean;
  onBack: () => void;
};

export function LedgerPanel({
  account,
  profile,
  locale,
  timezone,
  funding = false,
  onBack,
}: Props) {
  const [ledger, setLedger] = useState<Ledger | null>(null);
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  const [error, setError] = useState("");
  const [flash, setFlash] = useState("");
  const [busy, setBusy] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [form, setForm] = useState({
    entry_type: "income",
    amount: "",
    rate_to_base: "1",
    transaction_date: today(timezone),
    description: "",
  });
  const [reversing, setReversing] = useState<FoundationRecord | null>(null);
  const [reason, setReason] = useState("");
  const retryKey = useRef<string | null>(null);
  const retryPayload = useRef("");
  const initialized = useRef(false);
  const t = (text: string) => foundationText(locale, text);
  const permitted = (action: string) =>
    profile.permissions.includes(`ledger.${action}`);

  useEffect(() => subscribeBalanceUpdates(() => setRevision((value) => value + 1)), []);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Ledger>(`/foundation/ledger?account_id=${account.id}&page=${page}`)
      .then((data) => {
        if (!cancelled) {
          setLedger(data);
          if (!initialized.current) {
            initialized.current = true;
            if (funding) setForm((value) => ({ ...value,
              entry_type: data.entries.total === 0 ? "opening" : "income" }));
          }
          setError("");
        }
      })
      .catch((e) => {
        if (!cancelled)
          setError(isApiFailure(e) ? e.message : "Could not load ledger.");
      });
    return () => {
      cancelled = true;
    };
  }, [account.id, page, revision, funding]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (busy || !ledger) return;
    const errors: Record<string, string> = {};
    if (reversing) {
      if (reason.trim().length < 3 || reason.length > 255)
        errors.reason = "Use 3-255 characters.";
    } else {
      if (!validDecimal(form.amount, 16, 2))
        errors.amount = "Enter a positive amount with up to 2 decimal places.";
      if (!validDecimal(form.rate_to_base, 12, 8))
        errors.rate_to_base =
          "Enter a positive rate with up to 8 decimal places.";
      if (
        !validDate(form.transaction_date) ||
        form.transaction_date > today(timezone)
      )
        errors.transaction_date = "Choose a valid date, today or earlier.";
      if (form.description.trim().length < 3 || form.description.length > 255)
        errors.description = "Use 3-255 characters.";
    }
    setFieldErrors(errors);
    setError("");
    setFlash("");
    if (Object.keys(errors).length) return;
    const path = reversing
      ? `/foundation/ledger/${reversing.id}/reverse`
      : "/foundation/ledger";
    const payload = reversing
      ? { reason: reason.trim() }
      : { ...form, account_id: account.id };
    const fingerprint = JSON.stringify({ path, payload });
    if (fingerprint !== retryPayload.current) {
      retryKey.current = crypto.randomUUID();
      retryPayload.current = fingerprint;
    }
    setBusy(true);
    try {
      await apiFetch(path, {
        method: "POST",
        body: JSON.stringify(payload),
        headers: { "Idempotency-Key": retryKey.current! },
      });
      setFlash(t(reversing ? "Entry reversed." : "Entry posted."));
      setReversing(null);
      setReason("");
      setForm({ ...form, entry_type: form.entry_type === "opening" ? "income" : form.entry_type,
        amount: "", description: "" });
      retryPayload.current = "";
      retryKey.current = null;
      setRevision((n) => n + 1);
      setPage(1);
    } catch (e) {
      setError(
        isApiFailure(e)
          ? e.message
          : "Connection failed. Retry safely with the same form.",
      );
      if (isApiFailure(e)) setFieldErrors(e.fieldErrors);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section>
      <div className={styles.headingRow}>
        <div>
          <h2>
            {t("Ledger")} · {String(account.name)}
          </h2>
          <p>
            {t("Balance")}:{" "}
            <strong dir="ltr">
              {ledger?.balance ?? "…"} {ledger?.currency}
            </strong>
          </p>
        </div>
        <div className={styles.actions}>
          <button type="button" onClick={() => setRevision((value) => value + 1)}>{t("Refresh balances")}</button>
          <button onClick={onBack}>{t("Accounts")}</button>
        </div>
      </div>
      <p>
        {t(
          "Ledger entries cannot be edited or deleted. Use a reversal to correct an entry.",
        )}
      </p>
      {permitted("create") && <p className={styles.notice}>
        {t("Choose opening for the account's starting money. Use income only for actual income. Record weaver and washing payments through their linked pages.")}
      </p>}
      {error && (
        <p className={styles.error} role="alert">
          {t(error)}
        </p>
      )}
      {flash && (
        <p className={styles.notice} role="status">
          {flash}
        </p>
      )}
      {(permitted("create") || reversing) && (
        <form
          noValidate
          onSubmit={submit}
          className={styles.ledgerForm}
          aria-busy={busy}
        >
          <h3>{t(reversing ? "Reverse entry" : funding ? "Add money" : "Post entry")}</h3>
          {reversing ? (
            <div className={styles.field}>
              <label htmlFor="ledger-reason">{t("Reason")}</label>
              <input
                id="ledger-reason"
                value={reason}
                maxLength={255}
                disabled={busy}
                onChange={(e) => setReason(e.target.value)}
                aria-describedby="reason-error"
              />
              <span id="reason-error" role="alert">
                {t(fieldErrors.reason ?? "")}
              </span>
            </div>
          ) : (
            <div className={styles.fields}>
              <div className={styles.field}>
                <label htmlFor="ledger-type">{t("Entry type")}</label>
                <select
                  id="ledger-type"
                  disabled={busy || !ledger}
                  aria-invalid={Boolean(fieldErrors.entry_type)}
                  aria-describedby="ledger-entry-type-error"
                  value={form.entry_type}
                  onChange={(e) =>
                    setForm({ ...form, entry_type: e.target.value })
                  }
                >
                  {["opening", "income", "expense"].map((type) => (
                    <option key={type} value={type}>
                      {t(type)}
                    </option>
                  ))}
                </select>
                <span id="ledger-entry-type-error" className={styles.fieldError}>
                  {t(fieldErrors.entry_type ?? "")}
                </span>
              </div>
              {(
                [
                  "amount",
                  "rate_to_base",
                  "transaction_date",
                  "description",
                ] as const
              ).map((key) => (
                <div className={styles.field} key={key}>
                  <label htmlFor={`ledger-${key}`}>
                    {t(
                      {
                        amount: "Amount",
                        rate_to_base: "Rate to base currency",
                        transaction_date: "Date",
                        description: "Description",
                      }[key],
                    )}
                  </label>
                  <input
                    id={`ledger-${key}`}
                    disabled={busy || !ledger}
                    value={form[key]}
                    type={key === "transaction_date" ? "date" : "text"}
                    dir={key === "description" ? "auto" : "ltr"}
                    inputMode={
                      key === "amount" || key === "rate_to_base"
                        ? "decimal"
                        : undefined
                    }
                    maxLength={key === "description" ? 255 : 30}
                    onChange={(e) =>
                      setForm({ ...form, [key]: e.target.value })
                    }
                    aria-invalid={Boolean(fieldErrors[key])}
                    aria-describedby={`ledger-error-${key}`}
                  />
                  <span
                    id={`ledger-error-${key}`}
                    role="alert"
                    className={styles.fieldError}
                  >
                    {t(fieldErrors[key] ?? "")}
                  </span>
                </div>
              ))}
            </div>
          )}
          <div className={styles.formActions}>
            {reversing && (
              <button
                type="button"
                disabled={busy}
                onClick={() => {
                  setReversing(null);
                  setFieldErrors({});
                }}
              >
                {t("Cancel")}
              </button>
            )}
            <button className={styles.primary} disabled={busy || !ledger}>
              {t(
                busy ? "Saving..." : reversing ? "Reverse entry" : "Post entry",
              )}
            </button>
          </div>
        </form>
      )}
      <div className={styles.tableWrap}>
        <table>
          <thead>
            <tr>
              {[
                "Date",
                "Entry type",
                "Direction",
                "Amount",
                "Description",
                "Action",
              ].map((title) => (
                <th key={title}>{t(title)}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {ledger?.entries.data.map((row) => (
              <tr key={row.id}>
                <td>{String(row.transaction_date)}</td>
                <td>{t(String(row.entry_type))}</td>
                <td>{t(String(row.direction))}</td>
                <td dir="ltr">{String(row.amount)}</td>
                <td>{String(row.description)}</td>
                <td>
                  {permitted("reverse") && !row.reversal_of_id && (
                    <button
                      disabled={busy}
                      onClick={() => {
                        setReversing(row);
                        setReason("");
                        setFieldErrors({});
                        setError("");
                      }}
                    >
                      {t("Reverse entry")} #{row.id}
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!ledger && <p role="status">{t("Loading...")}</p>}
      {ledger && ledger.entries.data.length === 0 && (
        <p>{t("No records yet.")}</p>
      )}
      <div className={styles.pager}>
        <button disabled={page <= 1} onClick={() => setPage(page - 1)}>
          {t("Previous")}
        </button>
        <span>
          {page} / {ledger?.entries.last_page ?? 1}
        </span>
        <button
          disabled={!ledger || page >= ledger.entries.last_page}
          onClick={() => setPage(page + 1)}
        >
          {t("Next")}
        </button>
      </div>
    </section>
  );
}
