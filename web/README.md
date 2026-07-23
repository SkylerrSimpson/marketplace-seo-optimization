# DOWScripts web app

Laravel UI in front of the marketplace CLI scripts in `../marketplaces/`. See:

- `../README.md` / `../ARCHITECTURE.md` — what this repo is and how the two halves connect
- `CLAUDE.md` — the non-negotiable rules for changes in this app
- `CONTRIBUTING.md` — local setup, coding standards, testing
- `DEPLOYMENT.md` — production runbook
- `PLAN.md` — original design doc (superseded by the app as built; kept for history)

This is a standard Laravel 12 app (Breeze auth, queued jobs, SQLite/MySQL). No
framework internals are documented here — see [the Laravel docs](https://laravel.com/docs)
for that.
