<?php $page_title = $page_title ?? 'Dashboard'; ?>
<header class="topbar">

    <button class="topbar-hamburger" id="sidebarToggle" aria-label="Menu">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="3" y1="6" x2="21" y2="6"/>
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>

    <div class="topbar-title">
        <!-- <span class="topbar-system">PharmaCare</span> -->
        <div class="topbar-page"><?= htmlspecialchars($page_title) ?></div>
    </div>

    <!-- <div class="topbar-right">
        <!-- <span class="topbar-clock" id="liveClock">--:--:--</span>
        <span class="topbar-date"><?= date('D, M j Y') ?></span> -->

        <!-- Notification -->
        <!-- <button class="topbar-icon-btn" title="Low stock alerts">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <span class="notif-dot" id="notifBadge">0</span>
        </button> -->

        <!-- Refresh -->
        <!-- <button class="topbar-icon-btn" id="refreshBtn" title="Refresh data">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="23 4 23 10 17 10"/>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
            </svg>
        </button> -->

        <!-- Logout -->
        <!-- <a href="logout.php" class="topbar-icon-btn" onclick="return confirm('Log out?')" title="Logout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
        </a> -->
    <!-- </div> --> 

</header>
