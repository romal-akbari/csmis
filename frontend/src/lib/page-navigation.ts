import type { Profile } from "./api";
import { BUSINESS_PAGES } from "./business";
import { CORRECTION_PAGES } from "./inventory-corrections";
import { INVENTORY_PAGES } from "./inventory";
import { getFoundationPage } from "./navigation";
import { canOpen } from "./permissions";
import { WASHING_PAGES } from "./washing";
import { MANAGER_PRODUCTION_PAGES } from "./manager-production";
import { canonicalPage, historicalPage } from "./company-workflow";

const PAGE_EVENT = "csms:page-changed";

export function readPage(): string | null {
  if (typeof window === "undefined") return null;
  return new URLSearchParams(window.location.search).get("page");
}

export function serverPage(): null {
  return null;
}

export function subscribePage(onChange: () => void): () => void {
  window.addEventListener(PAGE_EVENT, onChange);
  window.addEventListener("popstate", onChange);
  return () => {
    window.removeEventListener(PAGE_EVENT, onChange);
    window.removeEventListener("popstate", onChange);
  };
}

/** Update the current module without a reload; keep unrelated query/hash data. */
export function writePage(page: string | null, replace = false): void {
  const url = new URL(window.location.href);
  if (page) url.searchParams.set("page", page);
  else url.searchParams.delete("page");
  const next = `${url.pathname}${url.search}${url.hash}`;
  if (next === `${window.location.pathname}${window.location.search}${window.location.hash}`) return;
  window.history[replace ? "replaceState" : "pushState"](null, "", next);
  window.dispatchEvent(new Event(PAGE_EVENT));
}

/** URL input must name an implemented page and pass the fresh profile's access. */
export function permittedPage(profile: Profile | null, requested: string | null): string | null {
  if (!profile || !requested || requested === "Dashboard") return null;
  requested = canonicalPage(requested);
  if (historicalPage(requested) || ["Historical records", "Supplier balances", "Stock overview"].includes(requested)) return canOpen(profile, requested) ? requested : null;
  const foundation = getFoundationPage(requested);
  if (foundation) return canOpen(profile, foundation.label) ? foundation.label : null;
  const implemented = [MANAGER_PRODUCTION_PAGES, BUSINESS_PAGES, WASHING_PAGES, CORRECTION_PAGES, INVENTORY_PAGES]
    .some((pages) => Object.prototype.hasOwnProperty.call(pages, requested))
    || ["Customers", "Weavers & Artisans", "Weaver records", "Suppliers", "Supplier records", "Directories"].includes(requested);
  return implemented && canOpen(profile, requested) ? requested : null;
}
