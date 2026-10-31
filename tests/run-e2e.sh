#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# End-to-end test runner.
#
# Copies the app into a throw-away directory, starts PHP's built-in server on
# it (router.php emulates the .htaccess rules) and runs tests/e2e_test.py
# against a REAL MySQL/MariaDB server — starting from a fresh install.
#
# Requirements: php (pdo_mysql, fileinfo, mbstring), python3, a MySQL user
# that may CREATE/DROP databases.
#
#   DB_HOST=127.0.0.1 DB_USER=root DB_PASS=secret tests/run-e2e.sh
# ---------------------------------------------------------------------------
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
WORK="$(mktemp -d)"
PORT="${PORT:-8099}"
export DB_HOST="${DB_HOST:-127.0.0.1}" DB_PORT="${DB_PORT:-3306}"
export DB_USER="${DB_USER:-root}" DB_PASS="${DB_PASS:-}"
export DB_NAME="${DB_NAME:-pdfv_e2e_$$}"
export APP_DIR="$WORK/app" BASE_URL="http://127.0.0.1:$PORT"

cleanup() {
    [[ -n "${SERVER_PID:-}" ]] && kill "$SERVER_PID" 2>/dev/null || true
    php -r '
        $p = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USER"), getenv("DB_PASS"));
        $p->exec("DROP DATABASE IF EXISTS `".getenv("DB_NAME")."`");
        $p->exec("DROP DATABASE IF EXISTS `".getenv("DB_NAME")."_legacy`");
    ' 2>/dev/null || true
    rm -rf "$WORK"
}
trap cleanup EXIT

mkdir -p "$APP_DIR"
# Fresh copy without live config / uploads
tar -C "$SRC" --exclude=.git --exclude='config/app.php' --exclude='config/database.php' \
    --exclude='config/install.lock' --exclude='uploads/*.pdf' -cf - . | tar -C "$APP_DIR" -xf -

# Small PHP limits on purpose: lets the suite exercise the "upload larger
# than post_max_size" path that used to surface as a 403 — and forces large
# PDFs through the chunked upload path.
# (zlib.output_compression is deliberately NOT enabled: PHP's built-in server
#  hangs when serving static .js/.css files with it on — a dev-server quirk;
#  Apache/nginx serve static files without PHP.)
php -d upload_max_filesize=1M -d post_max_size=1M -d display_errors=0 -d log_errors=1 \
    -d output_buffering=4096 \
    -d error_log="$WORK/php-errors.log" -d error_reporting=E_ALL \
    -S "127.0.0.1:$PORT" -t "$APP_DIR" "$APP_DIR/router.php" >"$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "$BASE_URL/" && break; sleep 0.1; done

export PHP_ERROR_LOG="$WORK/php-errors.log"
STATUS=0
python3 "$SRC/tests/e2e_test.py" || STATUS=1

# Browser (JavaScript) tests — optional, need Node + Playwright/Chromium
if node -e "require('playwright')" 2>/dev/null; then
    echo
    echo "[Browser tests]"
    # the HTTP suite ends by tripping the login rate limiter
    php -r '$p=new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_NAME"),getenv("DB_USER"),getenv("DB_PASS"));$p->exec("DELETE FROM login_attempts");'
    ADMIN_EMAIL="admin@example.com" ADMIN_PASS="Admin#12345" node "$SRC/tests/browser_test.js" || STATUS=1
else
    echo "(skipping browser tests: Playwright not installed)"
fi
exit $STATUS
