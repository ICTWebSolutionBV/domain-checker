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

## [1.10.2] — 2026-08-04

No user-facing behaviour changes. Maintenance release covering two fresh Guzzle
advisories and a routine dependency refresh.

### Security
- **Guzzle updated 7.15.1 → 7.15.2**, patching two advisories published 2026-08-03 against the HTTP client behind every outbound request (`RdapService`, `BulkDnsService`, `IpLookupService`, `TldRepository`):
  - `GHSA-v5mv-p594-2x33` / `CVE-2026-69246` (high) — a noncanonical host (trailing dot, mixed case, encoded forms) could bypass host-based checks. Reachable in principle via the redirects these services follow at Laravel's defaults, since a redirect target's host is what such checks compare.
  - `GHSA-f7vp-7xgx-4w4r` / `CVE-2026-69245` (medium) — a noncanonical cookie domain retained subdomain scope. Low practical exposure here: no cookie jar is enabled on any of the four services, so cookies are neither persisted nor replayed across hosts.
  - Both advisories also cover Guzzle 8.0.0; the tree stays on the 7.x line (`laravel/framework` requires `^7.8.2`), so 7.15.2 is the patched release rather than 8.0.1.
- `composer audit` and `npm audit` both report no advisories.

### Changed
- **Composer refresh** — `laravel/framework` 13.22.0 → 13.23.0, `inertiajs/inertia-laravel` 3.1.1 → 3.2.1, `league/commonmark` 2.8.3 → 2.9.0, symfony components 8.1.x → 8.1.2/8.1.3, `symfony/polyfill-*` 1.38.x → 1.41.0. Dev-only: `laravel/pint` 1.29.3 → 1.30.3, `phpunit/phpunit` 13.2.5 → 13.2.6, `phpunit/php-code-coverage` 14.2.3 → 14.2.4, `sebastian/cli-parser` 5.0.0 → 5.0.1, `sebastian/recursion-context` 8.0.0 → 8.0.1.
- **npm refresh** — `vite` 8.1.5 → 8.2.0 (rolldown 1.1.5 → 1.2.2, lightningcss 1.33.0), `axios` 1.18.1 → 1.19.0, `@vueuse/core` 14.3.0 → 14.4.0, `postcss` 8.5.23 → 8.5.25.
- Major bumps available but not taken, since nothing in the tree requires them: `guzzlehttp/guzzle` 8.0.1 and its `promises`/`psr7`/`uri-template` 2.x–3.x siblings, `brick/math` 0.19.0, `hamcrest/hamcrest-php` 3.0.0.

### Verified
- `php artisan test` — 2 passed, and `npm run build` completes clean on Vite 8.2.0.

---

## [1.10.1] — 2026-07-25

No user-facing behaviour changes. Maintenance release covering dependency
security fixes and test-tooling upgrades.

### Security
- **Guzzle updated 7.12.1 → 7.15.1**, picking up four upstream advisory fixes in the HTTP client that backs every outbound request (RDAP registries, ip-api.com, IANA). Redirect following is left at Laravel's default throughout `RdapService`, `BulkDnsService`, `IpLookupService` and `TldRepository`, which is the surface two of these cover:
  - `GHSA-94pj-82f3-465w` — prevent first-class and proxy URL credentials from reaching origins (7.14.2).
  - `GHSA-wm3w-8rrp-j577` — preserve host-only cookie scope and require explicit persistence markers (7.15.1).
  - `GHSA-f283-ghqc-fg79` — bound response cookie admission and generated `Cookie` headers (7.15.1).
  - `GHSA-h95v-h523-3mw8` — exclude URI fragments from `Referer` headers generated for redirects (7.15.1).
- `composer audit` and `npm audit` both report no advisories.

### Changed
- **Composer `php` constraint raised `^8.3` → `^8.4`.** This corrects a stale manifest rather than dropping support: the README has documented PHP 8.4+ as a requirement for some time, and `laravel/framework` v13.22.0 pulls symfony 8.x (`php >=8.4.1`), so the committed lock could not install on 8.3 regardless. Deployment requirements are unchanged.
- **PHPUnit 12 → 13** (`^12.5.12` → `^13.2`). Dev-only; `phpunit.xml` needed no migration and runs clean with `--display-deprecations --display-warnings`.
- **Dependency refresh** — `laravel/framework` 13.20.0 → 13.22.0, `laravel/fortify` 1.37.2 → 1.37.3, `laravel/serializable-closure` 2.0.13 → 2.0.15, `concurrently` 10.0.3 → 10.0.4, `postcss` 8.5.23, `es-toolkit` 1.50.0.

