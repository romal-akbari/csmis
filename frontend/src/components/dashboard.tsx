"use client";

import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import Link from "next/link";
import { FiChevronsLeft, FiChevronsRight, FiHome } from "react-icons/fi";
import { ReferenceIcon } from "@/components/reference-icon";
import type { IconType } from "react-icons";
import {
  FaBars,
  FaBox,
  FaChartBar,
  FaChevronDown,
  FaCog,
  FaGlobe,
  FaHome,
  FaIndustry,
  FaWater,
  FaShoppingCart,
  FaShoppingBag,
  FaReceipt,
  FaSignOutAlt,
  FaSlidersH,
  FaTimes,
  FaUserFriends,
} from "react-icons/fa";
import {
  setLocale,
  saveLocale,
  setSidebarOpen,
  useAppDispatch,
  useAppSelector,
} from "@/store/dashboard-store";
import { CustomersScreen } from "@/components/customers/CustomersScreen";
import { LoginPanel } from "@/components/customers/LoginPanel";
import { FoundationScreen } from "@/components/foundation/FoundationScreen";
import { SidebarFlyout } from "@/components/sidebar-flyout";
import { DirectoryScreen } from "@/components/directories/DirectoryScreen";
import { InventoryScreen } from "@/components/inventory/InventoryScreen";
import { CorrectionScreen } from "@/components/inventory/CorrectionScreen";
import { WashingScreen } from "@/components/washing/WashingScreen";
import { WASHING_PAGES } from "@/lib/washing";
import { DashboardOverview } from "@/components/overview/DashboardOverview";
import { ThemeToggle, useDocumentTheme } from "@/components/theme-toggle";
import { BrandingProvider, useBranding } from "@/components/branding-provider";
import { BRANDING_DEFAULTS } from "@/lib/branding";
import { CORRECTION_PAGES } from "@/lib/inventory-corrections";
import { INVENTORY_PAGES } from "@/lib/inventory";
import {
  fetchMe,
  isApiFailure,
  logout,
  readToken,
  serverToken,
  subscribeToken,
  writeToken,
  type Profile,
} from "@/lib/api";
import { can, canOpen } from "@/lib/permissions";
import { BusinessScreen } from "@/components/business/BusinessScreen";
import { ProductionScreen } from "@/components/production/ProductionScreen";
import { SupplyPurchasesScreen } from "@/components/production/SupplyPurchasesScreen";
import { SupplyStockScreen } from "@/components/production/SupplyStockScreen";
import { StockOverviewScreen } from "@/components/inventory/StockOverviewScreen";
import { SupplierBalancesScreen } from "@/components/production/SupplierBalancesScreen";
import { WorkflowHistoryScreen } from "@/components/workflow/WorkflowHistoryScreen";
import { historicalPage } from "@/lib/company-workflow";
import { MANAGER_PRODUCTION_PAGES } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { customerText } from "@/lib/customer-i18n";
import { BUSINESS_PAGES } from "@/lib/business";
import { businessText } from "@/lib/business-i18n";
import { getFoundationPage, SIDEBAR_SUBMENUS } from "@/lib/navigation";
import { foundationText } from "@/lib/foundation-i18n";
import { permittedPage, readPage, serverPage, subscribePage, writePage } from "@/lib/page-navigation";
import {
  readSidebarCollapsed,
  serverSidebarCollapsed,
  subscribeSidebarCollapsed,
  writeSidebarCollapsed,
} from "@/lib/sidebar-preference";

const navigation: { label: string; dari: string; icon: IconType }[] = [
  { label: "Dashboard", dari: "داشبورد", icon: FaHome },
  { label: "Purchases", dari: "خریدها", icon: FaShoppingBag },
  { label: "Inventory", dari: "موجودی", icon: FaBox },
  { label: "Production", dari: "تولید", icon: FaIndustry },
  { label: "Washing", dari: "شستشو", icon: FaWater },
  { label: "Sales", dari: "فروش", icon: FaShoppingCart },
  { label: "Expenses", dari: "مصارف", icon: FaReceipt },
  { label: "Finance", dari: "امور مالی", icon: FaChartBar },
  { label: "Users", dari: "کاربران", icon: FaUserFriends },
  { label: "Settings", dari: "تنظیمات", icon: FaSlidersH },
];

