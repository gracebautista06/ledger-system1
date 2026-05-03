<?php
$page_title = 'Staff Management';

session_start();
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/log_activity.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header('Location: ../../portal/login.php');
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($action === 'reset' && $user_id > 0) {
        $hash = password_hash('Farm1234', PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE user_id = ? AND role = ?');
        $role = 'Staff';
        $stmt->bind_param('sis', $hash, $user_id, $role);
        $stmt->execute();
        $stmt->close();
        $message = "<div class='alert success'>Password reset to <strong>Farm1234</strong> for account #$user_id.</div>";

    } elseif ($action === 'delete' && $user_id > 0) {
        if ($user_id === (int)$_SESSION['user_id']) {
            $message = "<div class='alert error'>You cannot delete your own account.</div>";
        } else {
            $stmt = $conn->prepare('DELETE FROM users WHERE user_id = ? AND role = ?');
            $role = 'Staff';
            $stmt->bind_param('is', $user_id, $role);
            $stmt->execute();
            $stmt->close();
            $message = "<div class='alert warning'>Staff account #$user_id has been removed.</div>";
        }

    } elseif ($action === 'add_staff') {
        $username = trim($_POST['new_username'] ?? '');
        $password = $_POST['new_password'] ?? '';
        $errors   = [];

        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            $errors[] = 'Username must be 3–20 characters (letters, numbers, underscores).';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if (empty($errors)) {
            $chk = $conn->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1');
            $chk->bind_param('s', $username);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $errors[] = 'That username is already taken.';
            }
            $chk->close();
        }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $role = 'Staff';
            $stmt = $conn->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
            $stmt->bind_param('sss', $username, $hash, $role);
            if ($stmt->execute()) {
                $message = "<div class='alert success'>Account <strong>" . htmlspecialchars($username) . "</strong> created.</div>";
            } else {
                $message = "<div class='alert error'>Database error: " . htmlspecialchars($conn->error) . "</div>";
            }
            $stmt->close();
        } else {
            $error_html = implode('<br>', array_map('htmlspecialchars', $errors));
            $message = "<div class='alert error'>$error_html</div>";
        }
    }
}

$search = trim($_GET['q'] ?? '');

if ($search !== '') {
    $stmt = $conn->prepare("SELECT user_id, username, role, created_at, last_seen, is_online FROM users WHERE role = 'Staff' AND username LIKE ? ORDER BY username ASC");
    $like = '%' . $search . '%';
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT user_id, username, role, created_at, last_seen, is_online FROM users WHERE role = 'Staff' ORDER BY username ASC");
}

$staff_count = $result ? $result->num_rows : 0;

$online_q     = $conn->query("SELECT COUNT(*) AS n FROM users WHERE role = 'Staff' AND is_online = 1");
$online_count = $online_q ? (int)$online_q->fetch_assoc()['n'] : 0;

function format_last_seen(?string $last_seen, int $is_online): string
{
    if ($is_online === 1 && $last_seen && (time() - strtotime($last_seen)) > 120) {
        $is_online = 0;
    }

    if ($is_online === 1) {
        $diff  = $last_seen ? time() - strtotime($last_seen) : 0;
        $label = $diff < 60 ? 'Active now' : 'Idle';
        return '<span class="status-dot status-online"></span><span class="status-label online">' . $label . '</span>';
    }

    if (!$last_seen) {
        return '<span class="status-label muted">Never</span>';
    }

    $diff = time() - strtotime($last_seen);

    if ($diff < 3600) {
        $n   = max(1, (int)floor($diff / 60));
        $ago = $n . 'm ago';
    } elseif ($diff < 86400) {
        $n   = (int)floor($diff / 3600);
        $ago = $n . 'h ago';
    } elseif ($diff < 86400 * 7) {
        $n   = (int)floor($diff / 86400);
        $ago = $n . 'd ago';
    } else {
        $ago = date('M d, Y', strtotime($last_seen));
    }

    return '<span class="status-dot status-offline"></span><span class="status-label muted">' . htmlspecialchars($ago) . '</span>';
}
?>

<style>
.status-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 6px;
    vertical-align: middle;
    flex-shrink: 0;
}
.status-online  { background: var(--success); animation: pulse-online 2s infinite; }
.status-offline { background: var(--text-muted); }
.status-label   { font-size: 0.82rem; vertical-align: middle; }
.status-label.online { color: var(--success); font-weight: 600; }
.status-label.muted  { color: var(--text-muted); }

@keyframes pulse-online {
    0%   { box-shadow: 0 0 0 0 rgba(78,155,91,.7); }
    70%  { box-shadow: 0 0 0 6px rgba(78,155,91,0); }
    100% { box-shadow: 0 0 0 0 rgba(78,155,91,0); }
}

