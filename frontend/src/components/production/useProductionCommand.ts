"use client";

import { useRef, useState } from "react";
import { apiFetch, isApiFailure } from "@/lib/api";

export function useProductionCommand(onSaved: (result: { data?: { id: number } }) => void) {
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [banner, setBanner] = useState("");
  const pending = useRef(false);
  const retry = useRef<{ payload: string; key: string } | null>(null);
  async function save(path: string, payload: Record<string, unknown>, method = "POST") {
    if (pending.current) return;
    const serialized = JSON.stringify(payload);
    if (retry.current?.payload !== serialized) retry.current = { payload: serialized, key: crypto.randomUUID() };
    pending.current = true; setBusy(true); setErrors({}); setBanner("");
    try {
      const result = await apiFetch<{ data?: { id: number } }>(path, { method, body: serialized, headers: { "Idempotency-Key": retry.current.key } });
      onSaved(result);
    } catch (error) {
      if (isApiFailure(error)) { setErrors(error.fieldErrors); setBanner(error.message); }
      else setBanner("Could not reach the server. Your form has been kept.");
    } finally { pending.current = false; setBusy(false); }
  }
  return { busy, errors, banner, setErrors, setBanner, save };
}
