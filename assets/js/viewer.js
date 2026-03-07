/**
 * PDF Viewer — viewer.js
 * PDF Viewer Platform — github.com/senthilnasa/pdf-viewer
 * Powered by PDF.js
 */

'use strict';

// Set PDF.js worker
pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

const cfg = VIEWER_CONFIG;

// State
let pdfDoc        = null;
let currentPage   = 1;
let totalPages    = 0;
let currentScale  = 'page-width';
let currentRotation = 0;
let renderTasks   = {};
let pageCache     = {};
let thumbsRendered = {};
let thumbsVisible  = true;
let searchResults  = [];
let searchIndex    = 0;
let pageViewLog   = new Set();

// DOM references
const container      = document.getElementById('pdfContainer');
const pageNumInput   = document.getElementById('pageNum');
const pageCountEl    = document.getElementById('pageCount');
const pdfPageCountEl = document.getElementById('pdfPageCount');
const footerPage     = document.getElementById('footerPage');
const thumbList      = document.getElementById('thumbnailList');
const thumbSidebar   = document.getElementById('thumbnailSidebar');
const loadingEl      = document.getElementById('toolbarLoading');
const loadingText    = document.getElementById('loadingText');
const searchBar      = document.getElementById('searchBar');
const searchInput    = document.getElementById('searchInput');
const searchMatches  = document.getElementById('searchMatches');

// ====================================================================
// LOADING
// ====================================================================

async function loadPDF() {
    setLoading(true, 'Loading PDF…');
    try {
        const loadingTask = pdfjsLib.getDocument({
            url:  cfg.pdfUrl,
            withCredentials: true,
        });

        loadingTask.onProgress = (data) => {
            if (data.total) {
                const pct = Math.min(99, Math.round((data.loaded / data.total) * 100));
                setLoading(true, `Loading… ${pct}%`);
            }
        };

        pdfDoc = await loadingTask.promise;
        totalPages = pdfDoc.numPages;

        // Update UI
        pageCountEl.textContent = totalPages;
        pageNumInput.max = totalPages;
        if (pdfPageCountEl) pdfPageCountEl.textContent = `${totalPages} pages`;
        if (footerPage) footerPage.textContent = `Page 1 of ${totalPages}`;

        // Build placeholders then render first page before hiding spinner
        buildPlaceholders();
        try { await renderPage(1); } catch (_) {}
        setLoading(false);

        // Render next pages in background
        renderPage(2);
        renderPage(3);
        renderThumbnails();

        // Auto-open flipbook if configured as default
        if (cfg.defaultView === 'flipbook' && typeof openFlipbook === 'function') {
            openFlipbook();
        }
    } catch (err) {
        setLoading(false);
        console.error('PDF load error:', err);
    }
}

// ====================================================================
// PLACEHOLDERS
// ====================================================================

function buildPlaceholders() {
    container.innerHTML = '';
    pageCache = {};

    // We render on demand — create wrapper divs as scroll targets
    for (let i = 1; i <= totalPages; i++) {
        const wrapper = document.createElement('div');
        wrapper.className = 'pdf-page-wrapper page-loading-placeholder';
        wrapper.id = `page-wrapper-${i}`;
        wrapper.style.width  = '800px';
        wrapper.style.height = '1130px';
        wrapper.dataset.page = i;
        wrapper.textContent  = `Page ${i}`;
        container.appendChild(wrapper);
    }

    // Intersection observer for lazy loading
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const page = parseInt(entry.target.dataset.page, 10);
                if (page) renderPage(page);
            }
        });
    }, { rootMargin: '400px 0px' });

    container.querySelectorAll('.pdf-page-wrapper').forEach(el => observer.observe(el));
}

// ====================================================================
// RENDERING
// ====================================================================

async function renderPage(pageNum) {
    if (!pdfDoc || pageNum < 1 || pageNum > totalPages) return;
    if (pageCache[pageNum]) return; // already rendered

    pageCache[pageNum] = true; // mark as in-progress

    const wrapper = document.getElementById(`page-wrapper-${pageNum}`);
    if (!wrapper) return;

    try {
        const page = await pdfDoc.getPage(pageNum);
        const scale  = computeScale(page, currentScale);
        const vp     = page.getViewport({ scale, rotation: currentRotation });

        // Clear placeholder
        wrapper.innerHTML = '';
        wrapper.classList.remove('page-loading-placeholder');
        wrapper.style.width  = vp.width + 'px';
        wrapper.style.height = vp.height + 'px';

        const canvas  = document.createElement('canvas');
        const ctx     = canvas.getContext('2d');
        canvas.width  = vp.width;
        canvas.height = vp.height;
        wrapper.appendChild(canvas);

        if (renderTasks[pageNum]) {
            renderTasks[pageNum].cancel();
        }

        const task = page.render({ canvasContext: ctx, viewport: vp });
        renderTasks[pageNum] = task;

        await task.promise;

        // Track analytics
        if (cfg.enableAnalytics && !pageViewLog.has(pageNum)) {
            pageViewLog.add(pageNum);
            recordPageView(pageNum);
        }

    } catch (err) {
        if (err?.name !== 'RenderingCancelledException') {
            console.warn(`Page ${pageNum} render error:`, err);
            pageCache[pageNum] = false;
        }
    }
}

