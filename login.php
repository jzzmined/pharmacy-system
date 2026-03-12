<?php
// ── Handle AJAX sub-actions BEFORE any HTML output ──────────────────────────
// These must exit before the HTML page renders.

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

$ajax_action = $_POST['ajax_action'] ?? '';

// AJAX: verify email exists
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ajax_action === 'forgot_check_email') {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    try {
        $db = getDB();
        $st = $db->prepare("SELECT UserID, FullName FROM users WHERE Email = ? AND Status = 'Active' LIMIT 1");
        $st->execute([$email]);
        $row = $st->fetch();
        if ($row) {
            echo json_encode(['ok' => true,  'name' => $row['FullName'], 'uid' => $row['UserID']]);
        } else {
            echo json_encode(['ok' => false, 'msg'  => 'No active account found with that email address.']);
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Database error. Please try again.']);
    }
    exit;
}

// AJAX: reset password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ajax_action === 'forgot_reset') {
    header('Content-Type: application/json');
    $email   = trim($_POST['email']   ?? '');
    $newpw   = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_pw']   ?? '';

    // Server-side validation
    $ok = $newpw === $confirm
        && strlen($newpw) >= 8
        && preg_match('/[A-Z]/', $newpw)
        && preg_match('/[a-z]/', $newpw)
        && preg_match('/[0-9]/', $newpw)
        && preg_match('/[^A-Za-z0-9]/', $newpw);

    if (!$ok) {
        echo json_encode(['ok' => false, 'msg' => 'Password does not meet requirements or does not match.']);
        exit;
    }

    try {
        $db = getDB();
        $st = $db->prepare("SELECT UserID FROM users WHERE Email = ? AND Status = 'Active' LIMIT 1");
        $st->execute([$email]);
        $row = $st->fetch();
        if (!$row) {
            echo json_encode(['ok' => false, 'msg' => 'Account not found.']);
            exit;
        }
        $hash = password_hash($newpw, PASSWORD_BCRYPT);
        $up   = $db->prepare("UPDATE users SET Password = ? WHERE UserID = ?");
        $up->execute([$hash, $row['UserID']]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'msg' => 'Database error. Please try again.']);
    }
    exit;
}

// ── Already logged in → redirect ─────────────────────────────────────────────
if (isLoggedIn()) {
    header("Location: /pharmacy-system/pages/dashboard.php");
    exit;
}

