"use client";

import { useLayoutEffect, useSyncExternalStore } from "react";
import { FiMoon, FiSun } from "react-icons/fi";
import { readToken } from "@/lib/api";
import { foundationText } from "@/lib/foundation-i18n";
import { readTheme, serverTheme, subscribeTheme, writeTheme } from "@/lib/theme-preference";

/** Also handles sign-out and preferences changed in another browser tab. */
export function useDocumentTheme(token: string | null) {
  const theme = useSyncExternalStore(subscribeTheme, readTheme, serverTheme);
  useLayoutEffect(() => {
    // Read storage here too: the server snapshot is intentionally light, while
    // the inline initializer may already have applied dark before hydration.
    document.documentElement.dataset.theme = readToken() ? readTheme() : "light";
  }, [theme, token]);
}

export function ThemeToggle({ locale }: { locale: string }) {
  const theme = useSyncExternalStore(subscribeTheme, readTheme, serverTheme);
  const label = foundationText(locale, theme === "dark" ? "Switch to light mode" : "Switch to dark mode");
  return <button type="button" className="theme-toggle" aria-label={label}
    title={label} aria-pressed={theme === "dark"}
    onClick={() => writeTheme(theme === "dark" ? "light" : "dark")}>
    {theme === "dark" ? <FiSun aria-hidden="true" /> : <FiMoon aria-hidden="true" />}
  </button>;
}
