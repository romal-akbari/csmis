"use client";

import { useId } from "react";

// Original icon silhouettes from the supplied design. The luminance mask removes
// the surrounding paper, so these assets sit cleanly on live controls.
const regions: Record<string, [number, number, number, number]> = {
  Dashboard: [0, 0, 27, 25],
  Inventory: [0, 0, 27, 27],
  Orders: [0, 0, 29, 26],
  Customers: [0, 0, 27, 25],
  "Weavers & Artisans": [0, 0, 28, 26],
  Users: [0, 0, 27, 25],
  Shipments: [0, 0, 29, 25],
  "Export Orders": [0, 0, 31, 30],
  Warehouses: [0, 0, 28, 29],
  Analytics: [0, 0, 28, 29],
  Reports: [0, 0, 26, 29],
  Settings: [0, 0, 30, 30],
  "Total Carpets": [0, 0, 28, 37],
  "Active Orders": [0, 0, 33, 34],
  "Monthly Revenue": [0, 0, 31, 36],
  "Domestic Sales": [0, 0, 35, 37],
  "International Sales": [0, 0, 33, 38],
  "Export Shipments": [0, 0, 36, 38],
  "Active Countries": [0, 0, 33, 38],
  "Low Stock Alerts": [0, 0, 30, 35],
  "Sales Overview": [0, 0, 27, 28],
  "Inventory by Carpet Type": [0, 0, 26, 28],
  "Carpet Source by Province": [0, 0, 23, 28],
  "Top Export Destinations": [0, 0, 28, 28],
  "Recent Orders": [0, 0, 22, 27],
  "Top International Buyers": [0, 0, 31, 29],
  "Featured Carpet Collections": [0, 0, 28, 28],
};

// Optical alignment within the reference sidebar rows, measured from the reference.
const sidebarOffsets: Record<string, [number, number]> = {
  Dashboard: [0.5, 0.5],
  Inventory: [0.5, 2.8125],
  Orders: [0.5, 0.625],
  Customers: [0.5, 0.4375],
  "Weavers & Artisans": [1, -0.75],
  Shipments: [0.5, -1.9375],
  "Export Orders": [0.5, -0.125],
  Warehouses: [0, -0.3125],
  Analytics: [0, 0],
  Reports: [-1, -0.6875],
  Settings: [0, 1.125],
};

export function ReferenceIcon({
  name,
  className = "",
  variant = "default",
}: {
  name: string;
  className?: string;
  variant?: "default" | "sidebar";
}) {
  const id = useId();
  const region = regions[name];
  if (!region) return null;
  const [, , width, height] = region;
  const isSidebar = variant === "sidebar";
  const [offsetX, offsetY] = sidebarOffsets[name === "Users" ? "Customers" : name] ?? [0, 0];
  const slug = (name === "Users" ? "Customers" : name)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-");
  const alpha =
    name === "Dashboard"
      ? "0.4252 1.4304 0.1444 0 -0.64"
      : "-0.4252 -1.4304 -0.1444 0 1.68";
  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      className={`reference-icon ${className}`}
      style={
        isSidebar
          ? {
              width: `${width / 16}rem`,
              height: `${height / 16}rem`,
              transform: `translate(${offsetX / 16}rem, ${offsetY / 16}rem)`,
            }
          : undefined
      }
      aria-hidden="true"
    >
      <defs>
        <filter
          id={id}
          x="0"
          y="0"
          width={width}
          height={height}
          filterUnits="userSpaceOnUse"
          colorInterpolationFilters="sRGB"
        >
          <feColorMatrix
            in="SourceGraphic"
            type="matrix"
            values={
              isSidebar
                ? `1 0 0 0 0 0 1 0 0 0 0 0 1 0 0 ${alpha}`
                : `0 0 0 0 1 0 0 0 0 1 0 0 0 0 1 ${alpha}`
            }
            result="silhouette"
          />
          {!isSidebar && (
            <>
              <feFlood floodColor="currentColor" />
              <feComposite in2="silhouette" operator="in" />
            </>
          )}
          {/* Keep empty pixels empty: while the PNG is still loading the source is
              transparent black, which the keying matrix would otherwise turn into an
              opaque black box (the "black icon" flash on refresh). */}
          <feComposite in2="SourceAlpha" operator="in" />
        </filter>
      </defs>
      <image
        href={`/images/icon-${slug}.png`}
        width={width}
        height={height}
        filter={`url(#${id})`}
      />
    </svg>
  );
}