function computeScale(page, scaleValue) {
    const vp = page.getViewport({ scale: 1 });
    const areaWidth  = document.getElementById('canvasArea')?.clientWidth - 48 || 800;
    const areaHeight = window.innerHeight - 200;

    if (scaleValue === 'page-width')  return areaWidth / vp.width;
    if (scaleValue === 'auto')        return Math.min(areaWidth / vp.width, areaHeight / vp.height);
    if (scaleValue === 'page-fit')    return Math.min(areaWidth / vp.width, areaHeight / vp.height);

    return parseFloat(scaleValue) || 1;
}

// Re-render all cached pages (after zoom/rotate change)
function rerenderAll() {
    pageCache = {};
    renderTasks = {};
    buildPlaceholders();
    renderPage(currentPage);
}

// ====================================================================
// THUMBNAILS
// ====================================================================

async function renderThumbnails() {
    thumbList.innerHTML = '';
    for (let i = 1; i <= totalPages; i++) {
        const item = document.createElement('div');
        item.className = 'thumbnail-item';
        item.id = `thumb-${i}`;
        item.dataset.page = i;

        const canvas  = document.createElement('canvas');
        const label   = document.createElement('div');
        label.className = 'thumbnail-label';
        label.textContent = i;

        item.appendChild(canvas);
        item.appendChild(label);
        item.addEventListener('click', () => goToPage(i));
        thumbList.appendChild(item);
    }

    // Lazy render thumbnails in view
    const thumbObs = new IntersectionObserver(async (entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                const i = parseInt(entry.target.dataset.page, 10);
                if (!thumbsRendered[i]) {
                    thumbsRendered[i] = true;
                    await renderThumb(i, entry.target.querySelector('canvas'));
                }
                thumbObs.unobserve(entry.target);
            }
        }
    }, { root: thumbSidebar, rootMargin: '200px 0px' });

    thumbList.querySelectorAll('.thumbnail-item').forEach(el => thumbObs.observe(el));
}

async function renderThumb(pageNum, canvas) {
    if (!pdfDoc || !canvas) return;
    try {
        const page = await pdfDoc.getPage(pageNum);
        const vp   = page.getViewport({ scale: 0.2 });
        canvas.width  = vp.width;
        canvas.height = vp.height;
        canvas.style.maxWidth = '130px';
        await page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
    } catch (e) { /* ignore */ }
}

function updateActiveThumbnail(pageNum) {
    document.querySelectorAll('.thumbnail-item').forEach(el => el.classList.remove('active'));
    const el = document.getElementById(`thumb-${pageNum}`);
    if (el) {
        el.classList.add('active');
        el.scrollIntoView({ block: 'nearest' });
    }
}

// ====================================================================
// NAVIGATION
// ====================================================================

function goToPage(pageNum) {
    pageNum = Math.max(1, Math.min(totalPages, pageNum));
    currentPage = pageNum;

    const wrapper = document.getElementById(`page-wrapper-${pageNum}`);
    if (wrapper) {
        wrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    pageNumInput.value = pageNum;
    if (footerPage) footerPage.textContent = `Page ${pageNum} of ${totalPages}`;
    updateActiveThumbnail(pageNum);
}

// Track which page is in view via scroll
function onScroll() {
    if (!pdfDoc) return;
    const scrollEl = document.getElementById('canvasScroll');
    const scrollTop = scrollEl.scrollTop + 100;

    for (let i = 1; i <= totalPages; i++) {
        const el = document.getElementById(`page-wrapper-${i}`);
        if (el && el.offsetTop <= scrollTop && el.offsetTop + el.offsetHeight > scrollTop) {
            if (currentPage !== i) {
                currentPage = i;
                pageNumInput.value = i;
                if (footerPage) footerPage.textContent = `Page ${i} of ${totalPages}`;
                updateActiveThumbnail(i);
            }
            break;
        }
    }
}

// ====================================================================
// SEARCH (basic text layer)
// ====================================================================

async function performSearch(query) {
    if (!pdfDoc || !query) {
        searchMatches.textContent = '';
        return;
    }

    searchResults = [];
    for (let i = 1; i <= totalPages; i++) {
        const page = await pdfDoc.getPage(i);
        const content = await page.getTextContent();
        const text = content.items.map(t => t.str).join(' ');
        if (text.toLowerCase().includes(query.toLowerCase())) {
            searchResults.push(i);
        }
    }

    searchMatches.textContent = searchResults.length
        ? `${searchResults.length} page(s) with match`
        : 'No matches';

    searchIndex = 0;
    if (searchResults.length) goToPage(searchResults[0]);
}

function searchStep(dir) {
    if (!searchResults.length) return;
    searchIndex = (searchIndex + dir + searchResults.length) % searchResults.length;
    goToPage(searchResults[searchIndex]);
}

// ====================================================================
// SHARE PANEL
// ====================================================================

function toggleSharePanel() {
    document.getElementById('sharePanel')?.classList.toggle('open');
}

function copyShareUrl() {
    const input = document.getElementById('shareUrl');
    if (!input) return;
    input.select();
    document.execCommand('copy');
    const btn = document.querySelector('.share-url-row button');
    if (btn) { btn.textContent = 'Copied!'; setTimeout(() => btn.textContent = 'Copy', 2000); }
}

// ====================================================================
// ANALYTICS
// ====================================================================

function recordPageView(pageNum) {
    if (!cfg.enableAnalytics) return;
    fetch(cfg.analyticsUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'page_view', pdf_id: cfg.pdfId, page: pageNum }),
    }).catch(() => {});
}