type Detail = { kind: "section"; name: string };

/** Decorative image regions retain the artwork from the user's reference. */
function Artwork({
  crop,
  className = "",
  label,
  fallback,
  customized = false,
  theme,
}: {
  /** Path to a per-section image under /images (already cropped to the region). */
  crop: string;
  className?: string;
  label?: string;
  fallback?: string;
  customized?: boolean;
  theme?: "light" | "dark";
}) {
  const [failedCrop, setFailedCrop] = useState<string | null>(null);
  const source = failedCrop === crop && fallback ? fallback : crop;
  return (
    /* eslint-disable-next-line @next/next/no-img-element */
    <img
      src={source}
      className={`artwork ${className}`}
      data-artwork-theme={theme}
      data-custom-branding={customized && source === crop ? "true" : undefined}
      onError={fallback && source !== fallback ? () => setFailedCrop(crop) : undefined}
      alt={label ?? ""}
      role={label ? "img" : undefined}
      aria-hidden={label ? undefined : true}
    />
  );
}

function Sidebar({
  onOpen,
  activeLabel,
  profile,
  collapsed,
}: {
  onOpen: (detail: Detail) => void;
  activeLabel: string;
  profile: Profile;
  collapsed: boolean;
}) {
  const dispatch = useAppDispatch();
  const { locale, sidebarOpen } = useAppSelector((state) => state.dashboard);
  const { assets } = useBranding();
  const t = (text: string) => customerText(locale, productionText(locale, businessText(locale, foundationText(locale, text))));
  useEffect(() => {
    if (!sidebarOpen) return;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        if (document.querySelector(".sidebar-flyout:popover-open")) return;
        dispatch(setSidebarOpen(false));
        document.querySelector<HTMLButtonElement>(".mobile-menu")?.focus();
      }
    };
    document.addEventListener("keydown", closeOnEscape);
    document.querySelector<HTMLButtonElement>(".mobile-close")?.focus();
    return () => document.removeEventListener("keydown", closeOnEscape);
  }, [sidebarOpen, dispatch]);

  return (
    <>
      {sidebarOpen && (
        <button
          className="sidebar-backdrop"
          aria-label="Close navigation"
          onClick={() => dispatch(setSidebarOpen(false))}
        />
      )}
      <aside
        id="dashboard-sidebar"
        className={`sidebar ${sidebarOpen ? "is-open" : ""}`}
        aria-label="Main navigation"
      >
        <div className="sidebar-inner">
          <button
            className="mobile-close icon-button"
            aria-label="Close navigation"
            onClick={() => dispatch(setSidebarOpen(false))}
          >
            <FaTimes />
          </button>
          <Link
            className="brand"
            href="/"
            aria-label="Sakhi Zada Uzbek Ltd home"
            onClick={(event) => {
              if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
              event.preventDefault();
              onOpen({ kind: "section", name: "Dashboard" });
            }}
          >
            <Artwork
              crop={assets.logo_light?.url ?? BRANDING_DEFAULTS.logo_light}
              fallback={BRANDING_DEFAULTS.logo_light}
              customized={!!assets.logo_light}
              theme="light"
              className="brand-lockup"
              label="Sakhi Zada Uzbek Ltd — سخی زاده اوزبیک لمیتد"
            />
            <Artwork
              crop={assets.logo_dark?.url ?? BRANDING_DEFAULTS.logo_dark}
              fallback={BRANDING_DEFAULTS.logo_dark}
              customized={!!assets.logo_dark}
              theme="dark"
              className="brand-lockup"
              label="Sakhi Zada Uzbek Ltd"
            />
            <Artwork crop={assets.logo_light?.url ?? "/icon.svg"} fallback="/icon.svg"
              customized={!!assets.logo_light} theme="light" className="sidebar-compact-brand" />
            <Artwork crop={assets.logo_dark?.url ?? "/icon.svg"} fallback="/icon.svg"
              customized={!!assets.logo_dark} theme="dark" className="sidebar-compact-brand" />
          </Link>
          <nav className="navigation">
            {navigation
              .filter(
                ({ label }) =>
                  (SIDEBAR_SUBMENUS[label]
                    ? SIDEBAR_SUBMENUS[label].some((item) =>
                        canOpen(profile, item),
                      )
                    : canOpen(profile, label)) &&
                  (label !== "Directories" ||
                    (!can(profile, "weavers.view") &&
                      !can(profile, "suppliers.view"))),
              )
              .map(({ label, dari, icon: NavIcon }) => {
                const children = SIDEBAR_SUBMENUS[label]?.filter((item) =>
                  canOpen(profile, item),
                );
                const iconName =
                  label === "Operations"
                    ? "Warehouses"
                    : label === "Finance"
                      ? "Analytics"
                      : label;
                if (children?.length) {
                  return (
                    <SidebarFlyout
                      key={label}
                      label={locale === "fa" ? dari : label}
                      items={children.map((item) => ({
                        label: t(item),
                        target: item,
                      }))}
                      activeLabel={activeLabel}
                      collapsed={collapsed}
                      locale={locale}
                      icon={["Purchases", "Production", "Washing", "Sales", "Expenses"].includes(label) ? <NavIcon aria-hidden="true" /> : <ReferenceIcon name={iconName === "Suppliers" ? "Customers" : iconName} variant="sidebar" />}
                      onSelect={(name) => {
                        dispatch(setSidebarOpen(false));
                        onOpen({ kind: "section", name });
                      }}
                    />
                  );
                }
                return (
                  <button
                    key={label}
                    className={`nav-item ${label === activeLabel ? "active" : ""}`}
                    aria-label={locale === "fa" ? dari : label}
                    title={
                      collapsed ? (locale === "fa" ? dari : label) : undefined
                    }
                    aria-current={label === activeLabel ? "page" : undefined}
                    onClick={() => {
                      dispatch(setSidebarOpen(false));
                      if (label === "Dashboard") {
                        window.scrollTo({ top: 0, behavior: "smooth" });
                      }
                      onOpen({ kind: "section", name: label });
                    }}
                  >
                    {label === "Dashboard" ? (
                      <FiHome
                        className="sidebar-dashboard-icon"
                        aria-hidden="true"
                      />
                    ) : (
                      <ReferenceIcon
                        name={
                          label === "Suppliers"
                            ? "Customers"
                            : label === "Directories"
                              ? "Warehouses"
                              : label
                        }
                        variant="sidebar"
                      />
                    )}
                    <span
                      className="sidebar-nav-label"
                      lang={locale === "fa" ? "fa" : "en"}
                    >
                      {locale === "fa" ? dari : label}
                    </span>
                  </button>
                );
              })}
          </nav>
          <div className="sidebar-bottom-ornament" aria-hidden="true" />
          <div className="sidebar-story">
            <Artwork
              crop={assets.story_light?.url ?? BRANDING_DEFAULTS.story_light}
              fallback={BRANDING_DEFAULTS.story_light}
              customized={!!assets.story_light}
              theme="light"
              className="sidebar-landscape sidebar-landscape-light"
            />
            <Artwork
              crop={assets.story_dark?.url ?? BRANDING_DEFAULTS.story_dark}
              fallback={BRANDING_DEFAULTS.story_dark}
              customized={!!assets.story_dark}
              theme="dark"
              className="sidebar-landscape sidebar-landscape-dark"
            />
            <div className="sr-only">
              <p>
                Afghan carpets
                <br />
                connect people,
                <br />
                cultures and homes
                <br />
                across the world.
              </p>
              <span>
                TRADITION TODAY
                <br />
                <small>A BRIGHTER TOMORROW</small>
              </span>
            </div>
          </div>
        </div>
      </aside>
    </>
  );
}

