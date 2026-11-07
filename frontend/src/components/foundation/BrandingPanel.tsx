"use client";

import { useEffect, useId, useRef, useState } from "react";
import { FiCheckCircle, FiImage, FiMoon, FiRotateCcw, FiSun, FiUpload } from "react-icons/fi";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import { useBranding } from "@/components/branding-provider";
import { BRANDING_DEFAULTS, type BrandingAsset, type BrandingAssets, type BrandingSlot } from "@/lib/branding";
import { brandingText } from "@/lib/branding-i18n";
import styles from "./branding.module.css";

type ThemeVariant = "light" | "dark";
type ArtworkKind = "story" | "logo" | "footer_signature";
type SelectedImage = { file: File; url: string; width: number; height: number };
type Mutation = { slot: BrandingSlot; operation: "save" | "restore" };

const cards: { kind: ArtworkKind; title: string; description: string }[] = [
  { kind: "story", title: "Sidebar story image", description: "The artwork displayed below the sidebar navigation." },
  { kind: "logo", title: "Dashboard logo", description: "Your company logo at the top of the sidebar." },
  { kind: "footer_signature", title: "Footer signature artwork", description: "The decorative signature artwork at the bottom of the dashboard." },
];

function readImage(url: string): Promise<{ width: number; height: number }> {
  return new Promise((resolve, reject) => {
    const image = new window.Image();
    image.onload = () => resolve({ width: image.naturalWidth, height: image.naturalHeight });
    image.onerror = () => reject(new Error("Invalid image"));
    image.src = url;
  });
}

