"use client";

import {
  useEffect,
  useId,
  useLayoutEffect,
  useRef,
  useState,
  useSyncExternalStore,
  type ReactNode,
  type KeyboardEvent,
} from "react";
import { createPortal } from "react-dom";
import { FiChevronRight } from "react-icons/fi";

const subscribe = () => () => {};
const browserBody = () => document.body;
const serverBody = () => null;

export function SidebarFlyout({
  label,
  items,
  activeLabel,
  collapsed,
  locale,
  icon,
  onSelect,
}: {
  label: string;
  items: { label: string; target: string }[];
  activeLabel: string;
  collapsed: boolean;
  locale: string;
  icon: ReactNode;
  onSelect: (label: string) => void;
}) {
  const id = useId();
  const body = useSyncExternalStore(subscribe, browserBody, serverBody);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const active = items.some((item) => item.target === activeLabel);

  function position(menu: HTMLDivElement) {
    const trigger = triggerRef.current;
    if (!trigger) return;
    const sidebar = trigger.closest(".sidebar")!.getBoundingClientRect();
    const rect = trigger.getBoundingClientRect();
    const width = Math.min(260, window.innerWidth - 24);
    const measuredHeight = menu.getBoundingClientRect().height;
    const height = Math.min(
      measuredHeight || 54 + items.length * 44,
      window.innerHeight - 24,
    );
    let left = locale === "fa" ? sidebar.left - width - 8 : sidebar.right + 8;
    if (left < 12 || left + width > window.innerWidth - 12) {
      left = (window.innerWidth - width) / 2;
    }
    menu.style.left = `${left}px`;
    menu.style.top = `${Math.max(12, Math.min(rect.top, window.innerHeight - height - 12))}px`;
  }

  useLayoutEffect(() => {
    if (!open || !menuRef.current) return;
    position(menuRef.current);
  });

  useLayoutEffect(() => {
    if (!open || !menuRef.current) return;
    menuRef.current
      .querySelector<HTMLButtonElement>("button")
      ?.focus({ preventScroll: true });
  }, [open]);

  useEffect(() => {
    const menu = menuRef.current;
    if (menu?.matches(":popover-open")) menu.hidePopover();
  }, [collapsed]);

  useEffect(() => {
    if (!open) return;
    const close = () => menuRef.current?.hidePopover();
    const reposition = () => {
      if (menuRef.current?.matches(":popover-open")) position(menuRef.current);
    };
    window.addEventListener("resize", close);
    window.addEventListener("scroll", reposition, true);
    return () => {
      window.removeEventListener("resize", close);
      window.removeEventListener("scroll", reposition, true);
    };
  });

  function keyboard(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key === "Tab") {
      event.currentTarget.hidePopover();
      triggerRef.current?.focus();
      return;
    }
    const buttons = Array.from(
      event.currentTarget.querySelectorAll<HTMLButtonElement>("button"),
    );
    const index = buttons.indexOf(document.activeElement as HTMLButtonElement);
    let next: number | undefined;
    if (event.key === "ArrowDown") next = (index + 1) % buttons.length;
    if (event.key === "ArrowUp")
      next = (index - 1 + buttons.length) % buttons.length;
    if (event.key === "Home") next = 0;
    if (event.key === "End") next = buttons.length - 1;
    if (next !== undefined) {
      event.preventDefault();
      buttons[next]?.focus();
    }
    if (event.key === "Escape") {
      event.preventDefault();
      event.stopPropagation();
      event.currentTarget.hidePopover();
      triggerRef.current?.focus();
    }
  }

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        className={`nav-item has-submenu${active ? " active" : ""}`}
        aria-label={label}
        title={collapsed ? label : undefined}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={id}
        popoverTarget={id}
      >
        {icon}
        <span className="sidebar-nav-label" lang={locale}>
          {label}
        </span>
        <FiChevronRight
          className="sidebar-submenu-chevron"
          aria-hidden="true"
        />
      </button>
      {body &&
        createPortal(
          <div
            ref={menuRef}
            id={id}
            popover="auto"
            role="menu"
            className="sidebar-flyout"
            aria-labelledby={`${id}-title`}
            dir={locale === "fa" ? "rtl" : "ltr"}
            lang={locale}
            onBeforeToggle={(event) => {
              if (event.newState === "open") position(event.currentTarget);
            }}
            onToggle={(event) => setOpen(event.newState === "open")}
            onKeyDown={keyboard}
          >
            <p className="sidebar-flyout-title" id={`${id}-title`}>
              {label}
            </p>
            {items.map((item) => (
              <button
                key={item.target}
                type="button"
                role="menuitem"
                tabIndex={-1}
                className="sidebar-flyout-item"
                aria-current={activeLabel === item.target ? "page" : undefined}
                onClick={() => {
                  menuRef.current?.hidePopover();
                  onSelect(item.target);
                }}
              >
                {item.label}
              </button>
            ))}
          </div>,
          body,
        )}
    </>
  );
}
