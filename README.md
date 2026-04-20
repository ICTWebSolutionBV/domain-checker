# Domain Checker

[![Version](https://img.shields.io/badge/Version-1.0.0-brightgreen?style=flat-square)](CHANGELOG.md)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![Vue.js](https://img.shields.io/badge/Vue.js-3-4FC08D?style=flat-square&logo=vuedotjs&logoColor=white)](https://vuejs.org)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-4-06B6D4?style=flat-square&logo=tailwindcss&logoColor=white)](https://tailwindcss.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=flat-square)](https://opensource.org/licenses/MIT)

A fast, public domain availability checker built with Laravel and Vue.js. Check a name across 46 popular extensions (or the full IANA list of 1,200+) in real time — no paid API needed. Results stream in one by one via Server-Sent Events using the free RDAP protocol with a WHOIS fallback.

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

---

## Features

### Domain checking
- **46 popular TLDs** checked by default — `.nl`, `.com`, `.be`, `.de`, `.net`, `.org`, `.io`, `.co`, `.eu`, `.app`, `.dev`, `.ai`, and more.
- **Full IANA TLD list** — expand to 1,200+ extensions with one click; list is fetched from IANA and cached daily.
- **RDAP-first** — uses the free [IANA RDAP bootstrap](https://data.iana.org/rdap/dns.json) to find each TLD's authoritative endpoint. HTTP 404 = available, 200 = taken.
- **WHOIS fallback** — for TLDs without RDAP, a PHP socket queries the authoritative WHOIS server with text-pattern parsing.
- **Real-time streaming** — results appear one by one via Server-Sent Events; RDAP queries run concurrently in batches of 10.
- **Result caching** — per-domain results cached 15 min; RDAP bootstrap and IANA list cached 24 h.

### Smart input
- Accepts plain names (`example`), full domains (`example.nl`), or URLs (`https://www.example.nl`).
- Auto-checks when a full domain is typed or pasted (400 ms debounce).
- Pins the explicitly typed TLD to the top of the results.
- Auto-selects the pinned TLD if it comes back available.

### Selection & clipboard
- Checkbox-select any available domains.
- **Select all available** with one click.
- Sticky clipboard bar slides up showing selected count + "Copy to clipboard" — copies all selected full domain names (one per line) for easy pasting in email or WhatsApp.

### Authentication & security
- Public checker — no login required.
- Rate-limited: 10 checks/min for guests, 60/min for authenticated users.
- Login via **WebAuthn passkey** or email + password.
- **TOTP two-factor authentication** with QR setup and 8 recovery codes.
- Settings page: profile, password, 2FA, and passkey management.

### UI
- Light / Dark / Auto theme (no flash on load).
- Subtle dot-grid background pattern.
- Fully responsive — 3-column list on desktop, single column on mobile.

---

## Installation

### Requirements

- PHP 8.4+
- Composer
- Node.js 20+
- MySQL 8.0+ / PostgreSQL 14+ / SQLite

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

# Configure your database in .env, then run migrations
php artisan migrate

# Create the first admin user
php artisan tinker
# >>> \App\Models\User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password')]);

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
- Select **PHP 8.4+**.

### 2. Connect repository

- Go to your site's **Repository** tab.
- Connect to `github.com/ICTWebSolutionBV/domain-checker`.
- Set branch to `main`.
- Enable **Install Composer dependencies**.

### 3. Deploy script

Replace the default deploy script with:

```bash
cd {SITE_DIRECTORY}
git pull origin main

# Ensure required directories exist and are writable (must run before composer/npm)
mkdir -p bootstrap/cache
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
chmod -R 775 storage bootstrap/cache

# Clear any stale compiled files so PHP can write fresh ones during the build
php artisan optimize:clear

composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

npm ci
npm run build

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link

echo "Application deployed!"
```

### 4. Environment variables

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
```

### 5. First admin user

After the first deploy, create your admin user via the Ploi console or an SSH session:

```bash
cd {SITE_DIRECTORY}
php artisan tinker
>>> \App\Models\User::create(['name' => 'Admin', 'email' => 'you@example.com', 'password' => bcrypt('your-password')]);
```

Then log in at `https://your-domain.com/login` and register a passkey or enable 2FA from Settings.

---

## Environment variables

### Application

| Variable | Default | What it does |
|---|---|---|
| `APP_NAME` | `Laravel` | Shown in the browser tab and emails. |
| `APP_ENV` | `local` | Set to `production` when deploying. |
| `APP_KEY` | _(required)_ | Generated by `php artisan key:generate`. Encrypts sessions and 2FA secrets. Never rotate without a plan. |
| `APP_DEBUG` | `true` | Set to `false` in production. |
| `APP_URL` | `http://localhost` | Base URL of the app. Used for passkey WebAuthn origin checks. |

### Database

| Variable | Default | What it does |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | `sqlite`, `mysql`, `pgsql`. |
| `DB_HOST` | `127.0.0.1` | Database server host (not used for SQLite). |
| `DB_DATABASE` | _(sqlite file)_ | Database name or SQLite file path. |
| `DB_USERNAME` / `DB_PASSWORD` | empty | Database credentials. |

### Session & cache

| Variable | Default | What it does |
|---|---|---|
| `SESSION_DRIVER` | `database` | Use `database` or `redis`. |
| `SESSION_LIFETIME` | `120` | Idle session timeout in minutes. |
| `CACHE_STORE` | `database` | Used to cache RDAP bootstrap, TLD list, and domain results. |

### Rate limiting

The domain-check endpoint uses Laravel's named rate limiter `domain-check`: 10 requests/min for guests, 60/min for authenticated users. Adjust in `bootstrap/app.php` if needed.

---

## Versioning

Domain Checker follows [Semantic Versioning](https://semver.org/). The current release is **v1.0.0**. All changes are tracked in [CHANGELOG.md](CHANGELOG.md):

- **Patch (`1.0.x`)** — Bug fixes and small tweaks.
- **Minor (`1.x.0`)** — New features, backwards-compatible.
- **Major (`x.0.0`)** — Breaking changes requiring manual intervention.

---

## License

MIT — see [LICENSE](LICENSE) for details.
