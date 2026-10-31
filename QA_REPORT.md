# QA Report — PDF Viewer Platform audit & fixes

**Scope:** full audit of `senthilnasa/pdf-viewer` (upstream commit `47f3a97`), covering PHP back end, JavaScript front end, SQL schema and migrations, installer, Docker and cron.
**Environment:** PHP 8.3.6 CLI and built-in server (`router.php`), MariaDB 10.11, Chromium (Playwright).
**Result:** **105 / 105** automated checks pass: 89 HTTP/DB end-to-end and 16 browser.

---

## 1. The two reported issues

### 1.1 File upload returns 403 Forbidden

| | |
|---|---|
| **Reproduced on original code** | Uploading a PDF larger than PHP `post_max_size` returned **`403 Invalid CSRF token.`** |
| **Root cause** | When a request body exceeds `post_max_size`, PHP discards all of it, so `$_POST` and `$_FILES` are empty. `verifyCsrf()` then found no token and answered 403. On PHP-FPM/CGI hosting (most shared hosts) the `php_value` limits in `.htaccess` are ignored. The real limit therefore stays at PHP's default of **8 MB**, while the UI advertised 50 MB. |
| **Second trigger** | The CSRF token hard-expired 1 hour after it was issued and was then rotated. Any form left open for more than an hour (typically the upload page) got the same 403, and so did every other open tab. |
| **Fix** | `verifyCsrf()` detects the discarded body and returns **413** with the real reason (JSON for AJAX). The CSRF token is now per-session with a sliding expiry. `.user.ini` added for FPM/CGI hosts. The upload page shows the *effective* limit and rejects oversized files in the browser before uploading. `handleUpload()` now gives readable errors (`UPLOAD_ERR_*`, uploads directory not writable, empty file). `admin-ajax.js` shows the server's error message instead of a generic "Request failed". **No security check was removed.** |
| **Verified** | Oversized upload → 413 with a clear message. Normal upload → OK. Bad token → 403 with a JSON message. Non-PDF → rejected. |

### 1.2 Installer has no installation lock