function ArtworkCard({
  kind, title, description, locale, assets, canEdit, unavailable, mutation, onMutation,
}: {
  kind: ArtworkKind;
  title: string;
  description: string;
  locale: string;
  assets: BrandingAssets;
  canEdit: boolean;
  unavailable: boolean;
  mutation: Mutation | null;
  onMutation: (slot: BrandingSlot, file?: File) => Promise<{ ok: boolean; error?: string }>;
}) {
  const id = useId();
  const t = (text: string) => brandingText(locale, text);
  const [variant, setVariant] = useState<ThemeVariant>("light");
  const [selected, setSelected] = useState<SelectedImage | null>(null);
  const [checking, setChecking] = useState(false);
  const [restoreReview, setRestoreReview] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [previewError, setPreviewError] = useState(false);
  const selectedUrl = useRef<string | null>(null);
  const sequence = useRef(0);
  const mounted = useRef(true);
  const checkingRef = useRef(false);
  const slot: BrandingSlot = `${kind}_${variant}`;
  const asset: BrandingAsset | null = assets[slot];
  const locked = unavailable || checking || mutation !== null;
  const saving = mutation?.slot === slot && mutation.operation === "save";
  const restoring = mutation?.slot === slot && mutation.operation === "restore";
  const previewUrl = selected?.url ?? asset?.url ?? BRANDING_DEFAULTS[slot];

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
      sequence.current += 1;
      if (selectedUrl.current) URL.revokeObjectURL(selectedUrl.current);
    };
  }, []);

  function discardSelection() {
    sequence.current += 1;
    if (selectedUrl.current) URL.revokeObjectURL(selectedUrl.current);
    selectedUrl.current = null;
    setSelected(null);
    setError("");
    setMessage("");
    setPreviewError(false);
  }

  function changeVariant(next: ThemeVariant) {
    if (locked || variant === next) return;
    discardSelection();
    setRestoreReview(false);
    setVariant(next);
  }

  async function chooseImage(file: File | undefined) {
    if (!file || locked || checkingRef.current) return;
    discardSelection();
    setRestoreReview(false);
    if (!["image/png", "image/jpeg", "image/webp"].includes(file.type)) {
      setError("Use a PNG, JPEG or WebP image.");
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      setError("The image must not exceed 5 MB.");
      return;
    }
    checkingRef.current = true;
    setChecking(true);
    const request = ++sequence.current;
    const url = URL.createObjectURL(file);
    try {
      const size = await readImage(url);
      if (!mounted.current || request !== sequence.current) {
        URL.revokeObjectURL(url);
        return;
      }
      if (!size.width || !size.height) throw new Error("Invalid image");
      if (size.width > 4096 || size.height > 4096) {
        URL.revokeObjectURL(url);
        setError("The image must not exceed 4096 × 4096 pixels.");
        return;
      }
      selectedUrl.current = url;
      setSelected({ file, url, ...size });
      setMessage("New image selected. Save to apply your changes.");
    } catch {
      URL.revokeObjectURL(url);
      if (mounted.current && request === sequence.current)
        setError("This file could not be read as an image. Choose another image.");
    } finally {
      checkingRef.current = false;
      if (mounted.current && request === sequence.current) setChecking(false);
    }
  }

  async function save() {
    if (!selected || locked || checkingRef.current) return;
    setError("");
    setMessage("");
    const result = await onMutation(slot, selected.file);
    if (!mounted.current) return;
    if (result.ok) {
      discardSelection();
      setMessage("Image saved.");
    } else setError(result.error ?? "Could not save the image. Please try again.");
  }

  async function restore() {
    if (locked || !restoreReview) return;
    setError("");
    setMessage("");
    const result = await onMutation(slot);
    if (!mounted.current) return;
    if (result.ok) {
      discardSelection();
      setRestoreReview(false);
      setMessage("Default image restored.");
    } else setError(result.error ?? "Could not restore the default image. Please try again.");
  }

  const dimensions = selected ?? asset;
  const number = (value: number) => new Intl.NumberFormat(locale === "fa" ? "fa-AF" : "en-US").format(value);

  return <article className={styles.card} aria-labelledby={`${id}-title`}>
    <div className={styles.cardHeading}>
      <h3 id={`${id}-title`}>{t(title)}</h3>
      <p>{t(description)}</p>
    </div>
    <div className={styles.tabs} role="tablist" aria-label={`${t(title)} — ${t("Theme variant")}`}>
      {(["light", "dark"] as const).map((theme) => <button key={theme} type="button"
        role="tab" id={`${id}-${theme}`} aria-controls={`${id}-preview`}
        aria-selected={variant === theme} tabIndex={variant === theme ? 0 : -1}
        disabled={locked} className={styles.tab}
        onClick={() => changeVariant(theme)}
        onKeyDown={(event) => {
          if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key) || locked) return;
          event.preventDefault();
          const next = event.key === "Home" ? "light" : event.key === "End" ? "dark" : variant === "light" ? "dark" : "light";
          changeVariant(next);
          document.getElementById(`${id}-${next}`)?.focus();
        }}>
        {theme === "light" ? <FiSun aria-hidden="true" /> : <FiMoon aria-hidden="true" />}
        {t(theme === "light" ? "Light mode" : "Dark mode")}
      </button>)}
    </div>
    <div role="tabpanel" id={`${id}-preview`} aria-labelledby={`${id}-${variant}`}>
      <div className={`${styles.preview} ${variant === "dark" ? styles.previewDark : ""} ${kind === "logo" ? styles.logoPreview : ""}`}>
        {previewError ? <p className={styles.previewFailure}>{t("Image preview unavailable.")}</p> :
          // Uploaded images and local object URLs must render without image optimization.
          // eslint-disable-next-line @next/next/no-img-element
          <img key={previewUrl} src={previewUrl} alt={`${t(title)} — ${t(variant === "light" ? "Light mode" : "Dark mode")}`}
            className={!selected && !asset && variant === "dark"
              ? kind === "logo" ? styles.defaultDarkLogo
                : kind === "footer_signature" ? styles.defaultDarkFooter : undefined
              : undefined}
            onError={() => setPreviewError(true)} />}
      </div>
      <div className={styles.caption}>
        <span>{t(selected ? "New image preview" : asset ? "Saved image" : "Default image")}</span>
        {dimensions && <span dir="ltr">{number(dimensions.width)} × {number(dimensions.height)}</span>}
      </div>
      {selected && <p className={styles.filename} title={selected.file.name}>{selected.file.name}</p>}
    </div>
    {kind === "logo" && <p className={styles.logoNote}>{t("The original dark logo is adapted automatically. Uploaded logos keep their original colors.")}</p>}
    {canEdit && <div className={styles.editor}>
      <div className={styles.controls}>
        <label className={`${styles.fileLabel} ${locked ? styles.disabled : ""}`}>
          <FiUpload aria-hidden="true" />{t("Choose image")}
          <input type="file" accept="image/png,image/jpeg,image/webp" disabled={locked}
            aria-describedby={`${id}-help${error ? ` ${id}-error` : ""}`} aria-invalid={Boolean(error)}
            onChange={(event) => {
              const file = event.target.files?.[0];
              event.target.value = "";
              void chooseImage(file);
            }} />
        </label>
        {selected && <>
          <button type="button" className={styles.primary} disabled={locked} onClick={() => void save()}>
            <FiCheckCircle aria-hidden="true" />{t(saving ? "Saving…" : "Save image")}
          </button>
          <button type="button" className={styles.secondary} disabled={locked} onClick={discardSelection}>{t("Cancel selection")}</button>
        </>}
      </div>
      <p id={`${id}-help`} className={styles.help}>{t("PNG, JPEG or WebP. Maximum 5 MB and 4096 × 4096 pixels.")}</p>
      {checking && <p className={styles.status} role="status">{t("Checking image…")}</p>}
      {error && <p id={`${id}-error`} className={styles.error} role="alert">{t(error)}</p>}
      {message && <p className={styles.status} role="status">{t(message)}</p>}
      {asset && !selected && !restoreReview && <button type="button" className={styles.restore}
        disabled={locked} onClick={() => { setRestoreReview(true); setError(""); setMessage(""); }}>
        <FiRotateCcw aria-hidden="true" />{t("Restore default")}
      </button>}
      {restoreReview && <div className={styles.review}>
        <strong>{t("Restore the default image?")}</strong>
        <p>{t("This replaces the saved image for this theme with the original artwork.")}</p>
        <div className={styles.controls}>
          <button type="button" className={styles.primary} disabled={locked} onClick={() => void restore()}>{t(restoring ? "Restoring…" : "Confirm restore")}</button>
          <button type="button" className={styles.secondary} disabled={locked} onClick={() => setRestoreReview(false)}>{t("Cancel")}</button>
        </div>
      </div>}
    </div>}
  </article>;
}

