"use client";

import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import { FiX } from "react-icons/fi";
import { washingText } from "@/lib/washing-i18n";
import styles from "./washing.module.css";

export function WashingDialog({ title, locale, busy = false, onClose, children }: { title: string; locale: string; busy?: boolean; onClose: () => void; children: ReactNode }) {
  const node = useRef<HTMLDialogElement>(null);
  const [closing, setClosing] = useState(false);
  const titleId = useId();
  useEffect(() => {
    const dialog = node.current;
    dialog?.showModal();
    return () => dialog?.close();
  }, []);
  useEffect(() => {
    if (!closing) return;
    const timer = setTimeout(() => { node.current?.close(); onClose(); }, 200);
    return () => clearTimeout(timer);
  }, [closing, onClose]);
  function close() {
    if (busy || closing) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) { onClose(); return; }
    setClosing(true);
  }
  return <dialog ref={node} className={styles.dialog} data-closing={closing || undefined} aria-labelledby={titleId} lang={locale} dir={locale === "fa" ? "rtl" : "ltr"} onCancel={(event) => { event.preventDefault(); close(); }}>
    <header><h2 id={titleId}>{washingText(locale, title)}</h2><button type="button" className={styles.close} disabled={busy} aria-label={washingText(locale, "Close")} onClick={close}><FiX aria-hidden="true" /></button></header>
    {children}
  </dialog>;
}