### Removed
- **`brick/math: ^0.17` pin.** Added in 8c60e59 to avoid a conflict between `laravel/framework`'s `^0.17` cap and `ramsey/uuid`'s inclusive `<=0.18` bound. Framework v13.22.0 now accepts `^0.18`, as do `cbor-php`, `pki-framework` and `cose-lib`, so the pin had become the only constraint holding the package back (now 0.18.0).
- **`shell-quote` npm override.** `GHSA-395f-4hp3-45gv` covers `<=1.8.4` and `concurrently` 10.0.4 pins an already-patched 1.9.0, so the override fixed nothing while hoisting a floating `^1.10.0` over concurrently's tested pin.

---

## [1.10.0] — 2026-06-07

### Added
- **Bulk DNS Lookup tool** — new `/dns` page (linked from the main navigation) that resolves MX, NS, TXT, A, AAAA, or CNAME records for up to 100 domains at once.
- **IP geolocation columns** — Country (with emoji flag), Region, City, ISP, and ASN are fetched alongside each domain's DNS records using ip-api.com's batch endpoint. Geo results are cached server-side for 1 hour; DNS records for 5 minutes.
- **4 concurrent geo workers** — IPs are split into up to 4 chunks and dispatched to ip-api.com in parallel via `Http::pool()`, keeping batch lookups fast regardless of list size.
- **Show / Hide geo toggle** — Eye button in the results toolbar collapses all five geo columns for a clean DNS-only view.
- **IP links** — resolved IPs in the IP column and in A/AAAA record cells link directly to the IP Lookup page (`/ip?q=<ip>`), opening in a new tab.
- **Instant record-type refresh** — switching the record-type selector (MX → NS → TXT, etc.) while results are on screen immediately re-runs the lookup without clearing the domain list or geo data.
- **Copy as TSV** — toolbar button copies the full results table (including geo columns, respecting the current show/hide state) as tab-separated values ready to paste into Excel or a spreadsheet.
- **Rate limiter `dns-bulk`** — 30 requests/minute for authenticated users, 5 requests/minute for guests.

---

## [1.9.2] — 2026-06-05

### Changed
- **Registration modal — UI overhaul for readability.** Inputs now use a white background with clearly visible `gray-300` borders (previously `gray-50/gray-200` — near-invisible on the white modal). Labels bumped to `text-sm font-medium text-gray-700`. Form fields reorganised into three named sections — Contact details, Address, and Business — with uppercase tracking-wide sub-headers as visual anchors. Vertical spacing increased throughout.
- **Registration details marked as required.** Subtitle, section header, hint text, and help instructions all updated to remove "optional" language.
- **"Copy to clipboard" button renamed to "Fill in details and request"** in the floating selection bar (Home) and the copy button (Transfer page).
- **Help / how-to instructions added.** A ghost outlined "How does this work?" button in the modal header and on the Transfer page hero expands a collapsible numbered step list explaining the full ordering flow.

---

## [1.9.1] — 2026-06-04

### Added
- **Mobile side drawer.** Navigation links collapse into a slide-in drawer on small screens, replacing the previous approach and giving a cleaner mobile experience.

### Fixed
- **Visitor IP detection.** Correctly resolves the real client IP behind proxies/load balancers.
- **My IP page now shows both IPv4 and IPv6** addresses when both are available.

---

## [1.9.0] — 2026-06-04

### Added
- **My IP page.** New `/my-ip` route shows the visitor's current IP address, geolocation data, and browser/device information. Linked from the main navigation.

---

## [1.8.0] — 2026-06-03

### Added
- **Bulk Check mode.** Toggle on the home page switches to a textarea where users can paste a list of complete domains (e.g. `example.com`, `example.nl`) and check all of them in one go via a dedicated SSE endpoint. Results stream in alongside the existing single-domain results.

---

## [1.7.0] — 2026-06-02

### Added
- **Redirect Checker tool.** New `/redirects` page traces the full redirect chain of any URL — shows each hop with its status code, location header, and timing. Powered by a dedicated `RedirectCheckService` with configurable max-hops and timeout. Linked from the main navigation.