export function BrandingPanel({ profile, locale }: { profile: Profile; locale: string }) {
  const { assets, loading, error, reload, updateAssets } = useBranding();
  const [mutation, setMutation] = useState<Mutation | null>(null);
  const mutationLock = useRef(false);
  const mounted = useRef(true);
  const id = useId();
  const t = (text: string) => brandingText(locale, text);
  const canEdit = Boolean(profile.is_administrator && profile.permissions.includes("settings.update"));

  useEffect(() => {
    mounted.current = true;
    return () => { mounted.current = false; };
  }, []);

  async function mutate(slot: BrandingSlot, file?: File): Promise<{ ok: boolean; error?: string }> {
    if (mutationLock.current || !canEdit || loading || error) return { ok: false };
    mutationLock.current = true;
    setMutation({ slot, operation: file ? "save" : "restore" });
    try {
      const body = file ? new FormData() : undefined;
      if (body && file) body.set("image", file);
      const result = await apiFetch<{ data: BrandingAssets }>(`/foundation/branding/${slot}`, {
        method: file ? "POST" : "DELETE", body,
      });
      updateAssets(result.data);
      return { ok: true };
    } catch (failure) {
      return { ok: false, error: isApiFailure(failure)
        ? failure.fieldErrors.image ?? failure.message
        : file ? "Could not save the image. Please try again." : "Could not restore the default image. Please try again." };
    } finally {
      mutationLock.current = false;
      if (mounted.current) setMutation(null);
    }
  }

  return <section className={styles.panel} aria-labelledby={`${id}-title`}>
    <div className={styles.heading}>
      <span className={styles.icon} aria-hidden="true"><FiImage /></span>
      <div><h2 id={`${id}-title`}>{t("Dashboard branding")}</h2>
        <p>{t("Manage the images displayed throughout your dashboard, with separate versions for light and dark mode.")}</p></div>
    </div>
    {loading && <p className={styles.status} role="status">{t("Loading branding images…")}</p>}
    {error && <div className={styles.loadError} role="alert">
      <p>{t("Could not load branding images. Please try again.")}</p>
      <button type="button" className={styles.secondary} disabled={loading || mutation !== null} onClick={() => void reload().catch(() => undefined)}>{t("Retry")}</button>
    </div>}
    {!canEdit && <p className={styles.readonly}>{t("Only an administrator with permission to update settings can change these images.")}</p>}
    {canEdit && <p className={styles.instruction}>{t("Choose an image, review the preview, then save to apply it.")}</p>}
    <div className={styles.grid}>
      {cards.map((card) => <ArtworkCard key={card.kind} {...card} locale={locale} assets={assets}
        canEdit={canEdit} unavailable={loading || Boolean(error)} mutation={mutation} onMutation={mutate} />)}
    </div>
  </section>;
}
