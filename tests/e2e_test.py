#!/usr/bin/env python3
"""
End-to-end tests for the PDF Viewer Platform (stdlib only).

Run through tests/run-e2e.sh, which provides a fresh app copy, a PHP server
and these environment variables:
    BASE_URL, APP_DIR, DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME, PHP_ERROR_LOG
"""
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = os.environ["BASE_URL"].rstrip("/")
APP = os.environ["APP_DIR"]
DB = {k: os.environ.get(k, "") for k in ("DB_HOST", "DB_PORT", "DB_USER", "DB_PASS", "DB_NAME")}
ADMIN_EMAIL, ADMIN_PASS = "admin@example.com", "Admin#12345"

RESULTS = []


# ---------------------------------------------------------------- helpers --
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect)

    def req(self, method, path, data=None, headers=None, files=None, ajax=False):
        url = path if path.startswith("http") else BASE + path
        headers = dict(headers or {})
        body = None
        if files is not None:
            boundary = uuid.uuid4().hex
            parts = []
            for k, v in (data or {}).items():
                parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode())
            for k, (fname, content, ctype) in files.items():
                parts.append(
                    f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{fname}"\r\n'
                    f"Content-Type: {ctype}\r\n\r\n".encode() + content + b"\r\n"
                )
            parts.append(f"--{boundary}--\r\n".encode())
            body = b"".join(parts)
            headers["Content-Type"] = f"multipart/form-data; boundary={boundary}"
        elif data is not None:
            body = urllib.parse.urlencode(data).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        if ajax:
            headers["X-Requested-With"] = "XMLHttpRequest"
        r = urllib.request.Request(url, data=body, headers=headers, method=method)
        try:
            resp = self.opener.open(r, timeout=30)
            return resp.status, dict(resp.headers), resp.read()
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read()

    def get(self, path, **kw):
        return self.req("GET", path, **kw)

    def post(self, path, data=None, **kw):
        return self.req("POST", path, data=data, **kw)

    def csrf(self, path):
        _, _, body = self.get(path)
        m = re.search(rb'name="csrf_token" value="([0-9a-f]+)"', body)
        assert m, f"no csrf token on {path}"
        return m.group(1).decode()


def sql(query):
    out = subprocess.run(
        ["php", "-r", """
$p = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT').';dbname='.getenv('DB_NAME'), getenv('DB_USER'), getenv('DB_PASS'));
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$s = $p->query($argv[1]);
echo json_encode($s->columnCount() ? $s->fetchAll(PDO::FETCH_ASSOC) : []);
""", query],
        capture_output=True, text=True, env={**os.environ},
    )
    if out.returncode != 0:
        raise RuntimeError(out.stderr)
    return json.loads(out.stdout or "[]")


def check(name, cond, detail=""):
    RESULTS.append((name, bool(cond), detail))
    print(("  PASS  " if cond else "  FAIL  ") + name + ("" if cond else f"  -- {detail}"))