function Header({
  onOpen,
  profile,
  onSignOut,
  sidebarCollapsed,
}: {
  onOpen: (detail: Detail) => void;
  profile: Profile;
  onSignOut: () => void;
  sidebarCollapsed: boolean;
}) {
  const dispatch = useAppDispatch();
  const { locale, localeSaving, localeError } = useAppSelector(
    (state) => state.dashboard,
  );
  const t = (text: string) => foundationText(locale, text);
  const toggleLabel =
    locale === "fa"
      ? sidebarCollapsed
        ? "باز کردن نوار کناری"
        : "جمع کردن نوار کناری"
      : sidebarCollapsed
        ? "Expand sidebar"
        : "Collapse sidebar";
  const ToggleIcon =
    sidebarCollapsed !== (locale === "fa") ? FiChevronsRight : FiChevronsLeft;
  const [popover, setPopover] = useState<"profile" | null>(null);
  const headerRef = useRef<HTMLElement>(null);
  useEffect(() => {
    const close = (event: PointerEvent) => {
      if (
        headerRef.current &&
        !headerRef.current.contains(event.target as Node)
      ) {
        setPopover(null);
      }
    };
    const escape = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setPopover(null);
      }
    };
    document.addEventListener("pointerdown", close);
    document.addEventListener("keydown", escape);
    return () => {
      document.removeEventListener("pointerdown", close);
      document.removeEventListener("keydown", escape);
    };
  }, []);
  return (
    <header className="topbar" ref={headerRef}>
      <button
        type="button"
        className="sidebar-toggle"
        aria-label={toggleLabel}
        title={toggleLabel}
        aria-expanded={!sidebarCollapsed}
        aria-controls="dashboard-sidebar"
        onClick={() => writeSidebarCollapsed(!sidebarCollapsed)}
      >
        <ToggleIcon aria-hidden="true" />
      </button>
      <button
        className="mobile-menu icon-button"
        aria-controls="dashboard-sidebar"
        aria-label="Open navigation"
        onClick={() => dispatch(setSidebarOpen(true))}
      >
        <FaBars />
      </button>
      <div className="topbar-spacer" aria-hidden="true" />
      <div className="header-actions">
        <ThemeToggle locale={locale} />
        <div className="language-dropdown" aria-busy={localeSaving}>
          <FaGlobe className="language-globe" aria-hidden="true" />
          <select
            className="language-select"
            aria-label="Display language"
            aria-describedby={localeError ? "language-save-error" : undefined}
            lang={locale}
            value={locale}
            disabled={localeSaving}
            onFocus={() => {
              setPopover(null);
            }}
            onChange={(event) => {
              void dispatch(saveLocale(event.target.value as "en" | "fa"));
            }}
          >
            <option value="en" lang="en">
              English
            </option>
            <option value="fa" lang="fa">
              دری
            </option>
          </select>
          <FaChevronDown className="language-chevron" aria-hidden="true" />
        </div>
        <span className="header-divider profile-divider" />
        <div className="popover-anchor">
          <button
            className="profile-button"
            aria-expanded={popover === "profile"}
            onClick={() => setPopover(popover === "profile" ? null : "profile")}
          >
            <Artwork crop="/images/avatar.png" className="avatar" />
            <span className="profile-info">
              <strong>{profile.name}</strong>
              <small>{t(profile.roles[0] ?? "Staff")}</small>
            </span>
            <FaChevronDown />
          </button>
          {popover === "profile" && (
            <div className="profile-popover popover">
              <strong>{profile.name}</strong>
              <span>
                {t(profile.roles[0] ?? "Staff") + " · Sakhi Zada Uzbek Ltd"}
              </span>
              {canOpen(profile, "Settings") && (
                <button
                  onClick={() => {
                    onOpen({ kind: "section", name: "Company settings" });
                    setPopover(null);
                  }}
                >
                  <FaCog /> {t("Account & preferences")}
                </button>
              )}
              <button
                onClick={() => {
                  setPopover(null);
                  onSignOut();
                }}
              >
                <FaSignOutAlt /> {t("Sign out")}
              </button>
              <p>
                {t("Signed in as")} <bdi>{profile.username}</bdi>
              </p>
            </div>
          )}
        </div>
      </div>
      {localeError && (
        <p id="language-save-error" className="language-error" role="alert">
          {localeError}
        </p>
      )}
    </header>
  );
}

