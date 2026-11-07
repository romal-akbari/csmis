import type { Metadata } from "next";
import "@fontsource-variable/inter";
import "@fontsource-variable/vazirmatn";
import "./globals.css";
import "./reference.css";
import "./responsive.css";
import "./customers.css";
import "./sidebar.css";
import "./topbar.css";
import "./themes.css";
import { StoreProvider } from "@/components/store-provider";
import { themeBootstrap } from "@/lib/theme-preference";

export const metadata: Metadata = {
  title: "Dashboard | Sakhi Zada Uzbek Ltd",
  description:
    "Carpet sales management for Sakhi Zada Uzbek Ltd. Afghan craftsmanship, connected to the world.",
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en" dir="ltr" data-theme="light" suppressHydrationWarning>
      <head>
        <script id="dashboard-theme" dangerouslySetInnerHTML={{ __html: themeBootstrap }} />
      </head>
      <body>
        <StoreProvider>{children}</StoreProvider>
      </body>
    </html>
  );
}
