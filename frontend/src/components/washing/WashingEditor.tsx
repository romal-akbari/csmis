"use client";

import { useRef, useState, type FormEvent } from "react";
import { isApiFailure } from "@/lib/api";
import { validateWashing, washingActions, washingAmountPattern, washingConverted, washingFormat, washingList, washingMinor, washingPayload, washingRatePattern, washingToday, washingValue, type WashingAction, type WashingLine, type WashingLookups, type WashingOption, type WashingRow, type WashingValues } from "@/lib/washing";
import { washingText } from "@/lib/washing-i18n";
import { WashingDialog } from "./WashingDialog";
import { WashingPicker } from "./WashingPicker";
import styles from "./washing.module.css";

export function WashingEditor({ action, row, lookups, locale, onSave, onClose }: { action: WashingAction; row?: WashingRow; lookups: WashingLookups; locale: string; onSave: (payload: Record<string, unknown>, key: string) => Promise<void>; onClose: () => void }) {
  const t = (text: string) => washingText(locale, text);
  const [values, setValues] = useState<WashingValues>(() => { const today = washingToday(lookups.company.timezone); return { branch_id: washingValue(row, "branch_id"), facility_id: washingValue(row, "facility_id"), currency_id: String(lookups.company.base_currency_id ?? ""), rate_to_base: "1", bill_units_per_payment_unit: "1", sent_on: today, completed_on: today, returned_on: today, billed_on: today, payment_on: today, voided_on: today }; });
  const [lines, setLines] = useState<WashingLine[]>([]); const [errors, setErrors] = useState<Record<string, string>>({}); const [banner, setBanner] = useState(""); const [busy, setBusy] = useState(false);
  const submitting = useRef(false); const identity = useRef<{ payload: string; key: string } | null>(null);
  const account = lookups.accounts.find((option) => option.id === Number(values.account_id));
  const currency = lookups.currencies.find((option) => option.id === Number(action === "payment" ? row?.currency_id : values.currency_id));
  const eligible = washingList(row, "items").filter((item) => item.state === (action === "return" ? "completed" : "sent"));
  function change(name: string, value: string) {
    setValues((previous) => ({ ...previous, [name]: value, ...(name === "facility_id" ? { facility_location_id: "" } : {}), ...(name === "account_id" ? { bill_units_per_payment_unit: "1", rate_to_base: "1" } : {}), ...(name === "currency_id" ? { rate_to_base: "1" } : {}) }));
    if (["branch_id", "facility_id"].includes(name)) setLines([]);
    setErrors({}); setBanner("");
  }
  function selectLines(next: WashingLine[]) { setLines(next); setErrors({}); setBanner(""); }
  function field(name: string, label: string, options?: WashingOption[], type = "text", maxLength?: number) {
    return <div className={styles.field} key={name}><label htmlFor={`washing-${name}`}>{t(label)}</label>{options ? <select id={`washing-${name}`} value={values[name] ?? ""} onChange={(event) => change(name, event.target.value)} aria-invalid={!!errors[name]} aria-describedby={errors[name] ? `washing-${name}-error` : undefined}><option value="">{t("Choose an option")}</option>{options.map((option) => <option key={option.id} value={option.id}>{locale === "fa" && option.name_fa ? option.name_fa : option.name || option.code}{option.name && option.code ? ` · ${option.code}` : ""}</option>)}</select> : <input id={`washing-${name}`} placeholder={["order_no", "expense_no", "payment_no"].includes(name) ? t("Generated automatically when saved") : undefined} type={type} inputMode={type === "text" && ["amount", "rate_to_base", "bill_units_per_payment_unit"].includes(name) ? "decimal" : undefined} max={type === "date" ? washingToday(lookups.company.timezone) : undefined} maxLength={maxLength} value={values[name] ?? ""} onChange={(event) => change(name, event.target.value)} aria-invalid={!!errors[name]} aria-describedby={errors[name] ? `washing-${name}-error` : undefined} />}{errors[name] && <span id={`washing-${name}-error`} className={styles.fieldError}>{t(errors[name])}</span>}</div>;
  }
  function textarea(name: string, label: string, maxLength: number) { return <div className={`${styles.field} ${styles.wide}`}><label htmlFor={`washing-${name}`}>{t(label)}</label><textarea id={`washing-${name}`} value={values[name] ?? ""} maxLength={maxLength} onChange={(event) => change(name, event.target.value)} aria-invalid={!!errors[name]} aria-describedby={errors[name] ? `washing-${name}-error` : undefined} />{errors[name] && <span id={`washing-${name}-error`} className={styles.fieldError}>{t(errors[name])}</span>}</div>; }
  async function submit(event: FormEvent) {
    event.preventDefault(); if (submitting.current) return;
    const validation = validateWashing(action, values, lines, lookups, row); setErrors(validation); setBanner("");
    if (Object.keys(validation).length) { setBanner("Please correct the highlighted fields."); return; }
    const payload = washingPayload(action, values, lines); const serialized = JSON.stringify(payload);
    if (identity.current?.payload !== serialized) identity.current = { payload: serialized, key: crypto.randomUUID() };
    submitting.current = true; setBusy(true);
    try { await onSave(payload, identity.current.key); }
    catch (error) { if (isApiFailure(error)) { setErrors(error.fieldErrors); setBanner(error.message); } else setBanner("Could not save the record."); }
    finally { submitting.current = false; setBusy(false); }
  }
  const total = action === "bill" && lines.every((line) => washingAmountPattern.test(line.amount)) ? washingFormat(lines.reduce((sum, line) => sum + washingMinor(line.amount), BigInt(0))) : "—";
  const settlement = action === "payment" && washingAmountPattern.test(values.amount ?? "") && washingRatePattern.test(values.bill_units_per_payment_unit ?? "") ? washingFormat(washingConverted(values.amount, values.bill_units_per_payment_unit, currency?.decimal_places ?? 2)) : "—";
  return <WashingDialog title={washingActions[action]} locale={locale} busy={busy} onClose={onClose}><form noValidate onSubmit={submit} aria-busy={busy}>
    {banner && <div role="alert" className={styles.error}><p>{t(banner)}</p>{Object.keys(errors).length > 0 && <ul>{Array.from(new Set(Object.values(errors))).map((error) => <li key={error}>{t(error)}</li>)}</ul>}</div>}
    {action === "bill" && <p className={styles.notice}>{t("Bills record carpet costs. Only payments change the cash account balance.")}</p>}
    {action === "return" && <p className={styles.notice}>{t("Only completed carpets can return to stock. You may return part of a batch.")}</p>}
    {action === "complete" && <p className={styles.notice}>{t("Select the carpets whose washing has finished.")}</p>}
    {action === "reverse" && <p className={styles.notice}>{t("Reversing a payment restores cash and reopens the bill balance.")}</p>}
    <fieldset disabled={busy}><div className={styles.grid}>
      {action === "dispatch" && <>{field("order_no", "Batch reference", undefined, "text", 40)}{field("branch_id", "Branch", lookups.branches)}{field("facility_id", "Washing facility", lookups.facilities)}{field("facility_location_id", "Facility location", lookups.facility_locations.filter((option) => option.washing_facility_id === Number(values.facility_id)))}{field("sent_on", "Dispatch date", undefined, "date")}{textarea("notes", "Notes", 500)}</>}
      {action === "complete" && field("completed_on", "Completion date", undefined, "date")}
      {action === "return" && <>{field("returned_on", "Return date", undefined, "date")}{field("location_id", "Stock location", lookups.locations.filter((option) => option.branch_id === Number(row?.branch_id)))}</>}
      {action === "bill" && <>{field("expense_no", "Bill reference", undefined, "text", 40)}{field("invoice_no", "Invoice number", undefined, "text", 80)}{field("branch_id", "Branch", lookups.branches)}{field("facility_id", "Washing facility", lookups.facilities)}{field("currency_id", "Currency", lookups.currencies)}{field("rate_to_base", "Exchange rate to base")}{field("billed_on", "Bill date", undefined, "date")}{field("amount", "Total amount")}{textarea("notes", "Notes", 500)}</>}
      {action === "payment" && <>{field("payment_no", "Payment reference", undefined, "text", 40)}{field("account_id", "Account", lookups.accounts)}{field("amount", "Amount")}{field("rate_to_base", "Exchange rate to base")}{field("bill_units_per_payment_unit", "Bill units per payment unit")}{field("payment_on", "Payment date", undefined, "date")}{textarea("reason", "Reason", 255)}<p className={`${styles.notice} ${styles.wide}`}>{account ? `${t("Account")}: ${account.name} · ${t("Balance")}: ${account.balance} ${lookups.currencies.find((option) => option.id === account.currency_id)?.code ?? ""}` : t("Choose an account to see its currency and balance.")}<br />{t("Outstanding balance")}: {washingValue(row, "remaining_amount")} {washingValue(row, "currency_code")}<br />{t("Converted settlement")}: {settlement} {currency?.code}</p></>}
      {action === "void" && field("voided_on", "Void date", undefined, "date")}
      {["void", "reverse"].includes(action) && textarea("reason", "Reason", action === "void" ? 500 : 255)}
    </div>
    {["dispatch", "bill"].includes(action) && <WashingPicker visits={action === "bill"} branch={values.branch_id ?? ""} facility={values.facility_id ?? ""} lines={lines} onLines={selectLines} locale={locale} />}
    {["complete", "return"].includes(action) && <section className={styles.picker} aria-label={t("Selected carpets")}>
      {eligible.map((item) => { const selected = lines.some((line) => line.id === item.id); const label = `${washingValue(item, "carpet_code")} · ${washingValue(item, "name")}`; return <label className={styles.choice} key={item.id}><input type="checkbox" checked={selected} disabled={!selected && lines.length >= 50} onChange={(event) => selectLines(event.target.checked ? [...lines, { id: item.id, label, expected_sequence: Number(item.current_sequence), amount: "" }] : lines.filter((line) => line.id !== item.id))} /><span>{label}</span></label>; })}
      {!eligible.length && <p>{t("No eligible carpets in this batch.")}</p>}
    </section>}
    {lines.length > 0 && <section className={styles.selected} aria-label={t(action === "bill" ? "Selected visits" : "Selected carpets")}><h3>{t(action === "bill" ? "Selected visits" : "Selected carpets")} ({lines.length}/50)</h3>{lines.map((line, index) => <div key={line.id} className={styles.selectedLine}><span>{line.label}</span>{action === "bill" && <div className={styles.field}><label htmlFor={`washing-allocation-${line.id}`}>{t("Amount for")} {line.label.split(" · ")[0]}</label><input id={`washing-allocation-${line.id}`} inputMode="decimal" value={line.amount} onChange={(event) => selectLines(lines.map((value) => value.id === line.id ? { ...value, amount: event.target.value } : value))} aria-invalid={!!errors[`allocations.${index}.amount`]} aria-describedby={errors[`allocations.${index}.amount`] ? `washing-allocation-${line.id}-error` : undefined} />{errors[`allocations.${index}.amount`] && <span id={`washing-allocation-${line.id}-error`} className={styles.fieldError}>{t(errors[`allocations.${index}.amount`])}</span>}</div>}<button type="button" onClick={() => selectLines(lines.filter((value) => value.id !== line.id))} aria-label={`${t("Unselect")} ${line.label}`}>{t("Remove")}</button></div>)}{action === "bill" && <p className={styles.notice}>{t("Allocated total")}: <strong>{total} {currency?.code}</strong> / {values.amount || "0.00"}</p>}</section>}
    {errors.items && <p className={styles.fieldError}>{t(errors.items)}</p>}{errors.allocations && <p className={styles.fieldError}>{t(errors.allocations)}</p>}
    </fieldset><footer><button type="button" disabled={busy} onClick={onClose}>{t("Cancel")}</button><button type="submit" className={styles.primary} disabled={busy}>{t(busy ? "Saving..." : washingActions[action])}</button></footer>
  </form></WashingDialog>;
}
