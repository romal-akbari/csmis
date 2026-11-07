"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { FiX } from "react-icons/fi";
import { businessText } from "@/lib/business-i18n";
import styles from "./business.module.css";

export function BusinessDialog({ title, locale, busy = false, dirty = false, onClose, children }: { title: string; locale: string; busy?: boolean; dirty?: boolean; onClose: () => void; children: ReactNode }) {
  const node = useRef<HTMLDialogElement>(null);
  const closeButton = useRef<HTMLButtonElement>(null);
  const keepEditingButton = useRef<HTMLButtonElement>(null);
  const [discard, setDiscard] = useState(false);
  const [closing, setClosing] = useState(false);
  const t = (s: string) => businessText(locale, s);
  useEffect(() => { const dialog = node.current; dialog?.showModal(); return () => dialog?.close(); }, []);
  useEffect(() => {
    if (!closing) return;
    const timer = setTimeout(() => { node.current?.close(); onClose(); }, 180);
    return () => clearTimeout(timer);
  }, [closing, onClose]);
  useEffect(() => {
    if (discard) keepEditingButton.current?.focus();
  }, [discard]);
  function keepEditing() {
    setDiscard(false);
    closeButton.current?.focus();
  }
  function close(force = false) {
    if (busy || closing) return;
    if (dirty && !force) { setDiscard(true); return; }
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) { onClose(); return; }
    setClosing(true);
  }
  return <dialog ref={node} className={styles.dialog} data-closing={closing || undefined} lang={locale} dir={locale === "fa" ? "rtl" : "ltr"} aria-labelledby="business-dialog-title" onCancel={(e) => { e.preventDefault(); close(); }}>
    <header className={styles.dialogHeader}>
      <h2 id="business-dialog-title">{t(title)}</h2><button ref={closeButton} type="button" className={styles.close} aria-label={t("Close")} disabled={busy} onClick={() => close()}><FiX aria-hidden="true" /></button>
      {discard && <div className={`${styles.notice} ${styles.discardNotice}`} role="alert"><p>{t("Discard unsaved changes?")}</p><div className={styles.actions}><button ref={keepEditingButton} type="button" onClick={keepEditing}>{t("Keep editing")}</button><button type="button" onClick={() => close(true)}>{t("Discard")}</button></div></div>}
    </header>
    {children}
  </dialog>;
}
