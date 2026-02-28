/**
 * PharmaCare — Dashboard JS
 * Clock · Sidebar · Auto-refresh · Toasts · Table search
 */
'use strict';

/* ── Live Clock ── */
function tickClock() {
    const el = document.getElementById('liveClock');
    if (el) el.textContent = new Date().toLocaleTimeString('en-PH', { hour12: true });
}
setInterval(tickClock, 1000);
tickClock();

/* ── Sidebar Toggle (mobile) ── */
const sidebar  = document.getElementById('sidebar');
const overlay  = document.getElementById('sidebarOverlay');
const hamBtn   = document.getElementById('sidebarToggle');

if (hamBtn) {
    hamBtn.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
    });
}
if (overlay) {
    overlay.addEventListener('click', () => {
        sidebar.classList.remove('open');   
        overlay.classList.remove('show');
    });
}

/* ── Toast Notification ── */
function toast(msg, type = 'ok') {
    const tray = document.getElementById('toastTray');
    if (!tray) return;
    const el = document.createElement('div');
    el.className = `toast-msg t-${type}`;
    el.textContent = msg;
    tray.appendChild(el);
    setTimeout(() => {
        el.style.opacity = '0';
        el.style.transform = 'translateX(16px)';
        el.style.transition = 'all .3s ease';
        setTimeout(() => el.remove(), 300);
    }, 3200);
}

/* ── Format helpers ── */
function fmtPHP(n) {
    return '₱' + Number(n || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
}
function fmtPad(n, len = 3) {
    return String(n).padStart(len, '0');
}
function esc(s) {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
}
function badgeCls(s) {
    const m = { Completed: 'badge-completed', Pending: 'badge-pending', Cancelled: 'badge-cancelled' };
    return m[s] || 'badge-pending';
}

/* ── Render helpers ── */
function renderTransactions(tbody, rows) {
    if (!rows || !rows.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="empty-state">No transactions found</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => `
        <tr>
            <td class="td-id">INV-${fmtPad(r.InvoiceID)}</td>
            <td class="td-bold">${esc(r.PatientName)}</td>
            <td class="td-sm">${esc(r.Medicines)}</td>
            <td class="td-amt">${fmtPHP(r.Total)}</td>
            <td><span class="badge ${badgeCls(r.Status)}">${esc(r.Status)}</span></td>
        </tr>
    `).join('');
}

function renderStock(container, items) {
    if (!items || !items.length) {
        container.innerHTML = '<div class="empty-state">No low stock alerts 🎉</div>';
        return;
    }
    container.innerHTML = items.map(item => {
        const qty  = parseInt(item.TotalStock);
        const pct  = Math.min(Math.round((qty / 200) * 100), 100);
        const crit = qty <= 50;
        return `
        <div class="stock-item">
            <div class="stock-row">
                <span class="stock-name">${esc(item.GenericName)} ${esc(item.DosageStrength)}</span>
                <span class="stock-qty ${crit ? 'sq-critical' : 'sq-low'}">${qty} left</span>
            </div>
            <div class="stock-bar-bg">
                <div class="stock-bar ${crit ? 'sb-red' : 'sb-amber'}" style="width:${pct}%"></div>
            </div>
            <div class="stock-brand">${esc(item.BrandName)}</div>
        </div>`;
    }).join('');
}

/* ── Dashboard Auto-Refresh ── */
let refreshing = false;

async function refreshDash() {
    if (refreshing) return;
    refreshing = true;
    const btn = document.getElementById('refreshBtn');
    if (btn) btn.classList.add('spinning');

    try {
        const res  = await fetch('api/dashboard.php', { cache: 'no-store' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (data.error) throw new Error(data.message);

        // Update stat values
        const set = (id, v) => { const e = document.getElementById(id); if (e) e.textContent = v; };
        set('statPatients',     data.total_patients_today);
        set('statPrescriptions',data.total_prescriptions_today);
        set('statLowStock',     data.low_stock_count);
        set('statRevenue',      fmtPHP(data.today_revenue));

        // Notification badge
        const nb = document.getElementById('notifBadge');
        if (nb) nb.textContent = data.low_stock_count ?? 0;

        // Tables
        const txnBody = document.getElementById('txnBody');
        if (txnBody) renderTransactions(txnBody, data.recent_transactions);

        const stockEl = document.getElementById('stockList');
        if (stockEl) renderStock(stockEl, data.low_stock_items);

        toast('Dashboard refreshed', 'ok');
    } catch (err) {
        toast('Refresh failed: ' + err.message, 'err');
    } finally {
        refreshing = false;
        const btn2 = document.getElementById('refreshBtn');
        if (btn2) btn2.classList.remove('spinning');
    }
}

// Auto-refresh every 60 seconds
if (document.getElementById('txnBody')) {
    setInterval(() => { if (!document.hidden) refreshDash(); }, 60000);
}

// Manual refresh button
document.getElementById('refreshBtn')?.addEventListener('click', refreshDash);

/* ── Animate stock bars on load ── */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.stock-bar').forEach(bar => {
        const w = bar.style.width;
        bar.style.width = '0';
        requestAnimationFrame(() => requestAnimationFrame(() => { bar.style.width = w; }));
    });

    // Update notif badge from stat
    const ls = document.getElementById('statLowStock');
    const nb = document.getElementById('notifBadge');
    if (ls && nb) nb.textContent = ls.textContent.trim();
});

/* ── Generic table search ── */
function bindSearch(inputId, tbodyId) {
    const inp = document.getElementById(inputId);
    const tbl = document.getElementById(tbodyId);
    if (!inp || !tbl) return;
    inp.addEventListener('input', () => {
        const q = inp.value.toLowerCase();
        Array.from(tbl.rows).forEach(row => {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
}

// Bind all page searches
bindSearch('searchTxn',   'txnBody');
bindSearch('searchMed',   'medBody');
bindSearch('searchPat',   'patBody');
bindSearch('searchRx',    'rxBody');
bindSearch('searchUsers', 'usersBody');