// ====================================================================
// EVENT LISTENERS
// ====================================================================

function setLoading(show, text = '') {
    if (!loadingEl) return;
    loadingEl.classList.toggle('hidden', !show);
    if (loadingText && text) loadingText.textContent = text;
}

document.addEventListener('DOMContentLoaded', () => {
    loadPDF();

    // Scroll tracking
    document.getElementById('canvasScroll')?.addEventListener('scroll', onScroll, { passive: true });

    // Navigation buttons
    document.getElementById('prevPage')?.addEventListener('click', () => goToPage(currentPage - 1));
    document.getElementById('nextPage')?.addEventListener('click', () => goToPage(currentPage + 1));

    document.getElementById('pageNum')?.addEventListener('change', (e) => {
        goToPage(parseInt(e.target.value, 10) || 1);
    });

    // Zoom
    document.getElementById('zoomSelect')?.addEventListener('change', (e) => {
        currentScale = e.target.value;
        pageCache = {};
        renderTasks = {};
        buildPlaceholders();
        renderPage(currentPage);
    });

    document.getElementById('zoomIn')?.addEventListener('click', () => {
        const steps = [0.5, 0.75, 1, 1.25, 1.5, 2, 3];
        const cur = parseFloat(currentScale) || 1;
        const next = steps.find(s => s > cur) || steps[steps.length - 1];
        currentScale = String(next);
        setZoomSelect(currentScale);
        rerenderAll();
    });

    document.getElementById('zoomOut')?.addEventListener('click', () => {
        const steps = [0.5, 0.75, 1, 1.25, 1.5, 2, 3];
        const cur = parseFloat(currentScale) || 1;
        const prev = [...steps].reverse().find(s => s < cur) || steps[0];
        currentScale = String(prev);
        setZoomSelect(currentScale);
        rerenderAll();
    });

    function setZoomSelect(val) {
        const sel = document.getElementById('zoomSelect');
        if (sel) sel.value = val;
    }

    // Rotate
    document.getElementById('rotateBtn')?.addEventListener('click', () => {
        currentRotation = (currentRotation + 90) % 360;
        rerenderAll();
    });

    // Fullscreen
    document.getElementById('fullscreenBtn')?.addEventListener('click', () => {
        if (!document.fullscreenElement) {
            document.documentElement.requestFullscreen?.();
        } else {
            document.exitFullscreen?.();
        }
    });

    // Thumbnails toggle
    document.getElementById('thumbnailToggle')?.addEventListener('click', function () {
        thumbsVisible = !thumbsVisible;
        thumbSidebar?.classList.toggle('hidden', !thumbsVisible);
        this.classList.toggle('tool-btn-active', thumbsVisible);
    });

    // Search
    document.getElementById('searchToggle')?.addEventListener('click', () => {
        const bar = document.getElementById('searchBar');
        if (bar) {
            const show = bar.style.display === 'none';
            bar.style.display = show ? 'flex' : 'none';
            if (show) document.getElementById('searchInput')?.focus();
        }
    });

    let searchTimer;
    document.getElementById('searchInput')?.addEventListener('input', (e) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => performSearch(e.target.value), 600);
    });

    document.getElementById('searchPrev')?.addEventListener('click', () => searchStep(-1));
    document.getElementById('searchNext')?.addEventListener('click', () => searchStep(1));
    document.getElementById('searchClose')?.addEventListener('click', () => {
        if (searchBar) searchBar.style.display = 'none';
        searchResults = [];
        if (searchMatches) searchMatches.textContent = '';
        if (searchInput) searchInput.value = '';
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', (e) => {
        if (e.target.tagName === 'INPUT') return;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') goToPage(currentPage + 1);
        if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   goToPage(currentPage - 1);
        if (e.key === 'f' || e.key === 'F') document.getElementById('fullscreenBtn')?.click();
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchToggle')?.click();
        }
        if (e.key === '+' || e.key === '=') document.getElementById('zoomIn')?.click();
        if (e.key === '-') document.getElementById('zoomOut')?.click();
        if (e.key === 'Escape') {
            document.getElementById('sharePanel')?.classList.remove('open');
            if (searchBar) searchBar.style.display = 'none';
        }
    });

    // Re-render on window resize
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (currentScale === 'page-width' || currentScale === 'auto' || currentScale === 'page-fit') {
                rerenderAll();
            }
        }, 300);
    });
});
