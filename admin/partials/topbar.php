<?php $user = $user ?? $auth->currentUser(); ?>
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
