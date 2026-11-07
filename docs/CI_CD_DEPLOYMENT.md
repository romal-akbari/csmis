# cPanel CI/CD deployment

The production deployment is isolated from the existing WordPress site. The
primary domain uses `/public_html`. cPanel is currently restricting all domain
document roots to that directory, so the Next.js app will run as a Node.js app
from its private application root and the API domain will expose only a symlink
to Laravel's `public` folder. Laravel source, `.env`, and dependencies stay
outside the web document root.

## Target layout

| Purpose | Public address | cPanel path |
| --- | --- | --- |
| Next.js app | `https://app.sakhizadacarpet.com` | Node app root: `/home/sakhizada/apps/csms/frontend`; cPanel domain root is `/home/sakhizada/public_html/apps/csms/frontend` |
| Laravel API | `https://api.sakhizadacarpet.com` | App root: `/home/sakhizada/apps/csms/backend`; cPanel domain root: `/home/sakhizada/public_html/apps/csms/backend/public`, linked to the private app's `public` folder |
| Production MySQL | Private cPanel database | Fresh schema; do not import `carpet_sales.sql` |

The existing main website and its files are left in place. The two subdomains
have been created and DNS and HTTPS are resolving to the cPanel host. cPanel
offers Node.js `24.22.1` and PHP `8.2`. The production database, runtimes,
symlink, and CI credentials still need to be configured before deployment can
be enabled.

## Automated checks and deployment

`.github/workflows/ci.yml` runs the frontend checks, Laravel tests against an
isolated MySQL service, and production builds. On `main`, it creates deploy
artifacts only after the repository variable `CPANEL_DEPLOY_ENABLED` is set to
`true`. The deploy job sends the standalone Next.js bundle and Laravel files to
the separate `frontend/` and `backend/` directories using FTPS on port 21, then
checks the public API health route and app response. It excludes environment
files, SQL dumps, SQLite files, and Laravel runtime storage.

Create a directory-scoped cPanel FTP account whose home is
`/home/sakhizada/apps/csms`, then add these GitHub repository secrets:

- `CPANEL_FTP_SERVER` — the cPanel FTP hostname
- `CPANEL_FTP_USERNAME` — the directory-scoped FTP account
- `CPANEL_FTP_PASSWORD` — its password

Keep these values in GitHub Secrets; never add them to the repository. Enable
`CPANEL_DEPLOY_ENABLED=true` only after the domains, Node.js app, API runtime,
database, and FTP account are ready. The first automatic deployment then runs on
the next successful push to `main` (or a manual run on `main`).

## One-time production setup

1. The subdomains already exist. Keep their current cPanel document roots under
   `/home/sakhizada/public_html/apps/csms/`. For the API, create the isolated
   directory `/home/sakhizada/public_html/apps/csms/backend/` and symlink its
   `public` entry to `/home/sakhizada/apps/csms/backend/public`. Check that the
   destination is an empty directory before replacing it with a symlink. Do not
   place Laravel source, `.env`, or `vendor/` under `/public_html`.
2. Create the cPanel Node.js application with Node.js `24.22.1`, production
   mode, application root `apps/csms/frontend`, URL `app.sakhizadacarpet.com`,
   and startup file `server.js`. The Node app proxy serves requests for the app
   subdomain; the placeholder document-root directory does not contain the
   application bundle.
3. Create a new production database and a separate database user with access to
   that database only. Configure Laravel's private `backend/.env` on the server
   with the database connection, a generated `APP_KEY`, `APP_ENV=production`,
   `APP_DEBUG=false`, and `APP_URL=https://api.sakhizadacarpet.com`.
4. Create the Laravel runtime directories and link its public storage from the
   cPanel Terminal. Run `php artisan migrate --force` against the new empty
   schema, then seed the administrator and role data using a strong
   `FOUNDATION_ADMIN_PASSWORD`. Remove that temporary seed variable after the
   seed completes. Do not create business test records in production.
5. Confirm HTTPS certificates are active for both subdomains, create the
   directory-scoped FTP account, add the three GitHub secrets, and set the
   repository variable `CPANEL_DEPLOY_ENABLED=true`.

Use cPanel's PHP Selector to ensure PHP 8.2 has `pdo_mysql`, `bcmath`, `ctype`,
`curl`, `fileinfo`, `intl`, `mbstring`, `tokenizer`, `xml`, and `zip` enabled.
The exact first-admin password is entered by the account owner during setup; it
must not be committed or sent through chat.

## Database migrations and smoke testing

The FTPS job deploys files and restarts the Node.js app by uploading
`frontend/tmp/restart.txt`. It cannot run Laravel CLI commands. Until a
restricted SSH deployment path is available, apply each new Laravel migration
from the cPanel Terminal with `php artisan migrate --force` after deployment.
The workflow intentionally does not import `carpet_sales.sql`, seed business
records, or run migrations against an unknown database.

After first deployment, verify:

- `https://api.sakhizadacarpet.com/api/health` returns JSON with `status: ok`.
- `https://app.sakhizadacarpet.com` serves the login page over HTTPS.
- Sign in with the newly seeded administrator account, then confirm the fresh
  system opens without business test data.

The existing root WordPress website is outside the deployment and smoke-test
targets.
