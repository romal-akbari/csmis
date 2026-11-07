const BALANCE_EVENT = "csms:balances-updated";
const BALANCE_STORAGE_KEY = "csms_balance_update";

/** Announce committed account changes without broadcasting financial data or tokens. */
export function announceBalanceUpdate(path: string, method = "GET"): void {
  if (typeof window === "undefined" || ["GET", "HEAD"].includes(method.toUpperCase())) return;
  const affectsBalances = /^\/foundation\/(accounts|ledger)(\/|$|\?)/.test(path)
    || /^\/inventory\/(payments|corrections\/refunds)(\/|$|\?)/.test(path)
    || /^\/washing\/(bills|payments)(\/|$|\?)/.test(path);
  if (!affectsBalances && !path.startsWith("/business/") && !path.startsWith("/production/")) return;
  window.dispatchEvent(new Event(BALANCE_EVENT));
  try {
    window.localStorage.setItem(BALANCE_STORAGE_KEY, crypto.randomUUID());
  } catch {
    // This tab still updates; focus/visibility revalidation covers restricted storage.
  }
}

/** Keep open account views fresh after local/cross-tab writes and on return. */
export function subscribeBalanceUpdates(refresh: () => void): () => void {
  const storage = (event: StorageEvent) => {
    if (event.key === BALANCE_STORAGE_KEY || event.key === null) refresh();
  };
  const visible = () => { if (!document.hidden) refresh(); };
  window.addEventListener(BALANCE_EVENT, refresh);
  window.addEventListener("storage", storage);
  window.addEventListener("focus", visible);
  document.addEventListener("visibilitychange", visible);
  return () => {
    window.removeEventListener(BALANCE_EVENT, refresh);
    window.removeEventListener("storage", storage);
    window.removeEventListener("focus", visible);
    document.removeEventListener("visibilitychange", visible);
  };
}
