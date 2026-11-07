"use client";

import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { FiPlus, FiX } from "react-icons/fi";
import { apiFetch, isApiFailure } from "@/lib/api";
import { positiveDecimal, productionToday, records, selectOptions, textValue, validateFields, type ProductionField, type ProductionLookups, type ProductionOption, type ProductionRecord } from "@/lib/manager-production";
import { FOUNDATION_RESOURCES, type FoundationRecord } from "@/lib/foundation";
import { RecordEditor } from "@/components/foundation/RecordEditor";
import { stockChoices, validateUsages, zeroQuantity, type UsageLine, type WorkflowContext } from "@/lib/production-workflow";
import { productionText } from "@/lib/manager-production-i18n";
import { ProductionDialog } from "./ProductionDialog";
import { ProductionFields } from "./ProductionFields";
import { useProductionCommand } from "./useProductionCommand";
import styles from "./production.module.css";

type FinishedCarpet = { line_id: string; length_cm: string; width_cm: string; carpet_code: string };
const emptyCarpet = (): FinishedCarpet => ({ line_id: "", length_cm: "", width_cm: "", carpet_code: "" });
const locationResource = FOUNDATION_RESOURCES.find((resource) => resource.key === "locations")!;
export function ProductionDeliveryEditor({ order, lookups, locale, canCreateLocations = false, locationFacilityIds, simple = false, context, onClose, onSaved }: {
  order: ProductionRecord; lookups: ProductionLookups; locale: string; canCreateLocations?: boolean; locationFacilityIds?: number[]; simple?: boolean; context?: WorkflowContext; onClose: () => void; onSaved: () => void;
}) {
  const initial = { delivered_on: productionToday(lookups), facility_id: simple ? String(context?.defaults?.facility_id ?? "") : "", facility_location_id: simple ? String(context?.defaults?.facility_location_id ?? "") : "" };
  const [values, setValues] = useState<Record<string, string>>(initial);
  const [items, setItems] = useState<FinishedCarpet[]>([emptyCarpet()]);
  const [materials, setMaterials] = useState<number[]>([]);
  const [preview, setPreview] = useState<{ charge_amount: string; cumulative_amount: string } | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [locationOptions, setLocationOptions] = useState(lookups.facility_locations);
  const [refreshing, setRefreshing] = useState(true);
  const [creatingLocation, setCreatingLocation] = useState(false);
  const [changeDestination, setChangeDestination] = useState(false);
  const heldMaterials = context ? stockChoices(context, false, true) : [];
  const [usages, setUsages] = useState<UsageLine[]>(() => heldMaterials.map((row) => ({ material_id: String(row.id), used_quantity: "0", waste_quantity: "0" })));
  const [noMaterialsUsed, setNoMaterialsUsed] = useState(false);
  const lookupRequest = useRef<AbortController | null>(null);
  const command = useProductionCommand(onSaved);
  const { setBanner } = command;
  const t = (text: string) => productionText(locale, text);
  const lines = records(order, "lines");
  const selectedFacility = lookups.facilities.find((facility) => String(facility.id) === values.facility_id);
  const canAddLocation = canCreateLocations && (!locationFacilityIds || locationFacilityIds.includes(Number(values.facility_id)));
  const facilityLocations = locationOptions.filter((location) => String(location.washing_facility_id) === values.facility_id);
  const refreshLocations = useCallback(() => {
    lookupRequest.current?.abort();
    const controller = new AbortController();
    lookupRequest.current = controller;
    return apiFetch<{ data: ProductionLookups }>("/production/lookups", { signal: controller.signal }).then((result) => {
      if (controller.signal.aborted) return;
      setLocationOptions(result.data.facility_locations);
      setValues((current) => {
        const choices = result.data.facility_locations.filter((location) => String(location.washing_facility_id) === current.facility_id);
        const selected = choices.some((location) => String(location.id) === current.facility_location_id);
        return { ...current, facility_location_id: selected ? current.facility_location_id : choices.length === 1 ? String(choices[0].id) : "" };
      });
      setBanner("");
    }).catch((failure) => {
      if (!controller.signal.aborted) setBanner(isApiFailure(failure) ? failure.message : "Could not load records.");
    }).finally(() => {
      if (!controller.signal.aborted) setRefreshing(false);
    });
  }, [setBanner]);
  useEffect(() => {
    void refreshLocations();
    return () => lookupRequest.current?.abort();
  }, [refreshLocations]);
  useEffect(() => {
    if (!simple || items.some((item) => !item.line_id || !positiveDecimal(item.length_cm, 2) || !positiveDecimal(item.width_cm, 2))) return;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      apiFetch<{ data: { charge_amount: string; cumulative_amount: string } }>(`/production/orders/${order.id}/preview`, {
        method: "POST", signal: controller.signal, body: JSON.stringify({ items: items.map((item) => ({ line_id: Number(item.line_id), length_cm: item.length_cm, width_cm: item.width_cm })) }),
      }).then((result) => { if (!controller.signal.aborted) setPreview(result.data); })
        .catch((failure) => { if (!controller.signal.aborted) setBanner(isApiFailure(failure) ? failure.message : "Could not load records."); });
    }, 300);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [items, order.id, simple, setBanner]);

  async function saveLocation(payload: Record<string, unknown>) {
    const result = await apiFetch<{ data: FoundationRecord }>("/foundation/locations", { method: "POST", body: JSON.stringify(payload) });
    const location = result.data;
    if (location.location_type === "washing" && location.status === "active") {
      const option: ProductionOption = { id: location.id, name: String(location.name), washing_facility_id: location.washing_facility_id };
      setLocationOptions((current) => [...current.filter((row) => row.id !== option.id), option]);
      setValues((current) => String(location.washing_facility_id) === current.facility_id ? { ...current, facility_location_id: String(location.id) } : current);
    }
    command.setErrors({});
    setCreatingLocation(false);
  }
  const fields: ProductionField[] = [
    { key: "delivered_on", label: "Delivery date", type: "date" },
    { key: "facility_id", label: "Washing facility", type: "select", options: selectOptions(lookups.facilities) },
    { key: "facility_location_id", label: "Washing stock location", type: "select", options: selectOptions(facilityLocations), disabled: !values.facility_id || refreshing || !facilityLocations.length,
      placeholder: !values.facility_id ? "Choose a washing facility first" : refreshing ? "Loading..." : !facilityLocations.length ? "No washing locations available" : "Choose an option" },
  ];
  function changeItem(index: number, key: string, next: string) {
    setPreview(null);
    setItems((old) => old.map((item, itemIndex) => {
      if (itemIndex !== index) return item;
      const line = key === "line_id" ? lines.find((row) => String(row.id) === next) : undefined;
      return { ...item, [key]: next, ...(line ? { length_cm: textValue(line, "length_cm"), width_cm: textValue(line, "width_cm") } : {}) };
    }));
    command.setErrors({});
  }
  async function calculate() {
    setPreviewing(true); command.setBanner("");
    try {
      const result = await apiFetch<{ data: { charge_amount: string; cumulative_amount: string } }>(`/production/orders/${order.id}/preview`, {
        method: "POST", body: JSON.stringify({ items: items.map((item) => ({ line_id: Number(item.line_id), length_cm: item.length_cm, width_cm: item.width_cm, ...(item.carpet_code.trim() ? { carpet_code: item.carpet_code.trim() } : {}) })) }),
      });
      setPreview(result.data);
    } catch (failure) {
      command.setBanner(isApiFailure(failure) ? failure.message : "Could not load records.");
    } finally { setPreviewing(false); }
  }
  async function submit(event: FormEvent) {
    event.preventDefault();
    const errors = validateFields(fields, values, productionToday(lookups));
    if (simple) {
      Object.assign(errors, validateUsages(usages, heldMaterials));
      const used = usages.some((row) => zeroQuantity(row.used_quantity) && zeroQuantity(row.waste_quantity) && (/[1-9]/.test(row.used_quantity) || /[1-9]/.test(row.waste_quantity)));
      if (!used && !records(order, "unallocated_material_movements").length && !noMaterialsUsed) errors.usages = "Enter material use or confirm that no company materials were used.";
      if (used && noMaterialsUsed) errors.usages = "Material quantities conflict with the no-materials confirmation.";
    }
    if (values.facility_id && !facilityLocations.length) errors.facility_location_id = "Choose or add an active washing location for this facility.";
    if (!items.length || items.length > 50) errors.items = "Select 1 to 50 finished carpets.";
    const codes = items.map((item) => item.carpet_code.trim()).filter(Boolean);
    if (new Set(codes).size !== codes.length) errors.items = "Choose different carpet codes.";
    items.forEach((item, index) => {
      if (!lines.some((line) => String(line.id) === item.line_id)) errors[`items.${index}.line_id`] = "Choose a valid option.";
      for (const key of ["length_cm", "width_cm"] as const) if (!positiveDecimal(item[key], 2)) errors[`items.${index}.${key}`] = "Enter a positive number.";
      if (item.carpet_code && !/^[A-Z0-9_-]{1,40}$/.test(item.carpet_code)) errors[`items.${index}.carpet_code`] = "Use uppercase letters, numbers, - or _.";
    });
    command.setErrors(errors);
    if (Object.keys(errors).length) return;
    await command.save(`/production/orders/${order.id}/${simple ? "receive" : "deliveries"}`, {
      ...values, facility_id: Number(values.facility_id), facility_location_id: Number(values.facility_location_id),
      items: items.map((item) => ({ line_id: Number(item.line_id), length_cm: item.length_cm, width_cm: item.width_cm, ...(item.carpet_code.trim() ? { carpet_code: item.carpet_code.trim() } : {}) })),
      ...(simple ? { usages: usages.filter((row) => /[1-9]/.test(row.used_quantity) || /[1-9]/.test(row.waste_quantity)).map((row) => ({ ...row, material_id: Number(row.material_id) })), no_materials_used: noMaterialsUsed } : { material_movement_ids: materials }),
    });
  }
  if (creatingLocation && selectedFacility && canAddLocation) return <RecordEditor resource={locationResource} locale={locale}
    lookups={{ branches: lookups.branches, facilities: lookups.facilities }}
    defaults={{ location_type: "washing", washing_facility_id: values.facility_id, branch_id: "", name: `${selectedFacility.name} stock`, status: "active" }}
    onClose={() => setCreatingLocation(false)} onSave={saveLocation} />;
  return <ProductionDialog title={simple ? "Receive carpets" : "Send to washing"} locale={locale} busy={command.busy || previewing} dirty={items.some((item) => item.line_id || item.carpet_code) || materials.length > 0 || JSON.stringify(values) !== JSON.stringify(initial)} onClose={onClose}>
    <p className={styles.notice}>{t("Carpets go directly to the selected washing facility. No warehouse receipt is created.")}</p>
    <form noValidate onSubmit={submit} aria-busy={command.busy}>
      {command.banner && <p className={styles.error} role="alert">{t(command.banner)}</p>}
      <fieldset disabled={command.busy || previewing}>
        {simple && context?.defaults && !changeDestination && <p className={styles.notice}>{t("To washing")}: <strong>{selectedFacility?.name ?? "—"} · {locationOptions.find((row) => String(row.id) === values.facility_location_id)?.name ?? "—"}</strong></p>}
        {(!simple || !context?.defaults || changeDestination) && <ProductionFields fields={simple ? fields.filter((field) => field.key !== "delivered_on") : fields} values={values} errors={command.errors} locale={locale} onChange={(key, value) => {
          const choices = key === "facility_id" ? locationOptions.filter((location) => String(location.washing_facility_id) === value) : [];
          setValues((old) => ({ ...old, [key]: value, ...(key === "facility_id" ? { facility_location_id: choices.length === 1 ? String(choices[0].id) : "" } : {}) }));
          command.setErrors({});
        }} />}
        {selectedFacility && !refreshing && !facilityLocations.length && <p className={styles.notice} role="status">
          <strong>{selectedFacility.name}</strong>{" · "}{t("No active washing stock location is linked to this facility. Add one with Location type Washing and this Washing facility under Inventory → Stock locations.")}
          {!canAddLocation && <span className={styles.sub}>{t("Ask an administrator to configure this washing location, then refresh the locations.")}</span>}
        </p>}
        <div className={styles.actions}>
          {simple && context?.defaults && <button type="button" onClick={() => setChangeDestination((old) => !old)}>{t("Change washing destination")}</button>}
          <button type="button" disabled={refreshing} onClick={() => { setRefreshing(true); void refreshLocations(); }}>{t(refreshing ? "Loading..." : "Refresh locations")}</button>
          {selectedFacility && canAddLocation && <button type="button" disabled={refreshing} onClick={() => setCreatingLocation(true)}><FiPlus aria-hidden="true" />{t("Add washing location")}</button>}
        </div>
        <section className={styles.section}><h3>{t("Finished carpets")}</h3>{items.map((item, index) => <div className={styles.line} key={index}>
          <div className={styles.lineHeader}><strong>{t("Carpet")} {index + 1}</strong><button type="button" className={styles.iconButton} aria-label={`${t("Remove")} ${index + 1}`} disabled={items.length === 1} onClick={() => { setItems((old) => old.filter((_, itemIndex) => itemIndex !== index)); setPreview(null); }}><FiX aria-hidden="true" /></button></div>
          <ProductionFields locale={locale} values={item} onChange={(key, value) => changeItem(index, key, value)} errors={Object.fromEntries(Object.entries(command.errors).filter(([key]) => key.startsWith(`items.${index}.`)).map(([key, error]) => [key.slice(`items.${index}.`.length), error]))}
            fields={[
              { key: "line_id", label: "Design line", type: "select", options: lines.map((line) => ({ value: String(line.id), label: `${textValue(line, "name")} · ${textValue(line, "delivered_quantity") || "0"}/${textValue(line, "target_quantity")}` })) },
              ...(!simple ? [{ key: "carpet_code", label: "Carpet code (optional)", optional: true, maxLength: 40 }] : []),
              { key: "length_cm", label: "Length (cm)", type: "decimal" }, { key: "width_cm", label: "Width (cm)", type: "decimal" },
            ]} />
        </div>)}{command.errors.items && <p className={styles.error} role="alert">{t(command.errors.items)}</p>}
          <button type="button" disabled={items.length >= 50} onClick={() => { setItems((old) => [...old, emptyCarpet()]); setPreview(null); }}><FiPlus aria-hidden="true" />{t("Add carpet")}</button>
        </section>
        {simple && <section className={styles.section}><h3>{t("Material used in this delivery")}</h3>
          {usages.map((usage, index) => <div className={styles.line} key={usage.material_id}><strong>{String(heldMaterials[index]?.name ?? "")} · {t("With manager")}: {String(heldMaterials[index]?.quantity)} {String(heldMaterials[index]?.unit ?? "")}</strong>
            <ProductionFields fields={[{ key: "used_quantity", label: "Quantity used" }, { key: "waste_quantity", label: "Waste quantity" }]} values={usage} locale={locale} errors={{}} onChange={(key, value) => setUsages((old) => old.map((row, i) => i === index ? { ...row, [key]: value } : row))} />
            {command.errors[`usages.${index}`] && <p className={styles.error} role="alert">{t(command.errors[`usages.${index}`])}</p>}
          </div>)}
          {records(order, "unallocated_material_movements").length > 0 && <p className={styles.notice}>{t("Previously recorded consumption for this order will also be included in this delivery.")}</p>}
          <label className={styles.checkbox}><input type="checkbox" checked={noMaterialsUsed} onChange={(event) => setNoMaterialsUsed(event.target.checked)} />{t("No company materials were used in this delivery")}</label>
          {command.errors.usages && <p className={styles.error} role="alert">{t(command.errors.usages)}</p>}
        </section>}
        {!simple && <section className={styles.section}><h3>{t("Unallocated material consumption")}</h3><p className={styles.muted}>{t("Select the consumed materials belonging to this delivery.")}</p>
          {records(order, "unallocated_material_movements").map((movement) => <label className={styles.checkbox} key={movement.id}><input type="checkbox" checked={materials.includes(movement.id)} onChange={(event) => setMaterials((old) => event.target.checked ? [...old, movement.id] : old.filter((id) => id !== movement.id))} /><span>{textValue(movement, "lot_no")} · {textValue(movement, "material_name")} · {textValue(movement, "quantity")} {textValue(movement, "unit")}</span></label>)}
          {!records(order, "unallocated_material_movements").length && <p className={styles.muted}>{t("No records yet.")}</p>}
        </section>}
        {simple && <details className={styles.advanced}><summary>{t("Delivery date and carpet codes")}</summary>
          <ProductionFields fields={[fields[0]]} values={values} errors={command.errors} locale={locale} onChange={(key, value) => setValues((old) => ({ ...old, [key]: value }))} />
          {items.map((item, index) => <ProductionFields key={index} locale={locale} values={item} errors={{}} onChange={(key, value) => changeItem(index, key, value)} fields={[{ key: "carpet_code", label: `Carpet code ${index + 1}`, optional: true, maxLength: 40 }]} />)}
        </details>}
      </fieldset>
      {preview && <p className={styles.notice} role="status">{t("Delivery production charge")}: <strong>{preview.charge_amount} {textValue(order, "currency_code")}</strong><span className={styles.sub}>{t("Cumulative production charge")}: {preview.cumulative_amount} {textValue(order, "currency_code")}</span></p>}
      <footer>{!simple && <button type="button" disabled={command.busy || previewing || items.some((item) => !item.line_id || !positiveDecimal(item.length_cm, 2) || !positiveDecimal(item.width_cm, 2))} onClick={calculate}>{t(previewing ? "Loading..." : "Calculate production charge")}</button>}<button type="submit" className={styles.primary} disabled={command.busy || previewing || refreshing || !values.facility_location_id}>{t(command.busy ? "Saving..." : simple ? "Receive carpets" : "Save delivery")}</button></footer>
    </form>
  </ProductionDialog>;
}