function Footer() {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const { assets } = useBranding();
  const t = (text: string) => foundationText(locale, text);
  return (
    <footer className="footer">
      <div className="footer-company">
        <Artwork
          crop="/images/footer-tagline.png"
          className="footer-wordmark"
          label="Sakhi Zada Uzbek Ltd — سخی زاده اوزبیک لمیتد"
        />
      </div>
      <div className="footer-flourish">
        <Artwork crop="/images/footer-pattern.png" />
      </div>
      <div className="footer-trust">
        <FaGlobe />
        <span>{t("Quality carpets from Afghanistan")}</span>
        <i>|</i>
        <span>{t("Trusted in homes worldwide")}</span>
      </div>
      <div className="footer-landscape">
        <Artwork
          crop={assets.footer_signature_light?.url ?? BRANDING_DEFAULTS.footer_signature_light}
          fallback={BRANDING_DEFAULTS.footer_signature_light}
          customized={!!assets.footer_signature_light}
          theme="light"
          className="footer-signature"
          label="More than carpets, we weave understanding."
        />
        <Artwork
          crop={assets.footer_signature_dark?.url ?? BRANDING_DEFAULTS.footer_signature_dark}
          fallback={BRANDING_DEFAULTS.footer_signature_dark}
          customized={!!assets.footer_signature_dark}
          theme="dark"
          className="footer-signature"
          label="More than carpets, we weave understanding."
        />
      </div>
    </footer>
  );
}

