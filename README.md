# PDF Viewer Platform

> A self-hosted, open-source PHP platform for publishing, sharing, and tracking PDF documents online — with a fully configurable viewer, analytics, and multi-user support.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP: 8.0+](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)
[![MySQL: 5.7+](https://img.shields.io/badge/MySQL-5.7%2B-orange.svg)](https://mysql.com)
[![Docker Ready](https://img.shields.io/badge/Docker-Ready-2496ED?logo=docker&logoColor=white)](docker-compose.yml)

**Repository:** `github.com/senthilnasa/pdf-viewer`

---

## Table of Contents

- [Features](#features)
- [Quick Start](#quick-start)
- [Deployment Guide A — Docker](#deployment-guide-a--docker)
- [Deployment Guide B — Shared Hosting](#deployment-guide-b--shared-hosting-apache--php--mysql)
- [Configuration Reference](#configuration-reference)
- [URL Structure](#url-structure)
- [User Roles](#user-roles)
- [Viewer Header & Footer Manager](#viewer-header--footer-manager)
- [Google OAuth2 Setup](#google-oauth2-setup)
- [Analytics & Reports](#analytics--reports)
- [Demo / Test Mode & Cron Jobs](#demo--test-mode--cron-jobs)
- [Security Notes](#security-notes)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

---

## Features

| Category            | What's Included                                                                                                  |
| ------------------- | ---------------------------------------------------------------------------------------------------------------- |
| **Viewer**          | PDF.js renderer · lazy page loading · thumbnails · zoom · rotate · fullscreen · text search · keyboard shortcuts |
| **Viewer Branding** | Configurable header logo · title · subtitle · colors · footer · per-document or global presets                   |
| **Analytics**       | Visit tracking · unique IPs · page-level heatmap · Chart.js dashboards · 30/90-day trends                        |
| **Auth**            | Local login + Google OAuth2 · rate limiting · CSRF protection · password reset · invite system                   |
| **Multi-user**      | Admin / Editor / Viewer roles · team management                                                                  |
| **PDF Management**  | Upload · edit · replace file without changing URL · delete · slug management                                     |
| **Share Links**     | Expiry dates · max view count · password-protected tokens                                                        |
| **Reports**         | CSV export · date / IP / document filters                                                                        |
| **SEO**             | Per-document meta title · meta description · OpenGraph · auto `sitemap.xml`                                      |
| **Integrations**    | Google Analytics 4 · Cloudflare Analytics                                                                        |
| **Performance**     | Gzip · browser caching · lazy rendering · PDO prepared statements · OPcache (Docker)                             |
| **Security**        | MIME-type file validation · PDO prepared statements · CSRF tokens · rate limiting · uploads directory hardened   |

---

## Quick Start

Choose your deployment method:

| I want to…                                                | Go to                                                                                         |
| --------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Run locally or on a VPS with Docker                       | [Deployment Guide A — Docker](#deployment-guide-a--docker)                                    |
| Deploy on shared hosting (cPanel, Hostinger, SiteGround…) | [Deployment Guide B — Shared Hosting](#deployment-guide-b--shared-hosting-apache--php--mysql) |

---

## Deployment Guide A — Docker

### Prerequisites

| Tool           | Minimum version                  |
| -------------- | -------------------------------- |
| Docker Engine  | 20.10+                           |
| Docker Compose | v2.0+ (plugin, `docker compose`) |
| RAM            | 512 MB free                      |
| Disk           | 1 GB free                        |

Verify your installation:

```bash
docker --version          # Docker version 24.x.x
docker compose version    # Docker Compose version v2.x.x
```

---

### Step 1 — Clone the Repository

```bash
git clone https://github.com/senthilnasa/pdf-viewer.git
cd pdf-viewer
```

---

### Step 2 — Create Your Environment File

```bash
cp .env.example .env
```

Open `.env` and edit the values:

```dotenv
# Application
APP_PORT=8080
APP_BASE_URL=http://localhost:8080   # Use your real domain in production

# Database credentials — change all passwords!
DB_NAME=pdf_viewer
DB_USER=pdfviewer
DB_PASS=strong_password_here
DB_ROOT_PASS=strong_root_password_here
DB_PORT_EXPOSE=3306

# phpMyAdmin (optional)
PMA_PORT=8081
```

> **Production tip:** Set `APP_BASE_URL` to your actual domain, e.g. `https://docs.yourcompany.com`

---

### Step 3 — Build and Start Containers

```bash
docker compose up -d --build
```

This starts:

- **`pdfviewer_app`** — PHP 8.2 + Apache on port `APP_PORT` (default `8080`)
- **`pdfviewer_db`** — MySQL 8.0, schema auto-imported from `database.sql`

Wait ~15 seconds for MySQL to initialise, then check:

```bash
docker compose ps          # All containers should show "running"
docker compose logs app    # Watch app startup logs
docker compose logs db     # Confirm "ready for connections"
```

---

### Step 4 — Run the Installer

Open in your browser:

```
http://localhost:8080/install.php
```

Follow the 4-step wizard:

| Step            | What happens                                                      |
| --------------- | ----------------------------------------------------------------- |
| 1. Welcome      | Overview + pre-flight info                                        |
| 2. Requirements | System check (PHP extensions, writable dirs)                      |
| 3. Configure    | Enter DB credentials (pre-filled from ENV) + create admin account |
| 4. Done         | Installation complete                                             |

> **Security:** After installation, remove or restrict `install.php`:
>
> ```bash
> docker compose exec app rm /var/www/html/install.php
> ```

---

### Step 5 — Access the Admin Panel

```
http://localhost:8080/admin/
```

Log in with the admin email and password you set in Step 4.

---

### Optional — phpMyAdmin

Start with the `tools` profile:

```bash
docker compose --profile tools up -d
# Access at: http://localhost:8081
```

---

### Docker Management Commands

```bash
# View live logs
docker compose logs -f app

# Stop all containers
docker compose down

# Stop and delete all data (database, uploads, config)
docker compose down -v

# Restart app only
docker compose restart app

# Run a shell inside the app container
docker compose exec app bash

# Update to latest code
git pull
docker compose up -d --build
```

---

### Production Docker Deployment (VPS / Cloud)

#### A. With a Reverse Proxy (Nginx / Traefik)

If you run Nginx or Traefik in front, change the app port to an internal port only:

```dotenv
APP_PORT=127.0.0.1:8080   # Bind only on localhost
APP_BASE_URL=https://yourdomain.com
```

Sample Nginx reverse proxy config:

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name yourdomain.com;

    ssl_certificate     /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;
        client_max_body_size 110M;
    }
}
```

#### B. SSL with Let's Encrypt (Certbot)

```bash
apt install certbot python3-certbot-nginx
certbot --nginx -d yourdomain.com
```

#### C. Automatic Restart on Server Reboot

```bash
# Enable Docker to start on boot
systemctl enable docker

# Containers already set restart: unless-stopped in docker-compose.yml
```

---

### Updating the Application (Docker)

```bash
# Pull latest code
git pull origin main

# Rebuild and restart — volumes are preserved
docker compose up -d --build
```

---

## Deployment Guide B — Shared Hosting (Apache + PHP + MySQL)

### Prerequisites

Verify these with your hosting provider's PHP info page:

| Requirement     | Minimum                                 |
| --------------- | --------------------------------------- |
| PHP             | 8.0+                                    |
| Extensions      | `pdo_mysql`, `fileinfo`, `json`, `curl` |
| MySQL / MariaDB | 5.7+ / 10.3+                            |
| Apache          | 2.4+ with `mod_rewrite`                 |
| `AllowOverride` | Must be `All` (for `.htaccess` to work) |
| Upload limit    | Recommended: 50 MB+                     |

> Most shared hosts (cPanel, Hostinger, SiteGround, Namecheap, A2 Hosting) meet all requirements.

---

### Step 1 — Download the Files

**Option A — Git (if available on your host)**

```bash
git clone https://github.com/senthilnasa/pdf-viewer.git public_html/pdf-viewer
```

**Option B — ZIP upload**

1. Download the ZIP from GitHub → Releases
2. Extract on your computer
3. Upload the extracted folder to your server via FTP / cPanel File Manager

---

### Step 2 — Choose Your Document Root

| Scenario                                  | Recommended path                                  |
| ----------------------------------------- | ------------------------------------------------- |
| Subdirectory: `yourdomain.com/pdf-viewer` | Upload to `public_html/pdf-viewer/`               |
| Subdomain: `docs.yourdomain.com`          | Upload to `public_html/docs/` (or subdomain root) |
| Root domain: `yourdomain.com`             | Upload directly to `public_html/`                 |

---

### Step 3 — Create the MySQL Database

**Via cPanel:**

1. Login to cPanel → **MySQL Databases**
2. Create a new database: e.g. `yourusername_pdfviewer`
3. Create a new user: e.g. `yourusername_pdfuser` with a strong password
4. **Add User to Database** — grant **All Privileges**
5. Note your database host (usually `localhost`)

**Via phpMyAdmin (alternative):**

1. cPanel → phpMyAdmin → New database

---

### Step 4 — Configure Database Connection

Open `config/database.php` and fill in your credentials:

```php
return [
    'host'     => 'localhost',       // Usually 'localhost' on shared hosting
    'port'     => 3306,
    'name'     => 'yourusername_pdfviewer',
    'username' => 'yourusername_pdfuser',
    'password' => 'your_strong_password',
    'charset'  => 'utf8mb4',
    'options'  => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ],
];
```

---

### Step 5 — Set Your Base URL

Open `config/app.php` and update `base_url`:

```php
'base_url' => 'https://yourdomain.com/pdf-viewer',  // No trailing slash
```

> If installed in root: `'base_url' => 'https://yourdomain.com'`

---

### Step 6 — Set Directory Permissions

Connect via FTP or use cPanel File Manager:

```
uploads/     → 755  (or 775 if needed by your host)
config/      → 755  (or 775)
```

To set via SSH:

```bash
chmod 755 uploads/ config/
chmod 644 config/app.php config/database.php
```

---

### Step 7 — Run the Web Installer

Navigate to:

```
https://yourdomain.com/pdf-viewer/install.php
```

The 4-step wizard will:

1. ✅ Check PHP extensions and writable directories
2. ✅ Test and create the database connection
3. ✅ Import the full database schema
4. ✅ Create your admin account
5. ✅ Write `config/app.php` and `config/database.php`

---

### Step 8 — Delete the Installer

> **Critical security step.** Anyone who finds `install.php` can reset your database.

Delete via FTP, cPanel File Manager, or SSH:

```bash
rm public_html/pdf-viewer/install.php
```

Or rename it: `install.php` → `install.php.bak`

---

### Step 9 — Access the Admin Panel

```
https://yourdomain.com/pdf-viewer/admin/
```

---

### Shared Hosting Tips

#### `.htaccess` Not Working?

If URLs like `/pdf/my-document` return 404:

1. Check that `mod_rewrite` is enabled (ask your host)
2. In cPanel → **Apache Handlers** or **MultiPHP INI Editor** — ensure `.htaccess` is allowed
3. Some hosts require `AllowOverride All` in the server's Apache config — contact support

#### Upload Size Limits

If PDF uploads fail on large files, add to `.htaccess`:

```apache
php_value upload_max_filesize 100M
php_value post_max_size 105M
php_value max_execution_time 120
```

Or create/edit `php.ini` in your root:

```ini
upload_max_filesize = 100M
post_max_size       = 105M
max_execution_time  = 120
memory_limit        = 256M
```

#### Subdomain Setup (docs.yourdomain.com)

1. cPanel → **Subdomains** → create `docs.yourdomain.com` pointing to `public_html/docs/`
2. Upload files to `public_html/docs/`
3. Set `base_url` to `https://docs.yourdomain.com`

#### SSL Certificate

Most shared hosts provide free Let's Encrypt SSL via cPanel → **SSL/TLS** → **Let's Encrypt**.

Once HTTPS is active, update `config/app.php`:

```php
'base_url' => 'https://yourdomain.com/pdf-viewer',
```

---

### Updating on Shared Hosting

**Via Git:**

```bash
git pull origin main
```

**Via FTP:**

1. Download the latest ZIP from GitHub
2. Upload and overwrite all files **except** `config/app.php` and `config/database.php`
3. Your uploads, config, and database remain intact

---

## Configuration Reference

### `config/app.php`

| Key                          | Default              | Description                               |
| ---------------------------- | -------------------- | ----------------------------------------- |
| `site_name`                  | `'PDF Viewer'`       | Site name shown in UI and page titles     |
| `base_url`                   | `'http://localhost'` | Full base URL, no trailing slash          |
| `timezone`                   | `'UTC'`              | PHP timezone string                       |
| `upload_directory`           | `uploads/`           | Absolute path to uploads folder           |
| `max_upload_size`            | `52428800`           | Max PDF size in bytes (default 50 MB)     |
| `google_oauth_client_id`     | `''`                 | Google OAuth2 Client ID                   |
| `google_oauth_client_secret` | `''`                 | Google OAuth2 Client Secret               |
| `google_oauth_redirect_uri`  | `''`                 | OAuth callback URL                        |
| `google_allowed_domains`     | `[]`                 | Allowed domains e.g. `['senthilnasa.me']` |
| `enable_public_viewing`      | `true`               | Allow anonymous PDF viewing               |
| `analytics_enabled`          | `true`               | Enable built-in visit tracking            |
| `ga_measurement_id`          | `''`                 | Google Analytics 4 ID                     |
| `demo_mode`                  | `false`              | Show demo credentials on login page       |
| `login_rate_limit`           | `5`                  | Max failed login attempts                 |
| `login_rate_window`          | `900`                | Rate limit window in seconds (15 min)     |

### Database Settings (Admin → Settings)

All settings are also manageable through the admin UI and stored in the `settings` table.

---

## URL Structure

| URL                       | Page                                       |
| ------------------------- | ------------------------------------------ |
| `/`                       | Public document library                    |
| `/pdf/{slug}`             | PDF viewer                                 |
| `/pdf/{slug}?token=abc`   | Share link access                          |
| `/admin/`                 | Dashboard                                  |
| `/admin/pdfs.php`         | PDF manager (upload, edit, replace, share) |
| `/admin/analytics.php`    | Analytics with charts                      |
| `/admin/reports.php`      | CSV reports                                |
| `/admin/team.php`         | User & role management                     |
| `/admin/settings.php`     | Application settings                       |
| `/admin/viewer-style.php` | **Header & Footer Manager**                |
| `/sitemap.xml`            | Auto-generated sitemap                     |

---

## User Roles

| Permission           | Admin | Editor | Viewer |
| -------------------- | :---: | :----: | :----: |
| View admin dashboard |   ✓   |   ✓    |   ✓    |
| Upload & manage PDFs |   ✓   |   ✓    |   —    |
| View analytics       |   ✓   |   ✓    |   ✓    |
| Export reports       |   ✓   |   ✓    |   ✓    |
| Delete PDFs          |   ✓   |   —    |   —    |
| Manage team          |   ✓   |   —    |   —    |
| Change settings      |   ✓   |   —    |   —    |
| Viewer style manager |   ✓   |   —    |   —    |

---

## Viewer Header & Footer Manager

Located at **Admin → Viewer Style** (`/admin/viewer-style.php`).

### What You Can Configure

**Header:**

- Show / hide the entire header bar
- Upload a logo image (PNG, JPG, SVG, WebP)
- Set title and subtitle text
- Pick background and text colors with a live color picker
- Toggle: file info (page count, file size), Share button, Download button

**Footer:**

- Show / hide the footer bar
- Set left-side text (copyright, organisation name)
- Pick background and text colors
- Toggle page number display

**Canvas Theme:**

- **Dark** — dark grey background (default, best for reading)
- **Light** — white/light grey background
- **Auto** — follows the user's OS dark/light preference

### Global vs Per-Document

- **Global** — applies to all documents (no document selected in dropdown)
- **Per-document** — select a specific document from the dropdown; its settings override the global ones

Live preview updates instantly as you change colors, so you can see the result before saving.

---

## Google OAuth2 Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (or select an existing one)
3. Navigate to **APIs & Services → Credentials**
4. Click **Create Credentials → OAuth 2.0 Client ID**
5. Application type: **Web application**
6. Add Authorized redirect URI:
   ```
   https://yourdomain.com/pdf-viewer/api/auth.php?action=google_callback
   ```
7. Copy the **Client ID** and **Client Secret**
8. In the admin panel: **Settings → Google OAuth2 Login**
   - Enable Google Sign-In ✓
   - Paste Client ID and Client Secret
   - Set the Redirect URI to match what you entered in Google Console
   - Optionally restrict to email domains (e.g. `senthilnasa.me`)

---

## Analytics & Reports

### Built-in Analytics

Every document view logs:

- Visitor IP address
- User agent
- Referrer URL
- Session ID
- Timestamp

Every page scroll logs page number + timestamp for the heatmap.

### Dashboard Charts

- **Views over time** — bar chart, last 7/14/30/90 days
- **Top documents** — sorted by total view count
- **Page heatmap** — per-page view count bar chart for selected document

### Export

**Admin → Reports → Export CSV**

Filters:

- Date range
- Specific document
- Visitor IP

Available exports:

- **Summary CSV** — one row per document with totals
- **Detail CSV** — one row per visit with IP, user agent, referrer, time

---

## Demo / Test Mode & Cron Jobs

### Demo Mode

Enable **Demo Mode** in **Admin → Settings → Demo / Test Mode** to allow anyone to explore the admin panel without permanently changing anything.

| Feature | Detail |
|---|---|
| Snapshot | Settings are saved as a baseline at activation time |
| Auto-reset | Settings restore to snapshot on a configurable interval (5 min – 24 h) |
| Pseudo-cron | Reset fires automatically on any page load when interval elapses |
| Server cron | Optional — for more precise timing when traffic is low |
| Credentials | Demo login credentials shown on the login page (set in `config/app.php`) |

### `cron.php` — Scheduled Task Runner

A dedicated cron script is located at the project root: **`cron.php`**

It handles all scheduled tasks (currently: demo reset). It works both via **PHP CLI** and **HTTP**.

#### HTTP call (with token)

```
GET /cron.php?token=YOUR_CRON_TOKEN
GET /cron.php?token=YOUR_CRON_TOKEN&force=1   # bypass interval check
```

Returns JSON:
```json
{
  "success": true,
  "message": "Settings reset to demo snapshot.",
  "ran_at": "2025-03-07 14:00:00",
  "tasks": [
    { "task": "demo_reset", "status": "ok", "message": "Settings reset to demo snapshot." }
  ]
}
```

#### Server crontab — HTTP (every 60 minutes)

```cron
0 * * * * wget -qO- "https://yourdomain.com/cron.php?token=YOUR_TOKEN" > /dev/null 2>&1
```

#### Server crontab — PHP CLI (every 60 minutes, with log)

```cron
0 * * * * php /var/www/html/cron.php >> /var/log/pdfviewer-cron.log 2>&1
```

#### Finding your cron token

Go to **Admin → Settings → Demo / Test Mode → Server Cron Setup**.
The full ready-to-paste crontab commands are shown there with your actual token embedded.

> **Note:** If you don't have server cron access (shared hosting), the built-in **pseudo-cron** fires automatically on every page load — no configuration needed.

---

## Security Notes

- All SQL queries use **PDO prepared statements** — no SQL injection possible
- File uploads validated by **MIME type** (via `finfo`), not just extension
- **CSRF tokens** on every state-changing form
- **Rate limiting** on login — 5 attempts per 15 minutes by default (configurable)
- `uploads/` directory has its own `.htaccess` that **blocks PHP execution**
- PDF files are served through `api/serve-pdf.php` — **real file paths are never exposed** to the browser
- Sessions are hardened: `httponly`, `strict_mode`, `secure` (on HTTPS)

---

## Troubleshooting

### 404 on `/pdf/{slug}`

- Confirm Apache `mod_rewrite` is enabled
- Confirm `AllowOverride All` is set for your document root
- Check `.htaccess` was uploaded (some FTP clients skip dot-files)

### Database Connection Failed

- Double-check credentials in `config/database.php`
- On shared hosting: hostname is usually `localhost`, not an IP
- Confirm the DB user has privileges on the correct database

### PDF Uploads Fail

- Check `uploads/` is writable (`chmod 755` or `775`)
- Increase PHP limits: `upload_max_filesize`, `post_max_size` in `.htaccess` or `php.ini`
- Check `config/app.php` → `max_upload_size` matches your PHP limits

### Google OAuth Redirect Error

- Confirm the Redirect URI in Google Console **exactly** matches the one in Settings
- Include `https://` — Google rejects `http://` for production
- Ensure `curl` PHP extension is installed

### Docker: "permission denied" on uploads

```bash
docker compose exec app chown -R www-data:www-data /var/www/html/uploads
docker compose exec app chmod -R 775 /var/www/html/uploads
```

### Docker: MySQL "Access denied"

- Delete the named volume so MySQL re-initialises:
  ```bash
  docker compose down -v
  docker compose up -d
  ```

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

1. Fork the repo
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Commit with clear messages
4. Open a pull request

Please:

- Use PHP 8.0+ syntax only
- Keep all SQL queries using PDO prepared statements
- Add CSRF protection to any new POST forms
- Test on both Apache shared hosting and Docker before submitting

---

## License

MIT — see [LICENSE](LICENSE).

---

## File Structure

```
pdf-viewer/
├── admin/
│   ├── index.php              # Dashboard (stats, charts)
│   ├── pdfs.php               # PDF manager (upload, edit, replace, share)
│   ├── analytics.php          # Analytics page
│   ├── reports.php            # Reports + CSV export
│   ├── team.php               # Team management
│   ├── settings.php           # Application settings
│   ├── viewer-style.php       # Header & Footer Manager
│   ├── login.php
│   ├── forgot-password.php
│   ├── accept-invite.php
│   └── partials/
│       ├── sidebar.php
│       └── topbar.php
├── api/
│   ├── auth.php               # Google OAuth callback + logout
│   ├── analytics.php          # Page view tracking (JSON API)
│   └── serve-pdf.php          # Secure PDF streaming (range requests)
├── assets/
│   ├── css/
│   │   ├── admin.css          # Admin panel styles
│   │   ├── viewer.css         # PDF viewer styles (dark/light/auto)
│   │   └── public.css         # Public listing page styles
│   └── js/
│       └── viewer.js          # PDF.js integration (lazy load, thumbnails, search)
├── config/
│   ├── app.php                # Main config (gitignored — copy from .example)
│   ├── app.php.example        # Sample app config (tracked in git)
│   ├── database.php           # DB credentials (gitignored)
│   └── database.php.example   # Sample DB config (tracked in git)
├── docker/
│   ├── nginx/apache.conf      # Apache virtual host for Docker
│   ├── php/php.ini            # PHP runtime settings for Docker
│   ├── php/opcache.ini        # OPcache settings
│   └── entrypoint.sh          # Docker entrypoint (writes config from ENV)
├── includes/
│   ├── Database.php           # PDO singleton (query, fetchAll, fetchOne, insert)
│   ├── Auth.php               # Auth: login, Google OAuth, CSRF, rate limiting
│   ├── PDF.php                # PDF: upload, CRUD, slugs, share links
│   ├── Analytics.php          # Analytics: visit tracking, reports, stats
│   └── helpers.php            # Utilities: bootstrap, e(), getSetting, exportCsv…
├── uploads/
│   ├── .gitkeep
│   └── .htaccess              # Blocks PHP execution in uploads
├── viewer/
│   └── index.php              # PDF viewer page (header, toolbar, PDF.js canvas, footer)
├── .env.example               # Docker environment template
├── .gitignore
├── .htaccess                  # URL rewriting, gzip, caching, security headers
├── Dockerfile                 # PHP 8.2 + Apache image
├── docker-compose.yml         # App + MySQL + optional phpMyAdmin
├── database.sql               # Complete DB schema (6 tables)
├── install.php                # Web installer (delete after use)
├── index.php                  # Public home page + sitemap router
├── README.md
├── LICENSE                    # MIT
└── CONTRIBUTING.md
```
