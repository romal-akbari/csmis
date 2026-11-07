export type Order = {
  id: string;
  customer: string;
  items: number;
  amount: number;
  status: "Shipped" | "Processing" | "Completed";
  date: string;
};

export const orders: Order[] = [
  {
    id: "KO-1042",
    customer: "Zara Trading (UAE)",
    items: 12,
    amount: 4800,
    status: "Shipped",
    date: "Jun 14, 2024",
  },
  {
    id: "KO-1041",
    customer: "Kabul Home",
    items: 8,
    amount: 2160,
    status: "Processing",
    date: "Jun 13, 2024",
  },
  {
    id: "KO-1040",
    customer: "Berlin Rugs (DE)",
    items: 20,
    amount: 7600,
    status: "Completed",
    date: "Jun 12, 2024",
  },
  {
    id: "KO-1039",
    customer: "Artisan Living (UK)",
    items: 15,
    amount: 5400,
    status: "Shipped",
    date: "Jun 11, 2024",
  },
  {
    id: "KO-1038",
    customer: "Herat Gallery",
    items: 10,
    amount: 3200,
    status: "Processing",
    date: "Jun 10, 2024",
  },
  {
    id: "KO-1037",
    customer: "Global Homes (USA)",
    items: 18,
    amount: 6840,
    status: "Completed",
    date: "Jun 9, 2024",
  },
  {
    id: "KO-1036",
    customer: "Anatolia Rugs (TR)",
    items: 24,
    amount: 9120,
    status: "Shipped",
    date: "Jun 8, 2024",
  },
  {
    id: "KO-1035",
    customer: "London Carpet Co.",
    items: 9,
    amount: 3420,
    status: "Processing",
    date: "Jun 7, 2024",
  },
];

export const buyers = [
  { company: "Al Noor Trading", country: "UAE", orders: 28, total: 52400 },
  { company: "Anatolia Rugs", country: "Türkiye", orders: 22, total: 38750 },
  { company: "Rhein Wohnkultur", country: "Germany", orders: 18, total: 31200 },
  { company: "London Carpet Co.", country: "UK", orders: 15, total: 26800 },
  { company: "Global Homes", country: "USA", orders: 12, total: 22400 },
];

export const inventory = [
  { name: "Kilim", percentage: 28, color: "#203c54", count: 3494 },
  { name: "Herati", percentage: 22, color: "#465870", count: 2746 },
  { name: "Baluchi", percentage: 18, color: "#a18c7c", count: 2246 },
  { name: "Turkoman", percentage: 12, color: "#8798a1", count: 1498 },
  { name: "Modern", percentage: 10, color: "#b1b1a3", count: 1248 },
  { name: "Others", percentage: 10, color: "#d4c7b6", count: 1248 },
];

export const destinations = [
  { name: "UAE", code: "ae", share: 28 },
  { name: "Türkiye", code: "tr", share: 20 },
  { name: "Germany", code: "de", share: 16 },
  { name: "United Kingdom", code: "gb", share: 14 },
  { name: "United States", code: "us", share: 12 },
];

export const collections = [
  {
    id: "herati",
    name: "Herati Classics",
    subtitle: "Elegant & Timeless",
    region: "Herat",
    material: "Hand-knotted wool",
    price: 420,
    stock: 248,
    description:
      "Intricate floral medallions, deep indigo, and warm rust. A celebration of the generations of master weavers in Herat.",
  },
  {
    id: "kilim",
    name: "Kilim Heritage",
    subtitle: "Bold & Colorful",
    region: "Mazar-e-Sharif",
    material: "Handwoven wool",
    price: 285,
    stock: 186,
    description:
      "Expressive geometric patterns and earthy natural colors, woven by hand using traditional Afghan flat-weaving techniques.",
  },
  {
    id: "turkoman",
    name: "Turkoman Traditions",
    subtitle: "Authentic & Rare",
    region: "Northern Afghanistan",
    material: "Hand-knotted wool",
    price: 560,
    stock: 124,
    description:
      "Rich red fields and distinctive tribal motifs. Beautifully detailed carpets rooted in the enduring traditions of Turkoman artisans.",
  },
] as const;

export type SalesRange = "6m" | "3m" | "year";
export const salesData: Record<
  SalesRange,
  { labels: string[]; values: number[]; total: string; growth: string }
> = {
  "6m": {
    labels: ["Jan", "Feb", "Mar", "Apr", "May", "Jun"],
    values: [28, 40, 53, 64, 75, 96],
    total: "$287,400",
    growth: "+18%",
  },
  "3m": {
    labels: ["Apr", "May", "Jun"],
    values: [64, 75, 96],
    total: "$235,000",
    growth: "+22%",
  },
  year: {
    labels: ["Jul", "Sep", "Nov", "Jan", "Mar", "Jun"],
    values: [36, 49, 44, 58, 73, 96],
    total: "$524,800",
    growth: "+26%",
  },
};

export const money = (amount: number) =>
  new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    maximumFractionDigits: 0,
  }).format(amount);
