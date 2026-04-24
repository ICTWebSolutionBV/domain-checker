# Changelog

All notable changes to Domain Checker are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Versioning policy

- **Major (`X.0.0`)** — Breaking changes to routes, database schema, or configuration that require manual intervention.
- **Minor (`1.X.0`)** — New user-facing features, backwards-compatible.
- **Patch (`1.0.X`)** — Bug fixes, performance improvements, copy/UI tweaks.

Every push that ships production-visible changes should bump the appropriate segment and add a dated entry below.

---

## [Unreleased]

_Nothing yet._

---

## [1.4.0] — 2026-04-24

### Added
- **IP Lookup** — new `/ip` page that geolocates any public IPv4/IPv6 address or hostname using [ip-api.com](https://ip-api.com). Shows country, region, city, postal code, coordinates, timezone, currency, ISP, organization, ASN, AS name, and reverse DNS.
- **Signals** — flags the IP as mobile, proxy/VPN/Tor, or hosting/datacenter.
- **Embedded map** — OpenStreetMap preview for the IP's coordinates.
- **Lookup history** — the five most recent distinct IPs looked up globally are shown below the search, clickable to re-run the lookup.
- **IP Lookup nav link** — "IP Lookup" entry in the top navigation bar, visible to all visitors.
- **Rate limiter `ip-lookup`** — 45 requests/minute for authenticated users, 60 requests/hour for guests.

### Migration required
```bash
php artisan migrate
```
Adds the `ip_lookups` table used to record lookup history.

---

## [1.3.0] — 2026-04-24

### Added
- **HTTP/3 checker** — new `/http3` page that verifies whether a host supports HTTP/3 via DNS, IPv6, HTTPS, TLS 1.3, HTTP/2, Alt-Svc advertisement, and a direct QUIC connection attempt. Results stream in real time via SSE.
- **HTTP/3 nav link** — "HTTP/3" entry added to the top navigation bar, visible to all visitors (public tool).
- **Rate limiter `http3-check`** — 30 requests/minute for authenticated users, 60 requests/hour for guests.

### Changed
- Domain search placeholder updated from `yourname` to `YourDomainName`.

---

## [1.2.0] — 2026-04-21

### Added
- **Multi-user management** — admin panel at `/admin/users` for creating, editing, and deleting user accounts.
- **Three-tier role system** — `user`, `admin`, and `super_admin`. Admins can manage users; only super admins can assign the `super_admin` role or delete other super admins.
- **Email invite flow** — admins can send invitation emails with a configurable expiry (1–720 hours). Invitees follow the link, set their name and password, and are auto-logged in. Expired/used/invalid tokens show a clear error page.
- **Pending invites table** — shows status (Pending / Expired / Used) with Resend and Revoke actions per row; used and fully-onboarded invites are cleaned up automatically.
- **Send password reset** — admins can trigger a password reset email for any user from the users panel.
- **Reset 2FA** — admins can clear a user's TOTP secret and all registered passkeys from the users panel.
- **Password reset flow** — `/forgot-password` → `/reset-password/{token}` with dedicated pages. "Forgot password?" link added to the login page.
- **Users nav link** — a "Users" link appears in the top navigation bar for admin and super admin users.
- **`first_name` / `last_name` fields** — users now store separate first and last name fields in addition to the computed `name` column.

### Changed
- First admin user creation (see Installation) now requires `role`, `first_name`, and `last_name` fields.
- `HandleInertiaRequests` now shares `role`, `is_admin`, and `is_super_admin` as part of the `auth.user` prop.
- Flash messages updated to also display `success` key (used by admin actions) alongside the existing `status` and `error` keys.

### Migration required
```bash
php artisan migrate
```
Adds `first_name`, `last_name`, `role` to the `users` table and creates the `user_invites` table.

---

## [1.1.0] — 2026-04-21

### Added
- **Realtime Register IsProxy integration** — optional socket-based domain availability API. When an API key is configured in Settings → API Integrations, all domain checks are routed through the IsProxy service first, with RDAP/WHOIS as fallback for unsupported TLDs.
- **Pipelined socket protocol** — all IS commands for the entire TLD list are sent over a single persistent TLS connection. The server processes them in parallel and streams responses back as they resolve, so total check time ≈ slowest single TLD regardless of list size (previously ~500 ms × number of batches).
- **`set_time_limit(0)`** in the SSE streaming closure to prevent PHP execution timeout on large TLD lists.
- **`checked` / `total` counters** in SSE events so the frontend can show accurate progress.

### Changed
- `/check` endpoint switched from GET to POST — the TLD list is now sent in the request body to avoid URL length limits when checking 1,200+ extensions.
- Domain check controller updated to call `streamCheck()` directly instead of looping fixed-size batches; results are emitted to the SSE stream as each one resolves.
- `DomainAvailabilityService` refactored: `checkBatch()` is now a thin wrapper around the new `streamCheck(callback)` method; cached results are flushed immediately before live checks begin.
- Rate limiter registration moved from `withRouting()->then:` to `AppServiceProvider::boot()` to ensure it is always registered even when the route cache is active.
- CSRF exemption added for `/check` (public read-only SSE endpoint).
- Clipboard bar hint text improved.

### Fixed
- "Something went wrong" error on domain check caused by rate limiter not being registered when route cache was active.
- "Something went wrong" on All Extensions mode caused by GET URL exceeding server URL length limits with 1,500+ TLD parameters.
- PHP 30-second execution timeout when checking large TLD lists.
- Socket TLS upgrade error (`stream_socket_enable_crypto` called with invalid argument) — fixed by using `stream_context_set_option()` before the crypto call instead of passing context as a fourth argument.

---

## [1.0.0] — 2026-04-20

### Added
- **Public domain availability checker** — check a domain name across 46 popular TLD extensions (`.nl`, `.com`, `.be`, `.de`, `.net`, `.org` and more) in real time via Server-Sent Events. No login required.
- **All extensions mode** — expand to the full IANA TLD list (1,200+ extensions) with a single click; list is fetched and cached daily.
- **RDAP-first lookup** — uses the free IANA RDAP bootstrap registry to query each TLD's authoritative RDAP server. HTTP 404 = available, HTTP 200 = taken.
- **WHOIS fallback** — for TLDs without an RDAP endpoint, a PHP socket connection to the authoritative WHOIS server is used with text-pattern parsing.
- **Result caching** — per-domain results are cached for 15 minutes; RDAP bootstrap and IANA TLD list are cached for 24 hours.
- **SSE streaming** — results stream in one by one as they resolve via `text/event-stream`; RDAP queries run concurrently in batches of 10 using `Http::pool()`.
- **Full-domain results list** — results displayed as a 3-column list showing the full domain name (e.g. `example.nl`) rather than just the extension.
- **Smart input parsing** — accepts plain names (`example`), full domains (`example.nl`), and URLs (`https://www.example.nl`); strips protocol/www/TLD automatically.
- **Auto-check on full domain input** — typing or pasting a full domain (e.g. `example.nl`) triggers the check automatically after 400 ms.
- **Pinned TLD** — when a specific TLD is typed (e.g. `.nl`), that result is pinned to the top of the list with a visual highlight.
- **Auto-select available pinned TLD** — if the explicitly typed TLD comes back available, it is pre-checked immediately.
- **Checkbox selection** — available domains can be individually checked or bulk-selected with "Select all available".
- **Clipboard bar** — a sticky bottom bar slides up when domains are selected, showing the count and a "Copy to clipboard" button that copies all selected full domain names, one per line, ready to paste in email or WhatsApp.
- **Filter toolbar** — filter results by All / Available / Taken; counts shown per status.
- **Progress bar** — live progress indicator while checking is in progress.
- **Dot-grid background** — subtle repeating dot-grid pattern across the page (light: slate dots; dark: translucent white dots), fading out at top and bottom.
- **Light / Dark / Auto theme** — stored in `localStorage`; dark class applied before first paint to prevent flash.
- **Rate limiting** — `/check` endpoint is throttled: 10 requests/min for guests, 60/min for authenticated users.
- **Authentication** — login via WebAuthn passkey (`spatie/laravel-passkeys`) or email + password.
- **TOTP two-factor authentication** — enable/disable 2FA from settings; QR code setup, code confirmation, and 8 recovery codes.
- **Settings page** — manage profile (name, email), password, 2FA, and registered passkeys.
- **Passkey management** — register multiple passkeys by name, view last-used date, delete individual passkeys.
