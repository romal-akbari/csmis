const storageKey = "csms_sidebar_collapsed";
const changeEvent = "csms:sidebar-preference";
let memoryCollapsed = false;
let storageUnavailable = false;

export function readSidebarCollapsed() {
  if (storageUnavailable) return memoryCollapsed;
  try {
    memoryCollapsed = window.localStorage.getItem(storageKey) === "true";
  } catch {
    storageUnavailable = true;
  }
  return memoryCollapsed;
}

export function serverSidebarCollapsed() {
  return false;
}

export function subscribeSidebarCollapsed(onChange: () => void) {
  const onStorage = (event: StorageEvent) => {
    if (event.key === storageKey || event.key === null) onChange();
  };
  window.addEventListener("storage", onStorage);
  window.addEventListener(changeEvent, onChange);
  return () => {
    window.removeEventListener("storage", onStorage);
    window.removeEventListener(changeEvent, onChange);
  };
}

export function writeSidebarCollapsed(collapsed: boolean) {
  memoryCollapsed = collapsed;
  if (!storageUnavailable) {
    try {
      window.localStorage.setItem(storageKey, String(collapsed));
    } catch {
      storageUnavailable = true;
    }
  }
  window.dispatchEvent(new Event(changeEvent));
}
