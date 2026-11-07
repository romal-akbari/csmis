"use client";

import { useEffect, useRef, useState, useSyncExternalStore } from "react";
import {
  apiFetch,
  isApiFailure,
  readToken,
  serverToken,
  subscribeToken,
  writeToken,
} from "@/lib/api";
import type { CustomerFormValues } from "@/lib/customer-validation";
import { customerText } from "@/lib/customer-i18n";
import { useAppSelector } from "@/store/dashboard-store";
import { CustomerFilterBar } from "./CustomerFilters";
import { CustomerForm } from "./CustomerForm";
import { CustomerTable } from "./CustomerTable";
import { LoginPanel } from "./LoginPanel";
import {
  EMPTY_FILTERS,
  type Customer,
  type CustomerFilters,
  type CustomerPage,
} from "./types";

type Props = { canCreate?: boolean; canUpdate?: boolean; canDelete?: boolean };
const PER_PAGE = 10;

/** Customers are a shared master directory; future transactions own branch scope. */
export function CustomersScreen({
  canCreate = false,
  canUpdate = false,
  canDelete = false,
}: Props) {
  const token = useSyncExternalStore(subscribeToken, readToken, serverToken);
  const locale = useAppSelector((state) => state.dashboard.locale);
  const t = (text: string) => customerText(locale, text);
  const [page, setPage] = useState(1);
  const [refresh, setRefresh] = useState(0);
  const [filters, setFilters] = useState<CustomerFilters>(EMPTY_FILTERS);
  const [rows, setRows] = useState<CustomerPage | null>(null);
  const [loadedKey, setLoadedKey] = useState("");
  const [banner, setBanner] = useState("");
  const [flash, setFlash] = useState("");
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Customer | null>(null);
  const [confirmId, setConfirmId] = useState<number | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const actionBusy = useRef(false);
  const query = new URLSearchParams({
    page: String(page),
    per_page: String(PER_PAGE),
  });
  for (const [key, value] of Object.entries(filters))
    if (value) query.set(key, value);
  const queryString = query.toString();
  const queryKey = `${token}:${queryString}:${refresh}`;
  const loading = loadedKey !== queryKey;

  useEffect(() => {
    if (!token) return;
    let cancelled = false;
    apiFetch<CustomerPage>(`/customers?${queryString}`)
      .then((data) => {
        if (cancelled) return;
        // A concurrent archive can empty the final page. Reload the last real page.
        if (page > data.last_page) {
          setPage(Math.max(1, data.last_page));
          return;
        }
        setRows(data);
        setLoadedKey(queryKey);
        setBanner("");
      })
      .catch((error: unknown) => {
        if (cancelled) return;
        if (isApiFailure(error) && error.status === 401) {
          writeToken(null);
          return;
        }
        setLoadedKey(queryKey);
        setRows(null);
        setBanner(
          isApiFailure(error)
            ? error.message
            : "Could not reach the API server.",
        );
      });
    return () => {
      cancelled = true;
    };
  }, [token, queryString, queryKey, page]);

  function changeFilters(next: CustomerFilters) {
    setFilters(next);
    setPage(1);
    setConfirmId(null);
    setFlash("");
  }

  async function save(values: CustomerFormValues) {
    if (editing ? !canUpdate : !canCreate) return;
    const payload = {
      name: values.name.trim(),
      phone: values.phone.trim() || null,
      address: values.address.trim() || null,
      customer_type: values.customer_type,
      registration_date: values.registration_date,
      notes: values.notes.trim() || null,
      status: values.status,
    };
    await apiFetch(editing ? `/customers/${editing.id}` : "/customers", {
      method: editing ? "PUT" : "POST",
      body: JSON.stringify(payload),
    });
  }

  async function archiveOrRestore(customer: Customer) {
    const archived = Boolean(customer.deleted_at);
    if (actionBusy.current || (archived ? !canUpdate : !canDelete)) return;
    if (!archived && confirmId !== customer.id) {
      setConfirmId(customer.id);
      return;
    }
    actionBusy.current = true;
    setBusyId(customer.id);
    setBanner("");
    try {
      await apiFetch(`/customers/${customer.id}${archived ? "/restore" : ""}`, {
        method: archived ? "POST" : "DELETE",
      });
      setConfirmId(null);
      setFlash(
        archived ? "Customer restored as inactive." : "Customer archived.",
      );
      setRefresh((count) => count + 1);
    } catch (error) {
      if (isApiFailure(error) && error.status === 401) writeToken(null);
      setBanner(
        isApiFailure(error)
          ? error.message
          : archived
            ? "Could not restore customer."
            : "Could not archive customer.",
      );
    } finally {
      actionBusy.current = false;
      setBusyId(null);
    }
  }

  if (!token)
    return <LoginPanel onLogin={(result) => writeToken(result.token)} />;
  const total = rows?.total ?? 0;
  const lastPage = rows?.last_page ?? 1;

  return (
    <section
      className="customers-screen"
      lang={locale}
      dir={locale === "fa" ? "rtl" : "ltr"}
    >
      <header className="customers-head">
        <div>
          <p className="customers-eyebrow">{t("CUSTOMER DIRECTORY")}</p>
          <h1>{t("Customers")}</h1>
          <p className="customers-muted">
            {loading ? t("Loading...") : `${total} ${t("records")}`}
          </p>
        </div>
        <div className="customers-head-actions">
          {canCreate && !filters.archived && (
            <button
              className="primary-button"
              type="button"
              disabled={loading || !rows}
              onClick={() => {
                setEditing(null);
                setFormOpen(true);
              }}
            >
              {t("+ Add customer")}
            </button>
          )}
        </div>
      </header>
      {flash && (
        <p className="customers-banner flash" role="status">
          {t(flash)}
        </p>
      )}
      {banner && (
        <div className="customers-banner error" role="alert">
          {t(banner)}{" "}
          <button
            className="customers-action"
            type="button"
            onClick={() => setRefresh((count) => count + 1)}
          >
            {t("Retry")}
          </button>
        </div>
      )}
      <CustomerFilterBar filters={filters} onChange={changeFilters} />
      <div className="customers-panel" aria-busy={loading}>
        <CustomerTable
          customers={rows?.data ?? []}
          confirmId={confirmId}
          loading={loading}
          busyId={busyId}
          canEdit={canUpdate}
          canDelete={canDelete}
          onEdit={(customer) => {
            setConfirmId(null);
            setEditing(customer);
            setFormOpen(true);
          }}
          onDelete={archiveOrRestore}
          onCancelDelete={() => setConfirmId(null)}
        />
      </div>
      {lastPage > 1 && (
        <nav className="customers-pager" aria-label={t("Pagination")}>
          <button
            className="customers-action"
            type="button"
            disabled={loading || page <= 1}
            onClick={() => setPage((value) => value - 1)}
          >
            {t("Previous")}
          </button>
          <span>
            {t("Page")} {page} {t("of")} {lastPage}
          </span>
          <button
            className="customers-action"
            type="button"
            disabled={loading || page >= lastPage}
            onClick={() => setPage((value) => value + 1)}
          >
            {t("Next")}
          </button>
        </nav>
      )}
      {formOpen && rows && (
        <CustomerForm
          key={editing?.id ?? "new"}
          initial={editing}
          today={rows.company_today}
          onCancel={() => {
            setFormOpen(false);
            setEditing(null);
          }}
          onSaved={() => {
            setFormOpen(false);
            setEditing(null);
            setFlash(editing ? "Customer updated." : "Customer added.");
            setRefresh((count) => count + 1);
          }}
          onSubmit={save}
        />
      )}
    </section>
  );
}
