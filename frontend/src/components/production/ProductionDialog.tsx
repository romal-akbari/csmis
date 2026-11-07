"use client";

import { useEffect, useEffectEvent, useId, useRef, useState, type ReactNode } from "react";
import { FiX } from "react-icons/fi";
import { productionText } from "@/lib/manager-production-i18n";
import styles from "./production.module.css";

export function ProductionDialog({ title, locale, busy = false, dirty = false, onClose, children }: {
  title: string; locale: string; busy?: boolean; dirty?: boolean; onClose: () => void; children: ReactNode;
}) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const closeButton = useRef<HTMLButtonElement>(null);
  const keepEditingButton = useRef<HTMLButtonElement>(null);
  const titleId = useId();
  const [discard, setDiscard] = useState(false);
  const [closing, setClosing] = useState(false);
  const t = (text: string) => productionText(locale, text);
  const finishClosing = useEffectEvent(() => {
    dialogRef.current?.close();
    onClose();
  });
  useEffect(() => {
    const previous = document.activeElement;
    const dialog = dialogRef.current;
    dialog?.showModal();
    return () => {
      dialog?.close();
      if (previous instanceof HTMLElement && previous.isConnected) previous.focus();
    };
  }, []);
  useEffect(() => {
    if (!closing) return;
    // Background refreshes must not restart the dismissal animation.
    const timer = setTimeout(() => finishClosing(), 180);
    return () => clearTimeout(timer);
  }, [closing]);
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
  return (
    <dialog ref={dialogRef} className={styles.dialog} data-closing={closing || undefined} lang={locale} dir={locale === "fa" ? "rtl" : "ltr"}
      aria-labelledby={titleId} onCancel={(event) => { event.preventDefault(); close(); }}>
      <header className={styles.dialogHeader}>
        <h2 id={titleId}>{t(title)}</h2>
        <button ref={closeButton} type="button" className={styles.iconButton} aria-label={t("Close")} disabled={busy} onClick={() => close()}><FiX aria-hidden="true" /></button>
        {discard && <div className={`${styles.notice} ${styles.discardNotice}`} role="alert"><p>{t("Discard unsaved changes?")}</p><div className={styles.actions}>
          <button ref={keepEditingButton} type="button" onClick={keepEditing}>{t("Keep editing")}</button><button type="button" onClick={() => close(true)}>{t("Discard")}</button>
        </div></div>}
      </header>
      {children}
    </dialog>
  );
}
