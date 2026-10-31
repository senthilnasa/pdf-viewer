#!/usr/bin/env node
/**
 * Browser tests (Playwright / Chromium) for the JavaScript fixes.
 * Run by tests/run-e2e.sh after the PHP/HTTP suite (needs an installed app).
 *
 * Env: BASE_URL, ADMIN_EMAIL, ADMIN_PASS
 *      LIB_DIR (optional) — local copies of the CDN libraries, used when the
 *      machine has no internet access: LIB_DIR/pdfjs/build/pdf.min.js,
 *      LIB_DIR/pdfjs/build/pdf.worker.min.js, LIB_DIR/page-flip.browser.js
 */
'use strict';
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL.replace(/\/$/, '');
const EMAIL = process.env.ADMIN_EMAIL || 'admin@example.com';
const PASS = process.env.ADMIN_PASS || 'Admin#12345';
const LIB = process.env.LIB_DIR || '';
const results = [];

function check(name, ok, detail = '') {
    results.push([name, !!ok]);
    console.log((ok ? '  PASS  ' : '  FAIL  ') + name + (ok ? '' : `  -- ${detail}`));
}

async function routeCdn(context) {
    if (!LIB) return;
    const map = [
        [/pdf\.worker\.min\.js$/, path.join(LIB, 'pdfjs/build/pdf.worker.min.js')],
        [/pdf\.min\.js$/, path.join(LIB, 'pdfjs/build/pdf.min.js')],
        [/page-flip\.browser\.js$/, path.join(LIB, 'page-flip.browser.js')],
    ];
    await context.route(/cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net/, (route) => {
        const url = route.request().url();
        for (const [re, file] of map) {
            if (re.test(url) && fs.existsSync(file)) {
                return route.fulfill({ path: file, contentType: 'application/javascript' });
            }
        }
        return route.fulfill({ status: 200, body: '', contentType: 'application/javascript' }); // chart.js etc.
    });
    await context.route(/googletagmanager|cloudflareinsights/, (r) => r.abort());
}

