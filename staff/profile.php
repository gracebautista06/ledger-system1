<?php
$page_title = 'My Profile';

session_start();
include('../includes/db.php');
include('../includes/header.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header('Location: ../portal/login.php');
    exit();
}

$user_id  = (int)$_SESSION['user_id'];
$message  = '';
$msg_type = '';

// ── FETCH CURRENT USER ────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT user_id, username, display_name, profile_pic, bio, role, created_at, last_seen FROM users WHERE user_id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ── HANDLE FORM SUBMISSIONS ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── UPDATE PROFILE ───────────────────────────────────────────────────────
    if ($action === 'update_profile') {
        $display_name = trim($_POST['display_name'] ?? '');
        $bio          = trim($_POST['bio'] ?? '');
        $new_username = trim($_POST['username'] ?? '');
        $errors       = [];

        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $new_username)) {
            $errors[] = 'Username must be 3–20 characters (letters, numbers, underscores).';
        }
        if (strlen($display_name) > 100) {
            $errors[] = 'Display name must be under 100 characters.';
        }

        // Check username uniqueness
        if (empty($errors) && $new_username !== $user['username']) {
            $chk = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ? LIMIT 1");
            $chk->bind_param('si', $new_username, $user_id);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) $errors[] = 'That username is already taken.';
            $chk->close();
        }

        // ── PROFILE PICTURE UPLOAD ───────────────────────────────────────────
        $pic_filename = $user['profile_pic'];

        if (!empty($_FILES['profile_pic']['name'])) {
            $upload_dir  = '../assets/uploads/avatars/';
            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $max_size    = 2 * 1024 * 1024;
            $ext         = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));

            if (!in_array($ext, $allowed_ext)) {
                $errors[] = 'Profile picture must be JPG, PNG, WEBP, or GIF.';
            } elseif ($_FILES['profile_pic']['size'] > $max_size) {
                $errors[] = 'Profile picture must be under 2MB.';
            } else {
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                if ($user['profile_pic'] && file_exists($upload_dir . $user['profile_pic'])) {
                    unlink($upload_dir . $user['profile_pic']);
                }
                $pic_filename = 'staff_' . $user_id . '_' . time() . '.' . $ext;
                if (!move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_dir . $pic_filename)) {
                    $errors[] = 'Failed to upload picture. Check folder permissions.';
                    $pic_filename = $user['profile_pic'];
                }
            }
        }

        if (empty($errors)) {
            $stmt = $conn->prepare("UPDATE users SET username = ?, display_name = ?, bio = ?, profile_pic = ? WHERE user_id = ?");
            $stmt->bind_param('ssssi', $new_username, $display_name, $bio, $pic_filename, $user_id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['username'] = $new_username;

            // Refresh
            $stmt = $conn->prepare("SELECT user_id, username, display_name, profile_pic, bio, role, created_at, last_seen FROM users WHERE user_id = ? LIMIT 1");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $message  = 'Profile updated successfully.';
            $msg_type = 'success';
        } else {
            $message  = implode('<br>', array_map('htmlspecialchars', $errors));
            $msg_type = 'error';
        }
    }

    // ── CHANGE PASSWORD ──────────────────────────────────────────────────────
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new_pw  = $_POST['new_password']      ?? '';
        $confirm = $_POST['confirm_password']  ?? '';
        $errors  = [];

        $chk = $conn->prepare("SELECT password FROM users WHERE user_id = ? LIMIT 1");
        $chk->bind_param('i', $user_id);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!password_verify($current, $row['password']))  $errors[] = 'Current password is incorrect.';
        if (strlen($new_pw) < 8)                           $errors[] = 'New password must be at least 8 characters.';
        if ($new_pw !== $confirm)                          $errors[] = 'New passwords do not match.';
        if ($current === $new_pw)                          $errors[] = 'New password must be different from your current password.';

        if (empty($errors)) {
            $hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->bind_param('si', $hash, $user_id);
            $stmt->execute();
            $stmt->close();
            $message  = 'Password changed successfully.';
            $msg_type = 'success';
        } else {
            $message  = implode('<br>', array_map('htmlspecialchars', $errors));
            $msg_type = 'error';
        }
    }

    // ── REMOVE PROFILE PICTURE ───────────────────────────────────────────────
    if ($action === 'remove_pic') {
        $upload_dir = '../assets/uploads/avatars/';
        if ($user['profile_pic'] && file_exists($upload_dir . $user['profile_pic'])) {
            unlink($upload_dir . $user['profile_pic']);
        }
        $stmt = $conn->prepare("UPDATE users SET profile_pic = NULL WHERE user_id = ?");
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
        $user['profile_pic'] = null;
        $message  = 'Profile picture removed.';
        $msg_type = 'success';
    }
}

