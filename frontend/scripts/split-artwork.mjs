// One-time tool: splits the monolithic dashboard-artwork.png into per-section
// images so no section loads the whole 1.9 MB source.
// Run from frontend/: node scripts/split-artwork.mjs
import sharp from "sharp";
import { mkdir } from "node:fs/promises";

const SRC = "public/images/dashboard-artwork.png";
const OUT = "public/images";

await mkdir(OUT, { recursive: true });

// [file, left, top, width, height] in source pixels (1672x941)
const cuts = [
  ["brand-lockup.png", 0, 0, 247, 158],
  ["sidebar-story.png", 0, 690, 247, 251],
  ["sidebar-ornament-top.png", 0, 0, 75, 78],
  ["sidebar-ornament-bottom.png", 164, 550, 84, 153],
  ["hero-banner.png", 620, 61, 1052, 191],
  ["avatar.png", 1452, 9, 44, 44],
  ["province-map.png", 1004, 407, 294, 205],
  ["inventory-ring.png", 661, 431, 174, 174],
  ["footer-tagline.png", 279, 898, 300, 23],
  ["footer-pattern.png", 670, 886, 300, 45],
  ["footer-landscape.png", 1315, 882, 200, 59],
  ["footer-emblem.png", 1518, 881, 143, 41],
  ["collection-herati.png", 1226, 699, 129, 114],
  ["collection-kilim.png", 1368, 699, 132, 114],
  ["collection-turkoman.png", 1513, 699, 130, 114],
  ["flag-ae.png", 1374, 422, 30, 30],
  ["flag-tr.png", 1374, 465, 30, 30],
  ["flag-de.png", 1374, 508, 30, 30],
  ["flag-gb.png", 1374, 551, 30, 30],
  ["flag-us.png", 1374, 594, 30, 30],
];

// 26 sidebar/panel icons from reference-icon.tsx regions
const icons = {
  Dashboard: [35, 169, 27, 25],
  Inventory: [35, 220, 27, 27],
  Orders: [34, 268, 29, 26],
  Customers: [35, 318, 27, 25],
  "Weavers & Artisans": [35, 366, 28, 26],
  Shipments: [34, 415, 29, 25],
  "Export Orders": [33, 464, 31, 30],
  Warehouses: [34, 514, 28, 29],
  Analytics: [34, 564, 28, 29],
  Reports: [34, 613, 26, 29],
  Settings: [33, 664, 30, 30],
  "Total Carpets": [289, 279, 28, 37],
  "Active Orders": [460, 282, 33, 34],
  "Monthly Revenue": [639, 279, 31, 36],
  "Domestic Sales": [838, 280, 35, 37],
  "International Sales": [1020, 278, 33, 38],
  "Export Shipments": [1198, 279, 36, 38],
  "Sales Overview": [278, 381, 27, 28],
  "Inventory by Carpet Type": [671, 381, 26, 28],
  "Carpet Source by Province": [1006, 382, 23, 28],
  "Top Export Destinations": [1327, 382, 28, 28],
  "Recent Orders": [277, 663, 22, 27],
  "Top International Buyers": [814, 662, 31, 29],
  "Featured Carpet Collections": [1228, 662, 28, 28],
};

for (const [file, left, top, width, height] of cuts) {
  await sharp(SRC)
    .extract({ left, top, width, height })
    .png()
    .toFile(`${OUT}/${file}`);
  console.log(`cut ${file} ${width}x${height}`);
}

for (const [name, [left, top, width, height]] of Object.entries(icons)) {
  const file = `icon-${name.toLowerCase().replace(/[^a-z0-9]+/g, "-")}.png`;
  await sharp(SRC)
    .extract({ left, top, width, height })
    .png()
    .toFile(`${OUT}/${file}`);
  console.log(`cut ${file} ${width}x${height}`);
}

console.log("done");
