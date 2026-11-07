import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  distDir: process.env.NEXT_DIST_DIR ?? ".next",
  poweredByHeader: false,
  devIndicators: false,
  allowedDevOrigins: ["127.0.0.1"],
  // Port 8002 keeps this API separate from other local Laravel projects.
  async rewrites() {
    return [
      {
        source: "/api/:path*",
        destination: `${process.env.API_ORIGIN ?? "http://127.0.0.1:8002"}/api/:path*`,
      },
    ];
  },
};

export default nextConfig;
