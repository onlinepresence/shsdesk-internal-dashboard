# ControlDesk

Internal ops control plane for our school products (starting with FlowEdu).
Staff use it to register school deployments, issue licences, bill invoices,
track leads, and mint access keys. It is **not student-facing** — schools
never log in here; their software phones home to it.

## What it does

- **Deployments registry** — every school product instance, its version, and last check-in.
- **Licences** — terms per deployment: modules, student caps, start/expiry, pricing snapshot.
- **Invoices** — proforma bills generated from saved licence terms; paid ones are immutable.
- **Leads** — quote requests from product sites, reviewed and converted into deployments.
- **Keys** — demo keys for prospects and one-time enrollment codes that bind an install.

## Who logs in

Staff only. Public registration is closed — accounts are invite-only:

- First run seeds `admin@controldesk.com` (rotate the password on first login).
- After that, super-admins invite staff from the **Users** page; invites arrive by email.
- Deactivated accounts cannot log in. Unverified emails get a resend banner.

## Run it locally

Prereqs: PHP 8.4+, Composer, MySQL running locally.

```bash
cp .env.example .env
composer install
php artisan desk:setup
php artisan serve
```

`desk:setup` fills only the gaps: app key, licence signing key, migrations,
owner account, storage link. There is no homepage — the root URL opens
straight onto login, and signed-in staff land on the dashboard:

- <http://localhost:8000>

Fresh database from scratch:

```bash
php artisan migrate --seed
```

Run the suite and the frontend build:

```bash
php artisan test --compact
npm run build
```

## Enroll a pilot school

1. Log in, open **Deployments**, register the school to create its record.
2. Open the deployment, mint an enrollment code, copy it once.
3. From the school server, redeem it for a heartbeat token:

```bash
curl -X POST http://localhost:8000/api/v1/enroll \
  -H "Content-Type: application/json" \
  -d '{"code":"PASTE-CODE-HERE","product":"flowedu"}'
```

4. The school install then checks in with that token:

```bash
curl -X POST http://localhost:8000/api/v1/heartbeats \
  -H "Authorization: Bearer PASTE-TOKEN-HERE" \
  -H "Content-Type: application/json" \
  -d '{"deployment_uuid":"PASTE-UUID-HERE","product":"flowedu","app_version":"1.2.0","counts":{"students":120,"teachers":12,"users":135},"modules_in_use":["attendance"]}'
```

Codes are single-use and expire in 30 minutes; a consumed code replays the
licence snapshot without minting a second token.

## Secrets

Secrets live in **environment only, never the repo**:

- `APP_KEY` — sessions and cookies. Missing on first run; `desk:setup` makes one.
- `LICENCE_SIGNING_KEY` — 64-hex seed signing every licence and demo document.
- `DB_*` — local MySQL (`dashboard` database per `.env.example`).
- `MAIL_*` — log driver locally (mail prints to the log); set a real driver plus
  `MAIL_FROM_ADDRESS` in production with zero code change.

If it isn't in `.env`, it doesn't exist. Check the First-run setup panel on the
dashboard — it lists open gaps and disappears when clean.