export function Dashboard() {
  const dispatch = useAppDispatch();
  const locale = useAppSelector((state) => state.dashboard.locale);
  const sidebarCollapsed = useSyncExternalStore(
    subscribeSidebarCollapsed,
    readSidebarCollapsed,
    serverSidebarCollapsed,
  );
  const [profile, setProfile] = useState<Profile | null>(null);
  const [verifiedToken, setVerifiedToken] = useState<string | null>(null);
  const [sessionError, setSessionError] = useState<{ token: string; message: string } | null>(null);
  const [probe, setProbe] = useState(0);
  const token = useSyncExternalStore<string | null | undefined>(subscribeToken, readToken, serverToken);
  const requestedModule = useSyncExternalStore(subscribePage, readPage, serverPage);
  const activeModule = permittedPage(profile, requestedModule);
  useDocumentTheme(token ?? null);
  const documentLocale =
    token && profile && verifiedToken === token ? locale : "en";

  useEffect(() => {
    document.documentElement.lang = documentLocale;
    document.documentElement.dir = documentLocale === "fa" ? "rtl" : "ltr";
    return () => {
      document.documentElement.lang = "en";
      document.documentElement.dir = "ltr";
    };
  }, [documentLocale]);

  // §17 — a stored token must be verified against /api/me before
  // the dashboard renders. State only changes in the async
  // callbacks below, never synchronously inside the effect.
  useEffect(() => {
    if (!token) return;
    let cancelled = false;
    fetchMe()
      .then((data) => {
        if (!cancelled && readToken() === token) {
          setProfile(data);
          setVerifiedToken(token);
          setSessionError(null);
          dispatch(setLocale(data.locale === "fa" ? "fa" : "en"));
        }
      })
      .catch((error: unknown) => {
        if (cancelled || readToken() !== token) return;
        if (isApiFailure(error) && error.status === 401) {
          writeToken(null); // expired or revoked — gate flips
          return;
        }
        setSessionError({
          token,
          message: isApiFailure(error)
            ? error.message
            : "Could not reach the server to verify your session.",
        });
      });
    return () => {
      cancelled = true;
    };
  }, [token, probe, dispatch]);

  useEffect(() => {
    if (!token || !profile || verifiedToken !== token) return;
    if (requestedModule !== activeModule) writePage(activeModule, true);
  }, [token, profile, verifiedToken, requestedModule, activeModule]);

  async function signOut() {
    // Start revocation with the current token, then clear local access immediately.
    const revocation = logout();
    writeToken(null);
    setProfile(null);
    setVerifiedToken(null);
    setSessionError(null);
    writePage(null, true);
    try {
      await revocation;
    } catch {
      // Token may already be invalid; clearing locally is enough.
    }
  }

  /** Only implemented, permitted pages can open from navigation. */
  const openDetail = (next: Detail) => {
    if (next?.kind !== "section") return;
    if (next.name === "Dashboard") writePage(null);
    else {
      const destination = permittedPage(profile, next.name);
      if (destination) writePage(destination);
    }
  };

  const foundationPage = activeModule
    ? getFoundationPage(activeModule)
    : undefined;

  // Hydration has not read storage yet; do not briefly mount the sign-in form.
  if (token === undefined) {
    return (
      <div className="auth-gate" role="status">
        <p className="customers-muted">Checking your session...</p>
      </div>
    );
  }

  // Gate: confirmed no token → sign-in; token → verify; failure → retry.
  if (token === null) {
    return (
      <div className="auth-gate">
        <LoginPanel
          onLogin={(result) => {
            setProfile(null);
            setVerifiedToken(null);
            setSessionError(null);
            writeToken(result.token);
          }}
        />
      </div>
    );
  }

  if (!profile || verifiedToken !== token) {
    if (sessionError?.token === token) {
      return (
        <div className="auth-gate" role="alert">
          <p className="customers-banner error">{sessionError.message}</p>
          <button
            className="secondary-button"
            type="button"
            onClick={() => {
              setSessionError(null);
              setProbe((count) => count + 1);
            }}
          >
            Try again
          </button>
          <button
            className="secondary-button"
            type="button"
            onClick={() => signOut()}
          >
            Sign out
          </button>
        </div>
      );
    }
    return (
      <div className="auth-gate" role="status">
        <p className="customers-muted">Checking your session...</p>
      </div>
    );
  }

  return (
    <BrandingProvider key={token}>
    <div
      className="dashboard-shell"
      data-sidebar-collapsed={sidebarCollapsed}
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
    >
      <a className="skip-link" href="#dashboard-content">
        Skip to dashboard
      </a>
      <Sidebar
        onOpen={openDetail}
        activeLabel={historicalPage(activeModule ?? "") ? "Historical records" : foundationPage?.label ?? activeModule ?? "Dashboard"}
        profile={profile}
        collapsed={sidebarCollapsed}
      />
      <div className="main-shell">
        <div className="main-content">
          <Header
            onOpen={openDetail}
            profile={profile}
            onSignOut={signOut}
            sidebarCollapsed={sidebarCollapsed}
          />
          <main id="dashboard-content">
            {activeModule === "Historical records" || historicalPage(activeModule ?? "") ? (
              <WorkflowHistoryScreen key={activeModule} profile={profile} initial={activeModule ?? undefined} />
            ) : activeModule === "Supplier balances" ? (
              <SupplierBalancesScreen profile={profile} />
            ) : activeModule === "Stock overview" ? (
              <StockOverviewScreen profile={profile} />
            ) : foundationPage ? (
              <FoundationScreen
                key={foundationPage.key}
                profile={profile}
                page={foundationPage}
              />
            ) : activeModule === "Materials & tools" ? (
              <SupplyPurchasesScreen profile={profile} />
            ) : activeModule === "Materials & tools stock" ? (
              <SupplyStockScreen profile={profile} />
            ) : activeModule && MANAGER_PRODUCTION_PAGES[activeModule] ? (
              <ProductionScreen key={activeModule} label={activeModule} profile={profile} />
            ) : activeModule && BUSINESS_PAGES[activeModule] ? (
              <BusinessScreen key={activeModule} label={activeModule} profile={profile} />
            ) : activeModule && WASHING_PAGES[activeModule] ? (
              <WashingScreen key={activeModule} label={activeModule} profile={profile} />
            ) : activeModule && CORRECTION_PAGES[activeModule] ? (
              <CorrectionScreen
                key={activeModule}
                label={activeModule}
                profile={profile}
              />
            ) : activeModule && INVENTORY_PAGES[activeModule] ? (
              <InventoryScreen
                key={activeModule}
                label={activeModule}
                profile={profile}
              />
            ) : activeModule &&
              ["Weavers & Artisans", "Weaver records", "Suppliers", "Supplier records", "Directories"].includes(
                activeModule,
              ) ? (
              <DirectoryScreen
                key={activeModule}
                profile={profile}
                initialTab={
                  ["Suppliers", "Supplier records"].includes(activeModule)
                    ? "suppliers"
                    : activeModule === "Directories"
                      ? "provinces"
                      : "weavers"
                }
                onExit={() => writePage(null)}
                onlyResource={activeModule === "Supplier records" ? "suppliers" : undefined}
              />
            ) : activeModule === "Customers" ? (
              <CustomersScreen
                canCreate={can(profile, "customers.create")}
                canUpdate={can(profile, "customers.update")}
                canDelete={can(profile, "customers.delete")}
              />
            ) : (
              <DashboardOverview
                profile={profile}
                onOpen={(name) => openDetail({ kind: "section", name })}
              />
            )}
          </main>
          <Footer />
        </div>
      </div>
    </div>
    </BrandingProvider>
  );
}
