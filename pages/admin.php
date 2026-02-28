<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/config.php';

$page_title = 'Admin';

try {
    $db = getDB();

    // Fetch all users/pharmacists
    $s = $db->prepare("
        SELECT
            PharmacistID,
            FullName,
            Email,
            Role,
            Status,
            LastLogin
        FROM pharmacists
        ORDER BY PharmacistID ASC
    ");
    $s->execute();
    $users = $s->fetchAll();

} catch (PDOException $e) {
    $users = [];
    error_log($e->getMessage());
}

function fmtAdminPad($n)
{
    return 'ADM-' . str_pad($n, 3, '0', STR_PAD_LEFT);
}

function roleClass($role)
{
    return match (strtolower($role ?? '')) {
        'admin' => 'role-admin',
        'pharmacist' => 'role-pharmacist',
        'cashier' => 'role-cashier',
        default => 'role-admin',
    };
}

function statusClass($s)
{
    return match (strtolower($s ?? '')) {
        'active' => 'status-active',
        'inactive' => 'status-inactive',
        default => 'status-active',
    };
}

// Avatar background colors per index
$avatarColors = ['#EF4444', '#3B82F6', '#22C55E', '#A855F7', '#F97316', '#06B6D4', '#EC4899', '#EAB308'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — Admin</title>
    <link rel="stylesheet" href="../assets/css/main.css">
</head>

<body>

    <div id="sidebarOverlay" class="sidebar-overlay"></div>

    <div class="app-layout">

        <!-- ══ SIDEBAR ══ -->
        <aside class="sidebar" id="sidebar">

            <div class="sidebar-brand">
                <div class="brand-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z" />
                        <line x1="12" y1="8" x2="12" y2="16" />
                        <line x1="8" y1="12" x2="16" y2="12" />
                    </svg>
                </div>
                <span class="brand-name">Pharma<br>Care</span>
            </div>

            <nav class="sidebar-nav">

                <a href="dashboard.php" class="nav-item" data-label="Dashboard">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7" rx="1" />
                        <rect x="14" y="3" width="7" height="7" rx="1" />
                        <rect x="14" y="14" width="7" height="7" rx="1" />
                        <rect x="3" y="14" width="7" height="7" rx="1" />
                    </svg>
                </a>

                <a href="prescriptions.php" class="nav-item" data-label="Prescriptions">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" />
                        <rect x="9" y="3" width="6" height="4" rx="2" />
                        <path d="M9 12h6M9 16h4" />
                        <text x="13" y="20" font-size="7" font-weight="bold" fill="currentColor" stroke="none">x</text>
                    </svg>
                </a>

                <a href="transactions.php" class="nav-item" data-label="Transactions">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" />
                        <rect x="9" y="3" width="6" height="4" rx="2" />
                        <circle cx="17" cy="17" r="4" />
                        <polyline points="17 15 17 17 18.5 18.5" />
                    </svg>
                </a>

                <a href="inventory.php" class="nav-item" data-label="Medications">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8h1a4 4 0 0 1 0 8h-1" />
                        <path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z" />
                        <line x1="6" y1="1" x2="6" y2="4" />
                        <line x1="10" y1="1" x2="10" y2="4" />
                        <line x1="14" y1="1" x2="14" y2="4" />
                        <circle cx="18" cy="18" r="3" />
                        <line x1="18" y1="16" x2="18" y2="20" />
                        <line x1="16" y1="18" x2="20" y2="18" />
                    </svg>
                </a>

                <a href="admin.php" class="nav-item active" data-label="Admin">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <circle cx="19" cy="19" r="3" />
                        <path
                            d="M19 16v2M19 22v0M16 19h2M22 19h0M17.1 17.1l1.4 1.4M20.5 20.5l1 1M17.1 20.9l1.4-1.4M20.5 17.5l1-1" />
                    </svg>
                </a>

            </nav>

            <a href="../logout.php" class="sidebar-footer" onclick="return confirm('Log out?')" title="Logout">
                <div class="s-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? 'P', 0, 1)) ?></div>
            </a>

        </aside>

        <!-- ══ MAIN ══ -->
        <div class="main-area">

            <?php include __DIR__ . '/../partials/header.php'; ?>

            <div class="page-body">

                <div class="admin-layout">

                    <!-- ══ LEFT: User Management ══ -->
                    <div class="admin-users-card">

                        <!-- Toolbar -->
                        <div class="admin-toolbar">
                            <div class="admin-toolbar-left">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    class="admin-toolbar-icon">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                    <circle cx="9" cy="7" r="4" />
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                </svg>
                                <span class="admin-toolbar-title">User Management</span>
                            </div>
                            <div class="admin-toolbar-right">
                                <div class="admin-search-wrap">
                                    <input type="text" id="userSearch" class="admin-search" placeholder="Search..."
                                        autocomplete="off">
                                </div>
                                <button class="btn-add-user" id="btnAddUser">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <line x1="12" y1="5" x2="12" y2="19" />
                                        <line x1="5" y1="12" x2="19" y2="12" />
                                    </svg>
                                    Add User
                                </button>
                            </div>
                        </div>

                        <!-- Table -->
                        <div class="admin-table-wrap">
                            <table class="admin-table" id="userTable">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th style="text-align:center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="userBody">
                                    <?php if (empty($users)): ?>
                                        <tr>
                                            <td colspan="6" class="admin-empty">No users found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($users as $i => $u): ?>
                                            <?php
                                            $color = $avatarColors[$i % count($avatarColors)];
                                            $initials = strtoupper(implode('', array_map(fn($w) => $w[0], array_filter(explode(' ', $u['FullName'])))));
                                            $initials = substr($initials, 0, 2);
                                            ?>
                                            <tr data-id="<?= $u['PharmacistID'] ?>"
                                                data-name="<?= strtolower($u['FullName']) ?>"
                                                data-email="<?= strtolower($u['Email'] ?? '') ?>">
                                                <td>
                                                    <div class="user-cell">
                                                        <div class="user-avatar" style="background:<?= $color ?>">
                                                            <?= $initials ?>
                                                        </div>
                                                        <div class="user-info">
                                                            <span
                                                                class="user-name"><?= htmlspecialchars($u['FullName']) ?></span>
                                                            <span class="user-id"><?= fmtAdminPad($u['PharmacistID']) ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="user-email"><?= htmlspecialchars($u['Email'] ?? '—') ?></td>
                                                <td>
                                                    <span class="user-role <?= roleClass($u['Role']) ?>">
                                                        <?= htmlspecialchars(ucfirst($u['Role'] ?? 'Admin')) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="user-status <?= statusClass($u['Status']) ?>">
                                                        <?= ucfirst($u['Status'] ?? 'Active') ?>
                                                    </span>
                                                </td>
                                                <td class="user-login"><?= htmlspecialchars($u['LastLogin'] ?? '—') ?></td>
                                                <td>
                                                    <div class="user-actions">
                                                        <!-- Edit -->
                                                        <button class="ua-btn ua-edit" title="Edit"
                                                            onclick="openEditModal(<?= $u['PharmacistID'] ?>, '<?= htmlspecialchars(addslashes($u['FullName'])) ?>', '<?= htmlspecialchars(addslashes($u['Email'] ?? '')) ?>', '<?= $u['Role'] ?? '' ?>', '<?= $u['Status'] ?? 'Active' ?>')">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                                stroke-width="2">
                                                                <path
                                                                    d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                                                <path
                                                                    d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                                            </svg>
                                                        </button>
                                                        <!-- Reset Password -->
                                                        <button class="ua-btn ua-reset" title="Reset Password"
                                                            onclick="resetPassword(<?= $u['PharmacistID'] ?>)">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                                stroke-width="2">
                                                                <polyline points="1 4 1 10 7 10" />
                                                                <path d="M3.51 15a9 9 0 1 0 .49-3.5" />
                                                            </svg>
                                                        </button>
                                                        <!-- Delete -->
                                                        <button class="ua-btn ua-delete" title="Delete"
                                                            onclick="deleteUser(<?= $u['PharmacistID'] ?>, '<?= htmlspecialchars(addslashes($u['FullName'])) ?>')">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                                stroke-width="2">
                                                                <polyline points="3 6 5 6 21 6" />
                                                                <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" />
                                                                <path d="M10 11v6M14 11v6" />
                                                                <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    </div><!-- /admin-users-card -->

                    <!-- ══ RIGHT: Backup & Reports ══ -->
                    <div class="admin-backup-card">
                        <div class="backup-header">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                class="backup-header-icon">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                                <line x1="16" y1="13" x2="8" y2="13" />
                                <line x1="16" y1="17" x2="8" y2="17" />
                            </svg>
                            <span>Backup &amp; Reports</span>
                        </div>

                        <div class="backup-grid">

                            <!-- Backup Data -->
                            <div class="backup-item">
                                <div class="backup-item-icon bi-blue">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="8 17 12 21 16 17" />
                                        <line x1="12" y1="12" x2="12" y2="21" />
                                        <path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="backup-item-title">Backup Data</div>
                                    <div class="backup-item-sub">Export full DB snapshot</div>
                                </div>
                            </div>

                            <!-- Export Sales -->
                            <div class="backup-item">
                                <div class="backup-item-icon bi-green">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                        <polyline points="14 2 14 8 20 8" />
                                        <line x1="16" y1="13" x2="8" y2="13" />
                                        <line x1="16" y1="17" x2="8" y2="17" />
                                        <polyline points="10 9 9 9 8 9" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="backup-item-title">Export Sales</div>
                                    <div class="backup-item-sub">Download CSV / Excel</div>
                                </div>
                            </div>

                            <!-- Restore Backup -->
                            <div class="backup-item">
                                <div class="backup-item-icon bi-amber">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="1 4 1 10 7 10" />
                                        <path d="M3.51 15a9 9 0 1 0 .49-3.5" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="backup-item-title">Restore Backup</div>
                                    <div class="backup-item-sub">Load previous snapshot</div>
                                </div>
                            </div>

                            <!-- Audit Report -->
                            <div class="backup-item">
                                <div class="backup-item-icon bi-red">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                        <polyline points="14 2 14 8 20 8" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="backup-item-title">Audit Report</div>
                                    <div class="backup-item-sub">Download PDF log</div>
                                </div>
                            </div>

                        </div><!-- /backup-grid -->

                        <div class="backup-actions">
                            <button class="backup-btn bb-save" onclick="saveChanges()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z" />
                                    <polyline points="17 21 17 13 7 13 7 21" />
                                    <polyline points="7 3 7 8 15 8" />
                                </svg>
                                Save Changes
                            </button>
                            <button class="backup-btn bb-export" onclick="exportSales()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <polyline points="8 17 12 21 16 17" />
                                    <line x1="12" y1="12" x2="12" y2="21" />
                                    <path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29" />
                                </svg>
                                Export Sales
                            </button>
                            <button class="backup-btn bb-restore" onclick="restoreBackup()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <polyline points="1 4 1 10 7 10" />
                                    <path d="M3.51 15a9 9 0 1 0 .49-3.5" />
                                </svg>
                                Restore
                            </button>
                            <button class="backup-btn bb-exit" onclick="exitSystem()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                                    <polyline points="16 17 21 12 16 7" />
                                    <line x1="21" y1="12" x2="9" y2="12" />
                                </svg>
                                Exit System
                            </button>
                        </div>

                    </div><!-- /admin-backup-card -->

                </div><!-- /admin-layout -->

            </div><!-- /page-body -->
        </div><!-- /main-area -->
    </div><!-- /app-layout -->

    <!-- ══ ADD USER MODAL ══ -->
    <div class="modal-overlay" id="addUserModal">
        <div class="modal-box">
            <div class="modal-head">
                <span class="modal-title">Add New User</span>
                <button class="modal-close" onclick="closeModal('addUserModal')">✕</button>
            </div>
            <form method="POST" action="../actions/add_user.php" id="addUserForm">
                <div class="modal-grid">
                    <div class="modal-field full">
                        <label class="modal-label">Full Name</label>
                        <input type="text" name="full_name" class="modal-input" placeholder="e.g. Juan Dela Cruz"
                            required>
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Email</label>
                        <input type="email" name="email" class="modal-input" placeholder="user@example.ph" required>
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Role</label>
                        <select name="role" class="modal-input">
                            <option value="Admin">Admin</option>
                            <option value="Pharmacist">Pharmacist</option>
                            <option value="Cashier">Cashier</option>
                        </select>
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Password</label>
                        <input type="password" name="password" class="modal-input" placeholder="Temporary password"
                            required>
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Status</label>
                        <select name="status" class="modal-input">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="modal-btn-cancel" onclick="closeModal('addUserModal')">Cancel</button>
                    <button type="submit" class="modal-btn-save">Add User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ EDIT USER MODAL ══ -->
    <div class="modal-overlay" id="editUserModal">
        <div class="modal-box">
            <div class="modal-head">
                <span class="modal-title">Edit User</span>
                <button class="modal-close" onclick="closeModal('editUserModal')">✕</button>
            </div>
            <form method="POST" action="../actions/edit_user.php" id="editUserForm">
                <input type="hidden" name="pharmacist_id" id="editUserId">
                <div class="modal-grid">
                    <div class="modal-field full">
                        <label class="modal-label">Full Name</label>
                        <input type="text" name="full_name" id="editFullName" class="modal-input" required>
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Email</label>
                        <input type="email" name="email" id="editEmail" class="modal-input" required>
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Role</label>
                        <select name="role" id="editRole" class="modal-input">
                            <option value="Admin">Admin</option>
                            <option value="Pharmacist">Pharmacist</option>
                            <option value="Cashier">Cashier</option>
                        </select>
                    </div>
                    <div class="modal-field">
                        <label class="modal-label">Status</label>
                        <select name="status" id="editStatus" class="modal-input">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="modal-btn-cancel" onclick="closeModal('editUserModal')">Cancel</button>
                    <button type="submit" class="modal-btn-save">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div class="toast-tray" id="toastTray"></div>

    <script>
        // ── Live search ──
        const userSearch = document.getElementById('userSearch');
        const userRows = document.querySelectorAll('#userBody tr[data-id]');

        userSearch.addEventListener('input', () => {
            const q = userSearch.value.toLowerCase().trim();
            userRows.forEach(row => {
                const name = row.dataset.name || '';
                const email = row.dataset.email || '';
                row.style.display = (!q || name.includes(q) || email.includes(q)) ? '' : 'none';
            });
        });

        // ── Modals ──
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        document.getElementById('btnAddUser').addEventListener('click', () => openModal('addUserModal'));

        function openEditModal(id, name, email, role, status) {
            document.getElementById('editUserId').value = id;
            document.getElementById('editFullName').value = name;
            document.getElementById('editEmail').value = email;
            document.getElementById('editRole').value = role.charAt(0).toUpperCase() + role.slice(1).toLowerCase();
            document.getElementById('editStatus').value = status.charAt(0).toUpperCase() + status.slice(1).toLowerCase();
            openModal('editUserModal');
        }

        // Close modal on overlay click
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', e => {
                if (e.target === overlay) overlay.classList.remove('active');
            });
        });

        // ── Action stubs ──
        function deleteUser(id, name) {
            if (confirm(`Delete user "${name}"? This cannot be undone.`)) {
                // POST to delete action
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '../actions/delete_user.php';
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'pharmacist_id'; inp.value = id;
                form.appendChild(inp);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function resetPassword(id) {
            if (confirm('Reset password for this user?')) {
                showToast('Password reset link sent.', 'ok');
            }
        }

        function saveChanges() { showToast('Changes saved successfully.', 'ok'); }
        function exportSales() { showToast('Exporting sales data…', 'warn'); }
        function restoreBackup() { if (confirm('Restore from last backup? Current data will be overwritten.')) showToast('Restore initiated.', 'warn'); }
        function exitSystem() { if (confirm('Exit and log out?')) window.location.href = '../logout.php'; }

        // ── Toast ──
        function showToast(msg, type = 'ok') {
            const tray = document.getElementById('toastTray');
            const toast = document.createElement('div');
            toast.className = `toast-msg t-${type}`;
            toast.textContent = msg;
            tray.appendChild(toast);
            setTimeout(() => toast.remove(), 3500);
        }

        // ── Sidebar toggle (mobile) ──
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const sidebarToggle = document.getElementById('sidebarToggle');

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                sidebar.classList.toggle('open');
                sidebarOverlay.classList.toggle('show');
            });
        }
        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', () => {
                sidebar.classList.remove('open');
                sidebarOverlay.classList.remove('show');
            });
        }
    </script>

</body>

</html>