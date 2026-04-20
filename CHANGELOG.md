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
