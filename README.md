# ShadApp — Backend (Laravel, MySQL variant)

REST API + WebSocket server for ShadApp. Serves both the Next.js dashboard
(`shadapp-dashboard`) and the Flutter app (`shadapp-mobile`).

> **About this copy.** This is a parallel copy of `shadapp-backend` configured
> to run on MySQL/MariaDB instead of PostgreSQL. The application code is
> identical and deliberately database-agnostic — the only differences are
> defaults in `config/database.php` and `.env`. Any change made here must be
> mirrored in `shadapp-backend` (and vice versa) to keep the two from drifting.

---

## Requirements

| Tool     | Version | Notes                                                    |
| -------- | ------- | -------------------------------------------------------- |
| PHP      | 8.3+    | with `pdo_mysql`, `mbstring`, `gd`, `zip`, `bcmath`       |
| Composer | 2.x     |                                                          |
| Database | —       | MySQL 8.0+ or MariaDB 10.6+                               |

See [`docs/DATABASE-PORTABILITY.md`](docs/DATABASE-PORTABILITY.md) for the full
list of portability decisions and engine-specific behaviour.

### MySQL-specific notes

- **Tables must be InnoDB.** `config/database.php` sets `'engine' => 'InnoDB'`
  explicitly. If MySQL/MariaDB falls back to MyISAM you get
  `Specified key was too long; max key length is 1000 bytes` during migration —
  and, more seriously, MyISAM silently ignores foreign keys, which this schema
  depends on.
- **String length.** `AppServiceProvider` calls `Schema::defaultStringLength(191)`
  on MySQL only, to stay within index limits under `utf8mb4`.
- **Auth plugin.** MySQL 8.4 removed `mysql_native_password`. If connections are
  rejected with a plugin error, switch the user over:
  ```sql
  ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '';
  FLUSH PRIVILEGES;
  ```
- **Host matters.** `user@localhost` and `user@127.0.0.1` are *different* MySQL
  accounts. Make sure the one you granted matches `DB_HOST` in `.env`.

---

## First-time setup

```sql
CREATE DATABASE shadapp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set the `DB_*` block (`DB_CONNECTION=mysql`). Then:

```bash
php artisan migrate
php artisan db:seed        # optional — demo data, see below
php artisan storage:link   # required for uploaded files to be reachable
```

`php artisan storage:link` is easy to forget and its absence is confusing:
uploads succeed, but every returned file URL 404s.

### Demo accounts (only after `db:seed`)

| Role        | Email                 | Password   |
| ----------- | --------------------- | ---------- |
| Super Admin | admin@shadapp.com     | `password` |
| Manager     | manager@shadapp.com   | `password` |
| Client      | client@shadapp.com    | `password` |

Seeded data is for local development only — never seed a production database.

---

## Running it

The app needs **four** processes. Only the first serves HTTP; skipping any of
the others fails silently rather than loudly, which is the main thing this
section exists to prevent.

Each command runs in its own terminal:

```bash
# 1. API server — without this, nothing works at all.
php artisan serve

# 2. Queue worker — emails and notifications are queued (ShouldQueue).
#    Without it they are written to the queue and never delivered. No error
#    appears anywhere; mail simply never arrives.
php artisan queue:work

# 3. WebSocket server — powers live chat and realtime updates.
#    Without it the apps still work, but nothing updates until a manual refresh.
php artisan reverb:start

