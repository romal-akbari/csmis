"use client";

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { apiFetch, isApiFailure } from "@/lib/api";
import { emptyBranding, type BrandingAssets } from "@/lib/branding";

type BrandingContextValue = {
  assets: BrandingAssets;
  loading: boolean;
  error: string;
  reload: () => Promise<void>;
  updateAssets: (assets: BrandingAssets) => void;
};

const BrandingContext = createContext<BrandingContextValue | null>(null);

/** One company-wide image snapshot keeps settings previews and shell in sync. */
export function BrandingProvider({ children }: { children: ReactNode }) {
  const [assets, setAssets] = useState<BrandingAssets>(emptyBranding);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    const controller = new AbortController();
    apiFetch<{ data: BrandingAssets }>("/branding", { signal: controller.signal })
      .then(({ data }) => {
        if (!controller.signal.aborted) setAssets(data);
      })
      .catch((failure) => {
        if (!controller.signal.aborted) setError(isApiFailure(failure)
          ? failure.message : "Could not load branding images.");
      })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, []);

  const reload = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const { data } = await apiFetch<{ data: BrandingAssets }>("/branding");
      setAssets(data);
    } catch (failure) {
      setError(isApiFailure(failure) ? failure.message : "Could not load branding images.");
    } finally {
      setLoading(false);
    }
  }, []);

  return <BrandingContext.Provider value={{ assets, loading, error, reload, updateAssets: setAssets }}>
    {children}
  </BrandingContext.Provider>;
}

export function useBranding() {
  const value = useContext(BrandingContext);
  if (!value) throw new Error("Branding must be used within the dashboard provider.");
  return value;
}
