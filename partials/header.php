<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = $page_title ?? 'Dashboard';
?>
<header class="topbar">
    <button class="topbar-hamburger" id="sidebarToggle" aria-label="Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="6" x2="21" y2="6"/>
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>
    <div class="topbar-title">
        <div class="topbar-page"><?= htmlspecialchars($page_title) ?></div>
    </div>
</header>