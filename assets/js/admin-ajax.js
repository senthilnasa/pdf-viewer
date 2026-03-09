/**
 * Admin AJAX Form Handler + Toast Notification System
 * All admin forms with the [data-ajax] attribute are submitted via fetch.
 * PHP pages return JSON: { success, message, reload?, redirect?, shareToken?, shareUrl?, inviteUrl? }
 */
(function () {
    'use strict';

    // ── Toast Container ───────────────────────────────────────────────────────
    let _container = null;
    function getContainer() {
        if (!_container) {
            _container = document.createElement('div');
            _container.id = 'toast-container';
            Object.assign(_container.style, {
                position: 'fixed', top: '1.25rem', right: '1.25rem',
                zIndex: '99999', display: 'flex', flexDirection: 'column',
                gap: '.55rem', pointerEvents: 'none', minWidth: '280px',
            });
            document.body.appendChild(_container);
        }
        return _container;
    }

    const TOAST_COLORS = {
        success: '#22c55e',
        error:   '#ef4444',
        warning: '#f59e0b',
        info:    '#3b82f6',
    };
    const TOAST_ICONS = {
        success: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>',
        error:   '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>',
        warning: '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>',
        info:    '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    };

    window.showToast = function showToast(message, type, duration) {
        type     = type     || 'success';
        duration = duration !== undefined ? duration : 4500;
        const color = TOAST_COLORS[type] || TOAST_COLORS.info;
        const icon  = TOAST_ICONS[type]  || TOAST_ICONS.info;

        const t = document.createElement('div');
        t.setAttribute('role', 'alert');
        t.style.cssText = [
            'display:flex;align-items:flex-start;gap:.75rem',
            'background:#1e293b;color:#e2e8f0',
            'border:1px solid rgba(255,255,255,.09)',
            'border-left:3px solid ' + color,
            'border-radius:10px;padding:.75rem 1rem',
            'font-size:.875rem;line-height:1.45',
            'box-shadow:0 8px 32px rgba(0,0,0,.45)',
            'max-width:380px;pointer-events:all',
            'transform:translateX(110%)',
            'transition:transform .32s cubic-bezier(.34,1.56,.64,1)',
        ].join(';');

        t.innerHTML =
            '<span style="width:20px;height:20px;background:' + color + ';border-radius:50%;' +
            'display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:.05rem">' +
            '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="#fff" width="12" height="12">' +
            icon + '</svg></span>' +
            '<span style="flex:1">' + message + '</span>' +
            '<button style="background:none;border:none;color:#64748b;cursor:pointer;padding:0;' +
            'font-size:1.1rem;line-height:1;pointer-events:all;margin-top:-.1rem;flex-shrink:0">' +
            '&times;</button>';

        t.querySelector('button').onclick = function () { dismiss(t); };
        getContainer().appendChild(t);

        requestAnimationFrame(function () {
            t.style.transform = 'translateX(0)';
        });

        if (duration > 0) {
            setTimeout(function () { dismiss(t); }, duration);
        }
        return t;
    };

    function dismiss(t) {
        t.style.transform = 'translateX(110%)';
        setTimeout(function () { t.remove(); }, 330);
    }

    // ── Form Interceptor ──────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form[data-ajax]').forEach(initForm);

        // Watch for dynamically injected forms (e.g. modals)
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                m.addedNodes.forEach(function (n) {
                    if (n.nodeType !== 1) return;
                    if (n.matches('form[data-ajax]')) initForm(n);
                    n.querySelectorAll && n.querySelectorAll('form[data-ajax]').forEach(initForm);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });

    function initForm(form) {
        if (form._ajaxBound) return;
        form._ajaxBound = true;

        // Track which submit button was clicked (for name=_action value=... buttons)
        var submitter = null;
        form.querySelectorAll('[type=submit]').forEach(function (btn) {
            btn.addEventListener('click', function () { submitter = btn; });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var fd  = new FormData(form);
            var btn = submitter || form.querySelector('[type=submit]');
            submitter = null;

            // Append clicked button's value (FormData skips submit buttons)
            if (btn && btn.name) fd.set(btn.name, btn.value);

            // Loading state
            var origHtml = btn ? btn.innerHTML : null;
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span style="opacity:.55">Saving…</span>';
            }

            fetch(form.action || location.href, {
                method:  'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body:    fd,
            })
            .then(function (r) {
                if (!r.ok && r.status !== 422) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                var type = data.success ? 'success' : 'error';
                showToast(data.message || (data.success ? 'Saved.' : 'Error.'), type);

                if (data.success) {
                    // Share link result → modal
                    if (data.shareToken && data.shareUrl) {
                        showShareModal(data.shareToken, data.shareUrl);
                    }
                    // Invite URL result → modal
                    if (data.inviteUrl) {
                        showInviteModal(data.inviteUrl);
                    }
                    // Reload or redirect
                    if (data.redirect) {
                        setTimeout(function () { location.href = data.redirect; }, 1100);
                    } else if (data.reload !== false) {
                        setTimeout(function () { location.reload(); }, 1100);
                    }
                }
            })
            .catch(function (err) {
                showToast('Request failed. Please check your connection and try again.', 'error');
                console.error('[admin-ajax]', err);
            })
            .finally(function () {
                if (btn) {
                    btn.disabled = false;
                    if (origHtml !== null) btn.innerHTML = origHtml;
                }
            });
        });
    }

    // ── Share Link Result Modal ───────────────────────────────────────────────
    function showShareModal(token, url) {
        _modal(
            'Share Link Created',
            '<p style="font-size:.85rem;color:#94a3b8;margin:0 0 .75rem">Copy this URL and send it to your recipients:</p>' +
            _copyRow('share-link-url', url) +
            '<p style="font-size:.75rem;color:#475569;margin:.6rem 0 0">Token: <code style="color:#94a3b8">' + _esc(token) + '</code></p>'
        );
    }

    // ── Invite URL Modal ──────────────────────────────────────────────────────
    function showInviteModal(url) {
        _modal(
            'User Invited',
            '<p style="font-size:.85rem;color:#94a3b8;margin:0 0 .75rem">Share this invite link with the new user:</p>' +
            _copyRow('invite-url', url)
        );
    }

    // ── Modal helper ──────────────────────────────────────────────────────────
    var _modalId = 0;
    function _modal(title, body) {
        var id = 'ajax-modal-' + (++_modalId);
        var wrap = document.createElement('div');
        wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;background:rgba(0,0,0,.65);' +
            'backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;padding:1.5rem';
        wrap.innerHTML =
            '<div style="background:#1e293b;border:1px solid rgba(255,255,255,.1);border-radius:16px;' +
            'padding:1.75rem;max-width:520px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.5)">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.1rem">' +
            '<h3 style="margin:0;font-size:1.05rem;color:#f1f5f9;font-weight:700">' + _esc(title) + '</h3>' +
            '<button onclick="document.getElementById(\'' + id + '\').remove()" ' +
            'style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:1.35rem;line-height:1">&times;</button></div>' +
            body + '</div>';
        wrap.id = id;
        wrap.addEventListener('click', function (e) { if (e.target === wrap) wrap.remove(); });
        document.body.appendChild(wrap);
    }

    function _copyRow(inputId, value) {
        return '<div style="display:flex;gap:.5rem;align-items:center">' +
            '<input id="' + inputId + '" type="text" value="' + _esc(value) + '" readonly ' +
            'style="flex:1;background:#0f172a;border:1px solid rgba(255,255,255,.1);border-radius:8px;' +
            'padding:.6rem .9rem;color:#a5b4fc;font-size:.84rem;outline:none">' +
            '<button onclick="(function(b){navigator.clipboard.writeText(document.getElementById(\'' + inputId + '\').value)' +
            '.then(function(){var o=b.textContent;b.textContent=\'Copied!\';b.style.color=\'#4ade80\';' +
            'setTimeout(function(){b.textContent=o;b.style.color=\'\'},2000)})})(this)" ' +
            'style="background:#4f46e5;color:#fff;border:none;border-radius:8px;padding:.6rem 1.1rem;' +
            'cursor:pointer;font-size:.84rem;font-weight:600;white-space:nowrap">Copy</button></div>';
    }

    function _esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

})();