| | |
|---|---|
| **Reproduced on original code** | After a normal install, `install.php` still worked. An **anonymous POST created a new admin account, which could then log in**. The same request could also reset the real admin's password (`ON DUPLICATE KEY UPDATE password`) or repoint `config/database.php`. Re-running also overwrote the saved settings. |
| **Fix** | New `includes/Installer.php`. A successful install writes `config/install.lock` as its **last** step. While the lock exists, `install.php` answers every request with 403. Installs that pre-date the lock are **detected and auto-locked**. The installer **refuses a database that already has users**, so production data cannot be overwritten. Other hardening: a CSRF token on the installer form, validation of the DB name, host and URL (the DB name was interpolated into SQL), atomic config writes (temp file + rename), and a safe `setConfigValue()` (a site name containing `$1` or `\` used to corrupt `app.php`). The schema and migrations are idempotent, so an interrupted install can simply be re-run. Recovery is documented: delete the lock and use an empty database. |
| **Verified** | Fresh install → lock created. GET and POST → 403. Lock deleted → auto-relocked. `config/` is not web-reachable. |

---

### 1.3 Follow-up: 40 MB upload → 408 Request Timeout

| | |
|---|---|
| **Root cause** | 408 is sent by the **web server or a proxy in front of PHP** when a request body doesn't arrive within its timeout: Apache `mod_reqtimeout` (enabled by default in the Docker image), nginx `client_body_timeout`, LiteSpeed or hosting-panel limits, or a CDN. A 40 MB PDF sent as one request takes long enough on an ordinary uplink to trip these, and PHP never sees the request, so no PHP setting can fix it. |
| **Fix: chunked, resumable uploads** | `includes/ChunkedUpload.php` plus `admin-ajax.js`. The browser sends the PDF in ≤ 2 MB pieces, sized to the server's PHP limits. Each piece is retried with backoff on network errors, 408, 429 or 5xx. The upload button shows progress. The server appends each piece under an exclusive lock and checks its offset. A retried piece that already arrived is acknowledged rather than appended twice. A gap makes the server tell the client where to resume. The finished file goes through the **same validation** as before (size, real MIME sniffing) and is moved into place atomically. Rejected files are deleted. |
| **Security** | Upload IDs are 128-bit random values bound to the user who started the upload. They are format-checked (no path traversal) and single-use, and every request is CSRF- and role-checked. Pieces live in `uploads/.chunks/`, which is not web-accessible. Abandoned uploads are purged after 24 h (by `cron.php` and on each new upload). The file-size cap is checked before any data is sent. |
| **Side benefits** | Uploads now also work behind small body-size limits (nginx's 1 MB default, CDN caps) and on hosts with PHP's 2 MB `upload_max_filesize` default. A form re-submitted after a validation error (e.g. slug taken) re-uses the already uploaded file instead of sending it again. |
| **Verified** | 13 new HTTP checks and 2 browser checks. A 5–6 MB PDF uploaded through a **1 MB PHP limit**, through the API and through the real form with progress. The assembled file is byte-identical and is served correctly including range requests. Also covered: replay blocked, duplicate-piece idempotency, out-of-order resume, malformed IDs, over-limit refusal, non-PDF rejection and cleanup, and the chunk directory not being web-reachable. |
| **Not verifiable here** | I can't reproduce your server's exact 408 (I don't know which web server or proxy it is). The fix removes the cause for any of them, because no single request is large or long-running any more. |

## 2. All bugs found and fixed

Severity: **C** critical · **H** high · **M** medium · **L** low

### Security
| # | Sev | Issue | Root cause | Fix (files) |
|---|---|---|---|---|
| 1 | C | Installer reusable after install → admin takeover | No lock | Install lock (§1.2) — `install.php`, `includes/Installer.php` |
| 2 | C | **Account takeover via "Forgot password"**: the page displayed the live reset link to whoever submitted any email address | Demo code left in | The token is only emailed. The response is identical whether or not the account exists — `admin/forgot-password.php` |
| 3 | H | Cancelling a `confirm()` (e.g. "Delete this PDF permanently?") **still deleted** | AJAX submit handler ignored `defaultPrevented` | `assets/js/admin-ajax.js` |
| 4 | H | Stored XSS: an editor creates a category named `a'><img onerror=…>`, which runs for admins | `json_encode` inside a single-quoted `onclick` | Escaped with `e()` + `JSON_HEX_*` — `admin/categories.php` |
| 5 | H | Login brute-force limit bypassed by sending a random `X-Forwarded-For` | Proxy headers always trusted; limit counted per IP only | Headers trusted only from proxies (`trusted_proxies`, default `auto`); added a per-account limit — `includes/Analytics.php`, `includes/Auth.php`, `includes/AuditLog.php` |
| 6 | H | Private PDFs and "download disabled" PDFs downloadable directly at `/uploads/<file>.pdf` | `uploads/` served every file; the root `.htaccess` `FilesMatch` used a directory path and never matched | Only images are public in `uploads/`; PDFs go through `serve-pdf.php` only — `uploads/.htaccess`, `.htaccess`, `router.php` |
| 7 | H | Branding uploads kept the client's file extension (a `.phtml` polyglot could be written) | `pathinfo()` on the client filename | Extension derived from the sniffed MIME type, random name, 2 MB cap — `admin/viewer-style.php` |
| 8 | H | Password-protected share link kept working **after expiry or view limit** once verified in the session | A verified session skipped all checks | Expiry and limit always enforced — `viewer/index.php` |
| 9 | M | CSV injection (`=HYPERLINK…` in User-Agent/Referer) in report and audit exports | Raw values | `csvSafeCell()` — `includes/helpers.php`, `admin/audit-logs.php` |
| 10 | M | Google OAuth: the allowed-domain restriction was ignored; unverified Google emails accepted; inactive accounts were modified | Setting never passed to `Auth`; no `email_verified` check | `api/auth.php`, `includes/Auth.php` |
| 11 | M | Open-redirect-safe return URL after login was missing (users lost the private-doc link) | `redirect` param ignored | Same-site path only — `admin/login.php` |
| 12 | M | Invitation links never expired | No expiry column | `invite_expires` (7 days) — schema, migration, `team.php`, `accept-invite.php` |
| 13 | M | HTML injection in outgoing emails (names, links, UA) | No escaping in templates | `includes/EmailTemplate.php` |
| 14 | M | SVGs in uploads could run script if opened directly | — | CSP `sandbox` header for SVG — `uploads/.htaccess` |
| 15 | M | Unescaped alert output (`team.php`, `accept-invite.php`, `forgot-password.php`, `viewer-style.php`); toast used `innerHTML` | — | `e()` / `textContent` |
| 16 | M | Path traversal in `LocalStorageProvider` (currently unused) | No `..` check | `includes/Storage.php` |
| 17 | L | `demo-reset.php` disclosed demo state before checking the token; `token[]=x` caused a TypeError | — | `api/demo-reset.php`, `cron.php` |
| 18 | L | Docker: env values written unescaped into PHP (a quote in `DB_PASS` broke the site); MySQL published on all interfaces | — | `docker/entrypoint.sh`, `docker-compose.yml` |
| 19 | L | Root `.htaccess` used Apache 2.2-only `Order/Deny` | — | 2.4 + 2.2 compatible blocks |

### Functional / correctness
| # | Sev | Issue | Fix (files) |
|---|---|---|---|
| 20 | H | **Share link with `max_views=1` never loaded the PDF.** The view was counted before PDF.js fetched the file, which then hit "limit reached" (403) | Session grant for the counted view — `viewer/index.php`, `api/serve-pdf.php` |
| 21 | H | Flipbook went blank after a layout toggle or window resize | StPageFlip `destroy()` **removes** its host element; now recreated — `viewer/index.php` |
| 22 | H | Deactivated or deleted user's open session → **500 on every page** | `currentUser(): ?array` returned `false` (TypeError); `requireLogin()` now logs out — `includes/Auth.php` |
| 23 | H | Audit-log pagination fatal error beyond 50 rows | Sidebar overwrote `$currentPage` with a string — `admin/partials/sidebar.php` |
| 24 | M | Settings showed **old values right after saving**; demo reset stale | Private static cache in `getSetting()` that `setSetting()` never updated — `includes/helpers.php` |
| 25 | M | Pressing **Enter** in Settings took a demo snapshot instead of saving | The first submit button in the form was "Take Snapshot"; added a default Save button — `admin/settings.php` |
| 26 | M | Unicode titles produced slugs the router can't match; empty or invalid custom slugs saved → documents unreachable | `sanitizeSlug()` + validation — `includes/PDF.php`, `admin/pdfs.php` |
| 27 | M | Range requests: end past EOF not clamped (wrong `Content-Length`); suffix ranges (`bytes=-N`) served the wrong bytes | `api/serve-pdf.php` |
| 28 | M | Share-link "copy" modal disappeared after 1 s (auto-reload); invite modal never refreshed the list | `reloadOnClose` — `admin-ajax.js`, `pdfs.php`, `team.php` |
| 29 | M | "Activate" on an *invited* user broke their invite permanently | Only `inactive` users can be activated — `admin/team.php` |
| 30 | M | Deleting a user who owns PDFs → uncaught FK exception (HTML 500 in AJAX) | Clear JSON error — `admin/team.php` |
| 31 | M | PHP/MySQL timezone mismatch: reset tokens created already expired, rate-limit window hours off | DB session `time_zone` aligned with PHP; SQL-side date math — `includes/Database.php`, `includes/Auth.php` |
| 32 | M | "Enable Flipbook Mode" setting had no effect | `viewer/index.php` |
| 33 | M | Email queue / Scheduler never worked: empty SMTP config, NULL `plain_body` TypeError, retries never scheduled, nothing ran the queue | `includes/Scheduler.php`, `cron.php` |
| 34 | M | Default install sent mail with an empty From address (`MAIL FROM:<>`) | `??` → `?:` — `helpers.php`, `EmailProvider.php` |
| 35 | M | Bare LF line endings sent to SMTP (rejected by strict MTAs); duplicate To/Subject headers in the `mail()` fallback | `includes/EmailProvider.php` |
| 36 | M | Upgrades never applied `database-migrations.sql` | New `migrate.php`, run by the Docker entrypoint — `migrate.php`, `docker/entrypoint.sh` |
| 37 | M | Re-running `database.sql` wiped saved settings | `ON DUPLICATE KEY UPDATE value = value` |
| 38 | M | Audit CSV exported only the current 50-row page; the "All time" filter was dropped when paging | `admin/audit-logs.php` |
| 39 | M | PDF edit: invalid enum values → SQL error; the file was replaced even when the edit then failed | Validation first — `admin/pdfs.php` |
| 40 | L | Over-long names → "Data too long" 500 | Length validation — `profile.php`, `team.php`, `accept-invite.php` |
| 41 | L | Email-change audit entry logged the new email as `old_email` | `admin/profile.php` |
| 42 | L | Categories nested more than 2 levels disappeared from the UI | Depth enforced — `includes/Category.php` |
| 43 | L | Viewer: Ctrl+F also toggled fullscreen; keys leaked through the open flipbook; zoom/rotate lost the reading position; observer leak; zoom ± ignored "Fit width"; missing 300 % option | `assets/js/viewer.js` |
| 44 | L | Flipbook re-downloaded the whole PDF on every re-init; blank pages on reopen; layout label out of sync | `viewer/index.php` |
| 45 | L | Homepage ignored the GA ID set in Settings; GA/Cloudflare IDs HTML-escaped inside JS | `index.php`, `viewer/index.php` |
| 46 | L | Google OAuth errors silently dropped on the login page | `admin/login.php` |
| 47 | L | Sidebar showed admin-only links to editors/viewers (403 pages) | `sidebar.php` |
| 48 | L | Dashboards threw if Chart.js (CDN) failed to load; CDN version not pinned | `index.php`, `analytics.php`, `reports.php` |
| 49 | L | Double-escaped `<title>` suffix, 404 message, test-email message; broken SVG icon path; wrong table `colspan` | various |
| 50 | L | `Notification::deleteExpired()` / `Scheduler::cleanupOldTasks()` returned `lastInsertId()`; failed tasks never cleaned up | `rowCount()`, `COALESCE` |
| 51 | L | `api/user.php`: negative `limit`, deactivated session | Clamped / 401 |
| 52 | L | Duplicate index `idx_date_range` in migration | Removed |

### Database integrity & concurrency
| # | Issue | Fix |
|---|---|---|
| 53 | Share-link view limit could be exceeded by concurrent visits (check-then-increment) | Atomic conditional `UPDATE … WHERE view_count < max_views` — `PDF::recordShareLinkView()` |
| 54 | Concurrent uploads, category creates or invites with the same slug/email → uncaught duplicate-key 500 | `Database::isDuplicateKey()`; handled as validation errors |
| 55 | Overlapping cron runs executed the same job twice (duplicate emails) | Atomic claim `UPDATE … WHERE status='pending'` + `rowCount()`; stale "running" jobs recovered |
| 56 | Demo reset rewrote dozens of rows without a transaction | `Database::transaction()` with deadlock (1213) / lock-wait (1205) retry |
| 57 | Installer admin + settings insert not atomic | Transaction with rollback |

No unnecessary locks were added. Every fix uses a single atomic statement or a short transaction.

---

## 3. New feature: PHPMailer support (requested)

* `composer.json` / `composer.lock` → `phpmailer/phpmailer` **v6.12.0**
* `PHPMailerEmailProvider` in `includes/EmailProvider.php`: SMTP with STARTTLS / SMTPS / none, auth, UTF-8, base64 encoding, `mail()` fallback. If the library is missing, the app falls back to the built-in SMTP client.
* Settings → Email → *Delivery Method* → **PHPMailer (SMTP)**. The option shows whether the library is installed.
* The Docker image runs `composer install`. The release ZIP ships `vendor/`.

---

## 4. Tests performed

`tests/run-e2e.sh` installs a **fresh copy** against real MariaDB and runs:

| Area | Checks | Result |
|---|---|---|
| Installation + lock (CSRF, unsafe DB name, lock GET/POST, auto-lock of legacy installs, config not reachable, `$1`/`\` in site name) | 12 | ✅ |
| Authentication (all 11 admin pages render, open redirect, reset-token leak, account enumeration) | 15 | ✅ |
| Uploads (success, unicode slug, **>post_max_size → 413**, non-PDF, bad CSRF, invalid slug, direct `/uploads` access, empty slug on edit) | 9 | ✅ |
| PDF serving (full, range, suffix range, clamped range) | 4 | ✅ |
| Private docs + share links (login redirect and return, 1-view link, password, expiry after verification) | 10 | ✅ |
| Admin features (settings cache, PHPMailer option, category XSS, invite flow, roles, delete-with-docs, deactivated session, audit paging/export, CSV injection, validation, sitemap) | 16 | ✅ |
| CLI (migrate ×2 idempotent, cron job queue, HTTP blocking, **upgrade of an original-schema DB**) | 6 | ✅ |
| Rate limiting (per IP; rotating spoofed `X-Forwarded-For`) | 2 | ✅ |
| PHP error log clean (no warnings, notices or deprecations) | 1 | ✅ |
| **Browser (Chromium):** confirm-Cancel keeps the PDF, OK deletes it, toast XSS, Enter saves settings, viewer renders, Ctrl+F, flipbook open / toggle ×2 / close-reopen, no JS errors | 14 | ✅ |

**Before/after:** on the original code, the same scenarios showed the upload 403 (`Invalid CSRF token.`), the anonymous admin creation through the open installer, and the reset-token leak.

---

## 5. Limitations / not verified here

* **Apache itself was not run.** Rewrite and deny rules were exercised through `router.php` (the PHP built-in server equivalent). The `.htaccess` changes follow Apache 2.4 syntax with 2.2 fallbacks, but were not executed under Apache.
* **Docker image not built** (no Docker daemon in this environment). The entrypoint's PHP config generation was tested in isolation with a hostile password.
* **Real SMTP delivery not tested** (no mail server). PHPMailer and the built-in client were exercised up to configuration and selection. Use *Settings → Test Email Delivery* after deploying.
* **Google OAuth** not tested end to end (requires Google credentials). Only the code paths were reviewed.
* `trusted_proxies` defaults to `auto` (headers trusted from private/loopback peers). If the app is exposed **directly** through Docker port mapping, set it to `[]` or to your proxy's IP. The per-account login limit applies either way.
* Pseudo-cron demo reset still runs on page loads (by design). Use the real cron for exact timing.
* Unused upstream components (`Storage.php` S3/R2 providers, email verification, the `user_sessions` table) were hardened where bugs were found but are not wired into the UI.
