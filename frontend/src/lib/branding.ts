export const BRANDING_SLOTS = [
  "story_light", "story_dark", "logo_light", "logo_dark",
  "footer_signature_light", "footer_signature_dark",
] as const;

export type BrandingSlot = typeof BRANDING_SLOTS[number];
export type BrandingAsset = {
  url: string;
  version: string;
  width: number;
  height: number;
  mime_type: string;
  bytes: number;
};
export type BrandingAssets = Record<BrandingSlot, BrandingAsset | null>;

export const BRANDING_DEFAULTS: Record<BrandingSlot, string> = {
  story_light: "/images/sidebar-story-full.png",
  story_dark: "/images/sidebar-story-full-dark-theme.png",
  logo_light: "/images/brand-lockup.png",
  logo_dark: "/images/brand-lockup.png",
  footer_signature_light: "/images/footer-landscape.png",
  footer_signature_dark: "/images/footer-landscape.png",
};

export function emptyBranding(): BrandingAssets {
  return Object.fromEntries(BRANDING_SLOTS.map((slot) => [slot, null])) as BrandingAssets;
}
