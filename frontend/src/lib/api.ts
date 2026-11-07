import { announceBalanceUpdate } from "./balance-updates";

/** Normalized API failure: HTTP status + message + per-field errors. */
export type ApiFailure = {
  status: number;
  message: string;
  fieldErrors: Record<string, string>;
};

export type LoginResult = {
  token: string;
  user: {
    id: number;
    name: string;
    username: string;
    locale: string;
  };
};

export type Profile = {
  id: number;
  name: string;
  username: string;
  locale: string;
  roles: string[];
  permissions: string[];
  is_administrator?: boolean;
  branch_ids?: number[];
  facility_ids?: number[];
};

const TOKEN_KEY = "csms_token";
const TOKEN_EVENT = "csms:token-changed";

/** Read the Bearer token stored for this browser session. */
export function readToken(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(TOKEN_KEY) || null;
}

/** Persist (or clear, with null) the Bearer token and notify listeners. */
export function writeToken(token: string | null): void {
  if (typeof window === "undefined") return;
  if (token === null) window.localStorage.removeItem(TOKEN_KEY);
  else window.localStorage.setItem(TOKEN_KEY, token);
  window.dispatchEvent(new Event(TOKEN_EVENT));
}

/** Subscribe to token changes (this tab and cross-tab storage events). */
export function subscribeToken(onChange: () => void): () => void {
  if (typeof window === "undefined") return () => undefined;
  window.addEventListener(TOKEN_EVENT, onChange);
  window.addEventListener("storage", onChange);
  return () => {
    window.removeEventListener(TOKEN_EVENT, onChange);
    window.removeEventListener("storage", onChange);
  };
}

/** Unknown until hydration reads browser storage; null means truly signed out. */
export function serverToken(): undefined {
  return undefined;
}

/** Type guard so callers can read `fieldErrors` safely. */
export function isApiFailure(error: unknown): error is ApiFailure {
  if (typeof error !== "object" || error === null) return false;
  return "status" in error && "fieldErrors" in error;
}

/** Convert a Laravel error body into an ApiFailure. */
function toFailure(status: number, body: unknown): ApiFailure {
  const payload = (body ?? {}) as {
    message?: string;
    errors?: Record<string, string[]>;
  };
  const fieldErrors: Record<string, string> = {};
  Object.entries(payload.errors ?? {}).forEach(([field, messages]) => {
    fieldErrors[field] = messages[0] ?? "Invalid value.";
  });
  return {
    status,
    message: payload.message ?? "The request could not be completed.",
    fieldErrors,
  };
}

function parseBody(text: string): unknown {
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/**
 * Fetch JSON from /api/<path> with the stored Bearer token.
 * Throws an ApiFailure for any non-2xx response.
 */
export async function apiFetch<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");
  if (init.body !== undefined && !(init.body instanceof FormData))
    headers.set("Content-Type", "application/json");

  const token = readToken();
  if (token) headers.set("Authorization", `Bearer ${token}`);

  const response = await fetch(`/api${path}`, { cache: "no-store", ...init, headers });
  const body = parseBody(await response.text());

  if (!response.ok) {
    if (response.status === 401 && token && readToken() === token) writeToken(null);
    throw toFailure(response.status, body);
  }
  announceBalanceUpdate(path, init.method);
  return body as T;
}

/** POST /api/login — exchange credentials for a token. */
export async function login(
  username: string,
  password: string,
): Promise<LoginResult> {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 15_000);
  try {
    return await apiFetch<LoginResult>("/login", {
      method: "POST",
      body: JSON.stringify({ username, password }),
      signal: controller.signal,
    });
  } catch (error) {
    if (controller.signal.aborted) {
      throw {
        status: 0,
        message: "Signing in took too long. Please try again.",
        fieldErrors: {},
      } satisfies ApiFailure;
    }
    if (isApiFailure(error) && error.status >= 500) {
      throw {
        ...error,
        message: "Sign-in is temporarily unavailable. Please try again shortly.",
      } satisfies ApiFailure;
    }
    throw error;
  } finally {
    clearTimeout(timeout);
  }
}

/** GET /api/me — validate the stored token and load the profile. */
export async function fetchMe(): Promise<Profile> {
  const body = await apiFetch<{ data: Profile }>("/me");
  return body.data;
}

/** Business images remain behind the same authenticated API and branch scope. */
export async function apiBlob(
  path: string,
  signal?: AbortSignal,
): Promise<Blob> {
  const headers = new Headers();
  const token = readToken();
  if (token) headers.set("Authorization", `Bearer ${token}`);
  const response = await fetch(`/api${path}`, { headers, signal });
  if (!response.ok) {
    if (response.status === 401 && token && readToken() === token) writeToken(null);
    throw toFailure(response.status, parseBody(await response.text()));
  }
  return response.blob();
}

/** POST /api/logout — revoke the current token server-side. */
export async function logout(): Promise<void> {
  await apiFetch("/logout", { method: "POST" });
}