(async () => {
    const browser = await chromium.launch();
    const context = await browser.newContext();
    await routeCdn(context);
    const page = await context.newPage();
    const jsErrors = [];
    page.on('pageerror', (e) => jsErrors.push(e.message));

    // ---- login
    await page.goto(`${BASE}/admin/login.php`);
    await page.fill('input[name=email]', EMAIL);
    await page.fill('input[name=password]', PASS);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    check('browser login', page.url().includes('/admin/'), page.url());

    // ---- confirm() → Cancel must NOT delete (AJAX handler ignored it before)
    await page.goto(`${BASE}/admin/pdfs.php`);
    const rowsBefore = await page.locator('table tbody tr').count();
    page.once('dialog', (d) => d.dismiss());
    await page.locator('form[data-ajax] button.btn-danger').first().click();
    await page.waitForTimeout(1500);
    await page.reload();
    const rowsAfterCancel = await page.locator('table tbody tr').count();
    check('delete → confirm Cancel keeps the PDF', rowsAfterCancel === rowsBefore, `${rowsBefore} -> ${rowsAfterCancel}`);

    page.once('dialog', (d) => d.accept());
    // the app reloads the list itself after a successful delete
    await Promise.all([
        page.waitForNavigation({ timeout: 15000 }),
        page.locator('form[data-ajax] button.btn-danger').first().click(),
    ]);
    await page.waitForLoadState('load');
    const rowsAfterOk = await page.locator('table tbody tr').count();
    check('delete → confirm OK deletes the PDF', rowsAfterOk === rowsBefore - 1, `${rowsBefore} -> ${rowsAfterOk}`);

    // ---- toast renders server messages as text (no HTML injection)
    await page.waitForFunction(() => typeof window.showToast === 'function');
    await page.evaluate(() => window.showToast('<img src=x onerror="window.__xss=1">', 'error'));
    await page.waitForTimeout(300);
    check('toast does not interpret HTML', !(await page.evaluate(() => window.__xss)));

    // ---- Settings: Enter in a text field saves (used to "Take Snapshot")
    await page.goto(`${BASE}/admin/settings.php`);
    await page.fill('input[name=site_name]', 'Enter Key Site');
    const respP = page.waitForResponse((r) => r.url().includes('settings.php') && r.request().method() === 'POST');
    await page.press('input[name=site_name]', 'Enter');
    const resp = await respP;
    const action = new URLSearchParams(resp.request().postData() || '').get('_action')
        || ((resp.request().postData() || '').match(/name="_action"\r\n\r\n(\w+)/) || [])[1];
    const json = await resp.json().catch(() => ({}));
    check('Enter key in settings submits "save", not "take_snapshot"', action === 'save' && /Settings saved/.test(json.message || ''), `${action} / ${json.message}`);

    // ---- Large PDF through the real upload form (chunked; PHP limit is 1 MB)
    {
        // Structurally valid PDF (xref + trailer) padded with a 6 MB comment
        const objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
        ];
        let body = '%PDF-1.4\n%' + 'x'.repeat(6 * 1024 * 1024) + '\n';
        const offs = [];
        objs.forEach((o, i) => { offs.push(body.length); body += `${i + 1} 0 obj\n${o}\nendobj\n`; });
        const xref = body.length;
        body += `xref\n0 ${objs.length + 1}\n0000000000 65535 f \n`
            + offs.map((o) => String(o).padStart(10, '0') + ' 00000 n \n').join('')
            + `trailer\n<< /Size ${objs.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;
        const pdf = Buffer.from(body, 'latin1');
        await page.goto(`${BASE}/admin/pdfs.php?action=upload`);
        await page.setInputFiles('input[name=pdf_file]', { name: 'large.pdf', mimeType: 'application/pdf', buffer: pdf });
        await page.fill('input[name=title]', 'Browser Large Upload');
        await page.selectOption('select[name=show_in_catalog]', '0');
        let sawProgress = false;
        const poll = setInterval(async () => {
            try { if (/Uploading \d+%/.test(await page.locator('button[type=submit]').innerText())) sawProgress = true; } catch (_) {}
        }, 100);
        const chunkReqs = [];
        page.on('request', (r) => { if (r.method() === 'POST' && /chunk_append/.test(r.postData() || '')) chunkReqs.push(r); });
        await Promise.all([
            page.waitForURL(/pdfs\.php$/, { timeout: 60000 }),
            page.click('button[type=submit]'),
        ]);
        clearInterval(poll);
        const listed = await page.locator('td strong', { hasText: 'Browser Large Upload' }).count();
        check('6 MB PDF uploads via the form in chunks (with progress)', listed === 1 && chunkReqs.length >= 6,
            `listed=${listed} chunks=${chunkReqs.length} progress=${sawProgress}`);
        check('upload progress shown on the button', sawProgress);
    }

    // ---- Viewer + flipbook
    // enable flipbook (button is hidden when the setting is off)
    await page.goto(`${BASE}/admin/settings.php`);
    await page.waitForTimeout(500);
    const fbBox = page.locator('input[name=enable_flipbook]');
    if (!(await fbBox.isChecked())) await fbBox.check();
    const saveP = page.waitForResponse((r) => r.url().includes('settings.php') && r.request().method() === 'POST');
    await page.click('button[type=submit][value=save]:not([hidden])');
    await saveP;

    const slug = await (async () => {
        await page.goto(`${BASE}/admin/pdfs.php`);
        const href = await page.locator('table a[href*="/pdf/"]').first().getAttribute('href');
        return href.split('/pdf/')[1];
    })();

    const viewer = await context.newPage();
    const viewerErrors = [];
    viewer.on('pageerror', (e) => viewerErrors.push(e.message));
    viewer.on('console', (m) => { if (m.type() === 'error') viewerErrors.push('console: ' + m.text()); });
    await viewer.goto(`${BASE}/pdf/${slug}`);
    await viewer.waitForSelector('#pdfContainer canvas', { timeout: 15000 }).catch(() => {});
    const canvasCount = await viewer.locator('#pdfContainer canvas').count();
    check('viewer renders first page canvas', canvasCount > 0,
        `url=${viewer.url()} title=${await viewer.title()} errors=${viewerErrors.join(' | ')}`);
    if (!canvasCount) { await browser.close(); process.exit(1); }
    check('viewer page count populated', /\d/.test(await viewer.locator('#pageCount').textContent()));

    // Ctrl+F opens search WITHOUT toggling fullscreen
    await viewer.keyboard.press('Control+f');
    await viewer.waitForTimeout(300);
    const searchShown = await viewer.locator('#searchBar').isVisible();
    const isFs = await viewer.evaluate(() => !!document.fullscreenElement);
    check('Ctrl+F opens search and does not toggle fullscreen', searchShown && !isFs, `search=${searchShown} fs=${isFs}`);
    await viewer.keyboard.press('Escape');

    // Flipbook: open → toggle layout twice → close → reopen
    await viewer.setViewportSize({ width: 1400, height: 900 });
    await viewer.click('#flipbookToggle');
    await viewer.waitForFunction(() => document.getElementById('fbLoading').style.display === 'none', null, { timeout: 15000 }).catch(() => {});
    check('flipbook opens', await viewer.locator('#fbOverlay:not(.fb-hidden)').count() === 1);

    for (let i = 1; i <= 2; i++) {
        await viewer.click('#fbLayoutBtn');
        await viewer.waitForFunction(() => document.getElementById('fbLoading').style.display === 'none', null, { timeout: 15000 }).catch(() => {});
        await viewer.waitForTimeout(500);
        const state = await viewer.evaluate(() => {
            const el = document.getElementById('fbBook');
            return { attached: !!el && document.body.contains(el), children: el ? el.querySelectorAll('canvas').length : 0 };
        });
        check(`flipbook layout toggle #${i} keeps #fbBook attached & populated`, state.attached && state.children > 0, JSON.stringify(state));
    }

    await viewer.evaluate(() => window.closeFlipbook());
    await viewer.evaluate(() => window.openFlipbook());
    await viewer.waitForTimeout(1500);
    const rendered = await viewer.evaluate(() => [...document.querySelectorAll('#fbBook canvas')].some((c) => c.width > 0));
    check('flipbook pages re-render after close + reopen', rendered);

    check('no JS errors on the viewer page', viewerErrors.filter((e) => !/favicon|Failed to load resource/.test(e)).length === 0, viewerErrors.join(' | '));
    check('no JS errors on admin pages', jsErrors.length === 0, jsErrors.join(' | '));

    await browser.close();
    const failed = results.filter((r) => !r[1]).length;
    console.log(`\n${results.length - failed}/${results.length} browser checks passed`);
    process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
