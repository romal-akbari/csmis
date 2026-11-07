import { options, selectOptions, type ProductionField, type ProductionLookups } from "@/lib/manager-production";

export function paymentFields(lookups: ProductionLookups, production = false): ProductionField[] {
  return [
    ...(production ? [{ key: "payment_type", label: "Payment type", type: "select" as const, options: [{ value: "production", label: "Production earnings" }, { value: "advance", label: "Production advance" }] }] : []),
    { key: "account_id", label: "Account", type: "select", options: lookups.accounts.map((account) => ({ value: String(account.id), label: `${account.name} · ${account.currency_code ?? lookups.currencies.find((currency) => currency.id === account.currency_id)?.code ?? ""} · ${account.balance ?? "—"}` })) },
    { key: "amount", label: "Amount", type: "decimal" }, { key: "rate_to_base", label: "Payment rate to base", type: "decimal" },
    { key: "units_per_payment_unit", label: "Document units per payment unit", type: "decimal" }, { key: "payment_on", label: "Payment date", type: "date" },
    { key: "reason", label: "Reason", type: "textarea", maxLength: 500 },
  ];
}
export function managerFields(lookups: ProductionLookups): ProductionField[] {
  return [
    { key: "code", label: "Code", maxLength: 40, optional: true }, { key: "name", label: "Name", maxLength: 150 },
    { key: "branch_id", label: "Branch", type: "select", options: selectOptions(lookups.branches) },
    { key: "phone", label: "Phone", optional: true, maxLength: 30 }, { key: "address", label: "Address", type: "textarea", optional: true, maxLength: 500 },
  ];
}
export function receiptFields(lookups: ProductionLookups, values: Record<string, string>): ProductionField[] {
  return [
    { key: "tool_id", label: "Tool", type: "select", options: selectOptions(options(lookups, "tools")) },
    { key: "branch_id", label: "Branch", type: "select", options: selectOptions(lookups.branches) },
    { key: "location_id", label: "Destination stock location", type: "select", options: selectOptions(options(lookups, "locations").filter((location) => String(location.branch_id) === values.branch_id)) },
    { key: "supplier_id", label: "Supplier", type: "select", optional: true, options: selectOptions(options(lookups, "suppliers")) },
    { key: "quantity", label: "Quantity", type: "integer" }, { key: "unit_cost", label: "Cost per unit", type: "decimal" },
    { key: "currency_id", label: "Currency", type: "select", options: selectOptions(lookups.currencies) },
    { key: "rate_to_base", label: "Rate to base currency", type: "decimal" }, { key: "received_on", label: "Receiving date", type: "date" },
  ];
}
export function assistanceFields(lookups: ProductionLookups, values: Record<string, string>): ProductionField[] {
  return [
    { key: "branch_id", label: "Branch", type: "select", options: selectOptions(lookups.branches) },
    { key: "manager_id", label: "Manager", type: "select", options: selectOptions(lookups.managers.filter((manager) => !values.branch_id || String(manager.branch_id) === values.branch_id)) },
    { key: "beneficiary_name", label: "Beneficiary name", maxLength: 150 }, { key: "beneficiary_phone", label: "Phone", optional: true, maxLength: 30 },
    { key: "assistance_type", label: "Assistance type", type: "select", options: [{ value: "advance", label: "Recoverable advance" }, { value: "bonus", label: "Non-recoverable bonus" }] },
    { key: "currency_id", label: "Currency", type: "select", options: selectOptions(lookups.currencies) },
    { key: "rate_to_base", label: "Rate to base currency", type: "decimal" }, { key: "amount", label: "Amount", type: "decimal" },
    { key: "assistance_on", label: "Date", type: "date" }, { key: "reason", label: "Reason", type: "textarea", maxLength: 500 },
    ...(values.assistance_type === "advance" ? [
      { key: "responsible_party", label: "Debtor", type: "select" as const, options: [{ value: "worker", label: "Worker" }, { value: "manager", label: "Manager" }] },
      { key: "recovery_terms", label: "Recovery terms", type: "textarea" as const, maxLength: 1000 },
    ] : []),
  ];
}
