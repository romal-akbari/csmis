"use client";

import { useState, type FormEvent } from "react";
import { apiFetch, type Profile } from "@/lib/api";
import { FOUNDATION_RESOURCES } from "@/lib/foundation";
import { defaultsFor } from "@/lib/production-workflow";
import { options, selectOptions, validateFields, productionToday, type ProductionLookups } from "@/lib/manager-production";
import { productionText } from "@/lib/manager-production-i18n";
import { RecordEditor } from "@/components/foundation/RecordEditor";
import { ProductionDialog } from "./ProductionDialog";
import { ProductionFields } from "./ProductionFields";
import { useProductionCommand } from "./useProductionCommand";
import styles from "./production.module.css";

export function ProductionSetupEditor({ lookups, locale, profile, branch, onClose, onSaved }: {
  lookups: ProductionLookups; locale: string; profile: Profile; branch?: string; onClose: () => void; onSaved: () => void;
}) {
  const initialBranch = branch || (lookups.branches.length === 1 ? String(lookups.branches[0].id) : "");
  const defaults = defaultsFor(lookups, initialBranch);
  const [values, setValues] = useState<Record<string, string>>({ branch_id: initialBranch, materials_location_id: String(defaults?.materials_location_id ?? ""), finished_location_id: String(defaults?.finished_location_id ?? ""), facility_id: String(defaults?.facility_id ?? ""), facility_location_id: String(defaults?.facility_location_id ?? ""), payment_trigger: String(defaults?.payment_trigger ?? "stock_acceptance") });
  const [local, setLocal] = useState(lookups);
  const [adding, setAdding] = useState<string | null>(null);
  const command = useProductionCommand(onSaved);
  const t = (text: string) => productionText(locale, text);
  const branchLocations = options(local, "locations").filter((item) => String(item.branch_id) === values.branch_id);
  const fields = [
    { key: "branch_id", label: "Branch", type: "select" as const, options: selectOptions(local.branches) },
    { key: "materials_location_id", label: "Materials and tools store", type: "select" as const, options: selectOptions(branchLocations) },
    { key: "finished_location_id", label: "Finished carpet store", type: "select" as const, options: selectOptions(branchLocations) },
    { key: "facility_id", label: "Default washing facility", type: "select" as const, options: selectOptions(local.facilities) },
    { key: "facility_location_id", label: "Washing stock location", type: "select" as const, options: selectOptions(local.facility_locations.filter((item) => String(item.washing_facility_id) === values.facility_id)) },
    { key: "payment_trigger", label: "When does the manager earn payment?", type: "select" as const, options: [{ value: "stock_acceptance", label: "After stock acceptance" }, { value: "completion", label: "On completed delivery to washing" }] },
  ];
  function change(key: string, value: string) {
    const saved = key === "branch_id" ? defaultsFor(local, value) : undefined;
    setValues((old) => ({ ...old, [key]: value, ...(key === "branch_id" ? { materials_location_id: String(saved?.materials_location_id ?? ""), finished_location_id: String(saved?.finished_location_id ?? ""), facility_id: String(saved?.facility_id ?? ""), facility_location_id: String(saved?.facility_location_id ?? ""), payment_trigger: String(saved?.payment_trigger ?? "stock_acceptance") } : {}), ...(key === "facility_id" ? { facility_location_id: "" } : {}) })); command.setErrors({});
  }
  async function submit(event: FormEvent) {
    event.preventDefault(); const errors = validateFields(fields, values, productionToday(local)); command.setErrors(errors);
    if (!Object.keys(errors).length) await command.save("/production/setup", Object.fromEntries(Object.entries(values).map(([key, value]) => [key, key.endsWith("_id") ? Number(value) : value])));
  }
  if (adding) {
    const resource = FOUNDATION_RESOURCES.find((item) => item.key === (adding === "facility" ? "facilities" : "locations"))!;
    return <RecordEditor resource={resource} locale={locale} lookups={{ branches: local.branches, facilities: local.facilities }}
      defaults={adding === "facility" ? undefined : { location_type: adding === "washing" ? "washing" : "warehouse", branch_id: adding === "washing" ? "" : values.branch_id, washing_facility_id: adding === "washing" ? values.facility_id : "", status: "active" }}
      onClose={() => setAdding(null)} onSave={async (payload) => {
        const result = await apiFetch<{ data: { id: number } }>(`/foundation/${resource.key}`, { method: "POST", body: JSON.stringify(payload) });
        const item = { ...payload, id: result.data.id };
        setLocal((old) => adding === "facility" ? { ...old, facilities: [...old.facilities, item] } : adding === "washing" ? { ...old, facility_locations: [...old.facility_locations, item] } : { ...old, locations: [...options(old, "locations"), item] });
        setValues((old) => ({ ...old, [adding === "facility" ? "facility_id" : adding === "washing" ? "facility_location_id" : adding === "finished" ? "finished_location_id" : "materials_location_id"]: String(result.data.id) })); setAdding(null);
      }} />;
  }
  return <ProductionDialog title="Production setup" locale={locale} busy={command.busy} onClose={onClose}>
    <p className={styles.notice}>{t("Choose these defaults once. New orders will use the stores and washing destination automatically.")}</p>
    <form noValidate onSubmit={submit}>{command.banner && <p className={styles.error} role="alert">{t(command.banner)}</p>}
      <fieldset disabled={command.busy}><ProductionFields fields={fields} values={values} errors={command.errors} locale={locale} onChange={change} />
        <div className={styles.actions}>{profile.permissions.includes("locations.create") && values.branch_id && <><button type="button" onClick={() => setAdding("materials")}>{t("Add materials store")}</button><button type="button" onClick={() => setAdding("finished")}>{t("Add finished store")}</button></>}
          {profile.is_administrator && <button type="button" onClick={() => setAdding("facility")}>{t("Add washing facility")}</button>}
          {profile.permissions.includes("locations.create") && values.facility_id && <button type="button" onClick={() => setAdding("washing")}>{t("Add washing location")}</button>}
        </div>
      </fieldset><footer><button className={styles.primary} disabled={command.busy}>{t("Save setup")}</button></footer>
    </form>
  </ProductionDialog>;
}
