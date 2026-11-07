export type FoundationRecord = Record<string, unknown> & { id: number };
export type Lookup = {
  id: number;
  name?: string;
  code?: string;
  is_active?: number;
};
export type Lookups = Record<string, Lookup[]>;
export type Field = {
  key: string;
  label: string;
  kind?: "text" | "select" | "multi" | "boolean" | "password" | "date";
  required?: boolean;
  max?: number;
  createOnly?: boolean;
  options?: string[];
  lookup?: string;
};
export type Resource = {
  key: string;
  label: string;
  permission: string;
  adminOnly?: boolean;
  fields: Field[];
};

const name: Field = { key: "name", label: "Name", required: true, max: 120 };
const code: Field = { key: "code", label: "Code", max: 30 };
const status: Field = {
  key: "status",
  label: "Status",
  kind: "select",
  required: true,
  options: ["active", "inactive"],
};
const branch: Field = {
  key: "branch_id",
  label: "Branch",
  kind: "select",
  lookup: "branches",
  required: true,
};
const currency: Field = {
  key: "currency_id",
  label: "Currency",
  kind: "select",
  lookup: "currencies",
  required: true,
};
const phone: Field = { key: "phone", label: "Phone", max: 30 };
const email: Field = { key: "email", label: "Email", max: 150 };

export const FOUNDATION_RESOURCES: Resource[] = [
  {
    key: "branches",
    label: "Branches",
    permission: "branches",
    adminOnly: true,
    fields: [
      { ...code, max: 20 },
      name,
      { key: "location", label: "Address", max: 255 },
      phone,
      { key: "is_main", label: "Main branch", kind: "boolean" },
      status,
    ],
  },
  {
    key: "locations",
    label: "Stock locations",
    permission: "locations",
    fields: [
      code,
      name,
      {
        key: "location_type",
        label: "Location type",
        kind: "select",
        required: true,
        options: ["showroom", "warehouse", "shelf", "washing", "other"],
      },
      { ...branch, required: false },
      {
        key: "washing_facility_id",
        label: "Washing facility",
        kind: "select",
        lookup: "facilities",
      },
      status,
    ],
  },
  {
    key: "currencies",
    label: "Currencies",
    permission: "currencies",
    adminOnly: true,
    fields: [
      { ...code, required: true, max: 3 },
      { ...name, max: 50 },
      { key: "symbol", label: "Symbol", required: true, max: 8 },
      {
        key: "decimal_places",
        label: "Decimal places",
        kind: "select",
        required: true,
        options: ["0", "1", "2"],
      },
      { key: "is_active", label: "Active", kind: "boolean" },
    ],
  },
  {
    key: "exchange-rates",
    label: "Exchange rates",
    permission: "currencies",
    adminOnly: true,
    fields: [
      { ...currency, key: "base_currency_id", label: "From currency" },
      { ...currency, key: "quote_currency_id", label: "To currency" },
      { key: "rate", label: "Rate", required: true, max: 21 },
      {
        key: "effective_date",
        label: "Effective date",
        kind: "date",
        required: true,
      },
    ],
  },
  {
    key: "employees",
    label: "Employees",
    permission: "employees",
    fields: [
      { ...name, max: 150 },
      phone,
      email,
      { key: "position", label: "Position", max: 100 },
      branch,
      { key: "joining_date", label: "Joining date", kind: "date" },
      { ...status, options: ["active", "inactive", "terminated"] },
    ],
  },
  {
    key: "categories",
    label: "Carpet categories",
    permission: "categories",
    adminOnly: true,
    fields: [
      { ...name, max: 100 },
      { key: "name_fa", label: "Dari name", max: 100 },
      { key: "description", label: "Description", max: 255 },
      status,
    ],
  },
  {
    key: "expense-categories",
    label: "Expense categories",
    permission: "categories",
    adminOnly: true,
    fields: [
      { ...name, max: 100 },
      { key: "name_fa", label: "Dari name", max: 100 },
      status,
    ],
  },
  {
    key: "facilities",
    label: "Washing facilities",
    permission: "facilities",
    adminOnly: true,
    fields: [
      { ...name, max: 150 },
      { key: "location", label: "Address", max: 255 },
      { key: "contact_name", label: "Contact person", max: 150 },
      phone,
      status,
    ],
  },
  {
    key: "accounts",
    label: "Accounts",
    permission: "accounts",
    fields: [
      code,
      name,
      currency,
      { key: "opening_amount", label: "Opening balance", createOnly: true, required: true },
      { key: "opening_on", label: "Opening date", kind: "date", createOnly: true, required: true },
      {
        key: "account_type",
        label: "Account type",
        kind: "select",
        required: true,
        options: ["cash", "bank", "mobile_money", "other"],
      },
      status,
    ],
  },
  {
    key: "users",
    label: "Users and access",
    permission: "users",
    adminOnly: true,
    fields: [
      { ...name, max: 150 },
      { key: "username", label: "Username", required: true, max: 60 },
      email,
      {
        key: "password",
        label: "Password",
        kind: "password",
        required: true,
        max: 72,
      },
      {
        key: "employee_id",
        label: "Employee",
        kind: "select",
        lookup: "employees",
      },
      {
        key: "locale",
        label: "Language",
        kind: "select",
        required: true,
        options: ["en", "fa"],
      },
      { key: "is_active", label: "Active", kind: "boolean" },
      {
        key: "role_ids",
        label: "Roles",
        kind: "multi",
        lookup: "roles",
        required: true,
      },
      {
        key: "branch_ids",
        label: "Branch access",
        kind: "multi",
        lookup: "branches",
      },
      {
        key: "facility_ids",
        label: "Facility access",
        kind: "multi",
        lookup: "facilities",
      },
      { key: "account_ids", label: "Account access", kind: "multi", lookup: "accounts" },
    ],
  },
  {
    key: "roles",
    label: "Roles and permissions",
    permission: "users",
    adminOnly: true,
    fields: [
      { ...name, max: 60 },
      { key: "description", label: "Description", max: 255 },
      {
        key: "permission_ids",
        label: "Permissions",
        kind: "multi",
        lookup: "permissions",
      },
    ],
  },
];

