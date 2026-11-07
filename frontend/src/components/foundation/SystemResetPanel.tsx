"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { FiRotateCcw, FiShield, FiX, FiCheckCircle } from "react-icons/fi";
import { apiFetch, isApiFailure, writeToken } from "@/lib/api";
import { RESET_CONFIRMATION, validateReset, type ResetPreview } from "@/lib/system-reset";
import { resetText } from "@/lib/system-reset-i18n";
import styles from "./system-reset.module.css";

function ResetDialog({ locale, onClose }: { locale: string; onClose: () => void }) {
  const t = (text: string) => resetText(locale, text);
  const dialog = useRef<HTMLDialogElement>(null);
  const submitted = useRef(false);
  const [preview, setPreview] = useState<ResetPreview | null>(null);
  const [revision, setRevision] = useState(0);
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const [busy, setBusy] = useState(false);
  const [complete, setComplete] = useState(false);
  const [uncertain, setUncertain] = useState(false);
  useEffect(() => {
    const node = dialog.current;
    node?.showModal();
    return () => node?.close();
  }, []);
  useEffect(() => {
    const controller = new AbortController();
    apiFetch<{ data: ResetPreview }>("/system/reset-preview", { signal: controller.signal })
      .then((result) => {
        if (!controller.signal.aborted) setPreview(result.data);
      }).catch(() => {
        if (!controller.signal.aborted) setBanner("Could not load the reset review.");
      });
    return () => controller.abort();
  }, [revision]);

  function reviewAgain() {
    setPreview(null);
    setBanner("");
    setErrors({});
    setPassword("");
    setConfirmation("");
    setRevision((value) => value + 1);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (submitted.current || !preview || complete || uncertain) return;
    const invalid = validateReset(password, confirmation);
    setErrors(invalid);
    if (Object.keys(invalid).length) return;
    submitted.current = true;
    setBusy(true);
    setBanner("");
    try {
      await apiFetch("/system/reset", { method: "POST", body: JSON.stringify({
        password, confirmation, preview_token: preview.preview_token,
      }) });
      setPassword("");
      setConfirmation("");
      setComplete(true);
    } catch (error) {
      if (isApiFailure(error) && error.status < 500) {
        setErrors(error.fieldErrors);
        setBanner(error.message);
        if (error.status === 409 || error.fieldErrors.preview_token) setPreview(null);
      } else {
        setUncertain(true);
        setPassword("");
        setBanner("Reset could not be confirmed. Sign in again to check the system before trying another reset.");
      }
      submitted.current = false;
    } finally {
      setBusy(false);
    }
  }
  const number = (value: number) => new Intl.NumberFormat(locale === "fa" ? "fa-AF" : "en-US")
    .format(value);
  const locked = busy || complete || uncertain;

  return <dialog ref={dialog} className={styles.dialog} lang={locale}
    dir={locale === "fa" ? "rtl" : "ltr"} aria-labelledby="system-reset-title"
    onCancel={(event) => { event.preventDefault(); if (!locked) onClose(); }}>
    <div className={styles.dialogHeading}>
      <span className={styles.icon} aria-hidden="true"><FiShield /></span>
      <div><h2 id="system-reset-title">{t(complete
        ? "System data reset successfully." : "Reset system data?")}</h2>
        {!complete && <p>{t("Review carefully before continuing.")}</p>}</div>
      {!complete && <button type="button" className={styles.close} disabled={locked}
        aria-label={t("Close")} onClick={onClose}><FiX aria-hidden="true" /></button>}
    </div>
    {complete ? <div className={styles.success} role="status">
      <FiCheckCircle aria-hidden="true" />
      <p>{t("Your users, roles and permissions are preserved. Sign in again to configure the system.")}</p>
      <button type="button" onClick={() => writeToken(null)}>{t("Sign in again")}</button>
    </div> : <>
      {banner && <p className={styles.banner} role="alert">{t(banner)}</p>}
      {preview ? <>
        <div className={styles.recordCount}>
          <strong>{number(preview.records_to_remove)}</strong><span>{t("Records to clear")}</span>
        </div>
        <div className={styles.kept}>{(["users", "roles", "permissions"] as const)
          .map((key) => <div key={key}><strong>{number(preview.kept[key])}</strong>
            <span>{t(`${key === "users" ? "Users" : key === "roles" ? "Roles" : "Permissions"} kept`)}</span>
          </div>)}</div>
        <p className={styles.muted}>{t("User-role and role-permission assignments are also preserved.")}</p>
      </> : !banner && <p role="status">{t("Loading reset review…")}</p>}
      <div className={styles.explanation}>
        <p>{t("This clears customers, weavers, suppliers, carpets, purchases, stock, materials, production, partnerships, sales, expenses, payments, accounts, currencies, branches, categories, geography, settings, audit history and uploaded carpet, branding and expense receipt files.")}</p>
        <p>{t("Employee links and branch/facility/account assignments are cleared. All users are signed out.")}</p>
        <p>{t("A private database backup and uploaded carpet, branding and expense receipt files are archived before deletion.")}</p>
      </div>
      <p className={styles.muted}>{t("After reset, sign in with your existing credentials. Create a currency, set the base currency, then recreate branches and assignments.")}</p>
      {uncertain ? <button type="button" className={styles.primary}
        onClick={() => writeToken(null)}>{t("Sign in again")}</button> :
        <form noValidate onSubmit={submit} aria-busy={busy}>
          <label htmlFor="system-reset-password">{t("Current password")}</label>
          <input id="system-reset-password" type="password" autoComplete="current-password"
            value={password} disabled={busy} maxLength={255}
            aria-invalid={Boolean(errors.password)} aria-describedby="system-reset-password-error"
            onChange={(event) => setPassword(event.target.value)} />
          <span className={styles.error} id="system-reset-password-error">{t(errors.password ?? "")}</span>
          <label htmlFor="system-reset-confirmation">{t("Type the confirmation phrase")}
            <code dir="ltr">{RESET_CONFIRMATION}</code></label>
          <input id="system-reset-confirmation" autoComplete="off" spellCheck={false}
            dir="ltr" value={confirmation} disabled={busy}
            aria-invalid={Boolean(errors.confirmation)} aria-describedby="system-reset-confirmation-error"
            onChange={(event) => setConfirmation(event.target.value)} />
          <span className={styles.error} id="system-reset-confirmation-error">{t(errors.confirmation ?? "")}</span>
          {busy && <p role="status">{t("Keep this window open until the reset finishes.")}</p>}
          <div className={styles.actions}>
            <button type="button" disabled={busy} onClick={onClose}>{t("Cancel")}</button>
            {!preview && <button type="button" onClick={reviewAgain} disabled={busy}>
              {t("Review again")}</button>}
            <button className={styles.primary} type="submit"
              disabled={!preview || busy || !password || confirmation !== RESET_CONFIRMATION}>
              {t(busy ? "Resetting system…" : "Clear all business data")}</button>
          </div>
        </form>}
    </>}
  </dialog>;
}

export function SystemResetPanel({ locale }: { locale: string }) {
  const [open, setOpen] = useState(false);
  const t = (text: string) => resetText(locale, text);
  return <section className={styles.panel} aria-labelledby="system-reset-section-title">
    <span className={styles.icon} aria-hidden="true"><FiRotateCcw /></span>
    <div className={styles.copy}><h2 id="system-reset-section-title">{t("System reset")}</h2>
      <p>{t("Clear business data and start fresh. Users, roles and permissions are kept.")}</p></div>
    <button type="button" onClick={() => setOpen(true)}>{t("Reset system data")}</button>
    {open && <ResetDialog locale={locale} onClose={() => setOpen(false)} />}
  </section>;
}