// ── Normal login form POST ────────────────────────────────────────────────────
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ajax_action === '') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please enter both your Email and Password.';
    } elseif (attemptLogin($email, $password)) {
        header('Location: /pharmacy-system/pages/dashboard.php');
        exit;
    } else {
        $error = 'Invalid Email or Password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PharmaCare — Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet"
        href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=DM+Serif+Display&display=swap">
    <!-- Bootstrap Icons — same CDN & version as admin.php -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/login.css">

    <style>
    /* ── Password wrapper: room for the eye button ── */
    .pass { position: relative; }
    .pass input { padding-right: 3rem !important; }

    /* Eye-toggle button — EXACT same inline style from admin.php change-password */
    .pw-eye-btn {
        position: absolute;
        right: .7rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        cursor: pointer;
        padding: 0;
        color: #94a3b8;
        font-size: 1rem;
        line-height: 1;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .pw-eye-btn:hover { color: #64748b; }

    /* ════════════════════════════════════
       Forgot-Password modal
       ════════════════════════════════════ */
    .fp-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15,23,42,.55);
        z-index: 9000;
        align-items: center;
        justify-content: center;
    }
    .fp-backdrop.open { display: flex; }

    .fp-modal {
        background: #fff;
        border-radius: 18px;
        padding: 32px 30px 28px;
        width: min(460px, 94vw);
        box-shadow: 0 24px 64px rgba(0,0,0,.28);
        position: relative;
        animation: fpSlideIn .22s cubic-bezier(.22,1,.36,1);
    }
    @keyframes fpSlideIn {
        from { opacity:0; transform:translateY(22px) scale(.97); }
        to   { opacity:1; transform:none; }
    }

    /* X close button */
    .fp-x {
        position: absolute; top: 14px; right: 16px;
        background: none; border: none;
        color: #94a3b8; font-size: 1.35rem; line-height: 1;
        cursor: pointer; padding: 2px 6px; border-radius: 6px;
    }
    .fp-x:hover { background: #f1f5f9; color: #1e2d40; }

    /* Step display */
    .fp-step { display: none; }
    .fp-step.fp-active { display: block; }

    /* Shared elements inside modal */
    .fp-title {
        font-family:'Outfit',sans-serif; font-size:1.18rem;
        font-weight:800; color:#0f172a; margin-bottom:4px;
        display:flex; align-items:center; gap:8px;
    }
    .fp-subtitle { font-size:.85rem; color:#64748b; margin-bottom:22px; }
    .fp-label {
        display: block;
        font-size:.72rem; font-weight:700;
        letter-spacing:.07em; text-transform:uppercase;
        color:#5a7a9a; margin-bottom:5px;
    }
    .fp-input {
        width: 100%; box-sizing: border-box;
        padding: 11px 44px 11px 14px;
        border: 1.5px solid #cbd5e1;
        border-radius: 10px;
        font-family: 'Outfit', sans-serif;
        font-size: .93rem; color: #1e293b;
        outline: none;
        transition: border-color .2s, box-shadow .2s;
        background: #fff;
    }
    .fp-input:focus {
        border-color: #5a7a9a;
        box-shadow: 0 0 0 3px rgba(90,122,154,.15);
    }
    /* wrapper for password fields with eye btn */
    .fp-pw-wrap { position: relative; }
    .fp-pw-wrap .fp-input { padding-right: 2.6rem; width:100%; box-sizing:border-box; }
    /* eye btn inside modal — same style as admin.php */
    .fp-pw-wrap .pw-eye-btn {
        right: .7rem; top: 50%; transform: translateY(-50%);
    }

    /* Strength bar (mirrors admin.php RPM section) */
    .fp-strength-track {
        height:5px; border-radius:99px; background:#eee;
        overflow:hidden; margin-top:4px;
    }
    #fp-strength-bar {
        height:100%; width:0; border-radius:99px;
        transition:width .3s,background .3s;
    }
    #fp-strength-label { font-size:.73rem; font-weight:600; display:block; margin-top:2px; }

    /* Requirements list (identical to admin.php) */
    #fp-requirements {
        margin:6px 0 0 2px; padding:0; list-style:none;
        display:flex; flex-direction:column; gap:3px;
    }
    #fp-requirements li {
        font-size:.75rem; color:#94a3b8;
        display:flex; align-items:center; gap:5px;
    }

    #fp-match-label { font-size:.73rem; font-weight:600; display:block; margin-top:2px; }

    /* Error/success banners */
    .fp-banner {
        display: none;
        border-radius: 8px; padding: .6rem .85rem;
        font-size: .82rem; font-weight:500;
        align-items: center; gap: 6px;
        margin-bottom: 14px;
    }
    .fp-banner.show { display: flex; }
    .fp-banner.err  { background:#fff0f0; border:1px solid #fca5a5; color:#b91c1c; }
    .fp-banner.ok   { background:#f0fdf4; border:1px solid #86efac; color:#166534; }

    /* Buttons */
    .fp-btn {
        display:flex; align-items:center; justify-content:center; gap:7px;
        width:100%; padding:.72rem 1rem;
        border-radius:10px; border:none;
        background:#1e2d40; color:#fff;
        font-family:'Outfit',sans-serif; font-size:.93rem; font-weight:700;
        cursor:pointer; transition:background .2s, opacity .15s;
        margin-top: 16px;
    }
    .fp-btn:hover:not(:disabled) { background:#2d4460; }
    .fp-btn:disabled { opacity:.55; cursor:not-allowed; }
    .fp-btn-back {
        background:none; border:none; padding:0;
        font-family:'Outfit',sans-serif; font-size:.83rem; font-weight:600;
        color:#5a7a9a; cursor:pointer; display:inline-flex; align-items:center;
        gap:5px; margin-bottom:14px;
    }
    .fp-btn-back:hover { color:#1e2d40; }

    /* Spinner (mirrors admin.php rpm-spin) */
    @keyframes fp-spin { to { transform:rotate(360deg); } }
    .fp-spinner {
        display:none; width:13px; height:13px; border-radius:50%;
        border:2px solid rgba(255,255,255,.35); border-top-color:#fff;
        animation:fp-spin .6s linear infinite;
    }
    </style>
</head>

<body>

<!-- ═══ LEFT: Brand Wordmark ═══ -->
<div class="brand-side">
    <div class="brand-name">
        Pharma<br>
        <span class="heart">♥</span>Care
    </div>
</div>

<!-- ═══ RIGHT: Login Card ═══ -->
<div class="cons">
    <div class="Log_card">

        <h1>Admin Login</h1>
        <p class="tag">The care you can count on...</p>

        <?php if ($error): ?>
            <div class="login-error">⚠ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <!-- ajax_action blank = normal login -->
            <input type="hidden" name="ajax_action" value="">

            <!-- Email -->
            <div class="inputs">
                <input type="email" id="email" name="email" placeholder=" "
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    autocomplete="username" required>
                <label for="email">Email Address</label>
            </div>

            <!-- Password + eye icon (EXACT same structure as admin.php change-password) -->
            <div class="pass">
                <input type="password" id="password" name="password"
                    placeholder=" " autocomplete="current-password"
                    required style="width:100%;padding-right:2.6rem">
                <label for="password">Password</label>
                <button type="button"
                        onclick="rpmToggleEye('password','login-eye-icon')"
                        style="position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0;color:#94a3b8;font-size:1rem;line-height:1;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-eye-slash-fill" id="login-eye-icon"></i>
                </button>
            </div>

            <div class="opts">
                <label>
                    <input type="checkbox" name="remember"> Remember Me
                </label>
                <a href="#" id="forgotPwLink">Forgot Password?</a>
            </div>

            <button type="submit">Login</button>
        </form>

    </div>
</div>


<!-- ═══════════════════════════════════════════════════════
     Forgot Password Modal  (3-step wizard)
     Step 1 → Enter email
     Step 2 → Set new password (with strength bar & requirements matching admin.php)
     Step 3 → Success
     ═══════════════════════════════════════════════════════ -->
<div class="fp-backdrop" id="fpBackdrop">
  <div class="fp-modal" role="dialog" aria-modal="true" aria-labelledby="fpModalTitle">
    <button class="fp-x" onclick="fpClose()" aria-label="Close">&times;</button>

    <!-- ── STEP 1: Verify email ── -->
    <div class="fp-step fp-active" id="fp-step-1">
        <p class="fp-title"><i class="bi bi-shield-lock-fill" style="color:#1e2d40"></i> Reset Password</p>
        <p class="fp-subtitle">Enter the email address linked to your account.</p>

        <div class="fp-banner err" id="fp1-err">
            <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0"></i>
            <span id="fp1-err-text"></span>
        </div>

        <label class="fp-label" for="fp-email">Email Address</label>
        <input type="email" class="fp-input" id="fp-email"
               placeholder="admin@example.ph" autocomplete="email"
               style="padding-left:14px;padding-right:14px;"
               onkeydown="if(event.key==='Enter'){event.preventDefault();fpStep1Submit();}">

        <button class="fp-btn" id="fp1-btn" onclick="fpStep1Submit()">
            <i class="bi bi-arrow-right-circle-fill"></i>
            <span id="fp1-btn-text">Continue</span>
            <span class="fp-spinner" id="fp1-spinner"></span>
        </button>
    </div>

    <!-- ── STEP 2: New password ── -->
    <div class="fp-step" id="fp-step-2">
        <button class="fp-btn-back" onclick="fpGoStep(1)">
            <i class="bi bi-arrow-left"></i> Back
        </button>
        <p class="fp-title"><i class="bi bi-key-fill" style="color:#1e2d40"></i> Set New Password</p>
        <p class="fp-subtitle" id="fp2-subtitle">Create a strong new password for your account.</p>

        <div class="fp-banner err" id="fp2-err">
            <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0"></i>
            <span id="fp2-err-text"></span>
        </div>

        <!-- New password -->
        <label class="fp-label" for="fp-new-pw">New Password</label>
        <div class="fp-pw-wrap" style="margin-bottom:0">
            <input type="password" class="fp-input" id="fp-new-pw"
                   placeholder="Enter new password"
                   oninput="fpOnInput()" autocomplete="new-password">
            <button type="button"
                    onclick="rpmToggleEye('fp-new-pw','fp-eye-new')"
                    style="position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0;color:#94a3b8;font-size:1rem;line-height:1;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-eye-slash-fill" id="fp-eye-new"></i>
            </button>
        </div>

        <!-- Strength bar — identical markup to admin.php -->
        <div class="fp-strength-track">
            <div id="fp-strength-bar"></div>
        </div>
        <span id="fp-strength-label"></span>

        <!-- Requirements checklist — identical markup to admin.php -->
        <ul id="fp-requirements">
            <li id="fp-req-length" ><i class="bi bi-circle" style="font-size:.6rem"></i> At least 8 characters</li>
            <li id="fp-req-upper"  ><i class="bi bi-circle" style="font-size:.6rem"></i> At least one uppercase letter (A–Z)</li>
            <li id="fp-req-lower"  ><i class="bi bi-circle" style="font-size:.6rem"></i> At least one lowercase letter (a–z)</li>
            <li id="fp-req-number" ><i class="bi bi-circle" style="font-size:.6rem"></i> At least one number (0–9)</li>
            <li id="fp-req-special"><i class="bi bi-circle" style="font-size:.6rem"></i> At least one special character (!@#$…)</li>
        </ul>

        <!-- Confirm password -->
        <label class="fp-label" for="fp-confirm-pw" style="margin-top:10px;display:block">Confirm New Password</label>
        <div class="fp-pw-wrap" style="margin-bottom:0">
            <input type="password" class="fp-input" id="fp-confirm-pw"
                   placeholder="Re-enter new password"
                   oninput="fpOnInput()" autocomplete="new-password">
            <button type="button"
                    onclick="rpmToggleEye('fp-confirm-pw','fp-eye-confirm')"
                    style="position:absolute;right:.7rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:0;color:#94a3b8;font-size:1rem;line-height:1;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-eye-slash-fill" id="fp-eye-confirm"></i>
            </button>
        </div>
        <span id="fp-match-label"></span>

        <button class="fp-btn" id="fp2-btn" onclick="fpStep2Submit()" disabled>
            <i class="bi bi-check-circle-fill"></i>
            <span id="fp2-btn-text">Reset Password</span>
            <span class="fp-spinner" id="fp2-spinner"></span>
        </button>
    </div>

    <!-- ── STEP 3: Success ── -->
    <div class="fp-step" id="fp-step-3" style="text-align:center;padding:12px 0 6px">
        <i class="bi bi-check-circle-fill" style="font-size:3rem;color:#16a34a"></i>
        <p class="fp-title" style="justify-content:center;margin-top:14px">Password Reset!</p>
        <p class="fp-subtitle" style="margin-top:6px">
            Your password has been updated successfully.<br>
            You can now log in with your new password.
        </p>
        <button class="fp-btn" onclick="fpClose()">
            <i class="bi bi-box-arrow-in-right"></i> Back to Login
        </button>
    </div>

  </div><!-- /.fp-modal -->
</div><!-- /.fp-backdrop -->


<script>
/* ═══════════════════════════════════════════════════════════════
   Eye-toggle — EXACT copy of rpmToggleEye() from admin.php
   Used for both the main login password and the modal fields.
   ═══════════════════════════════════════════════════════════════ */
function rpmToggleEye(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') {
        inp.type = 'text';
        if (icon) { icon.className = 'bi bi-eye-fill'; }
    } else {
        inp.type = 'password';
        if (icon) { icon.className = 'bi bi-eye-slash-fill'; }
    }
}

/* ═══════════════════════════════════════════════════════════════
   Forgot-password modal state
   ═══════════════════════════════════════════════════════════════ */
let fpEmail = '';   // verified email carried from step 1 → 2

// Open
document.getElementById('forgotPwLink').addEventListener('click', function(e) {
    e.preventDefault();
    fpReset();
    document.getElementById('fpBackdrop').classList.add('open');
    setTimeout(() => document.getElementById('fp-email').focus(), 60);
});

// Close on backdrop click
document.getElementById('fpBackdrop').addEventListener('click', function(e) {
    if (e.target === this) fpClose();
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fpClose();
});

function fpClose() {
    document.getElementById('fpBackdrop').classList.remove('open');
}

function fpGoStep(n) {
    document.querySelectorAll('.fp-step').forEach(s => s.classList.remove('fp-active'));
    document.getElementById('fp-step-' + n).classList.add('fp-active');
}

function fpReset() {
    fpEmail = '';
    document.getElementById('fp-email').value      = '';
    document.getElementById('fp-new-pw').value     = '';
    document.getElementById('fp-confirm-pw').value = '';

    // Reset strength + match UI
    document.getElementById('fp-strength-bar').style.width      = '0';
    document.getElementById('fp-strength-bar').style.background = '';
    document.getElementById('fp-strength-label').textContent    = '';
    document.getElementById('fp-match-label').textContent       = '';
    document.getElementById('fp2-btn').disabled = true;

    // Reset requirement bullets (same as admin.php rpmSetReq)
    ['fp-req-length','fp-req-upper','fp-req-lower','fp-req-number','fp-req-special'].forEach(function(id) {
        fpSetReq(id, false);
    });

    // Reset eye icons
    ['login-eye-icon','fp-eye-new','fp-eye-confirm'].forEach(function(id) {
        const ic = document.getElementById(id);
        if (ic) ic.className = 'bi bi-eye-slash-fill';
    });
    const np = document.getElementById('fp-new-pw');
    const cp = document.getElementById('fp-confirm-pw');
    if (np) np.type = 'password';
    if (cp) cp.type = 'password';

    fpBanner(1, '', false);
    fpBanner(2, '', false);
    fpGoStep(1);
}

// Show/hide error banner
function fpBanner(step, msg, show) {
    const el = document.getElementById('fp' + step + '-err');
    document.getElementById('fp' + step + '-err-text').textContent = msg;
    el.classList.toggle('show', show);
}

/* ── STEP 1: verify email via AJAX ── */
async function fpStep1Submit() {
    const email = document.getElementById('fp-email').value.trim();
    if (!email) { fpBanner(1, 'Please enter your email address.', true); return; }

    const btn     = document.getElementById('fp1-btn');
    const btnText = document.getElementById('fp1-btn-text');
    const spinner = document.getElementById('fp1-spinner');

    btn.disabled = true; btnText.textContent = 'Verifying…'; spinner.style.display = 'inline-block';
    fpBanner(1, '', false);

    try {
        const fd = new FormData();
        fd.append('ajax_action', 'forgot_check_email');
        fd.append('email', email);
        const res  = await fetch(location.pathname, { method:'POST', body:fd });
        const data = await res.json();

        if (data.ok) {
            fpEmail = email;
            document.getElementById('fp2-subtitle').textContent =
                'Hi ' + data.name + '! Create a new secure password below.';
            fpGoStep(2);
            setTimeout(() => document.getElementById('fp-new-pw').focus(), 60);
        } else {
            fpBanner(1, data.msg, true);
        }
    } catch(e) {
        fpBanner(1, 'Connection error. Please try again.', true);
    } finally {
        btn.disabled = false; btnText.textContent = 'Continue'; spinner.style.display = 'none';
    }
}

/* ── Password strength (EXACT logic from admin.php rpmCheckStrength) ── */
function fpCheckStrength(pw) {
    let score = 0;
    if (pw.length >= 8)           score++;
    if (pw.length >= 12)          score++;
    if (/[A-Z]/.test(pw))         score++;
    if (/[0-9]/.test(pw))         score++;
    if (/[^A-Za-z0-9]/.test(pw))  score++;
    const levels = [
        { label: '',            color: '',        pct: '0%'   },
        { label: 'Weak',        color: '#ef4444', pct: '25%'  },
        { label: 'Fair',        color: '#f97316', pct: '50%'  },
        { label: 'Good',        color: '#eab308', pct: '75%'  },
        { label: 'Strong',      color: '#22c55e', pct: '100%' },
        { label: 'Very Strong', color: '#16a34a', pct: '100%' },
    ];
    const lvl = levels[Math.min(score, levels.length - 1)];
    const bar = document.getElementById('fp-strength-bar');
    const lbl = document.getElementById('fp-strength-label');
    bar.style.width = lvl.pct; bar.style.background = lvl.color;
    lbl.textContent = pw.length > 0 ? lvl.label : ''; lbl.style.color = lvl.color;
    return score;
}

/* ── Requirement bullet helper (EXACT copy of admin.php rpmSetReq) ── */
function fpSetReq(id, met) {
    const li = document.getElementById(id);
    if (!li) return;
    const ic = li.querySelector('i');
    if (met) {
        li.style.color = '#16a34a';
        ic.className   = 'bi bi-check-circle-fill';
        ic.style.fontSize = '.72rem';
    } else {
        li.style.color = '#94a3b8';
        ic.className   = 'bi bi-circle';
        ic.style.fontSize = '.6rem';
    }
}

/* ── Live validation (mirrors admin.php rpmOnInput) ── */
function fpOnInput() {
    const pw      = document.getElementById('fp-new-pw').value;
    const confirm = document.getElementById('fp-confirm-pw').value;
    const matchEl = document.getElementById('fp-match-label');
    const btn     = document.getElementById('fp2-btn');

    fpCheckStrength(pw);

    const hasLength  = pw.length >= 8;
    const hasUpper   = /[A-Z]/.test(pw);
    const hasLower   = /[a-z]/.test(pw);
    const hasNumber  = /[0-9]/.test(pw);
    const hasSpecial = /[^A-Za-z0-9]/.test(pw);

    fpSetReq('fp-req-length',  hasLength);
    fpSetReq('fp-req-upper',   hasUpper);
    fpSetReq('fp-req-lower',   hasLower);
    fpSetReq('fp-req-number',  hasNumber);
    fpSetReq('fp-req-special', hasSpecial);

    const pwValid = hasLength && hasUpper && hasLower && hasNumber && hasSpecial;
    let confirmValid = false;

    if (confirm.length > 0) {
        if (pw === confirm) {
            matchEl.textContent = '✓ Passwords match'; matchEl.style.color = '#16a34a';
            confirmValid = true;
        } else {
            matchEl.textContent = '✗ Passwords do not match'; matchEl.style.color = '#dc2626';
        }
    } else {
        matchEl.textContent = '';
    }

    btn.disabled = !(pwValid && confirmValid);
    btn.style.opacity = btn.disabled ? '.55' : '1';
    btn.style.cursor  = btn.disabled ? 'not-allowed' : 'pointer';
}

/* ── STEP 2: submit new password via AJAX ── */
async function fpStep2Submit() {
    fpBanner(2, '', false);
    const btn     = document.getElementById('fp2-btn');
    const btnText = document.getElementById('fp2-btn-text');
    const spinner = document.getElementById('fp2-spinner');

    btn.disabled = true; btnText.textContent = 'Saving…'; spinner.style.display = 'inline-block';

    try {
        const fd = new FormData();
        fd.append('ajax_action',   'forgot_reset');
        fd.append('email',         fpEmail);
        fd.append('new_password',  document.getElementById('fp-new-pw').value);
        fd.append('confirm_pw',    document.getElementById('fp-confirm-pw').value);
        const res  = await fetch(location.pathname, { method:'POST', body:fd });
        const data = await res.json();

        if (data.ok) {
            fpGoStep(3);
        } else {
            fpBanner(2, data.msg, true);
            btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
            btnText.textContent = 'Reset Password'; spinner.style.display = 'none';
        }
    } catch(e) {
        fpBanner(2, 'Connection error. Please try again.', true);
        btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
        btnText.textContent = 'Reset Password'; spinner.style.display = 'none';
    }
}
</script>

</body>
</html>