def make_pdf(pages=2, pad=0):
    objs = [b"<< /Type /Catalog /Pages 2 0 R >>"]
    kids = " ".join(f"{3 + i} 0 R" for i in range(pages))
    objs.append(f"<< /Type /Pages /Kids [{kids}] /Count {pages} >>".encode())
    for _ in range(pages):
        objs.append(b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>")
    out = b"%PDF-1.4\n"
    offsets = []
    for i, o in enumerate(objs, 1):
        offsets.append(len(out))
        out += f"{i} 0 obj\n".encode() + o + b"\nendobj\n"
    if pad:
        out += b"%" + b"x" * pad + b"\n"
    xref = len(out)
    out += f"xref\n0 {len(objs) + 1}\n0000000000 65535 f \n".encode()
    for off in offsets:
        out += f"{off:010d} 00000 n \n".encode()
    out += f"trailer\n<< /Size {len(objs) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    return out


def jbody(b):
    try:
        return json.loads(b)
    except Exception:
        return {}


# ------------------------------------------------------------------ tests --
def test_install():
    print("\n[Installation + lock]")
    c = Client()
    s, h, _ = c.get("/")
    check("fresh app redirects to installer", s in (301, 302) and "install.php" in h.get("Location", ""), (s, h.get("Location")))

    form = {
        "db_host": DB["DB_HOST"], "db_port": DB["DB_PORT"], "db_name": DB["DB_NAME"],
        "db_user": DB["DB_USER"], "db_pass": DB["DB_PASS"], "base_url": BASE, "site_name": "QA $1 Site\\",
        "admin_name": "Admin", "admin_email": ADMIN_EMAIL, "admin_pass": ADMIN_PASS,
    }
    c.get("/install.php?step=3")
    s, _, b = c.post("/install.php?step=3", {**form, "csrf_token": "bad"})
    check("installer rejects missing/invalid CSRF token", b"session expired" in b and not os.path.exists(f"{APP}/config/install.lock"))

    s, _, b = c.post("/install.php?step=3", {**form, "db_name": "x`; DROP DATABASE mysql; --", "csrf_token": c.csrf("/install.php?step=3")})
    check("installer rejects unsafe database name", b"may only contain" in b)

    s, h, b = c.post("/install.php?step=3", {**form, "csrf_token": c.csrf("/install.php?step=3")})
    err = re.search(rb'alert-error">(.*?)</div>', b, re.S)
    check("installation succeeds", s == 302 and "step=4" in h.get("Location", ""), (s, err.group(1)[:400] if err else b[:300]))
    check("install.lock created", os.path.exists(f"{APP}/config/install.lock"))
    s, _, b = c.get("/install.php?step=4")
    check("completion page shown once", s == 200 and b"Installation Complete" in b)

    r = subprocess.run(["php", "-l", f"{APP}/config/app.php"], capture_output=True, text=True)
    check("config/app.php valid PHP even with '$1' and trailing backslash in site name", "No syntax errors" in r.stdout, r.stdout + r.stderr)

    a = Client()
    s, _, b = a.get("/install.php")
    check("installer locked for GET (403)", s == 403 and b"Already installed" in b, s)
    s, _, b = a.post("/install.php?step=3", {**form, "admin_email": "attacker@evil.test", "csrf_token": "x"})
    check("installer locked for POST (403)", s == 403, s)
    check("no attacker admin created", not sql("SELECT id FROM users WHERE email='attacker@evil.test'"))

    os.remove(f"{APP}/config/install.lock")
    s, _, _ = a.get("/install.php")
    check("pre-lock installation is detected and auto-locked", s == 403 and os.path.exists(f"{APP}/config/install.lock"), s)

    s, _, b = Client().get("/config/install.lock")
    check("config/ not web-accessible", s == 403, s)


def login(c, email=ADMIN_EMAIL, pw=ADMIN_PASS, extra=None):
    tok = c.csrf("/admin/login.php")
    return c.post("/admin/login.php", {"email": email, "password": pw, "csrf_token": tok, **(extra or {})})


def test_auth(c):
    print("\n[Authentication]")
    s, h, _ = login(c)
    check("admin login redirects to dashboard", s == 302 and "/admin/" in h.get("Location", ""), (s, h))
    for page in ["index", "pdfs", "categories", "analytics", "reports", "team", "viewer-style", "audit-logs", "settings", "profile", "notifications"]:
        s, _, b = c.get(f"/admin/{page}.php")
        check(f"admin page {page}.php renders (200)", s == 200, s)

    s, h, _ = Client().get("/admin/login.php?redirect=//evil.example/x")
    s2, h2, _ = login(Client(), extra={"redirect": "//evil.example/x"})
    check("open redirect rejected after login", "evil.example" not in h2.get("Location", ""), h2.get("Location"))

    fp = Client()
    tok = fp.csrf("/admin/forgot-password.php")
    s, _, b = fp.post("/admin/forgot-password.php", {"email": ADMIN_EMAIL, "csrf_token": tok})
    token_db = sql(f"SELECT reset_token FROM users WHERE email='{ADMIN_EMAIL}'")[0]["reset_token"]
    check("forgot-password no longer reveals the reset token", token_db and token_db.encode() not in b and b"token=" not in b)
    s2, _, b2 = fp.post("/admin/forgot-password.php", {"email": "nobody@example.com", "csrf_token": tok})
    check("forgot-password response identical for unknown email (no enumeration)",
          re.sub(rb'value="[0-9a-f]+"', b"", b) == re.sub(rb'value="[0-9a-f]+"', b"", b2))


def upload(c, title, pdf, extra=None, ajax=True):
    tok = c.csrf("/admin/pdfs.php?action=upload")
    data = {"csrf_token": tok, "_action": "upload", "title": title, "visibility": "public",
            "enable_download": "1", "show_in_catalog": "0", "category_id": "", **(extra or {})}
    return c.req("POST", "/admin/pdfs.php?action=upload", data=data,
                 files={"pdf_file": ("doc.pdf", pdf, "application/pdf")}, ajax=ajax)


def test_uploads(c):
    print("\n[Uploads]")
    s, _, b = upload(c, "Résumé Spéciale", make_pdf())
    check("PDF upload succeeds (AJAX JSON)", s == 200 and jbody(b).get("success"), (s, b[:300]))
    row = sql("SELECT * FROM pdf_documents ORDER BY id DESC LIMIT 1")[0]
    check("unicode title → routable ASCII slug", row["slug"] == "resume-speciale", row["slug"])
    s, _, _ = Client().get(f"/pdf/{row['slug']}")
    check("viewer page reachable by slug", s == 200, s)

    s, _, b = upload(c, "Too big", make_pdf(pad=2 * 1024 * 1024))
    j = jbody(b)
    check("upload over post_max_size → clear 413 message (was 403 'Invalid CSRF')",
          s == 413 and "too large" in j.get("message", ""), (s, b[:200]))

    s, _, b = upload(c, "Not a PDF", b"<?php echo 1; ?>")
    check("non-PDF rejected", s == 200 and not jbody(b).get("success") and "Only PDF" in jbody(b).get("message", ""), b[:200])

    s, _, b = c.req("POST", "/admin/pdfs.php?action=upload", data={"_action": "upload", "title": "x", "csrf_token": "nope"},
                    files={"pdf_file": ("a.pdf", make_pdf(), "application/pdf")}, ajax=True)
    check("bad CSRF → 403 with JSON message", s == 403 and "reload" in jbody(b).get("message", ""), (s, b[:200]))

    s, _, b = upload(c, "Bad slug", make_pdf(), {"slug": "!!!"})
    check("invalid custom slug rejected", not jbody(b).get("success"), b[:200])

    fname = os.path.basename(row["file_path"])
    s, _, _ = Client().get(f"/uploads/{fname}")
    check("direct /uploads/*.pdf access blocked", s == 403, s)

    tok = c.csrf(f"/admin/pdfs.php?action=edit&id={row['id']}")
    s, _, b = c.post(f"/admin/pdfs.php?action=edit&id={row['id']}",
                     {"csrf_token": tok, "_action": "update", "id": row["id"], "title": row["title"], "slug": "",
                      "visibility": "public", "status": "active", "enable_download": "1", "show_in_catalog": "0", "category_id": ""}, ajax=True)
    after = sql(f"SELECT slug FROM pdf_documents WHERE id={row['id']}")[0]["slug"]
    check("empty slug on edit rejected (document stays reachable)", not jbody(b).get("success") and after == row["slug"], (b[:200], after))
    return row


def chunk_post(c, fields, chunk=None):
    tok = c.csrf("/admin/pdfs.php?action=upload")
    files = {"chunk": ("chunk.bin", chunk, "application/octet-stream")} if chunk is not None else None
    if files is None:
        return c.post("/admin/pdfs.php", {"csrf_token": tok, **fields}, ajax=True)
    return c.req("POST", "/admin/pdfs.php", data={"csrf_token": tok, **fields}, files=files, ajax=True)


def chunked_upload(c, data, chunk=900 * 1024):
    j = jbody(chunk_post(c, {"_action": "chunk_init", "size": len(data), "name": "big.pdf"})[2])
    assert j.get("success"), j
    uid, off = j["upload_id"], 0
    while off < len(data):
        j = jbody(chunk_post(c, {"_action": "chunk_append", "upload_id": uid, "offset": off}, data[off:off + chunk])[2])
        assert j.get("success"), j
        off = j["received"]
    return uid


def test_chunked_uploads(c):
    print("\n[Chunked uploads (fix for 408 Request Timeout on large PDFs)]")
    big = make_pdf(pad=5 * 1024 * 1024)          # ~5 MB, server PHP limit is 1 MB
    s, _, b = c.get("/admin/pdfs.php?action=upload")
    m = re.search(rb'data-chunk-bytes="(\d+)"', b)
    check("upload form advertises a chunk size below the PHP limit", m and int(m.group(1)) < 1024 * 1024, m and m.group(1))
    check("upload form advertises the app limit (50 MB), not PHP's 1 MB", b'data-max-bytes="52428800"' in b)

    uid = chunked_upload(c, big)
    tok = c.csrf("/admin/pdfs.php?action=upload")
    s, _, b = c.post("/admin/pdfs.php?action=upload", {"csrf_token": tok, "_action": "upload", "title": "Big Chunked",
                     "visibility": "public", "enable_download": "1", "show_in_catalog": "0", "category_id": "",
                     "chunk_upload_id": uid}, ajax=True)
    check("5 MB PDF uploads in chunks through a 1 MB PHP limit", jbody(b).get("success"), b[:200])
    row = sql("SELECT * FROM pdf_documents WHERE title='Big Chunked'")
    ok = bool(row) and open(row[0]["file_path"], "rb").read() == big
    check("assembled file is byte-identical", ok)
    if row:
        s, h, body = Client().get(f"/api/serve-pdf.php?id={row[0]['id']}", headers={"Range": f"bytes={len(big) - 100}-"})
        check("assembled file served (range at the end)", s == 206 and body == big[-100:], s)
    s, _, b = c.post("/admin/pdfs.php?action=upload", {"csrf_token": tok, "_action": "upload", "title": "Replay",
                     "visibility": "public", "enable_download": "1", "show_in_catalog": "0", "category_id": "",
                     "chunk_upload_id": uid}, ajax=True)
    check("a finalised upload id cannot be replayed", not jbody(b).get("success"), b[:200])

    # Retry of an already-stored chunk (lost response) is acknowledged, not duplicated
    data = make_pdf(pad=2 * 1024 * 1024)
    j = jbody(chunk_post(c, {"_action": "chunk_init", "size": len(data), "name": "r.pdf"})[2])
    uid = j["upload_id"]
    part = data[:500 * 1024]
    a = jbody(chunk_post(c, {"_action": "chunk_append", "upload_id": uid, "offset": 0}, part)[2])
    a2 = jbody(chunk_post(c, {"_action": "chunk_append", "upload_id": uid, "offset": 0}, part)[2])
    check("duplicate chunk acknowledged without double-append", a.get("received") == a2.get("received") == len(part), (a, a2))
    gap = jbody(chunk_post(c, {"_action": "chunk_append", "upload_id": uid, "offset": len(part) + 1000}, part)[2])
    check("out-of-order chunk → server tells client where to resume", gap.get("resume_from") == len(part), gap)

    s, _, b = chunk_post(c, {"_action": "chunk_append", "upload_id": "../../config/x", "offset": 0}, b"x")
    check("malformed upload id rejected (no path traversal)", not jbody(b).get("success"), b[:150])

    j = jbody(chunk_post(c, {"_action": "chunk_init", "size": 60 * 1024 * 1024, "name": "huge.pdf"})[2])
    check("file over the app limit refused before any data is sent", not j.get("success") and "maximum size" in j.get("message", ""), j)

    fake = b"<?php system($_GET[1]); ?>" + b"A" * 300000
    uid = chunked_upload(c, fake)
    tok = c.csrf("/admin/pdfs.php?action=upload")
    s, _, b = c.post("/admin/pdfs.php?action=upload", {"csrf_token": tok, "_action": "upload", "title": "Fake",
                     "visibility": "public", "enable_download": "1", "show_in_catalog": "0", "category_id": "",
                     "chunk_upload_id": uid}, ajax=True)
    check("non-PDF content rejected after assembly", "Only PDF" in jbody(b).get("message", ""), b[:200])
    check("rejected assembled file is deleted", not os.path.exists(f"{APP}/uploads/.chunks/{uid}.part"))
    s, _, _ = Client().get(f"/uploads/.chunks/{uid}.part")
    check("chunk directory not web-accessible", s == 403, s)


def test_serve(row):
    print("\n[PDF serving / range requests]")
    c = Client()
    size = len(make_pdf())
    s, h, b = c.get(f"/api/serve-pdf.php?id={row['id']}")
    check("full PDF served", s == 200 and b.startswith(b"%PDF") and len(b) == size, (s, len(b)))
    s, h, b = c.get(f"/api/serve-pdf.php?id={row['id']}", headers={"Accept-Encoding": "gzip"})
    check("PDF stream not re-compressed by zlib.output_compression (length matches)",
          s == 200 and h.get("Content-Encoding") is None and b.startswith(b"%PDF") and len(b) == size,
          (s, h.get("Content-Encoding"), len(b)))
    s, h, b = c.get(f"/api/serve-pdf.php?id={row['id']}", headers={"Accept-Encoding": "gzip", "Range": "bytes=0-99"})
    check("range 0-99 → 206 / 100 bytes", s == 206 and len(b) == 100, (s, len(b)))
    s, h, b = c.get(f"/api/serve-pdf.php?id={row['id']}", headers={"Range": "bytes=-50"})
    check("suffix range -50 → last 50 bytes", s == 206 and len(b) == 50 and b.endswith(b"%%EOF\n"), (s, len(b)))
    s, h, b = c.get(f"/api/serve-pdf.php?id={row['id']}", headers={"Range": "bytes=10-99999999"})
    check("range end beyond EOF clamped", s == 206 and len(b) == size - 10 and h.get("Content-Length") == str(size - 10), (s, len(b), h.get("Content-Length")))


def test_private_and_share(c):
    print("\n[Private documents + share links]")
    upload(c, "Secret Doc", make_pdf(), {"visibility": "private"})
    doc = sql("SELECT * FROM pdf_documents WHERE title='Secret Doc'")[0]
    anon = Client()
    s, h, _ = anon.get(f"/pdf/{doc['slug']}")
    check("private doc → login redirect carrying return URL", s == 302 and "redirect=" in h.get("Location", ""), h)
    s, _, _ = anon.get(f"/api/serve-pdf.php?id={doc['id']}")
    check("private PDF file not served anonymously", s == 403, s)
    s, h, _ = login(Client(), extra={"redirect": f"/pdf/{doc['slug']}"})
    check("login returns user to the private document", h.get("Location", "").endswith(f"/pdf/{doc['slug']}"), h.get("Location"))

    # share link limited to ONE view
    tok = c.csrf(f"/admin/pdfs.php?action=share&id={doc['id']}")
    s, _, b = c.post(f"/admin/pdfs.php?action=share&id={doc['id']}",
                     {"csrf_token": tok, "_action": "create_share", "id": doc["id"], "expires_days": "1", "max_views": "1"}, ajax=True)
    j = jbody(b)
    check("share link created; modal not auto-reloaded", j.get("success") and j.get("shareUrl") and j.get("reload") is False, j)
    url = j["shareUrl"]
    v = Client()
    s, _, page = v.get(url)
    pdf_url = re.search(rb'pdfUrl:\s+"([^"]+)"', page).group(1).decode().replace("\\/", "/")
    s2, _, b2 = v.get(pdf_url)
    check("single-view share link: the PDF actually loads (was 403)", s == 200 and s2 == 200 and b2.startswith(b"%PDF"), (s, s2))
    s3, _, _ = Client().get(url)
    check("second visit blocked (limit reached)", s3 == 403, s3)

    # password link + expiry enforced after verification
    tok = c.csrf(f"/admin/pdfs.php?action=share&id={doc['id']}")
    j = jbody(c.post(f"/admin/pdfs.php?action=share&id={doc['id']}",
                     {"csrf_token": tok, "_action": "create_share", "id": doc["id"], "expires_days": "1",
                      "max_views": "0", "link_password": "s3cret"}, ajax=True)[2])
    url = j["shareUrl"]
    p = Client()
    s, _, b = p.get(url)
    check("password form shown", b"Password Required" in b)
    s, _, b = p.post(url, {"share_pass": "wrong"})
    check("wrong password re-prompts", b"Incorrect password" in b)
    s, _, b = p.post(url, {"share_pass": "s3cret"})
    check("correct password opens viewer", s == 200 and b"VIEWER_CONFIG" in b, s)
    token = url.split("token=")[1]
    sql(f"UPDATE share_links SET expires_at = NOW() - INTERVAL 1 HOUR WHERE token='{token}'")
    s, _, b = p.get(url)
    check("expired link blocked even after password was verified", s == 403, s)


def test_admin_features(c):
    print("\n[Admin features]")
    # Settings cache: value visible immediately on the non-AJAX response
    tok = c.csrf("/admin/settings.php")
    s, _, b = c.post("/admin/settings.php", {"csrf_token": tok, "_action": "save", "site_name": "Renamed Site",
                                             "email_provider": "phpmailer", "smtp_port": "587", "demo_reset_interval": "30",
                                             "enable_public_view": "1", "analytics_enabled": "1", "enable_download": "1"})
    check("saved settings shown immediately (stale cache fixed)", b'value="Renamed Site"' in b, b[:100])
    check("PHPMailer selectable and available", b'value="phpmailer" selected' in b and b"recommended" in b)

    # Category name XSS
    tok = c.csrf("/admin/categories.php")
    evil = "a'><img src=x onerror=alert(1)>"
    c.post("/admin/categories.php", {"csrf_token": tok, "_action": "create", "name": evil, "parent_id": ""}, ajax=True)
    _, _, b = c.get("/admin/categories.php")
    check("category name escaped in onclick attribute (stored XSS fixed)", b"<img src=x" not in b)

    # Team: invite, activate-invited guard, delete user with docs
    tok = c.csrf("/admin/team.php")
    j = jbody(c.post("/admin/team.php", {"csrf_token": tok, "_action": "invite", "email": "ed@example.com",
                                          "name": "Ed", "role": "editor"}, ajax=True)[2])
    check("invite returns URL + reloadOnClose", j.get("success") and j.get("inviteUrl") and j.get("reloadOnClose"), j)
    uid = sql("SELECT id, invite_expires FROM users WHERE email='ed@example.com'")[0]
    check("invite has an expiry", uid["invite_expires"] is not None)
    tok = c.csrf("/admin/team.php")
    j = jbody(c.post("/admin/team.php", {"csrf_token": tok, "_action": "activate", "member_id": uid["id"]}, ajax=True)[2])
    check("'activate' refused for invited user (would break invite)", not j.get("success"), j)
    inv = Client()
    itoken = sql("SELECT invite_token FROM users WHERE id=%d" % uid["id"])[0]["invite_token"]
    tok = inv.csrf(f"/admin/accept-invite.php?token={itoken}")
    s, _, b = inv.post(f"/admin/accept-invite.php?token={itoken}", {"csrf_token": tok, "password": "Editor#123", "confirm": "Editor#123", "name": "Ed"})
    check("invite accepted", b"Account activated" in b)
    ed = Client()
    login(ed, "ed@example.com", "Editor#123")
    s, _, b = ed.get("/admin/settings.php")
    check("editor blocked from admin-only settings (403)", s == 403, s)
    _, _, b = ed.get("/admin/pdfs.php")
    check("editor sidebar hides admin-only links", b"settings.php" not in b and b"team.php" not in b)
    upload(ed, "Ed Doc", make_pdf())
    tok = c.csrf("/admin/team.php")
    s, _, b = c.post("/admin/team.php", {"csrf_token": tok, "_action": "delete", "member_id": uid["id"]}, ajax=True)
    check("deleting a user who owns PDFs → JSON error, not 500", s == 200 and "owns" in jbody(b).get("message", ""), (s, b[:200]))

    # Deactivated user's live session is logged out cleanly (was TypeError 500)
    tok = c.csrf("/admin/team.php")
    c.post("/admin/team.php", {"csrf_token": tok, "_action": "deactivate", "member_id": uid["id"]}, ajax=True)
    s, h, _ = ed.get("/admin/index.php")
    check("deactivated user's session → redirect to login (no 500)", s == 302 and "login.php" in h.get("Location", ""), s)

    # Audit log pagination beyond page 1 (sidebar used to clobber $currentPage)
    sql("INSERT INTO audit_logs (action, user_id, ip_address) SELECT 'test_event', NULL, '127.0.0.1' FROM information_schema.COLUMNS LIMIT 120")
    s, _, b = c.get("/admin/audit-logs.php?page=2&days=0")
    check("audit logs page 2 renders", s == 200 and b"Page 2 of" in b, s)
    s, _, b = c.get("/admin/audit-logs.php?export=1&days=0")
    check("audit CSV export contains all rows, not just one page", b.count(b"\n") > 100, b.count(b"\n"))

    # Reports CSV: formula injection neutralised
    doc = sql("SELECT slug FROM pdf_documents WHERE visibility='public' AND status='active' LIMIT 1")[0]
    Client().get(f"/pdf/{doc['slug']}", headers={"User-Agent": "=HYPERLINK(\"http://evil\")"})
    s, _, b = c.get("/admin/reports.php?export=csv&date_from=2000-01-01&date_to=2100-01-01")
    check("CSV export neutralises formula injection", b"'=HYPERLINK" in b and b',"=HYPERLINK' not in b, b[:300])

    # Profile name too long
    tok = c.csrf("/admin/profile.php")
    s, _, b = c.post("/admin/profile.php", {"csrf_token": tok, "_action": "update_profile", "name": "x" * 200})
    check("over-long name → validation error, not 500", s == 200 and b"120 characters" in b, s)

    s, _, b = Client().get("/sitemap.xml")
    check("sitemap renders", s == 200 and b"<urlset" in b, s)


def test_cli():
    print("\n[CLI: migrations + cron]")
    for i in (1, 2):
        r = subprocess.run(["php", f"{APP}/migrate.php"], capture_output=True, text=True)
        check(f"migrate.php run #{i} (idempotent)", r.returncode == 0 and "Applied" in r.stdout, r.stdout + r.stderr)
    r = subprocess.run(["php", f"{APP}/cron.php"], capture_output=True, text=True, cwd=APP)
    check("cron.php runs job queue", r.returncode == 0 and "job_queue" in r.stdout, r.stdout + r.stderr)
    s, _, _ = Client().get("/migrate.php")
    check("migrate.php not usable over HTTP", s == 404, s)
    s, _, b = Client().get("/cron.php?token[]=x")
    check("cron.php token[]=x → 401, not a TypeError", s == 401, (s, b[:120]))

    # Upgrade path: database built from the ORIGINAL (pre-fix) schema gets new columns
    legacy = DB["DB_NAME"] + "_legacy"
    orig = subprocess.run(["git", "-C", os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "show", "47f3a97:database.sql"],
                          capture_output=True, text=True).stdout
    if orig and "invite_expires" not in orig:
        subprocess.run(["php", "-r", """
$p = new PDO('mysql:host='.getenv('DB_HOST').';port='.getenv('DB_PORT'), getenv('DB_USER'), getenv('DB_PASS'));
$p->exec('CREATE DATABASE `'.$argv[1].'`'); $p->exec('USE `'.$argv[1].'`');
foreach (preg_split('/;\\s*\\n/', $argv[2]) as $s) { $s = trim(preg_replace('/^\\s*--.*$/m', '', $s)); if ($s) $p->exec($s); }
""", legacy, orig], check=True)
        cfg = open(f"{APP}/config/database.php").read()
        open(f"{APP}/config/database.php", "w").write(cfg.replace(f"'{DB['DB_NAME']}'", f"'{legacy}'"))
        subprocess.run(["php", "-r", "$p=new PDO('mysql:host='.getenv('DB_HOST').';dbname=" + legacy +
                        "',getenv('DB_USER'),getenv('DB_PASS'));$p->exec(\"INSERT INTO users(name,email,role) VALUES('a','a@a.a','admin')\");"], check=True)
        r = subprocess.run(["php", f"{APP}/migrate.php"], capture_output=True, text=True)
        open(f"{APP}/config/database.php", "w").write(cfg)
        cols = subprocess.run(["php", "-r", "$p=new PDO('mysql:host='.getenv('DB_HOST').';dbname=" + legacy +
                               "',getenv('DB_USER'),getenv('DB_PASS'));echo $p->query(\"SHOW COLUMNS FROM users LIKE 'invite_expires'\")->rowCount();"],
                              capture_output=True, text=True).stdout
        check("upgrade: migrate.php adds new columns to a pre-fix database", r.returncode == 0 and cols == "1", r.stdout + r.stderr + cols)


def test_rate_limit():
    print("\n[Login rate limiting]")
    sql("DELETE FROM login_attempts")

    def attempt(pw, ip):
        c = Client()
        tok = c.csrf("/admin/login.php")
        return c.req("POST", "/admin/login.php", {"email": ADMIN_EMAIL, "password": pw, "csrf_token": tok},
                     headers={"X-Forwarded-For": ip})

    # Same IP: blocked after 5 failures
    for _ in range(5):
        attempt("wrong-password", "198.51.100.1")
    _, _, b = attempt(ADMIN_PASS, "198.51.100.1")
    check("per-IP limit: 6th attempt from same IP blocked (even with right password)", b"Too many login attempts" in b, b[:200])

    # Attacker rotates the (spoofable) client IP on every guess
    for i in range(2, 13):
        attempt("wrong-password", f"198.51.100.{i}")
    s, _, b = attempt(ADMIN_PASS, "203.0.113.200")
    check("rotating X-Forwarded-For cannot bypass the per-account limit", b"Too many login attempts" in b, (s, b[:200]))


def test_php_log():
    print("\n[PHP error log]")
    log = os.environ.get("PHP_ERROR_LOG", "")
    text = open(log).read() if log and os.path.exists(log) else ""
    bad = [l for l in text.splitlines()
           if re.search(r"PHP (Fatal|Warning|Parse|Notice|Deprecated)|Uncaught", l)
           # expected: emitted by PHP itself for the deliberate oversized-upload test
           and "POST Content-Length of" not in l]
    check("no PHP errors/warnings/deprecations logged during the run", not bad, "\n".join(bad[:15]))


def main():
    test_install()
    c = Client()
    test_auth(c)
    row = test_uploads(c)
    test_serve(row)
    test_private_and_share(c)
    test_admin_features(c)
    test_chunked_uploads(c)
    test_cli()
    test_rate_limit()
    test_php_log()
    failed = [r for r in RESULTS if not r[1]]
    print(f"\n{len(RESULTS) - len(failed)}/{len(RESULTS)} checks passed")
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