export type FormValues = Record<string, string | boolean | number[]>;

export function initialValues(
  resource: Resource,
  row?: FoundationRecord,
): FormValues {
  return Object.fromEntries(
    resource.fields.map((field) => {
      const existing = row?.[field.key];
      let value: string | boolean | number[] = "";
      if (field.kind === "boolean")
        value = existing === undefined ? true : Boolean(Number(existing));
      else if (field.kind === "multi")
        value = Array.isArray(existing) ? existing.map(Number) : [];
      else if (field.kind !== "password")
        value =
          existing == null ? (field.options?.[0] ?? "") : String(existing);
      if (!row && field.key === "is_main") value = false;
      if (!row && field.key === "decimal_places") value = "2";
      return [field.key, value];
    }),
  );
}

export function validateFoundation(
  resource: Resource,
  values: FormValues,
  editing = false,
  lookups: Lookups = {},
): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const field of resource.fields) {
    if (editing && field.createOnly) continue;
    const value = values[field.key];
    const text = typeof value === "string" ? value.trim() : "";
    const required = (field.required || editing && field.key === "code") && !(editing && field.kind === "password");
    const missing =
      typeof value === "string"
        ? !text
        : Array.isArray(value)
          ? value.length === 0
          : value == null;
    if (required && missing) {
      errors[field.key] = "This field is required.";
    }
    if (typeof value !== "string" || !text) continue;
    if (field.max && [...value].length > field.max)
      errors[field.key] = `Use at most ${field.max} characters.`;
    if (field.key === "name" && [...text].length < 2)
      errors[field.key] = "Use at least 2 characters.";
    if (field.options && !field.options.includes(value))
      errors[field.key] = "Choose a valid option.";
    if (
      field.lookup &&
      !lookups[field.lookup]?.some((option) => String(option.id) === value)
    ) {
      errors[field.key] = "Choose a valid record.";
    }
    if (field.key === "code" && !/^[A-Z0-9_-]+$/.test(value))
      errors.code = "Use uppercase letters, numbers, - or _.";
    if (
      resource.key === "currencies" &&
      field.key === "code" &&
      !/^[A-Z]{3}$/.test(value)
    )
      errors.code = "Use a three-letter currency code.";
    if (field.key === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text))
      errors.email = "Enter a valid email address.";
    if (field.key === "phone" && !/^[+0-9()\s.\-]{5,30}$/.test(value))
      errors.phone = "Enter a valid phone number.";
    if (field.key === "username" && !/^[a-zA-Z0-9_.-]{3,60}$/.test(value))
      errors.username = "Use 3-60 letters, numbers, ., - or _.";
    if (field.kind === "password" && value.length < 12)
      errors.password = "Use at least 12 characters.";
    if (
      field.kind === "password" &&
      new TextEncoder().encode(value).length > 72
    )
      errors.password = "Passwords must fit within 72 UTF-8 bytes.";
    if (
      field.kind === "date" &&
      (!validDate(value) || value > today(lookups.company?.[0]?.name))
    )
      errors[field.key] = "Choose a valid date, today or earlier.";
    if (field.key === "rate" && !validDecimal(value, 12, 8))
      errors.rate = "Enter a positive rate with up to 8 decimal places.";
  }
  if (resource.key === "accounts" && !editing) {
    const amount = String(values.opening_amount ?? "");
    if (!/^(?:0|[1-9]\d{0,15})(?:\.\d{1,2})?$/.test(amount)) errors.opening_amount = "Enter a valid amount.";
  }
  if (resource.key === "locations") {
    const hasBranch = Boolean(values.branch_id);
    const hasFacility = Boolean(values.washing_facility_id);
    if (!hasBranch && !hasFacility) {
      if (values.location_type === "washing")
        errors.washing_facility_id = "Choose a washing facility for this location.";
      else if (values.location_type)
        errors.branch_id = "Choose a branch for this stock location.";
    } else if (hasBranch && hasFacility) {
      errors.branch_id = "Choose exactly one branch or facility.";
    }
    if (
      (hasBranch || hasFacility) &&
      values.location_type &&
      (values.location_type === "washing") !==
      hasFacility
    )
      errors.location_type =
        "Washing locations require a facility; other locations require a branch.";
  }
  if (
    resource.key === "exchange-rates" &&
    values.base_currency_id === values.quote_currency_id
  )
    errors.quote_currency_id = "Choose two different currencies.";
  if (resource.key === "users") {
    const roles = values.role_ids as number[];
    const admin = lookups.roles?.find((role) => role.name === "Administrator");
    if (
      !roles.includes(admin?.id ?? -1) &&
      !(values.branch_ids as number[]).length &&
      !(values.facility_ids as number[]).length
    )
      errors.branch_ids = "Assign a branch or washing facility.";
  }
  if (resource.key === "settings") {
    const days = String(values.reminder_days_before);
    if (!/^\d{1,3}$/.test(days) || Number(days) > 365)
      errors.reminder_days_before = "Use a whole number from 0 to 365.";
    try {
      new Intl.DateTimeFormat("en", {
        timeZone: String(values.timezone),
      }).format();
    } catch {
      errors.timezone = "Choose a valid timezone.";
    }
  }
  return errors;
}

export function foundationPayload(
  resource: Resource,
  values: FormValues,
): Record<string, unknown> {
  return Object.fromEntries(
    resource.fields.map((field) => {
      const value = values[field.key];
      if (field.kind === "select" && field.lookup)
        return [field.key, value ? Number(value) : null];
      if (field.key === "decimal_places") return [field.key, Number(value)];
      if (typeof value === "string")
        return [
          field.key,
          field.kind === "password" ? value || null : value.trim() || null,
        ];
      return [field.key, value];
    }),
  );
}

export function validDecimal(
  value: string,
  digits: number,
  scale: number,
): boolean {
  return (
    new RegExp(`^(?:0|[1-9]\\d{0,${digits - 1}})(?:\\.\\d{1,${scale}})?$`).test(
      value,
    ) && /[1-9]/.test(value)
  );
}

export function validDate(value: string): boolean {
  return (
    value >= "1000-01-01" &&
    /^\d{4}-\d{2}-\d{2}$/.test(value) &&
    Number.isFinite(Date.parse(value)) &&
    new Date(value).toISOString().slice(0, 10) === value
  );
}

export function today(timeZone = "Asia/Kabul"): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone }).format(new Date());
}
