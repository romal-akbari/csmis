export type CustomerType = "retail" | "wholesale" | "vip" | "other";

export type CustomerStatus = "active" | "inactive";

export type Customer = {
  id: number;
  name: string;
  phone: string | null;
  address: string | null;
  customer_type: CustomerType;
  registration_date: string;
  notes: string | null;
  status: CustomerStatus;
  created_by: number | null;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
};

/** One page of the customers.index pagination response. */
export type CustomerPage = {
  data: Customer[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  company_timezone: string;
  company_today: string;
};

/** Filter state shared by the toolbar and the loader. */
export type CustomerFilters = {
  search: string;
  status: string;
  customer_type: string;
  archived: string;
};

export const EMPTY_FILTERS: CustomerFilters = {
  search: "",
  status: "",
  customer_type: "",
  archived: "",
};