// ── HELPERS ───────────────────────────────────────────────────────────────────
$avatar_url   = $user['profile_pic']
    ? '../assets/uploads/avatars/' . htmlspecialchars($user['profile_pic'])
    : null;
$display      = $user['display_name'] ?: $user['username'];
$member_since = $user['created_at'] ? date('F j, Y', strtotime($user['created_at'])) : '—';
$last_seen    = $user['last_seen']   ? date('M j, Y g:i A', strtotime($user['last_seen'])) : 'Never';

// Staff-specific stats — only their own activity
$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM harvests WHERE staff_id = ?");
$stmt->bind_param('i', $user_id); $stmt->execute();
$my_harvests = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sales WHERE staff_id = ?");
$stmt->bind_param('i', $user_id); $stmt->execute();
$my_sales = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();
?>

<style>
.profile-wrap {
    max-width: 960px;
    margin: 2rem auto;
}

/* Hero */
.profile-hero {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius);
    padding: 2.4rem 2rem 2rem;
    margin-bottom: 1.6rem;
    display: flex;
    gap: 2rem;
    align-items: flex-start;
    flex-wrap: wrap;
}

/* Avatar */
.avatar-wrap { position: relative; flex-shrink: 0; }
.avatar-ring {
    width: 110px; height: 110px;
    border-radius: 50%;
    border: 3px solid var(--terra-lt);
    padding: 3px;
    background: var(--bg-wood);
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
}
.avatar-ring img {
    width: 100%; height: 100%;
    border-radius: 50%;
    object-fit: cover;
}
.avatar-initials {
    font-size: 2.4rem;
    font-weight: 800;
    font-family: 'Playfair Display', serif;
    color: var(--terra-lt);
    line-height: 1;
    user-select: none;
}
.avatar-upload-btn {
    position: absolute;
    bottom: 2px; right: 2px;
    width: 28px; height: 28px;
    border-radius: 50%;
    background: var(--terra-lt);
    border: 2px solid var(--bg-card);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.avatar-upload-btn:hover { background: var(--gold); }
.avatar-upload-btn svg { width: 13px; height: 13px; fill: none; stroke: #1a1208; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }

/* Hero info */
.profile-hero-info { flex: 1; min-width: 200px; }
.profile-hero-info h2 {
    font-family: 'Playfair Display', serif;
    font-size: 1.7rem;
    color: var(--text-primary);
    margin: 0 0 4px;
}
.profile-username { font-size: 0.83rem; color: var(--text-muted); margin-bottom: 10px; }
.profile-bio {
    font-size: 0.87rem; color: var(--text-secondary);
    line-height: 1.6; margin-bottom: 14px; font-style: italic;
}
.profile-meta { display: flex; gap: 18px; flex-wrap: wrap; }
.profile-meta-item {
    font-size: 0.75rem; color: var(--text-muted);
    display: flex; align-items: center; gap: 5px;
}
.profile-meta-item svg {
    width: 13px; height: 13px;
    fill: none; stroke: var(--terra-lt);
    stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
    flex-shrink: 0;
}
.profile-stats { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 16px; }
.profile-stat-pill {
    background: var(--bg-wood);
    border: 1px solid var(--border-subtle);
    border-radius: 999px;
    padding: 5px 14px;
    font-size: 0.75rem;
    color: var(--text-secondary);
    display: flex; align-items: center; gap: 6px;
}
.profile-stat-pill strong { color: var(--terra-lt); }

/* Grid */
.profile-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.4rem;
}
@media (max-width: 680px) {
    .profile-grid { grid-template-columns: 1fr; }
    .profile-hero { flex-direction: column; align-items: center; text-align: center; }
    .profile-meta { justify-content: center; }
    .profile-stats { justify-content: center; }
}

/* Section cards */
.profile-section {
    background: var(--bg-card);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius);
    overflow: hidden;
}
.profile-section-header {
    padding: 1rem 1.4rem;
    border-bottom: 1px solid var(--border-subtle);
    display: flex; align-items: center; gap: 8px;
}
.profile-section-header h3 {
    font-size: 0.88rem; font-weight: 700;
    color: var(--text-primary); margin: 0;
}
.profile-section-header svg { flex-shrink: 0; }
.profile-section-body { padding: 1.4rem; }
.profile-section .form-group { margin-bottom: 1rem; }
.profile-section .form-group:last-of-type { margin-bottom: 0; }
.profile-section label {
    display: block; font-size: 0.75rem; font-weight: 700;
    color: var(--text-muted); text-transform: uppercase;
    letter-spacing: 0.5px; margin-bottom: 6px;
}
.profile-section .form-input { width: 100%; box-sizing: border-box; }
.profile-section textarea.form-input {
    resize: vertical; min-height: 80px;
    font-family: inherit; line-height: 1.5;
}

