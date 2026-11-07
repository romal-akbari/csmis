export type DirectoryKey =
  "provinces" | "districts" | "areas" | "weavers" | "suppliers";
export type DirectoryRecord = { id: number; [key: string]: unknown };
export type DirectoryOption = {
  id: number;
  name: string;
  name_fa?: string | null;
  status?: string;
  province_id?: number;
  district_id?: number;
  branch_id?: number | null;
};
export type DirectoryLookups = {
  provinces: DirectoryOption[];
  districts: DirectoryOption[];
  areas: DirectoryOption[];
  managers: DirectoryOption[];
  company: DirectoryOption[];
};
export type DirectoryValues = Record<string, string>;
export type DirectoryField = {
  key: string;
  label: string;
  required?: boolean;
  max?: number;
  kind?: "select" | "date" | "textarea";
  lookup?: keyof DirectoryLookups;
};
export type DirectoryResource = {
  key: DirectoryKey;
  label: string;
  permission: string;
  adminOnly?: boolean;
  fields: DirectoryField[];
  columns: { key: string; label: string }[];
};

const name = (max = 100): DirectoryField => ({
  key: "name",
  label: "Name",
  required: true,
  max,
});
const status: DirectoryField = {
  key: "status",
  label: "Status",
  required: true,
  kind: "select",
};
const province: DirectoryField = {
  key: "province_id",
  label: "Province",
  required: true,
  kind: "select",
  lookup: "provinces",
};
const district: DirectoryField = {
  key: "district_id",
  label: "District",
  required: true,
  kind: "select",
  lookup: "districts",
};
const phone: DirectoryField = { key: "phone", label: "Phone", max: 30 };
const notes: DirectoryField = {
  key: "notes",
  label: "Notes",
  kind: "textarea",
  max: 1000,
};
const namedColumns = [
  { key: "name", label: "Name" },
  { key: "name_fa", label: "Dari name" },
  { key: "status", label: "Status" },
];
export const DIRECTORY_RESOURCES: DirectoryResource[] = [
  {
    key: "weavers",
    label: "Weavers & Artisans",
    permission: "weavers",
    fields: [
      name(150),
      phone,
      province,
      district,
      {
        key: "area_id",
        label: "Area",
        kind: "select",
        lookup: "areas",
        required: true,
      },
      {
        key: "manager_employee_id",
        label: "Manager",
        kind: "select",
        lookup: "managers",
      },
      {
        key: "registration_date",
        label: "Registration date",
        required: true,
        kind: "date",
      },
      status,
      notes,
    ],
    columns: [
      { key: "name", label: "Name" },
      { key: "phone", label: "Phone" },
      { key: "province_name", label: "Province" },
      { key: "district_name", label: "District" },
      { key: "area_name", label: "Area" },
      { key: "manager_name", label: "Manager" },
      { key: "status", label: "Status" },
    ],
  },
  {
    key: "suppliers",
    label: "Suppliers",
    permission: "suppliers",
    fields: [
      name(150),
      phone,
      { key: "address", label: "Address", max: 255, kind: "textarea" },
      status,
      notes,
    ],
    columns: [
      { key: "name", label: "Name" },
      { key: "phone", label: "Phone" },
      { key: "address", label: "Address" },
      { key: "status", label: "Status" },
    ],
  },
  {
    key: "provinces",
    label: "Provinces",
    permission: "geography",
    adminOnly: true,
    fields: [name(), { key: "name_fa", label: "Dari name", max: 100 }, status],
    columns: namedColumns,
  },
  {
    key: "districts",
    label: "Districts",
    permission: "geography",
    adminOnly: true,
    fields: [
      province,
      name(),
      { key: "name_fa", label: "Dari name", max: 100 },
      status,
    ],
    columns: [{ key: "province_name", label: "Province" }, ...namedColumns],
  },
  {
    key: "areas",
    label: "Areas",
    permission: "geography",
    adminOnly: true,
    fields: [
      province,
      district,
      name(),
      { key: "name_fa", label: "Dari name", max: 100 },
      status,
    ],
    columns: [
      { key: "province_name", label: "Province" },
      { key: "district_name", label: "District" },
      ...namedColumns,
    ],
  },
];
export const EMPTY_DIRECTORY_LOOKUPS: DirectoryLookups = {
  provinces: [],
  districts: [],
  areas: [],
  managers: [],
  company: [],
};

