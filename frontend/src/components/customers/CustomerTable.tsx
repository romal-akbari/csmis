"use client";

import { customerText } from "@/lib/customer-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { StatusBadge, TypeBadge } from "./CustomerBadge";
import type { Customer } from "./types";

type Props = {
  customers: Customer[];
  confirmId: number | null;
  loading: boolean;
  busyId: number | null;
  canEdit?: boolean;
  canDelete?: boolean;
  onEdit: (customer: Customer) => void;
  onDelete: (customer: Customer) => void;
  onCancelDelete: () => void;
};
const COLUMNS = ["Name", "Phone", "Type", "Status", "Registered", "Actions"];

export function CustomerTable({
  customers,
  confirmId,
  loading,
  busyId,
  canEdit = false,
  canDelete = false,
  onEdit,
  onDelete,
  onCancelDelete,
}: Props) {
  const locale = useAppSelector((state) => state.dashboard.locale);
  const t = (text: string) => customerText(locale, text);
  if (loading)
    return (
      <p className="customers-loading" role="status">
        {t("Loading customers...")}
      </p>
    );
  if (!customers.length)
    return (
      <p className="empty-table">{t("No customers match your search.")}</p>
    );
  const showActions = canEdit || canDelete;
  return (
    <div
      className="customers-table-wrap"
      role="region"
      aria-label={t("Customer records")}
      tabIndex={0}
    >
      <table className="customers-table">
        <thead>
          <tr>
            {(showActions ? COLUMNS : COLUMNS.slice(0, -1)).map((column) => (
              <th key={column} scope="col">
                {t(column)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {customers.map((customer) => {
            const archived = Boolean(customer.deleted_at);
            const confirming = confirmId === customer.id;
            return (
              <tr key={customer.id}>
                <td className="customers-name">{customer.name}</td>
                <td dir="ltr">{customer.phone ?? "—"}</td>
                <td>
                  <TypeBadge value={customer.customer_type} />
                </td>
                <td>
                  <StatusBadge value={customer.status} />
                </td>
                <td dir="ltr">{customer.registration_date}</td>
                {showActions && (
                  <td>
                    <div className="customers-actions">
                      {canEdit && (
                        <button
                          className="customers-action"
                          type="button"
                          disabled={busyId !== null}
                          onClick={() =>
                            archived ? onDelete(customer) : onEdit(customer)
                          }
                        >
                          {t(
                            busyId === customer.id
                              ? "Working..."
                              : archived
                                ? "Restore"
                                : "Edit",
                          )}
                        </button>
                      )}
                      {!archived && canDelete && (
                        <button
                          className={`customers-action ${confirming ? "danger" : ""}`}
                          type="button"
                          disabled={busyId !== null}
                          onClick={() => onDelete(customer)}
                        >
                          {t(
                            busyId === customer.id
                              ? "Working..."
                              : confirming
                                ? "Confirm archive?"
                                : "Archive",
                          )}
                        </button>
                      )}
                      {confirming && (
                        <button
                          className="customers-action"
                          type="button"
                          disabled={busyId !== null}
                          onClick={onCancelDelete}
                        >
                          {t("Cancel")}
                        </button>
                      )}
                    </div>
                  </td>
                )}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