---

## [1.6.5] — 2026-05-26

### Security
- Patched three Symfony advisories flagged by `composer audit`:
  - **`symfony/http-foundation`** v8.0.8 → v8.1.0 — [CVE-2026-48736](https://symfony.com/cve-2026-48736): `IpUtils::PRIVATE_SUBNETS` omits IPv6 transition forms (6to4, NAT64, Teredo, IPv4-compatible), enabling SSRF bypass in `NoPrivateNetworkHttpClient`.
  - **`symfony/routing`** v8.0.12 → v8.1.0 — [CVE-2026-48784](https://symfony.com/cve-2026-48784): `UrlGenerator` dot-segment encoding skips every other chained `../` or `./`, so a generated URL can collapse off-route under RFC 3986 normalization.
  - **`symfony/polyfill-intl-idn`** v1.37 → v1.38.1 — [CVE-2026-46644](https://symfony.com/cve-2026-46644): accepts `xn--` labels whose Punycode payload decodes to ASCII-only, causing insecure equivalence.
- `composer audit` now reports zero advisories. Build and test suite verified green.

---

## [1.6.4] — 2026-05-21

### Changed
- **Dependency maintenance.** Updated all Composer and npm packages to their latest versions within the existing `composer.json` / `package.json` constraints — no version constraints were widened, so this is a non-breaking refresh. Notable bumps: `laravel/framework` 13.5 → 13.11, `laravel/fortify` 1.36 → 1.37, `spatie/laravel-passkeys` 1.7.0 → 1.7.3, `inertiajs/inertia-laravel` 3.0 → 3.1, `@inertiajs/vue3` 3.0 → 3.2, `tailwindcss` 4.2 → 4.3, `vite` 8.0.9 → 8.0.13, `vue` 3.5.32 → 3.5.34. Build and test suite verified green. `composer audit` reports no known vulnerabilities.

---

## [1.6.3] — 2026-05-01

### Changed
- **"Transfer" nav link is now a highlighted call-to-action** — emerald/teal gradient pill with a soft glow shadow, white bold text, and a small pulsing amber dot in the corner. Sits next to the muted plain nav links (Domain check, HTTP/3, IP Lookup) so it visibly pops for visitors who land on the site.

---

## [1.6.2] — 2026-05-01

### Changed
- Tiny spacing tweak in the Transfer page group header — added breathing room between the "N domains" count and the trash/chevron buttons so they don't crowd each other.

---

## [1.6.1] — 2026-05-01

### Changed
- **Transfer page wording — "block" → "group" everywhere it's user-visible.** The page now talks about "groups of domains" with one set of owner details per group, which reads more naturally than the internal "registrant block" name.
- **The group-name field now visibly looks like a renameable input.** It has a dashed border, a small pencil icon next to it, an inviting placeholder ("Name this group (e.g. \"My company domains\")"), and brightens on hover/focus — so it's obvious the requester can give each group a meaningful name instead of leaving the auto "Group 1" label.
- **"Add another registrant block"** button renamed to **"Add domains for another owner"** — clearer about *why* you'd add a second group.
- New groups now start with a blank label (the placeholder shows the example name) rather than an auto-generated "Block N", which both makes the input look more clearly editable and avoids stale labels when groups are added/removed.

---

## [1.6.0] — 2026-05-01

### Added
- **Transfer domains** page at `/transfer`, plus a "Transfer" entry in the top navigation. Public, no login required.
- **Repeater of registrant blocks.** Each block is a collapsible card with its own list of domains and one shared registrant detail set (existing-account reference, or new registrant fields: company, name, address, phone, email, KVK, VAT ID, optional EPP/auth code, optional notes). The single registrant block applies to every domain inside that block. Add as many blocks as needed via "Add another registrant block" — useful when different domains in the same request need different owners.
- **Domain chip input** with paste-many, Enter/comma/space to commit, backspace to remove the last chip, and per-domain validation against a hostname regex.
- **Copy-to-clipboard output** mirroring the existing domain-search pattern: one button assembles a single, neatly formatted plain-text summary (requester header + every block) ready to paste into an email or chat. No data leaves the browser — there is no submission endpoint.
- **Live preview** toggle so the user can see exactly what will be copied before pressing the button.

---

## [1.5.5] — 2026-04-24

### Fixed
- **"Check aborted: Undefined array key `appconnect_time`"** on the HTTP/3 page. `curl_getinfo()` doesn't always populate `appconnect_time` (older curl builds, or failed TLS handshakes leave the timer unset), and the `?:` fallback we were using doesn't suppress the warning like `??` does. Under Laravel's strict error handler that became a fatal, which is why the stream was silently dying right after `altsvc` before v1.5.4 added the try/catch that surfaced it.
- All curl timing reads now coerce to float with `??` first, so missing timers fall back cleanly to 0.

---

## [1.5.4] — 2026-04-24

### Fixed
- **HTTP/3 check stream cutting off after `altsvc` event on production.** The CDN in front of the app (flowguard) was idle-closing the SSE connection while the backend waited on the baseline HTTP/2 probe and the 8-second QUIC probe, so the `server_info`, `http3`, and `done` events never reached the browser and the new detail cards stayed blank.
- Stream now flushes nested output buffers up-front, writes an initial `: ping` comment to open the pipe, emits `: hb` heartbeat comments between slow probes, and wraps the whole pipeline in a try/catch that logs exceptions and emits a `done` error event instead of dying silently.
- JSON encoding of SSE payloads now uses `JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR` so a single bad byte in a response header can't produce empty frames.

---

## [1.5.3] — 2026-04-24

### Added
- **`NoHtmlCache` middleware** applied to every web response. Marks the Inertia HTML shell and Inertia JSON partial responses as `Cache-Control: no-cache, no-store, must-revalidate, private`, so browsers and any CDN in front of the app never hold a stale copy that still references the previous build's asset hashes. Hashed JS/CSS under `/build/*` are served directly by the web server and keep their long-term immutable caching behavior.

### Fixes
- "Deployed a new version but users still see the old UI" — hard-refresh workaround no longer needed.

---

## [1.5.2] — 2026-04-24

### Added
- **Shareable check URLs.** Running an HTTP/3 check now updates the browser URL to `/http3?host=<hostname>`, and running an IP lookup updates it to `/ip?q=<input>`. Copy the URL to share or bookmark the exact result — the Inertia controllers already auto-populate the input and auto-run the check when the query param is present.

---

## [1.5.1] — 2026-04-24

### Added
- **QUIC session strip** at the top of the HTTP/3 server info panel showing **Connection ID**, **Packet RX**, and **Handshake Done** — matching the layout of tools like http3checker.com. Populated when curl is QUIC-capable; the panel stays hidden when we can't negotiate HTTP/3.
- Primary stats redesigned as three large cards: **HTTP Version**, **Status Code**, **Response Time**.
- Response headers now render as a proper **Header / Value** table.

### Changed
- `Http3CheckService` now captures curl's verbose output over a memory stream and parses the Connection ID, ALPN, and TLS cipher from it.
- `checkHttp3` now emits `quic.handshake_done_ms` and `quic.packet_rx_ms` timings alongside the regular response metrics.

---

## [1.5.0] — 2026-04-24

### Added
- **HTTP/3 server information panel** on the `/http3` page: HTTP version used, status code, server IP/port, DNS / connect / TLS / TTFB / total response timings, and the full list of response headers returned by the origin.
- Server info is emitted as a new `server_info` SSE event during the check. When curl has QUIC support, the panel prefers data observed over HTTP/3; otherwise it falls back to the HTTP/2 or HTTP/1.1 probe.

### Notes
- For richer HTTP/3-specific metadata (connection ID, per-packet stats, etc.) the host server needs a curl build linked against `ngtcp2 + nghttp3` or `quiche`. Without it, the panel still reports the server's actual HTTP version and headers, but the numbers come from the HTTP/2/1.1 probe.

---

## [1.4.1] — 2026-04-24

### Changed
- **IP Lookup history is now private.** Recent lookups are kept in the browser's `localStorage` (per-device, per-browser) and expire automatically after 7 days. Nothing is stored on the server.
- Added a "Clear" button on the IP Lookup page to wipe local history on demand.

### Removed
- Server-side `ip_lookups` table (dropped) and the associated global "Recent lookups" list on `/ip`.

### Migration required
```bash
php artisan migrate
```
Drops the `ip_lookups` table added in 1.4.0.

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
