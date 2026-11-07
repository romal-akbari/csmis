export type Theme = "light" | "dark";

const storageKey = "csms_theme";
const changeEvent = "csms:theme-changed";
let memoryTheme: Theme = "light";
let storageUnavailable = false;

export function readTheme(): Theme {
  if (typeof window === "undefined") return "light";
  if (storageUnavailable) return memoryTheme;
  try {
    memoryTheme = window.localStorage.getItem(storageKey) === "dark" ? "dark" : "light";
  } catch {
    storageUnavailable = true;
    // Keep the toggle usable if browser storage is unavailable.
  }
  return memoryTheme;
}

export const serverTheme = (): Theme => "light";

export function subscribeTheme(onChange: () => void) {
  const onStorage = (event: StorageEvent) => {
    if (event.key === storageKey || event.key === null) {
      storageUnavailable = false;
      onChange();
    }
  };
  window.addEventListener("storage", onStorage);
  window.addEventListener(changeEvent, onChange);
  return () => {
    window.removeEventListener("storage", onStorage);
    window.removeEventListener(changeEvent, onChange);
  };
}

export function writeTheme(theme: Theme) {
  memoryTheme = theme;
  try {
    window.localStorage.setItem(storageKey, theme);
    storageUnavailable = false;
  } catch {
    storageUnavailable = true;
    // The in-memory preference still applies for this session.
  }
  document.documentElement.dataset.theme = theme;
  window.dispatchEvent(new Event(changeEvent));
}

// Static, parser-blocking initialization applies the saved dashboard preference
// before paint. Login keeps its original light design and branding.
export const themeBootstrap = `try{document.documentElement.dataset.theme=localStorage.getItem("csms_token")&&localStorage.getItem("csms_theme")==="dark"?"dark":"light"}catch{document.documentElement.dataset.theme="light"}`;
