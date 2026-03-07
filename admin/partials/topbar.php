<?php
$user            = $user ?? $auth->currentUser();
$_demoActive     = getSetting('demo_mode', false);
$_cronLastRun    = (int)getSetting('cron_last_run', 0);

if ($_demoActive) {
    $_demoInterval  = (int)getSetting('demo_reset_interval', 60);
    $_demoLastReset = (int)getSetting('demo_last_reset_at', 0);
    $_demoNextReset = $_demoLastReset + ($_demoInterval * 60);
    $_cronToken     = getSetting('demo_cron_token', '');
    $_cronUrl       = ($config['base_url'] ?? '') . '/cron.php?token=' . urlencode($_cronToken);
    $_lastRunAgo    = $_cronLastRun ? (time() - $_cronLastRun) : null;
}
?>
<?php if ($_demoActive): ?>
<style>
#demo-notice {
    position: sticky;
    top: 0;
    z-index: 999;
    background: linear-gradient(90deg, #78350f 0%, #92400e 40%, #b45309 100%);
    color: #fef3c7;
    font-size: .8rem;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    border-bottom: 1px solid rgba(251,191,36,.25);
    box-shadow: 0 2px 12px rgba(0,0,0,.35);
}
#demo-notice .notice-inner {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .55rem 1.25rem;
    flex-wrap: wrap;
}
#demo-notice .notice-badge {
    display: flex;
    align-items: center;
    gap: .45rem;
    font-weight: 700;
    letter-spacing: .03em;
    white-space: nowrap;
}
#demo-notice .pulse-dot {
    width: 8px; height: 8px;
    background: #fbbf24;
    border-radius: 50%;
    flex-shrink: 0;
    animation: pulse-demo 1.6s ease-in-out infinite;
    box-shadow: 0 0 0 0 rgba(251,191,36,.7);
}
@keyframes pulse-demo {
    0%   { box-shadow: 0 0 0 0 rgba(251,191,36,.7); }
    70%  { box-shadow: 0 0 0 6px rgba(251,191,36,0); }
    100% { box-shadow: 0 0 0 0 rgba(251,191,36,0); }
}
#demo-notice .notice-divider {
    width: 1px; height: 14px;
    background: rgba(251,191,36,.3);
    flex-shrink: 0;
}
#demo-notice .notice-meta {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex: 1;
    flex-wrap: wrap;
    font-weight: 400;
    opacity: .9;
}
#demo-notice .meta-item { display: flex; align-items: center; gap: .35rem; white-space: nowrap; }
#demo-notice .meta-label { opacity: .65; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; }
#demo-notice .meta-value { font-weight: 600; font-size: .82rem; }
#demo-notice .countdown-urgent { color: #fca5a5 !important; }
#demo-notice .countdown-warn   { color: #fde68a !important; }
#demo-notice .notice-actions {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin-left: auto;
    flex-shrink: 0;
}
#demo-notice .notice-btn {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .3rem .7rem;
    background: rgba(255,255,255,.12);
    color: #fef3c7;
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 6px;
    font-size: .75rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: background .15s;
    white-space: nowrap;
}
#demo-notice .notice-btn:hover { background: rgba(255,255,255,.22); }
#demo-notice .notice-dismiss {
    background: none; border: none; color: rgba(254,243,199,.5);
    cursor: pointer; padding: .2rem; line-height: 1;
    transition: color .15s;
}
#demo-notice .notice-dismiss:hover { color: #fef3c7; }
</style>

<div id="demo-notice">
    <div class="notice-inner">

        <!-- Badge -->
        <div class="notice-badge">
            <div class="pulse-dot"></div>
            DEMO MODE
        </div>

        <div class="notice-divider"></div>

        <!-- Meta -->
        <div class="notice-meta">
            <div class="meta-item">
                <span class="meta-label">Resets every</span>
                <span class="meta-value"><?= $_demoInterval ?> min</span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Last reset</span>
                <span class="meta-value">
                    <?php if ($_demoLastReset): ?>
                        <?= $_demoLastReset > (time() - 60)
                            ? 'just now'
                            : gmdate('H:i:s', time() - $_demoLastReset) . ' ago'
                        ?>
                    <?php else: ?>—<?php endif; ?>
                </span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Next reset</span>
                <span class="meta-value" id="demo-countdown" data-ts="<?= $_demoNextReset ?>">
                    <?= $_demoNextReset > time()
                        ? gmdate('i:s', max(0, $_demoNextReset - time()))
                        : 'imminent'
                    ?>
                </span>
            </div>
            <?php if ($_cronLastRun): ?>
            <div class="meta-item">
                <span class="meta-label">Cron ran</span>
                <span class="meta-value">
                    <?php
                    $ago = time() - $_cronLastRun;
                    if ($ago < 60)         echo 'just now';
                    elseif ($ago < 3600)   echo floor($ago/60) . 'm ago';
                    else                   echo floor($ago/3600) . 'h ' . floor(($ago%3600)/60) . 'm ago';
                    ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Actions -->
        <div class="notice-actions">
            <a href="<?= htmlspecialchars($_cronUrl) ?>&force=1" class="notice-btn"
               onclick="return confirm('Run cron reset now?')" target="_blank">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" width="12" height="12">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                          d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Run Now
            </a>
            <a href="../admin/settings.php" class="notice-btn">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" width="12" height="12">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                          d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Manage
            </a>
            <button class="notice-dismiss" id="demo-notice-dismiss" title="Dismiss for this session">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" width="14" height="14">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                          d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

    </div>
</div>

<script>
(function(){
    // Restore dismiss state
    if (sessionStorage.getItem('demo_notice_dismissed')) {
        document.getElementById('demo-notice').style.display = 'none';
    }
    document.getElementById('demo-notice-dismiss').addEventListener('click', function(){
        document.getElementById('demo-notice').style.display = 'none';
        sessionStorage.setItem('demo_notice_dismissed', '1');
    });

    // Live countdown
    var el = document.getElementById('demo-countdown');
    if (!el) return;
    var ts = parseInt(el.dataset.ts, 10);
    function tick(){
        var r = ts - Math.floor(Date.now() / 1000);
        if (r <= 0) {
            el.textContent = 'imminent';
            el.className = 'meta-value countdown-urgent';
            return;
        }
        var m = Math.floor(r / 60), s = r % 60;
        el.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        el.className = 'meta-value' + (r < 60 ? ' countdown-urgent' : r < 300 ? ' countdown-warn' : '');
    }
    tick();
    setInterval(tick, 1000);
})();
</script>
<?php endif; ?>
<header class="admin-topbar">
    <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="22" height="22">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
    </button>
    <div class="topbar-right">
        <div class="user-menu">
            <div class="user-avatar"><?= strtoupper(mb_substr($user['name'] ?? 'U', 0, 1)) ?></div>
            <div class="user-info">
                <span class="user-name"><?= e($user['name'] ?? '') ?></span>
                <span class="user-role badge badge-<?= $user['role'] === 'admin' ? 'primary' : 'success' ?>"><?= e($user['role'] ?? '') ?></span>
            </div>
        </div>
    </div>
</header>
<script>
    document.getElementById('sidebarToggle')?.addEventListener('click', () => {
        document.getElementById('sidebar')?.classList.toggle('open');
    });
</script>
