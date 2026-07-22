# DOWScripts — deployment runbook

DOWScripts is a Laravel app that shells out to the marketplace CLI scripts in the
parent repo. Beyond a normal web server, it needs **two always-on background
processes** and a handful of production `.env` values. Missing any of these is the
difference between "runs execute and recover" and "runs sit Pending/Running
forever."

## 1. Queue worker (required — runs do nothing without it)

Every script run is a queued job (`QUEUE_CONNECTION=database`). If no worker is
running, submitted runs stay **Pending** indefinitely.

Run a supervised worker (systemd shown; supervisor equivalent is fine):

```ini
# /etc/systemd/system/dowscripts-queue.service
[Unit]
Description=DOWScripts queue worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/var/www/dowscripts/web
# --tries=1: never auto-retry. These jobs can write live to a marketplace; a
# silent retry could double-write. A failed run is surfaced in the UI to re-run
# deliberately instead.
ExecStart=/usr/bin/php8.2 artisan queue:work --tries=1 --timeout=3700
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`--timeout=3700` must stay **above** `RunScriptJob::TIMEOUT_SECONDS` (3600) so the
worker's own watchdog never kills a run before the job's own timeout does.

If the worker is killed mid-run (deploy, OOM), that run is left Running — the
scheduler's `runs:reap-stuck` (below) recovers it.

## 2. Scheduler (required — recovers orphaned runs, runs automations)

```
* * * * * cd /var/www/dowscripts/web && php8.2 artisan schedule:run >> /dev/null 2>&1
```

or a supervised `php8.2 artisan schedule:work`. This drives:

- `runs:reap-stuck` — every 5 min, fails runs orphaned by a dead worker.

## 3. Production `.env`

| Key | Dev | Production |
|-----|-----|------------|
| `APP_ENV` | `local` | `production` |
| `APP_DEBUG` | `true` | **`false`** — `true` leaks stack traces and decrypted secrets |
| `APP_URL` | `http://localhost` | the real https URL — OAuth redirect URIs derive from it |
| `APP_KEY` | set | set (keep stable — it decrypts stored marketplace credentials) |
| `MAIL_MAILER` | `log` | **a real SMTP transport** — see the hard blocker below |
| `SESSION_SECURE_COOKIE` | — | `true` behind https |

After changing `.env` in production: `php8.2 artisan config:cache`.

### 🔴 Mail is a hard requirement, not a nice-to-have

Users self-register and **must verify their email before any page of the app is
usable** (every route is behind the `verified` middleware). With `MAIL_MAILER=log`
the verification email is only written to the log — **nobody can verify, so nobody
can get in.** Configure a real transport before launch:

```
MAIL_MAILER=smtp
MAIL_HOST=<your smtp host>
MAIL_PORT=587
MAIL_USERNAME=<...>
MAIL_PASSWORD=<...>
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS="noreply@yourdomain.com"
MAIL_FROM_NAME="DOWScripts"
```

`APP_URL` must also be the real https URL — the verification link is built from it,
so a wrong value produces links that 404.

Safety valve if a verification email is ever lost: an admin can manually confirm a
user from **Admin → Users → Verify**, so a flaky mailer can't permanently lock
someone out.

## 3a. Database — don't ship SQLite under load

The `.env.example` defaults to `DB_CONNECTION=sqlite`, and sessions, cache, AND the
queue all default to the database (`SESSION_DRIVER=database`, `CACHE_STORE=database`,
`QUEUE_CONNECTION=database`). That's four write-heavy workloads plus the web app, the
queue worker, and the scheduler all hitting **one SQLite file concurrently** — a
recipe for `SQLITE_BUSY` lock errors once more than one person uses it.

For production use **MySQL or PostgreSQL** (`DB_CONNECTION=mysql` + `DB_HOST`/
`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`). If you must stay on SQLite for a tiny
single-user deployment, at least enable WAL mode. Redis for `CACHE_STORE`/
`SESSION_DRIVER`/`QUEUE_CONNECTION` is also a good move but not required.

## 4. One-time per deploy

```
# Run from web/. There are TWO Composer trees: the marketplace scripts' deps at the
# repo root, and this Laravel app's deps here.
composer install --no-dev --optimize-autoloader --working-dir=..   # scripts (repo root)
composer install --no-dev --optimize-autoloader                    # web app (this dir)
php8.2 artisan migrate --force
npm ci && npm run build
php8.2 artisan config:cache route:cache view:cache
sudo systemctl restart dowscripts-queue     # pick up new code in the worker
```

Restarting the worker on deploy matters: a long-lived `queue:work` process holds
the *old* code until restarted.

**Do not run `php artisan db:seed` in production** — it creates a `test@example.com`
admin with a known factory password. That seeder is a dev convenience only.

## 5. Creating the first admin

There is no seeded admin in production, and the `/admin` area can only be reached by
an existing admin — so bootstrap the first one from the CLI:

1. Have the person register normally at `/register`.
2. From the server, promote them:

   ```
   php8.2 artisan users:make-admin their-email@yourdomain.com
   ```

   This also marks them active and email-verified, so they can sign in even before
   SMTP is fully wired. From then on they can manage everyone else from **Admin →
   Users** — no more CLI needed.
