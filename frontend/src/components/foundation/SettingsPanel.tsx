"use client";

import { useEffect, useState } from "react";
import { apiFetch, isApiFailure, type Profile } from "@/lib/api";
import {
  type FoundationRecord,
  type Lookups,
  type Resource,
} from "@/lib/foundation";
import { foundationText } from "@/lib/foundation-i18n";
import { RecordEditor } from "./RecordEditor";
import { SystemResetPanel } from "./SystemResetPanel";
import { BrandingPanel } from "./BrandingPanel";
import { resetText } from "@/lib/system-reset-i18n";
import styles from "./foundation.module.css";

const settingsResource: Resource = {
  key: "settings",
  label: "Company settings",
  permission: "settings",
  fields: [
    {
      key: "base_currency_id",
      label: "Base currency",
      kind: "select",
      lookup: "currencies",
      required: true,
    },
    {
      key: "default_locale",
      label: "Default language",
      kind: "select",
      options: ["en", "fa"],
      required: true,
    },
    { key: "timezone", label: "Timezone", required: true, max: 60 },
    {
      key: "reminder_days_before",
      label: "Reminder days before due date",
      required: true,
      max: 3,
    },
  ],
};

export function SettingsPanel({
  profile,
  lookups,
  locale,
}: {
  profile: Profile;
  lookups: Lookups;
  locale: string;
}) {
  const [settings, setSettings] = useState<FoundationRecord | null>(null);
  const [editing, setEditing] = useState(false);
  const [banner, setBanner] = useState("");
  const t = (text: string) => foundationText(locale, text);
  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: FoundationRecord }>("/foundation/settings")
      .then((r) => {
        if (!cancelled) setSettings(r.data);
      })
      .catch((e) => {
        if (!cancelled)
          setBanner(isApiFailure(e) ? e.message : "Could not load settings.");
      });
    return () => {
      cancelled = true;
    };
  }, []);
  return (
    <section>
      <div className={styles.actions}>
        {profile.is_administrator &&
          profile.permissions.includes("settings.update") && (
            <button disabled={!settings} onClick={() => setEditing(true)}>
              {t("Edit")}
            </button>
          )}
      </div>
      <p>{t("Base currency is fixed after the first ledger posting.")}</p>
      {settings && !settings.base_currency_id && (
        <p className={styles.notice} role="status">{resetText(locale,
          "Base currency is not configured. Create a currency in Finance → Currencies, then edit these settings before posting transactions.")}</p>
      )}
      {banner && (
        <p role="status" className={styles.notice}>
          {t(banner)}
        </p>
      )}
      {settings ? (
        <dl className={styles.settings}>
          {settingsResource.fields.map((field) => (
            <div key={field.key}>
              <dt>{t(field.label)}</dt>
              <dd>
                {field.lookup
                  ? lookups[field.lookup]?.find(
                      (r) => r.id === Number(settings[field.key]),
                    )?.name ?? resetText(locale, "Not configured")
                  : t(String(settings[field.key]))}
              </dd>
            </div>
          ))}
        </dl>
      ) : (
        <p>{t("Loading...")}</p>
      )}
      {editing && settings && (
        <RecordEditor
          resource={settingsResource}
          row={settings}
          lookups={lookups}
          locale={locale}
          onClose={() => setEditing(false)}
          onSave={async (data) => {
            const result = await apiFetch<{ data: FoundationRecord }>(
              "/foundation/settings",
              {
                method: "PUT",
                body: JSON.stringify({
                  ...data,
                  reminder_days_before: Number(data.reminder_days_before),
                }),
              },
            );
            setSettings(result.data);
            setEditing(false);
            setBanner(t("Settings saved."));
          }}
        />
      )}
      <BrandingPanel profile={profile} locale={locale} />
      {profile.is_administrator && profile.permissions.includes("settings.update") &&
        <SystemResetPanel locale={locale} />}
    </section>
  );
}
