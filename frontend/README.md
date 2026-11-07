# Sakhi Zada Uzbek Ltd — Carpet Sales Dashboard

A carpet management dashboard built with Next.js, React, TypeScript and Redux Toolkit, connected to the Laravel API in `../backend`.

## Run locally

Requires Node.js 20.9 or newer.

Start the Laravel API in a terminal and keep it running:

```powershell
cd D:\CSMS\backend
C:\xampp\php\php.exe artisan serve --host=127.0.0.1 --port=8002
```

Start the dashboard in a second terminal:

```powershell
cd D:\CSMS\frontend
npm install
npm run dev
```

Open **http://localhost:3100**. Login and dashboard data require both servers. The default API address is `http://127.0.0.1:8002`; `API_ORIGIN` in `.env.local` overrides it. The configured address must match the port where you start the CSMS backend. Restart Next.js after changing that variable. Check `http://localhost:3100/api/health` to verify that the response identifies the **Carpet Sales** application.

If you run the CSMS backend on port **8001**, keep this in `frontend/.env.local`:

```dotenv
API_ORIGIN=http://127.0.0.1:8001
```

Start that backend with `php artisan serve --host=127.0.0.1 --port=8001` and restart `npm run dev` after changing the local setting. A mismatch between this port and the running API causes the temporary sign-in unavailable message. `.env.local` is local to this workspace and is not committed.

Start with the [current company production workflow](../docs/COMPANY_PRODUCTION_WORKFLOW.md), covering supply purchases, manager production, washing, stock and payments. Supporting references include [the foundation handover](../docs/PHASE1_HANDOVER.md), [simple production workflow](../docs/SIMPLE_PRODUCTION_WORKFLOW.md) and [manager production reference](../docs/MANAGER_PRODUCTION_WORKFLOW.md). Earlier carpet-purchasing workflows are available as read-only history.

```powershell
npm run lint
npm run typecheck
npm run build
npm start
```

Stop an existing preview server before starting another server on the same port. To choose another port, use `npm run dev -- --port 3200`.

## Original design reference

- Reference-matched sidebar, hero, six metric cards, sales chart, inventory chart, province map, export destinations, order and buyer tables, carpet collections, and footer.
- Local search for orders, customers, and collections, including a clearable order-table filter.
- Redux-managed sales periods, search, language selection, and mobile navigation.
- Notifications, account menu, collection and order details, and static previews for sidebar sections.
- Dari labels for navigation, metrics, welcome text, and search. Analytical tables and detail dialogs currently use English.
- Responsive desktop, tablet, and phone layouts; keyboard-operable dialogs, visible focus indicators, and reduced-motion support.

The application now uses authenticated API requests and persistent database records. Automated practice fixtures run in separate test databases.

## Structure

- `src/app/` — Next.js page, root layout, base styles, reference-matching styles, and favicon. Desktop uses responsive dimensions based on the available viewport, with shared typography and spacing for the sidebar and content. No CSS zoom or fixed-width canvas is used; tablets and phones reflow.
- `src/components/dashboard.tsx` — dashboard presentation and preview interactions.
- `src/components/reference-icon.tsx` — original icon silhouettes isolated with SVG luminance masks.
- `src/components/store-provider.tsx` — a Redux store scoped to each rendered app instance.
- `src/store/dashboard-store.ts` — typed Redux state, actions, and hooks.
- `src/lib/dashboard-data.ts` — typed sample data, ready to replace with Laravel API responses.
- `public/images/dashboard-artwork.png` — original user-supplied reference; SVG viewports reuse its branding, hero illustration, map, profile photo, and carpet artwork. Navigation, metrics, sales chart, tables, controls, inventory total, and inventory legend are rendered components. The static inventory ring preserves the source illustration; replace this illustration with a data-driven chart when connecting inventory API data.

The Laravel application is in `../backend`. Existing credentials are preserved; starting the servers does not seed or reset business data.
