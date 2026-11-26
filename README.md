# Domain Checker

[![Version](https://img.shields.io/badge/Version-1.11.1-brightgreen?style=flat-square)](CHANGELOG.md)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D?style=flat-square&logo=vuedotjs&logoColor=white)](https://vuejs.org)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)](https://tailwindcss.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](https://opensource.org/licenses/MIT)

A public domain toolbox built with Laravel and Vue.js. Check a name across 46 popular extensions (or the full IANA list of 1,200+) in real time — results stream in one by one via Server-Sent Events — and alongside it, check bulk lists, HTTP/3 support, redirect chains, DNS records for up to 100 domains at once, and IP geolocation. Supports optional [Realtime Register IsProxy](#realtime-register-isproxy) for faster, parallel lookups with a free RDAP/WHOIS fallback.

> **Disclaimer:** This software is provided "as is", without warranty of any kind. Use at your own risk. The authors are not responsible for any data loss, security breaches, or other damages resulting from the use of this software. Always review the code and configure proper security measures before deploying to production.

---

## Screenshots

<p align="center">
  <img src="docs/screenshots/home-empty.jpg" alt="Home — empty state with dot-grid background" width="48%" />
  &nbsp;
  <img src="docs/screenshots/home-results.jpg" alt="Home — live results streaming in" width="48%" />
</p>

<p align="center">
  <img src="docs/screenshots/home-dark.jpg" alt="Home — dark mode" width="48%" />
  &nbsp;
  <img src="docs/screenshots/login.jpg" alt="Login with passkey support" width="48%" />
</p>

<p align="center">
  <img src="docs/screenshots/settings.jpg" alt="Settings — profile, password, 2FA, passkeys" width="70%" />
</p>

> All five were captured on 2026-04-20, the first release day, and have never
> been retaken. Everything except `home-dark` shows the light theme as it was
> before the v1.11.0 design pass; none of them show the extra tools, the
> bulk-check toggle or the registration modal, and the Settings shot predates
> the API Integrations card by a day. They need re-capturing at v1.11.1.

---

## Features

### Domain checking
- **46 popular TLDs** checked by default — `.nl`, `.com`, `.be`, `.de`, `.net`, `.org`, `.io`, `.co`, `.eu`, `.app`, `.dev`, `.ai`, and more.
- **Full IANA TLD list** — expand to 1,200+ extensions with one click; list is fetched from IANA and cached daily.
- **Realtime Register IsProxy** (optional) — socket-based parallel lookups over a single persistent TLS connection. All IS commands are sent at once and responses stream back as the server resolves them, making total check time ≈ slowest single TLD regardless of list size.
- **RDAP-first lookup** — free fallback using the [IANA RDAP bootstrap](https://data.iana.org/rdap/dns.json). HTTP 404 = available, 200 = taken.
- **WHOIS fallback** — for TLDs without RDAP, a PHP socket queries the authoritative WHOIS server with text-pattern parsing.
- **Real-time streaming** — results appear one by one via Server-Sent Events.
- **Result caching** — per-domain results cached 15 min; RDAP bootstrap and IANA list cached 24 h. An `unknown` verdict is cached for 60 s only, because it is a non-answer rather than a result.

### Tools

Seven pages ship in the navigation, plus a bulk mode on the checker itself.
All of them are public, and every endpoint that does outbound work is
rate-limited:

| Tool | Route | What it does |
|---|---|---|
| Domain checker | `/` | The default: one name across 46 popular TLDs or the full IANA list, streamed over SSE. |
| Bulk check | `/` (mode toggle) | Paste a list of full domains and check them all at once. Each line is split on the longest known TLD suffix, so `blog.google.com` is read as the domain it is. |
| HTTP/3 checker | `/http3` | Streams a live probe of DNS, TLS 1.3, HTTP/2, `Alt-Svc` and QUIC, with server info, per-phase timings and all response headers. |
| Redirect checker | `/redirect` | Traces the full redirect chain of a URL — each hop with its status code, `Location` header and timing — with selectable user agents. Hard-capped at 20 hops and a 15 s timeout. |
| Bulk DNS lookup | `/dns` | MX, NS, TXT, A, AAAA or CNAME for up to 100 domains at once, with optional IP geolocation columns and Copy as TSV. |
| IP lookup | `/ip` | Geolocation, ASN, reverse DNS and proxy/hosting signals for any IP, with 7-day browser-local history. |
| My IP | `/my-ip` | Shows the visitor their own address. |
| Transfer request | `/transfer` | Builds a formatted transfer request across several registrant groups. Nothing is submitted: the result goes to the clipboard. |

Every outbound probe goes through `PublicNetworkGuard`, which resolves the
target host first and refuses loopback, link-local, RFC1918, CGNAT and the
other reserved ranges, so these tools cannot be pointed at internal hosts.

### Smart input
- Accepts plain names (`example`), full domains (`example.nl`), or URLs (`https://www.example.nl`).
- Auto-checks when a full domain is typed or pasted (400 ms debounce).
- The explicitly typed extension is always checked and answered first, as a headline result card above the grid — even when that TLD is not in the selected list.
- Auto-selects the pinned TLD if it comes back available.

### Selection & ordering
- Checkbox-select any available domains (the single and bulk result lists each have their own **Select all available**).
- A sticky bar slides up with the selected count and a **Fill in details and request** button.
- That opens the registration modal — contact, address and business fields, all required — with a collapsible "How does this work?" explainer.
- **Copy to clipboard** in the modal footer puts a formatted summary (the domains plus the registration details) on the clipboard. Nothing is submitted to the server.

### User management
- **Multi-user support** — admin panel at `/admin/users` to create, edit, and delete user accounts.
- **Three-tier roles** — `user`, `admin`, `super_admin`. Regular admins can manage users and send invites; only super admins can assign the `super_admin` role.
- **Email invite flow** — send an invite link with configurable expiry. Invitees set their name and password; they are automatically logged in on acceptance.
- **Password reset** — admins can trigger password reset emails per user; users can also self-serve via "Forgot password?" on the login page.
- **2FA reset** — admins can clear a user's TOTP secret and passkeys from the panel.

### Authentication & security
- Public checker — no login required.
- Rate-limited per tool; see [Rate limiting](#rate-limiting). The checker itself allows 10 checks/min for guests and 60/min for authenticated users.
- Login via **WebAuthn passkey** or email + password, with `throttle:5,1` on login, `throttle:10,1` on two-factor verification and `throttle:5,1` on password reset.
- **TOTP two-factor authentication** with QR setup and 8 recovery codes.
- Settings page: profile, password, 2FA, and passkey management.
- **`PublicNetworkGuard`** vets every outbound target (HTTP/3, redirect, DNS and WHOIS/RDAP probes) against the reserved IPv4 and IPv6 ranges, so a visitor cannot use the tools to reach internal or tailnet hosts. TLS peer verification is on in every outbound client.
- **CSRF is exempted on four public read-only endpoints** (`check`, `bulk-check`, `http3/check`, `ip/lookup`) in `bootstrap/app.php`; they take no authenticated action.
- **`NoHtmlCache`** marks every Inertia HTML/JSON response `no-store`, so a deploy reaches users on their next request while hashed assets keep caching.
- **Partner API** at `/api/v1` for our own sites: bearer tokens stored as SHA-256 hashes only, stateless, no CORS, rate-limited per token and per visitor. See [Partner API](#partner-api).
- **`GET /up`** is the framework health endpoint, used by the deploy script and suitable for uptime monitoring.

### UI
- Light / Dark / Auto theme (no flash on load).
- Subtle dot-grid background pattern.
- Fully responsive — 3-column list on desktop, single column on mobile.

---

## Installation

### Requirements

- PHP 8.4.1+ (Symfony 8 and PHPUnit 13 both require `>=8.4.1`)
- PHP extensions: `pdo_mysql` (or `pdo_sqlite`), `intl`, `openssl`, `sockets` (WHOIS and IsProxy use raw TLS sockets), `curl` — for QUIC metadata on the HTTP/3 page, curl must be built against ngtcp2 + nghttp3 or quiche
- Composer
- Node.js `^20.19` or `>=22.12` (required by Vite 8 and laravel-vite-plugin 3 — plain "Node 20" is not enough; see `engines` in `package.json`)
- MySQL 8.0+ / MariaDB / PostgreSQL 14+ / SQLite. Sessions, cache and the queue all use the database driver, so a real database is required.

### Local development

```bash
# Clone the repository
git clone https://github.com/ICTWebSolutionBV/domain-checker.git
cd domain-checker

# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Copy environment file and generate key
cp .env.example .env
php artisan key:generate

# Create the database configured in .env (.env.example uses MySQL:
# DB_DATABASE=domain_checker), then run the migrations
mysql -u root -e 'CREATE DATABASE IF NOT EXISTS domain_checker'
php artisan migrate

# Create the first super admin user. Pass the plain password: the model casts
# `password` as `hashed`, so it is hashed once, with the configured cost.
php artisan tinker --execute="\App\Models\User::create([
    'first_name' => 'Your',
    'last_name'  => 'Name',
    'name'       => 'Your Name',
    'email'      => 'admin@example.com',
    'password'   => 'your-password',
    'role'       => 'super_admin',
]);"

# Build frontend assets
npm run build

# Start development servers
php artisan serve
npm run dev
```

---

## Deploying with Ploi

### 1. Create a new site

- In Ploi, create a new site pointing to your domain.
- Set the **web directory** to `/public`.
- Select **PHP 8.4** (8.4.1 or newer). Pin it: the deploy does not check the version.

### 2. Connect repository

- Go to your site's **Repository** tab.
- Connect to `github.com/ICTWebSolutionBV/domain-checker`.
- Set branch to `main`.
- Enable **Install Composer dependencies**.

### 3. One-time permissions

Run this **once** per server, over SSH. It is deliberately not part of the
deploy script: re-applying permissions on every deploy is how `chmod -R 777
storage` became permanent, and world-writable compiled Blade under
`storage/framework/views` is executable PHP that any other account on the box
can replace.

```bash
cd {SITE_DIRECTORY}
sudo chown -R ploi:www-data storage bootstrap/cache
sudo chmod -R 2775 storage bootstrap/cache
```

The setgid bit (the `2`) makes every file created later inherit the group, so
the deploy user and PHP-FPM both keep write access. On a host with ACLs
available, `sudo setfacl -R -m u:www-data:rwX -m d:u:www-data:rwX storage
bootstrap/cache` does the same job.

### 4. Deploy script

The deploy steps live in [`deploy.sh`](deploy.sh), tracked in this repository
so they can be reviewed and corrected in a pull request. Ploi's own deploy
script should be only:

```bash
cd {SITE_DIRECTORY}
git pull origin main
bash deploy.sh
```

`deploy.sh` installs dependencies, builds the assets, takes a `mysqldump`,
runs the migrations between `artisan down` and `artisan up`, warms the caches
with `php artisan optimize` (config, routes, views and events) and finishes
with a `curl` health check against `/up` — so a deploy that leaves the site
500-ing fails instead of printing "deployed".

**A queue worker is required.** Invite mail and password-reset mail are queued,
not sent inside the request, so without a worker they wait in the `jobs` table
and are never delivered — including the reset link for someone who is locked
out. In Ploi, add a daemon (or use the site's Queue tab) with:

```bash
php artisan queue:work database --queue=default --tries=3
```

Connection `database` and queue `default` are what the app dispatches to; a
worker listening anywhere else silently processes nothing. `deploy.sh` runs
`php artisan queue:restart` so the worker picks up each deploy's code. Failed
jobs land in `failed_jobs` — check with `php artisan queue:failed`.

The app has no scheduled tasks, so no scheduler entry is needed.

### 5. Environment variables

In the **Environment** tab, set your `.env`:

```env
APP_NAME="Domain Checker"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=domain_checker
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-smtp-user
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=noreply@your-domain.com
MAIL_FROM_NAME="${APP_NAME}"
```

### 6. First super admin user

After the first deploy, create your super admin via the Ploi console or SSH:

```bash
cd {SITE_DIRECTORY}
php artisan tinker --execute="\App\Models\User::create([
    'first_name' => 'Your',
    'last_name'  => 'Name',
    'name'       => 'Your Name',
    'email'      => 'you@example.com',
    'password'   => 'your-password',
    'role'       => 'super_admin',
]);"
```

Then log in at `https://your-domain.com/login` and register a passkey or enable 2FA from Settings.

To promote an existing user to super admin:

```bash
php artisan tinker --execute="\App\Models\User::where('email', 'you@example.com')->update(['role' => 'super_admin']);"
```

---

## User management

The admin panel is available at `/admin/users` for any user with the `admin` or `super_admin` role.

### Roles

| Role | Can do |
|---|---|
| `user` | Use the domain checker, manage own profile and passkeys |
| `admin` | Everything above + manage users, send invites, reset passwords and 2FA |
| `super_admin` | Everything above + assign/revoke `super_admin` role, delete super admins |

### Creating users

Two ways to add a user:

1. **Invite** — click the **Invite** button, fill in the email (and optionally name + role + expiry), and click **Send Invite**. The invitee receives an email with a link to set their own password. The link expires after the configured number of hours.

2. **Direct create** — click **Create User**, fill in name, email, password, and role. The account is created immediately; no email is sent.

### Managing existing users

From the users table you can:

- **Edit** — change first name, last name, email, or role. The display `name` is recomputed from the two name fields.
- **Send password reset** — triggers a standard Laravel password reset email.
- **Reset 2FA** — clears the user's TOTP secret and all registered passkeys. They will need to re-enroll on next sign-in.
- **Delete** — permanently removes the account. You cannot delete your own account; only super admins can delete other super admins.

### Pending invites

Invites that have been sent but not yet accepted appear in the **Pending Invites** table below the users list. Each invite shows the invitee email, role, who sent it, and its current status (Pending / Expired). You can **Revoke** a valid invite or **Resend** an expired one with a fresh 72-hour expiry.

---

## Realtime Register IsProxy

[Realtime Register](https://www.realtimeregister.com) offers an IsProxy API for fast parallel domain availability lookups over a single TLS socket connection. When configured, it is used as the primary check source with RDAP/WHOIS as fallback.

### How it works

1. A single TCP connection is opened to `is.yoursrs.com:2001`.
2. STARTTLS is negotiated and the connection is upgraded to TLS.
3. The client logs in with `LOGIN <api_key>`.
4. All `IS <domain>.<tld>` commands are sent in one batch without waiting for responses.
5. The server processes them in parallel and sends back async responses.
6. Each result is streamed to the browser immediately via SSE as it arrives.

This makes total check time ≈ slowest single TLD lookup, regardless of how many TLDs are being checked.

### Configuration

Add the API key in **Settings → API Integrations** after logging in. Optionally override the host:

```env
REALTIME_REGISTER_API_KEY=your-api-key
REALTIME_REGISTER_HOST=is.yoursrs.com   # default
REALTIME_REGISTER_PORT=2001             # default
```

The key requires **IsProxy** access. RDAP and WHOIS are used automatically for any TLD the IsProxy service cannot resolve.

---

## Partner API

A server-to-server API so our own sites can run live checks through this
app. All checking logic stays here; a client only sends names and extensions
and relays the stream. It is stateless (the `api` middleware group: no
session, no cookies, no CSRF), never renders Inertia, sends no CORS headers,
and answers errors as JSON whatever the client accepts.

| Endpoint | What it does |
|---|---|
| `POST /api/v1/check` | One name across a list of extensions, or across all of them (`scope: all`). |
| `POST /api/v1/bulk-check` | A list of full domains, each with its own extension. |
| `GET /api/v1/tlds` | The popular list and the full IANA list. |

### Authentication

Every request carries `Authorization: Bearer <token>`. Tokens are configured
as comma-separated `name:sha256hash` pairs in `PARTNER_API_CLIENTS`; only the
SHA-256 hash is stored, and the presented token is hashed and compared with
`hash_equals`. Generate a token with:

```bash
php artisan partner-api:token website
```

It prints the token once (put it in the client's environment) and the
`PARTNER_API_CLIENTS=` line to paste here, with any existing clients kept. The
plain token is not stored anywhere. To rotate, add a second entry under the
same name, switch the client over, then remove the old entry; entries with the
same name share one rate-limit bucket. Run `php artisan config:cache` after a
change on a server that caches its config.

Every `POST` should also send `X-Client-IP: <the visitor's IP>`. It is only
read after the token is accepted, and ignored (with a log notice) unless it is
a valid IPv4 or IPv6 address; without it only the per-token limits apply.

### `POST /api/v1/check`

```http
POST /api/v1/check
Authorization: Bearer <token>
Accept: text/event-stream
Content-Type: application/json
X-Client-IP: 203.0.113.7

{"domain": "voorbeeld", "tlds": ["nl", "com", "eu"]}
```

| Field | Rules |
|---|---|
| `domain` | Required. The name without extension. Unicode is converted to punycode first, exactly like the public checker (`münchen` → `xn--mnchen-3ya`), then it must be one DNS label: letters, digits and inner hyphens, at most 63 characters. |
| `tlds` | A JSON array of at most 100 extensions, each a label of letters, digits and hyphens (not starting with a hyphen), at most 63 characters. Lowercased and deduplicated before the check. |
| `scope` | `"all"` instead of `tlds`: check the full IANA list (currently about 1,280 extensions), the same list and 1,500 cap the public "All extensions" mode uses. `total` is then the list size. |

Send exactly one of `tlds` and `scope`; both or neither is a 422.

The response is `200 text/event-stream` with exactly the lines the public
`/check` stream sends, each followed by a blank line:

```text
: ping
data: {"tld":"nl","status":"taken","checked":1,"total":3}
data: {"tld":"eu","status":"available","checked":2,"total":3}
data: {"tld":"com","status":"taken","checked":3,"total":3}
data: {"done":true}
```

- `: ping` is an SSE comment that opens the pipe before the first lookup.
- One `data:` line per TLD, in the order the verdicts arrive (cached ones
  first), not the order requested. `checked` counts up to `total`, the number
  of distinct TLDs.
- `data: {"error":"The check stopped early. Please try again."}` is sent if a
  lookup fails part-way; TLDs that had not answered by then get no line.
- `data: {"done":true}` always ends the stream, also after an error.

### `POST /api/v1/bulk-check`

```http
POST /api/v1/bulk-check
Authorization: Bearer <token>
Accept: text/event-stream
Content-Type: application/json
X-Client-IP: 203.0.113.7

{"domains": ["voorbeeld.nl", "blog.voorbeeld.com", "geen domein"]}
```

`domains` is a JSON array of at most 50 strings (`PARTNER_API_BULK_MAX`), each
a full domain with its extension. Each is parsed the way the public bulk check
parses a line: unicode becomes punycode, a leading `http(s)://` and `www.` are
dropped, the extension is the longest suffix in the IANA list, and a subdomain
is checked as its registrable domain. A line that is no domain at all, or
whose extension is not in the IANA list, gets one `invalid` line without a
lookup (the public bulk check drops those instead).

The stream carries the public bulk lines, keyed on the string exactly as sent:

```text
: ping
data: {"domain":"geen domein","status":"invalid","checked":1,"total":3}
data: {"domain":"voorbeeld.nl","status":"taken","checked":2,"total":3}
data: {"domain":"blog.voorbeeld.com","status":"available","checked":3,"total":3,"checked_domain":"voorbeeld.com"}
data: {"done":true}
```

- `invalid` lines come first, then one line per domain as it resolves.
- `checked_domain` appears when a subdomain was sent: it names the
  registrable domain that was checked instead. A stripped `www.` or
  `http(s)://` does not add it.
- Two entries that reduce to the same domain are looked up once and each get
  their own line. `total` is the number of entries sent.
- `error` and `done` work as for `/check`.

### Status values

| `status` | Meaning |
|---|---|
| `available` | The registry has no record of the domain (RDAP 404, an IsProxy "available", or a WHOIS not-found answer). |
| `taken` | The registry holds the domain (RDAP 200, IsProxy "not available", or a WHOIS record for it). |
| `unknown` | No verdict: the registry timed out, refused or rate-limited us, answered in a shape we cannot read, or offers neither RDAP nor WHOIS. Cached for 60 s only, so a retry soon after can get an answer. |
| `invalid` | `bulk-check` only: the entry is not a domain, or its extension is not in the IANA list. Nothing was looked up. |

### Errors

Errors are JSON, never a stream:

| Status | When | Body |
|---|---|---|
| `401` | Missing or unknown token (also sends `WWW-Authenticate: Bearer`) | `{"message":"Unauthenticated."}` |
| `422` | Validation failed | `{"message":"…","errors":{"domain":["…"]}}` |
| `429` | A rate limit was reached, or more than 30 failed authentications from one IP within a minute; `Retry-After` gives the seconds to wait | `{"message":"Too Many Attempts."}` |

### `GET /api/v1/tlds`

Same authentication, not rate-limited. Returns the configured popular list
(`domain-checker.popular_tlds`) and the full IANA list from `TldRepository`,
cached for 24 hours as it is for the public checker:

```json
{"popular": ["nl", "com", "be", "..."], "all": ["aaa", "aarp", "...", "zw"]}
```

If the IANA list cannot be fetched, `all` falls back to the popular list (for
as long as that answer is cached), the same as on the public site.

### Rate limits

Each `POST` has a limit per client token and one per visitor (from
`X-Client-IP`, per client), counted per request whatever its size. A request
is refused when either is spent.

| Request | Per token | Per visitor | Variables |
|---|---|---|---|
| `check` with `tlds` | 300/min | 20/min | `PARTNER_API_CLIENT_LIMIT`, `PARTNER_API_VISITOR_LIMIT` |
| `check` with `scope: all` | 60/hour | 3 per 10 min | `PARTNER_API_ALL_CLIENT_LIMIT`, `PARTNER_API_ALL_VISITOR_LIMIT` |
| `bulk-check` | 60/min | 5/min | `PARTNER_API_BULK_CLIENT_LIMIT`, `PARTNER_API_BULK_VISITOR_LIMIT` |

The three have separate budgets: a full check does not use up a visitor's
ordinary checks. Why these numbers:

- **`scope: all`** is about 1,280 registry lookups per name. With IsProxy most
  of them go out as one pipelined batch, but whatever IsProxy cannot answer,
  and the whole list when IsProxy is not configured, goes through RDAP and
  WHOIS: about a minute of a PHP worker, and bursts that make the shared RDAP
  hosts answer 429, which turns verdicts into `unknown` for every visitor. The
  limits are sized for that worst path. 60/hour per token keeps the average at
  about one worker busy; 3 per 10 minutes is enough for a visitor trying a few
  names. A repeat of the same name within 15 minutes comes from the cache.
- **`bulk-check`** is at most 50 single-extension lookups, and they run one
  domain after another, so a cold request can hold a worker for tens of
  seconds. It counts once per request (no weighting by size), at a quarter of
  the ordinary per-visitor rate.

A visitor is counted per IPv4 address, or per IPv6 /64, since one IPv6
subscriber holds a whole /64 and could otherwise rotate through it for a fresh
budget; an IPv4-mapped address (`::ffff:203.0.113.7`) counts as the IPv4
address it carries. Rejected requests (401) never count; a request that fails
validation (422) does. The `X-RateLimit-Limit` and `X-RateLimit-Remaining`
headers describe the per-visitor limit when `X-Client-IP` is valid, otherwise
the per-token limit.

Failed authentication is capped separately at 30 per minute per caller IP
(`$request->ip()`, so behind the same proxy caveat as the guest limits under
[Rate limiting](#rate-limiting)); past that cap a bad token gets a 429 instead
of a 401, and no log line. A valid token is never held back by it.

### Logging

- A rejected token: a warning with the reason (`missing token` or
  `unknown token`) and the path, until the failed-authentication cap is hit.
- A reached rate limit: a notice with the client name, the endpoint (`check`,
  `all` or `bulk`) and which limit (`client` or `visitor`).
- A request without a usable `X-Client-IP`: a notice with the client name and
  `missing` or `invalid`, since the per-visitor limit then silently drops out.

Neither the token nor any IP address is logged.

---

## Environment variables

### Application

| Variable | Default | What it does |
|---|---|---|
| `APP_NAME` | `Domain Checker` | Shown in the browser tab and emails. Also feeds the session cookie name and the cache prefix, so changing it logs everyone out. |
| `APP_ENV` | `production` (`.env.example` ships `local`) | The code default is `production`; only the example file makes a fresh clone local. |
| `APP_KEY` | _(required)_ | Generated by `php artisan key:generate`. Encrypts sessions and 2FA secrets. Never rotate without a plan. |
| `APP_DEBUG` | `false` (`.env.example` ships `true`) | The code default is already safe; the example file is the thing that turns debug on. |
| `APP_URL` | `http://localhost` | Base URL of the app. Used for passkey WebAuthn origin checks. |

### Database

| Variable | Default | What it does |
|---|---|---|
| `DB_CONNECTION` | `mysql` | One of `mysql`, `mariadb`, `pgsql`, `sqlite`, `sqlsrv`. Sessions, cache and the queue all use the database driver, so a real database is required. For SQLite, `database/database.sqlite` must exist first — it is gitignored, so a clone never has one. |
| `DB_HOST` | `127.0.0.1` | Database server host (not used for SQLite). |
| `DB_DATABASE` | `domain_checker` | Database name, or the absolute path to the SQLite file. |
| `DB_USERNAME` / `DB_PASSWORD` | empty | Database credentials. |

### Mail

| Variable | Default | What it does |
|---|---|---|
| `MAIL_MAILER` | `log` | Set to `smtp` (or `ses`, `mailgun`, etc.) in production. |
| `MAIL_HOST` | `127.0.0.1` | SMTP host. |
| `MAIL_PORT` | `2525` | SMTP port. |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | empty | SMTP credentials. |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | From address for all outgoing mail (invites, password resets). |
| `MAIL_FROM_NAME` | `${APP_NAME}` | From name for all outgoing mail. |

### Session & cache

| Variable | Default | What it does |
|---|---|---|
| `SESSION_DRIVER` | `database` | Use `database` or `redis`. |
| `SESSION_LIFETIME` | `120` | Idle session timeout in minutes. |
| `SESSION_SECURE_COOKIE` | `false` | **Set to `true` in production.** Stops the session cookie from ever being sent over plain HTTP. |
| `CACHE_STORE` | `database` | Used to cache RDAP bootstrap, TLD list, domain results and the IANA WHOIS-server map. |

### Realtime Register

| Variable | Default | What it does |
|---|---|---|
| `REALTIME_REGISTER_API_KEY` | empty | IsProxy API key. Leave empty to use RDAP/WHOIS only. |
| `REALTIME_REGISTER_HOST` | `is.yoursrs.com` | IsProxy hostname. |
| `REALTIME_REGISTER_PORT` | `2001` | IsProxy port. |

### Partner API

| Variable | Default | What it does |
|---|---|---|
| `PARTNER_API_CLIENTS` | empty | Comma-separated `name:sha256hash` pairs, one per client token; generate with `php artisan partner-api:token <name>`. Empty means `/api/v1` refuses every request. |
| `PARTNER_API_CLIENT_LIMIT` | `300` | Checks per minute per client token. |
| `PARTNER_API_VISITOR_LIMIT` | `20` | Checks per minute per visitor IP reported in `X-Client-IP`. |
| `PARTNER_API_ALL_CLIENT_LIMIT` | `60` | `scope: all` checks per hour per client token. |
| `PARTNER_API_ALL_VISITOR_LIMIT` | `3` | `scope: all` checks per 10 minutes per visitor IP. |
| `PARTNER_API_BULK_MAX` | `50` | Most domains in one `bulk-check` request. |
| `PARTNER_API_BULK_CLIENT_LIMIT` | `60` | `bulk-check` requests per minute per client token. |
| `PARTNER_API_BULK_VISITOR_LIMIT` | `5` | `bulk-check` requests per minute per visitor IP. |

### Rate limiting

Named rate limiters live in `app/Providers/AppServiceProvider.php`:

| Limiter | Routes | Authenticated | Guest |
|---|---|---|---|
| `domain-check` | `POST /check`, `POST /bulk-check` | 60/min | 10/min |
| `http3-check` | `GET /http3/check` | 30/min | 60/hour |
| `ip-lookup` | `POST /ip/lookup` | 45/min | 60/hour |
| `redirect-check` | `POST /redirect/check` | 30/min | 60/hour |
| `dns-bulk` | `POST /dns/lookup` | 30/min | 5/min |
| `partner-api` | `POST /api/v1/check` | 300/min per client token **and** 20/min per visitor IP; with `scope: all` 60/hour and 3 per 10 min. See [Partner API](#partner-api) | — |
| `partner-api-bulk` | `POST /api/v1/bulk-check` | 60/min per client token **and** 5/min per visitor IP | — |

Authentication is limited separately, by named limiters in
`app/Providers/FortifyServiceProvider.php`:

| Limiter | Route | Limit |
|---|---|---|
| `login` | `POST /login` | 5/min per email+IP **and** 20/min per account |
| `two-factor` | `POST /two-factor` | 5/min per pending-login session |

Password reset keeps an inline `throttle:5,1` in `routes/web.php`.

The tool limiters key guests by IP, and `bootstrap/app.php` still trusts
`X-Forwarded-For` from any source (`trustProxies(at: '*')`), so **those**
limits are only as trustworthy as the proxy in front of the app — rotating
that header rotates the bucket. Set `trustProxies(at: [...])` to the real
proxy ranges to close it.

Two things are deliberately not IP-keyed, because they are the ones worth
attacking: the login limiter's second limit is per account, which no header
can rotate, and the two-factor limiter keys on the pending-login session.
The host is no longer taken from the request at all — `X-Forwarded-Host` is
untrusted and URLs are generated from `APP_URL`.

---

## Contributing

Setup, the checks CI runs, the PHPStan baseline rule and the definition of done
are in [CONTRIBUTING.md](CONTRIBUTING.md). The short version: run
`php artisan test` on PHP 8.4, `composer lint`, and — if you touched the front
end — `npm run lint && npm run format:check && npm run build`.

## Versioning

Domain Checker follows [Semantic Versioning](https://semver.org/). The current release is **v1.11.1**. All changes are tracked in [CHANGELOG.md](CHANGELOG.md), which also carries an `[Unreleased]` section for what is on `main` but not yet released.

> Release tags stop at **v1.6.5**: 1.7.0 through 1.11.1 were never tagged, so
> there is no ref to roll back to. Tag them before relying on a rollback.

- **1.11.1** — "How does this work?" became a real help affordance (indigo pill, pulsing amber dot, `aria-expanded`) on `/transfer` and in the registration modal; the registration modal is wider on desktop (`max-w-2xl`) with phone and email side by side from `sm` up.
- **1.11.0** — Light-mode design-system pass: semantic surface tokens, shared `.ui-*` component classes, white form controls with visible borders and indigo focus rings, AA-contrast light typography, reworked `/transfer` layout. Dark mode unchanged; no behavioural changes.
- **1.10.2** — Security: Guzzle 7.15.1 → 7.15.2, patching two advisories published 2026-08-03 (noncanonical host bypassing host-based checks, noncanonical cookie domain keeping subdomain scope). Also a Composer/npm refresh — Laravel 13.23, Inertia 3.2.1, Vite 8.2.0, axios 1.19.0. No user-facing changes.
- **1.10.1** — Security: Guzzle 7.12.1 → 7.15.1, patching four upstream advisories in the outbound HTTP client (URL credentials reaching origins, host-only cookie scope, response cookie admission, URI fragments in `Referer` on redirects). Also PHPUnit 12 → 13, a Composer/npm refresh, and the now-obsolete `brick/math` pin and `shell-quote` override removed. No user-facing changes.
- **1.10.0** — Bulk DNS Lookup tool at `/dns`: resolves MX, NS, TXT, A, AAAA, or CNAME for up to 100 domains at once, with IP geolocation columns (country, region, city, ISP, ASN) via 4 concurrent ip-api.com workers, a show/hide geo toggle, IP links through to the IP Lookup page, instant record-type refresh, and Copy as TSV.
- **1.9.2** — Modal UI overhaul: readable labels, visible input borders, grouped form sections, required registration details, renamed "Fill in details and request" button, collapsible help instructions on Home and Transfer.
- **1.9.1** — Mobile navigation drawer for the growing nav.
- **1.9.0** — My IP page at `/my-ip`.
- **1.8.0** — Bulk check mode on the home page: paste a list of full domains and check them in one run.
- **1.7.0** — Redirect Checker tool at `/redirect`: the full redirect chain of a URL, hop by hop.
- **1.6.5** — Security: patched three Symfony CVEs (`symfony/http-foundation` SSRF bypass, `symfony/routing` URL-collapse, `symfony/polyfill-intl-idn` Punycode equivalence). `composer audit` now clean.
- **1.6.4** — Dependency maintenance: all Composer and npm packages updated within existing constraints (Laravel 13.11, Inertia 3.1/3.2, Tailwind 4.3, Vite 8.0.13, etc.). Non-breaking; build and tests verified green.
- **1.6.3** — "Transfer" nav link is now a highlighted CTA — emerald/teal gradient pill with white bold text, a soft glow shadow, and a small pulsing amber dot — so visitors immediately notice it next to the muted plain nav links.
- **1.6.2** — Transfer page: small breathing room added between the per-group domain count and the trash/chevron buttons in the group header.
- **1.6.1** — Transfer page polish: "block" → "group" everywhere user-visible, the group-name field now visibly looks like a renameable input (dashed border, pencil icon, helpful placeholder), and "Add another registrant block" is now "Add domains for another owner".
- **1.6.0** — New `/transfer` page with a repeater of registrant blocks. Each block holds its own list of domains and one shared registrant detail set (existing-account reference or new registrant fields, plus optional EPP/auth code and notes). One "Copy to clipboard" button produces a single formatted plain-text summary across all blocks — no data leaves the browser.
- **1.5.5** — Fixes `Undefined array key "appconnect_time"` fatal on the HTTP/3 page: missing curl timing fields now fall back to 0 via `??` instead of `?:`. This was the real reason the stream died after `altsvc` — v1.5.4's try/catch surfaced it.
- **1.5.4** — HTTP/3 SSE stream hardened against proxy idle-timeouts: flushes nested output buffers, writes an initial `: ping` and `: hb` heartbeats between slow probes, and wraps the pipeline in a try/catch that logs exceptions instead of dying silently. Also uses UTF-8-safe JSON encoding so a bad byte in a response header can't produce empty frames. Fixes the HTTP/3 detail cards not appearing behind the production CDN.
- **1.5.3** — New `NoHtmlCache` middleware marks every Inertia HTML/JSON response as `Cache-Control: no-cache, no-store, must-revalidate, private`. Hashed JS/CSS under `/build/*` keep their aggressive caching; only the tiny HTML shell is uncached, so every deploy reaches users on their next request.
- **1.5.2** — HTTP/3 and IP Lookup pages now sync the checked host/IP into the URL (e.g. `/http3?host=example.com`, `/ip?q=8.8.8.8`) so results are shareable and bookmarkable.
- **1.5.1** — HTTP/3 panel now shows a QUIC session strip (Connection ID, Packet RX, Handshake Done) and a cleaner HTTP Version / Status Code / Response Time summary, plus a Header/Value response-headers table.
- **1.5.0** — HTTP/3 checker now returns full server info: HTTP version, status, DNS / connect / TLS / TTFB / total timings, server IP, and all response headers. When curl has QUIC built in, the panel shows data observed over HTTP/3.
- **1.4.1** — IP Lookup history moved to browser-local storage with a 7-day expiry (no longer stored or shared on the server).
- **1.4.0** — IP Lookup page with geolocation, ASN, reverse DNS, and proxy/hosting signals via ip-api.com.
- **1.3.0** — HTTP/3 checker page with real-time SSE streaming (DNS, TLS 1.3, HTTP/2, Alt-Svc, QUIC).

- **Patch (`1.0.x`)** — Bug fixes and small tweaks.
- **Minor (`1.x.0`)** — New features, backwards-compatible.
- **Major (`x.0.0`)** — Breaking changes requiring manual intervention.

---

## License

MIT — see [LICENSE](LICENSE) for details.