export function directoryToday(timezone = "Asia/Kabul"): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: timezone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}
export function validDirectoryDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || value < "1000-01-01") return false;
  const date = new Date(`${value}T00:00:00Z`);
  return (
    !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value
  );
}
export function directoryInitialValues(
  resource: DirectoryResource,
  row?: DirectoryRecord,
  timezone = "Asia/Kabul",
): DirectoryValues {
  return Object.fromEntries(
    resource.fields.map((field) => [
      field.key,
      String(
        row?.[field.key] ??
          (field.key === "status"
            ? "active"
            : field.kind === "date"
              ? directoryToday(timezone)
              : ""),
      ),
    ]),
  );
}
export function directoryOptions(
  field: DirectoryField,
  values: DirectoryValues,
  lookups: DirectoryLookups,
): DirectoryOption[] {
  if (!field.lookup) return [];
  return lookups[field.lookup].filter((option) =>
    field.lookup === "districts"
      ? option.province_id === Number(values.province_id)
      : field.lookup === "areas"
        ? option.district_id === Number(values.district_id)
        : true,
  );
}
/** Reset descendants whenever a geographic parent changes; never submit stale IDs. */
export function changeDirectoryValue(
  values: DirectoryValues,
  key: string,
  value: string,
): DirectoryValues {
  return {
    ...values,
    [key]: value,
    ...(key === "province_id"
      ? { district_id: "", area_id: "" }
      : key === "district_id"
        ? { area_id: "" }
        : {}),
  };
}
export function validateDirectory(
  resource: DirectoryResource,
  values: DirectoryValues,
  lookups: DirectoryLookups,
  isAdministrator = true,
): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const field of resource.fields) {
    const value = (values[field.key] ?? "").trim();
    if (field.required && !value) errors[field.key] = "This field is required.";
    else if (value && field.max && Array.from(value).length > field.max)
      errors[field.key] = `Use at most ${field.max} characters.`;
    else if (field.key === "name" && Array.from(value).length < 2)
      errors[field.key] = "Use at least 2 characters.";
    else if (field.key === "status" && !["active", "inactive"].includes(value))
      errors[field.key] = "Choose a valid status.";
    else if (
      field.lookup &&
      value &&
      (!/^[1-9]\d*$/.test(value) ||
        !directoryOptions(field, values, lookups).some(
          (o) => o.id === Number(value),
        ))
    )
      errors[field.key] = "Choose a valid option.";
    else if (
      field.key === "phone" &&
      value &&
      (value.length < 7 ||
        value.length > 30 ||
        !/^\+?[0-9][0-9\s().-]+$/.test(value))
    )
      errors[field.key] = "Enter a valid phone number (7–30 characters).";
    else if (
      field.kind === "date" &&
      value &&
      (!validDirectoryDate(value) ||
        value > directoryToday(lookups.company?.[0]?.name))
    )
      errors[field.key] = "Enter a valid date that is not in the future.";
  }
  if (
    resource.key === "weavers" &&
    !isAdministrator &&
    !(values.manager_employee_id ?? "").trim()
  )
    errors.manager_employee_id = "This field is required.";
  return errors;
}
/** Derived geographic parents are UI fields, not duplicate database columns. */
export function directoryPayload(
  resource: DirectoryResource,
  values: DirectoryValues,
): Record<string, unknown> {
  const excluded =
    resource.key === "weavers"
      ? ["province_id", "district_id"]
      : resource.key === "areas"
        ? ["province_id"]
        : [];
  return Object.fromEntries(
    resource.fields
      .filter((field) => !excluded.includes(field.key))
      .map((field) => {
        const value = (values[field.key] ?? "").trim();
        return [
          field.key,
          field.lookup ? (value ? Number(value) : null) : value || null,
        ];
      }),
  );
}