.action-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: var(--radius-sm);
    border: 1px solid var(--border-subtle);
    background: var(--bg-wood); cursor: pointer;
    transition: background .15s, border-color .15s;
    color: var(--text-secondary);
}
.action-btn:hover         { background: var(--bg-plank); border-color: var(--border-mid); }
.action-btn.danger:hover  { background: rgba(220,53,69,.15); border-color: var(--danger); color: var(--danger); }
.action-btn.reset:hover   { background: rgba(255,146,43,.15); border-color: var(--terra-lt); color: var(--terra-lt); }
.action-btn svg           { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
</style>

<div style="max-width:980px; margin:2rem auto;">

    <div class="page-header">
        <div>
            <h2>Staff Management</h2>
            <p>
                <?php echo $staff_count; ?> staff member<?php echo $staff_count !== 1 ? 's' : ''; ?>
                <?php echo $search ? 'matching <em>"' . htmlspecialchars($search) . '"</em>' : 'registered'; ?>.
                <?php if ($online_count > 0): ?>
                    &nbsp;&middot;&nbsp;<span style="color:var(--success); font-weight:600;"><?php echo $online_count; ?> online</span>
                <?php endif; ?>
            </p>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <form method="GET" style="display:flex; gap:8px; align-items:center;">
                <input type="text" name="q" class="form-input"
                       placeholder="Search username"
                       value="<?php echo htmlspecialchars($search); ?>"
                       style="width:190px; padding:8px 12px; font-size:0.85rem;">
                <button type="submit" class="btn-farm btn-sm" title="Search">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </button>
                <?php if ($search): ?>
                    <a href="users.php" class="btn-farm btn-dark btn-sm" title="Clear">&times;</a>
                <?php endif; ?>
            </form>
            <button class="btn-farm btn-orange btn-sm" onclick="toggleAddPanel()">+ Add Staff</button>
        </div>
    </div>

    <?php echo $message; ?>

    <div id="add-panel" style="display:none; margin-bottom:1.5rem;">
        <div class="card" style="border:1px solid var(--border-mid); border-top:3px solid var(--terra-lt); padding:1.4rem 1.8rem;">
            <h4 style="margin-bottom:1.2rem;">New Staff Account</h4>
            <form method="POST" style="display:grid; grid-template-columns:1fr 1fr auto; gap:14px; align-items:flex-end;">
                <input type="hidden" name="action" value="add_staff">
                <div class="form-group" style="margin:0;">
                    <label>Username</label>
                    <input type="text" name="new_username" class="form-input" placeholder="3–20 characters" required>
                </div>
                <div class="form-group" style="margin:0;">
                    <label>Password</label>
                    <input type="password" name="new_password" class="form-input" placeholder="Min. 8 characters" required>
                </div>
                <div style="display:flex; gap:8px;">
                    <button type="submit" class="btn-farm btn-green">Create</button>
                    <button type="button" class="btn-farm btn-dark" onclick="toggleAddPanel()">Cancel</button>
                </div>
            </form>
            <p style="font-size:0.75rem; color:var(--text-muted); margin-top:10px;">
                Default role is <strong>Staff</strong>. Staff will log in with these credentials.
            </p>
        </div>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrapper" style="border:none; border-radius:0;">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Registered</th>
                        <th>Status</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($staff_count > 0):
                        while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-size:0.8rem;">#<?php echo $row['user_id']; ?></td>
                        <td style="font-weight:600;"><?php echo htmlspecialchars($row['username']); ?></td>
                        <td><span class="badge badge-staff"><?php echo $row['role']; ?></span></td>
                        <td style="font-size:0.8rem; color:var(--text-muted);">
                            <?php echo $row['created_at'] ? date('M d, Y', strtotime($row['created_at'])) : '—'; ?>
                        </td>
                        <td><?php echo format_last_seen($row['last_seen'], (int)$row['is_online']); ?></td>
                        <td style="text-align:center; white-space:nowrap;">
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('Reset password to Farm1234 for <?php echo htmlspecialchars($row['username'], ENT_QUOTES); ?>?')">
                                <input type="hidden" name="action" value="reset">
                                <input type="hidden" name="user_id" value="<?php echo $row['user_id']; ?>">
                                <button type="submit" class="action-btn reset" title="Reset password">
                                    <svg viewBox="0 0 24 24"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0115-6.7L21 8M3 22v-6h6"/><path d="M21 12a9 9 0 01-15 6.7L3 16"/></svg>
                                </button>
                            </form>
                            <form method="POST" style="display:inline; margin-left:4px;"
                                  onsubmit="return confirm('Permanently delete <?php echo htmlspecialchars($row['username'], ENT_QUOTES); ?>? This cannot be undone.')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?php echo $row['user_id']; ?>">
                                <button type="submit" class="action-btn danger" title="Delete account">
                                    <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile;
                    else: ?>
                    <tr><td colspan="6">
                        <div class="empty-state">
                            <p>No staff members found<?php echo $search ? ' matching your search' : ''; ?>.</p>
                            <small>Click "+ Add Staff" to create a new account.</small>
                        </div>
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div style="padding:10px 16px; border-top:1px solid var(--border-subtle);
                    display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
            <span style="font-size:0.72rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; letter-spacing:.5px;">Status:</span>
            <span style="display:flex; align-items:center; gap:6px; font-size:0.78rem; color:var(--text-secondary);">
                <span class="status-dot status-online" style="animation:none;"></span> Online
            </span>
            <span style="display:flex; align-items:center; gap:6px; font-size:0.78rem; color:var(--text-secondary);">
                <span class="status-dot status-offline"></span> Offline
            </span>
        </div>
    </div>

    <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
</div>

<script>
function toggleAddPanel() {
    const panel = document.getElementById('add-panel');
    const visible = panel.style.display !== 'none';
    panel.style.display = visible ? 'none' : 'block';
    if (!visible) panel.querySelector('input[name="new_username"]').focus();
}

<?php if (str_contains($message, 'alert error') && ($_POST['action'] ?? '') === 'add_staff'): ?>
document.addEventListener('DOMContentLoaded', toggleAddPanel);
<?php endif; ?>

setTimeout(() => location.reload(), 30000);
</script>

<?php include('../../includes/footer.php'); ?>