/* Password strength */
.strength-wrap { margin-top: 6px; display: none; }
.strength-bar-bg { background: var(--border-subtle); border-radius: 999px; height: 4px; overflow: hidden; margin-bottom: 4px; }
#strength-bar { height: 100%; width: 0; border-radius: 999px; transition: width .3s, background .3s; }

/* Danger zone */
.danger-zone {
    background: rgba(194,58,58,.05);
    border: 1px solid rgba(194,58,58,.2);
    border-radius: var(--radius);
    padding: .8rem 1rem;
    margin-top: 1rem;
    display: flex; align-items: center;
    justify-content: space-between;
    gap: 1rem; flex-wrap: wrap;
}
.danger-zone p { font-size: 0.82rem; color: var(--text-secondary); margin: 0; }
.danger-zone small { font-size: 0.72rem; color: var(--text-muted); display: block; margin-top: 3px; }
</style>

<div class="profile-wrap">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div>
            <h2 style="display:flex; align-items:center; gap:10px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--terra-lt)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                My Profile
            </h2>
            <p style="color:var(--text-muted); font-size:0.9rem; margin:0;">
                Manage your account information and password.
            </p>
        </div>
    </div>

    <!-- ALERT -->
    <?php if ($message): ?>
        <div class="alert <?php echo $msg_type; ?>" id="profile-alert">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- HERO CARD -->
    <div class="profile-hero">

        <div class="avatar-wrap">
            <div class="avatar-ring">
                <?php if ($avatar_url): ?>
                    <img src="<?php echo $avatar_url; ?>" alt="Profile Picture">
                <?php else: ?>
                    <div class="avatar-initials">
                        <?php echo strtoupper(substr($display, 0, 1)); ?>
                    </div>
                <?php endif; ?>
            </div>
            <label class="avatar-upload-btn" title="Change photo" for="pic-input-hero">
                <svg viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            </label>
            <input type="file" id="pic-input-hero" accept="image/*"
                   style="display:none;" onchange="previewAvatar(this)">
        </div>

        <div class="profile-hero-info">
            <h2><?php echo htmlspecialchars($display); ?></h2>
            <div class="profile-username">
                @<?php echo htmlspecialchars($user['username']); ?>
                &nbsp;
                <span class="badge badge-staff" style="font-size:0.6rem; vertical-align:middle;">Staff</span>
            </div>

            <?php if ($user['bio']): ?>
                <div class="profile-bio">"<?php echo htmlspecialchars($user['bio']); ?>"</div>
            <?php else: ?>
                <div class="profile-bio" style="opacity:0.4;">No bio yet — add one below.</div>
            <?php endif; ?>

            <div class="profile-meta">
                <span class="profile-meta-item">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    Member since <?php echo $member_since; ?>
                </span>
                <span class="profile-meta-item">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Last active: <?php echo $last_seen; ?>
                </span>
            </div>

            <div class="profile-stats">
                <div class="profile-stat-pill">
                    <strong><?php echo number_format($my_harvests); ?></strong> My Harvests
                </div>
                <div class="profile-stat-pill">
                    <strong><?php echo number_format($my_sales); ?></strong> My Sales
                </div>
            </div>
        </div>

    </div>

    <!-- TWO COLUMN GRID -->
    <div class="profile-grid">

        <!-- LEFT: EDIT PROFILE -->
        <div class="profile-section">
            <div class="profile-section-header">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--terra-lt)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <h3>Edit Profile</h3>
            </div>
            <div class="profile-section-body">
                <form method="POST" enctype="multipart/form-data" id="profile-form">
                    <input type="hidden" name="action" value="update_profile">
                    <input type="file" name="profile_pic" id="pic-input-form"
                           accept="image/*" style="display:none;"
                           onchange="previewAvatar(this)">

                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" class="form-input"
                               value="<?php echo htmlspecialchars($user['username']); ?>"
                               pattern="[a-zA-Z0-9_]{3,20}" required>
                    </div>
                    <div class="form-group">
                        <label>Display Name</label>
                        <input type="text" name="display_name" class="form-input"
                               placeholder="Your real name (optional)"
                               value="<?php echo htmlspecialchars($user['display_name'] ?? ''); ?>"
                               maxlength="100">
                    </div>
                    <div class="form-group">
                        <label>Bio</label>
                        <textarea name="bio" class="form-input"
                                  placeholder="A short note about yourself..."
                                  maxlength="300"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Profile Picture</label>
                        <input type="file" name="profile_pic" class="form-input"
                               accept="image/*" style="padding:6px;"
                               onchange="previewAvatar(this)">
                        <small style="color:var(--text-muted); font-size:0.72rem;">
                            JPG, PNG, WEBP or GIF — max 2MB
                        </small>
                    </div>
                    <button type="submit" class="btn-farm btn-orange btn-full" style="margin-top:6px;">
                        Save Profile
                    </button>
                </form>

                <?php if ($user['profile_pic']): ?>
                <div class="danger-zone">
                    <div>
                        <p>Remove profile picture</p>
                        <small>Reverts to your initial letter avatar.</small>
                    </div>
                    <form method="POST" onsubmit="return confirm('Remove your profile picture?')">
                        <input type="hidden" name="action" value="remove_pic">
                        <button type="submit" class="btn-farm btn-danger btn-sm">Remove</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT -->
        <div>

            <!-- CHANGE PASSWORD -->
            <div class="profile-section">
                <div class="profile-section-header">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--terra-lt)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <h3>Change Password</h3>
                </div>
                <div class="profile-section-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-group">
                            <label>Current Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="cur_pw" name="current_password"
                                       class="form-input" placeholder="Enter current password" required>
                                <button type="button" class="toggle-password"
                                        onclick="togglePw('cur_pw','eye_cur')">
                                    <span id="eye_cur" class="eye-icon active"></span>
                                </button>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>New Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="new_pw" name="new_password"
                                       class="form-input" placeholder="Min. 8 characters"
                                       required oninput="updateStrength(this.value)">
                                <button type="button" class="toggle-password"
                                        onclick="togglePw('new_pw','eye_new')">
                                    <span id="eye_new" class="eye-icon active"></span>
                                </button>
                            </div>
                            <div class="strength-wrap" id="strength-wrap">
                                <div class="strength-bar-bg">
                                    <div id="strength-bar"></div>
                                </div>
                                <small id="strength-label"></small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <div class="password-wrapper">
                                <input type="password" id="conf_pw" name="confirm_password"
                                       class="form-input" placeholder="Re-enter new password"
                                       required oninput="checkMatch()">
                                <button type="button" class="toggle-password"
                                        onclick="togglePw('conf_pw','eye_conf')">
                                    <span id="eye_conf" class="eye-icon active"></span>
                                </button>
                            </div>
                            <small id="pw-match-msg" style="font-size:0.78rem;"></small>
                        </div>
                        <button type="submit" class="btn-farm btn-green btn-full" style="margin-top:6px;">
                            Update Password
                        </button>
                    </form>
                </div>
            </div>

            <!-- ACCOUNT INFO -->
            <div class="profile-section" style="margin-top:1.4rem;">
                <div class="profile-section-header">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--terra-lt)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <h3>Account Info</h3>
                </div>
                <div class="profile-section-body" style="display:flex; flex-direction:column; gap:12px;">
                    <?php foreach ([
                        ['Role',        htmlspecialchars($user['role'])],
                        ['User ID',     '#' . $user['user_id']],
                        ['Registered',  $member_since],
                        ['Last Active', $last_seen],
                    ] as [$label, $val]): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center;
                                padding-bottom:10px; border-bottom:1px solid var(--border-subtle);">
                        <span style="font-size:0.75rem; color:var(--text-muted); font-weight:700;
                                     text-transform:uppercase; letter-spacing:0.4px;">
                            <?php echo $label; ?>
                        </span>
                        <span style="font-size:0.85rem; color:var(--text-primary); font-weight:600;">
                            <?php echo $val; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>

    </div><!-- /.profile-grid -->

    <a href="dashboard.php" class="back-link" style="margin-top:1.6rem; display:inline-block;">
        &larr; Back to Dashboard
    </a>