# 4. Scheduler — contract/meeting/payment/birthday reminders.
#    Local dev only; in production use a real cron entry (see below).
php artisan schedule:work
```

### Scheduled jobs

Defined in `routes/console.php`:

| Command                    | Frequency        |
| -------------------------- | ---------------- |
| `contracts:send-reminders` | daily 09:00      |
| `meetings:send-reminders`  | every 30 minutes |
| `meetings:update-statuses` | every 5 minutes  |
| `payments:send-reminders`  | daily 09:00      |
| `birthdays:send-reminders` | daily 09:00      |
| `db:backup`                | daily 03:00      |

---

## Backups

```bash
php artisan db:backup                 # database + uploaded files
php artisan db:backup --database-only # skip the files
php artisan db:backup --keep=30       # retain 30 archives instead of 14
```

Writes a timestamped `.zip` to `storage/app/backups` containing:

```
database.sql   # mysqldump / pg_dump output
files/         # everything under storage/app/public
```

**Both halves are needed.** The database stores file *paths*; the signed
contract PDFs, signature images and payment proofs themselves live on disk. A
database-only restore leaves every record intact and every document link
broken.

`mysqldump` must be on the server's `PATH` — it ships with the MySQL client
tools, which are not always installed alongside the server.

### Restoring

```bash
unzip shadapp-2026-08-26_030000.zip -d restore/
mysql -u root -p shadapp < restore/database.sql
cp -r restore/files/* storage/app/public/
php artisan storage:link   # if the symlink is missing on the new machine
```

The dump includes `DROP TABLE IF EXISTS`, so restoring over an existing
database replaces it rather than failing on the first conflict.

> `storage/app/backups` is gitignored and sits outside the web root, so
> archives are never served or committed. They still contain every record in
> the system — treat them as production data, and copy them somewhere off the
> server. A backup that only exists on the machine it protects is not a
> backup.

---

## Configuration notes

### Mail

`MAIL_MAILER=log` (the default in `.env.example`) writes messages to
`storage/logs/` instead of sending them — useful locally. Point it at a real
SMTP transport for production.

All mailables are queued **except** `ClientWelcomeMail`, which is sent
synchronously on purpose: it carries the client's plaintext generated
password, and queueing would persist that password in the jobs table.

#### Reading a mail-driver email locally

With `MAIL_MAILER=log` nothing is delivered — the rendered message is appended
to `storage/logs/laravel-YYYY-MM-DD.log`. To pull the newest password reset
link out of it without scrolling:

```powershell
Select-String -Path storage\logs\laravel-*.log -Pattern "reset-password\?token=[^\s<\"]*" |
  Select-Object -Last 1 -ExpandProperty Matches |
  ForEach-Object { "http://localhost:3000/" + $_.Value }
```

Remember the queue worker must be running, or the email is never rendered at
all and the log stays empty.

### Cache / session / queue drivers

Default to `database` so the project runs with no extra services (WAMP/XAMPP
have no Redis). Redis is supported and preferable in production — set
`CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` to `redis`.

### Realtime (Reverb)

`BROADCAST_CONNECTION=reverb` must be set, and the `REVERB_*` values must match
what the dashboard (`NEXT_PUBLIC_REVERB_*`) and the mobile app
(`assets/env.txt`) are configured with. A mismatch shows up as a silently dead
connection rather than an error.

Every broadcast channel is private and authorised in `routes/channels.php`.

**In production**, Reverb listens on `REVERB_PORT` (8080 by default) while
browsers and phones connect to your domain on 443. The reverse proxy has to
forward that route *and* pass the WebSocket upgrade headers — without them the
handshake is rejected even though Reverb itself is running fine. For nginx:

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 60s;
}
```

Then set `REVERB_SCHEME=https` and `REVERB_PORT=443` in the clients'
configuration, so they connect through the proxy rather than to port 8080
directly.

### File uploads

Allowed types and size limits live in `app/Support/UploadRules.php` — a single
source of truth for every upload endpoint. Add new upload endpoints through it
rather than writing inline `mimes:`/`max:` rules.

### Logging

`LOG_CHANNEL=daily` rotates logs and prunes them after `LOG_DAILY_DAYS`. Set
`LOG_LEVEL=warning` in production; `debug` is very noisy.

---

## Tests

```bash
php artisan test
```

Tests run against an in-memory SQLite database (`phpunit.xml`), so they neither
need nor touch your MySQL development database. This is also why the suite
passing here does **not** by itself prove MySQL compatibility — run migrations
against a real MySQL database for that.

---

## Production checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `LOG_LEVEL=warning`
- [ ] `FRONTEND_URL` points at the real dashboard domain — password reset links are built from it
- [ ] Real SMTP credentials configured
- [ ] `./supervisor/setup.sh` has been run — installs the queue worker and Reverb under Supervisor and adds the scheduler cron entry
- [ ] Reverse proxy forwards the Reverb route with WebSocket upgrade headers (see below)
- [ ] `php artisan storage:link` has been run
- [ ] `php artisan config:cache && php artisan route:cache`
- [ ] Database backups scheduled
- [ ] `FCM_SERVER_KEY` set if push notifications are wanted
- [ ] `ZOOM_*` credentials set if meeting integration is wanted

---

## Layout

```
app/
  Domains/        Feature-grouped controllers (Auth, Client, Contract, Payment, Chat, ...)
  Models/         Eloquent models
  Policies/       Authorisation rules
  Mail/           Mailables
  Notifications/  Database + broadcast + push notifications
  Events/         Broadcast events
  Listeners/      Event handlers (email fan-out)
  Services/       PDF generation, business logic
  Support/        Shared helpers (UploadRules, DbExpr, SignatureValue)
routes/
  api.php         All API routes
  channels.php    Broadcast channel authorisation
  console.php     Scheduled commands
```

### Authentication

Three account types, each with its own guard: `User` (staff — super admin and
account managers), `Client`, and `SubUser`. The custom `auth.any` middleware
accepts a list of guards, e.g. `auth.any:sanctum,client,sub_user`.
