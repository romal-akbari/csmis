# CI/CD setup

The repository now has a GitHub Actions CI workflow in `.github/workflows/ci.yml`.
For each push or pull request, it installs locked dependencies, runs the frontend
lint, type checks and validation tests, builds the Next.js app, builds Laravel's
Vite assets, prepares a disposable MySQL database named `csms_phase1_test`, and
runs the Laravel API tests. The CI database is created inside the workflow's
temporary MySQL service; it does not connect to the business database.

## What is needed to turn on deployment

This workspace copy currently has no `.git` directory and no Git remote, so a
push cannot trigger GitHub Actions yet. Connect this folder to the intended
private GitHub repository and push the workflow files to it. Keep `backend/.env`
and `frontend/.env.local` out of Git; deployment credentials belong in GitHub
Actions secrets, never in source files.

The app is a Next.js frontend that proxies `/api` requests to a Laravel API. A
cPanel deployment therefore needs PHP 8.2 or newer, MySQL/MariaDB, SSH access,
and a cPanel Node.js application runtime. The current cPanel browser session was
not available for this inspection, so those hosting features are not yet
verified. The existing `public_html` contains the WordPress site; deploy CSMS to
a separate app/API subdomain and application directory, not over that folder.

Before enabling an automatic production deployment, decide the app and API
subdomains, confirm the cPanel Node.js runtime and SSH access, and create the
production database and environment files. The frontend's `API_ORIGIN` must point
to the deployed Laravel API. Once those target details are confirmed, the CD job
can be added with its exact paths and restart method; no production files or
database have been changed by this setup.

## Run the same checks locally

```powershell
cd D:\CSMS\frontend
npm ci
npm run lint
npm run typecheck
npm test
$env:API_ORIGIN = 'https://api.example.invalid'
npm run build

cd D:\CSMS\backend
composer validate --no-check-publish
composer install --no-interaction --prefer-dist
npm ci
npm run build
```

The Laravel feature tests require a disposable MySQL database. Use the guarded
`backend/scripts/prepare-test-database.php csms_phase1_test` helper and the
project's test database settings; do not point it at a production database.
