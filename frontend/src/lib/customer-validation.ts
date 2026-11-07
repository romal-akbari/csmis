/**
 * Client-side mirror of the backend customer rules
 * (StoreCustomerRequest). Both sides reject exactly the same input.
 */

export type CustomerFormValues = {
  name: string;
  phone: string;
  address: string;
  customer_type: string;
  registration_date: string;
  notes: string;
  status: string;
};

export type CustomerFieldErrors = Partial<
  Record<keyof CustomerFormValues, string>
>;

export const CUSTOMER_TYPES: string[] = [
  "retail",
  "wholesale",
  "vip",
  "other",
];

export const CUSTOMER_STATUSES: string[] = ["active", "inactive"];

const PHONE_PATTERN = /^[+0-9()\- ]{7,30}$/;

const LIMITS = {
  name: 150,
  phone: 30,
  address: 255,
  notes: 2000,
} as const;

/** Business date, independent of the browser's timezone. */
export function todayIso(timezone = "Asia/Kabul", now = new Date()): string {
  const parts = new Intl.DateTimeFormat("en-US", {
    timeZone: timezone, year: "numeric", month: "2-digit", day: "2-digit",
  }).formatToParts(now);
  const value = (type: string) => parts.find((part) => part.type === type)!.value;
  return `${value("year")}-${value("month")}-${value("day")}`;
}

export function validCustomerDate(value: string): boolean {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value) || value < "1000-01-01") return false;
  const date = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value;
}

/** Blank form values with sensible defaults. */
export function emptyCustomer(today = todayIso()): CustomerFormValues {
  return {
    name: "",
    phone: "",
    address: "",
    customer_type: "retail",
    registration_date: today,
    notes: "",
    status: "active",
  };
}

/**
 * Validate the customer form. Returns {} when the values are valid,
 * otherwise one message per offending field (rendered inline).
 */
export function validateCustomer(
  values: CustomerFormValues,
  today = todayIso(),
): CustomerFieldErrors {
  const errors: CustomerFieldErrors = {};
  const name = values.name.trim();
  const phone = values.phone.trim();

  if (!name) {
    errors.name = "Customer name is required.";
  } else if ([...name].length < 2) {
    errors.name = "Customer name must be at least 2 characters.";
  } else if ([...name].length > LIMITS.name) {
    errors.name = "Customer name may not exceed 150 characters.";
  }

  if (phone.length > LIMITS.phone) {
    errors.phone = "Phone may not exceed 30 characters.";
  } else if (phone && !PHONE_PATTERN.test(phone)) {
    errors.phone = "Use digits, spaces, +, - and ( ) only.";
  }

  if ([...values.address.trim()].length > LIMITS.address) {
    errors.address = "Address may not exceed 255 characters.";
  }

  if (!CUSTOMER_TYPES.includes(values.customer_type)) {
    errors.customer_type = "Choose a valid customer type.";
  }

  const date = values.registration_date;
  if (!date) {
    errors.registration_date = "Registration date is required.";
  } else if (!validCustomerDate(date)) {
    errors.registration_date = "Enter a valid date.";
  } else if (date > today) {
    errors.registration_date = "Date cannot be in the future.";
  }

  if ([...values.notes.trim()].length > LIMITS.notes) {
    errors.notes = "Notes may not exceed 2000 characters.";
  }

  if (!CUSTOMER_STATUSES.includes(values.status)) {
    errors.status = "Choose a valid status.";
  }

  return errors;
}