</div>

<script>
function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.querySelector('.avatar-ring').innerHTML =
            `<img src="${e.target.result}" alt="Preview" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
    };
    reader.readAsDataURL(input.files[0]);
}

function togglePw(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (!input || !icon) return;
    input.type = input.type === 'password' ? 'text' : 'password';
    icon.classList.toggle('active', input.type === 'password');
}

function updateStrength(val) {
    const wrap  = document.getElementById('strength-wrap');
    const bar   = document.getElementById('strength-bar');
    const label = document.getElementById('strength-label');
    wrap.style.display = val.length ? 'block' : 'none';
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
    if (/[0-9]/.test(val))  score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        { pct:'20%', color:'#C23A3A', text:'Very weak'   },
        { pct:'40%', color:'#C24B2A', text:'Weak'        },
        { pct:'60%', color:'#D4900A', text:'Fair'        },
        { pct:'80%', color:'#4E9B5B', text:'Strong'      },
        { pct:'100%',color:'#2A7A40', text:'Very strong' },
    ];
    const l = levels[Math.min(score, 4)];
    bar.style.width      = l.pct;
    bar.style.background = l.color;
    label.textContent    = l.text;
    label.style.color    = l.color;
}

function checkMatch() {
    const pw1 = document.getElementById('new_pw').value;
    const pw2 = document.getElementById('conf_pw').value;
    const msg = document.getElementById('pw-match-msg');
    if (!pw2.length) { msg.textContent = ''; return; }
    msg.textContent = pw1 === pw2 ? 'Passwords match' : 'Passwords do not match';
    msg.style.color = pw1 === pw2 ? '#4E9B5B' : '#C23A3A';
}

document.addEventListener('DOMContentLoaded', () => {
    const alert = document.getElementById('profile-alert');
    if (alert) {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.6s ease';
            alert.style.opacity    = '0';
            setTimeout(() => alert.remove(), 650);
        }, 4000);
    }
});
</script>

<?php include('../includes/footer.php'); ?>