# CSMS

Carpet Sales & Management System for Sakhi Zada Uzbek Ltd.

The project has a Next.js/React dashboard in `frontend/` and a Laravel API in
`backend/`. See [the frontend guide](frontend/README.md), [the backend guide](backend/README.md),
and the [CI/CD deployment notes](docs/CI_CD_DEPLOYMENT.md) for local setup and
deployment requirements.

GitHub Actions runs frontend lint, type checks, validation tests and a production
build, along with Laravel asset builds and API tests against a temporary MySQL
database. It never connects to the live business database.
