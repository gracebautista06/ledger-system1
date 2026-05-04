<?php
$page_title = 'My Notifications';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header("Location: ../portal/login.php"); exit();
}

$staff_id = (int)$_SESSION['user_id'];
$flash    = '';

// ── Mark single notification as read ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['notif_action'] ?? '';

    if ($action === 'mark_read') {
        $nid = (int)($_POST['notif_id'] ?? 0);
        if ($nid > 0) {
            $stmt = $conn->prepare("
                UPDATE staff_notifications
                SET status = 'read', read_at = NOW()
                WHERE notif_id = ? AND staff_id = ? AND status = 'unread'
            ");
            $stmt->bind_param('ii', $nid, $staff_id);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, $staff_id, 'Staff', 'Notification Acknowledged',
                "Acknowledged request outcome notification #{$nid}");
            $flash = "<div class='alert success'>Notification marked as seen.</div>";
        }

    } elseif ($action === 'mark_all_read') {
        $stmt = $conn->prepare("
            UPDATE staff_notifications
            SET status = 'read', read_at = NOW()
            WHERE staff_id = ? AND status = 'unread' AND notif_type = 'request_outcome'
        ");
        $stmt->bind_param('i', $staff_id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, $staff_id, 'Staff', 'All Notifications Acknowledged',
            'Marked all request outcome notifications as read');
        $flash = "<div class='alert success'>All notifications marked as seen.</div>";
    }
}

// ── Fetch request outcome notifications for this staff member ─
$notifs_q = $conn->prepare("
    SELECT *
    FROM staff_notifications
    WHERE staff_id = ? AND notif_type = 'request_outcome'
    ORDER BY FIELD(status, 'unread', 'read'), created_at DESC
    LIMIT 50
");
$notifs_q->bind_param('i', $staff_id);
$notifs_q->execute();
$result = $notifs_q->get_result();
$notifs_q->close();

$notifications = [];
$unread_count  = 0;

while ($row = $result->fetch_assoc()) {
    if ($row['status'] === 'unread') $unread_count++;
    $notifications[] = $row;
}
?>

<div style="max-width:860px; margin:2rem auto;">

    <div class="page-header">
        <div>
            <h2>
                My Notifications
                <?php if ($unread_count > 0): ?>
                <span class="badge badge-critical" style="font-size:0.68rem; vertical-align:middle; margin-left:8px;">
                    <?php echo $unread_count; ?> NEW
                </span>
                <?php endif; ?>
            </h2>
            <p style="color:var(--text-muted); font-size:0.9rem; margin:0;">
                Status updates on your edit and deletion requests.
            </p>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <?php if ($unread_count > 0): ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="notif_action" value="mark_all_read">
                <button type="submit" class="btn-farm btn-outline btn-sm">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>Mark all as seen
                </button>
            </form>
            <?php endif; ?>
            <a href="dashboard.php" class="back-link" style="margin:0;">← Dashboard</a>
        </div>
    </div>

    <?php echo $flash; ?>

    <?php if (empty($notifications)): ?>
    <div class="card">
        <div class="empty-state">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                 stroke-linecap="round" stroke-linejoin="round" style="opacity:0.35; margin-bottom:12px;">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <p>No notifications yet.</p>
            <small>You'll see updates here once the Owner reviews your edit or deletion requests.</small>
        </div>
    </div>

    <?php else:
        foreach ($notifications as $n):
            $is_unread   = $n['status'] === 'unread';
            $msg_lower   = strtolower($n['message']);
            $is_approved = strpos($msg_lower, 'approved') !== false;

            $border_color = $is_approved ? 'var(--success)' : 'var(--danger)';
            $status_label = $is_approved ? 'Approved' : 'Rejected';
            $badge_class  = $is_approved ? 'badge-healthy' : 'badge-critical';

            // Extract owner note if present
            $owner_note = '';
            if (preg_match('/Owner note:\s*(.+)$/i', $n['message'], $match)) {
                $owner_note = trim($match[1]);
            }
    ?>

    <div class="card" style="margin-bottom:1.2rem;
         border-left:5px solid <?php echo $border_color; ?>;
         padding:1.4rem 1.6rem;
         <?php echo $is_unread ? 'background:rgba(0,0,0,0.02);' : ''; ?>">

        <!-- Header row -->
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">

                <?php if ($is_unread): ?>
                    <span class="badge badge-critical" style="font-size:0.62rem;">NEW</span>
                <?php else: ?>
                    <span class="badge badge-pending" style="font-size:0.62rem;">SEEN</span>
                <?php endif; ?>

                <span class="badge <?php echo $badge_class; ?>" style="font-size:0.62rem;">
                    <?php if ($is_approved): ?>
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:2px;"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:2px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <?php endif; ?>
                    <?php echo $status_label; ?>
                </span>

                <?php if (!empty($n['record_type']) && !empty($n['record_id'])): ?>
                <span class="badge badge-pending" style="font-size:0.62rem;">
                    <?php echo htmlspecialchars($n['record_type']); ?> #<?php echo (int)$n['record_id']; ?>
                </span>
                <?php endif; ?>

            </div>
            <span style="font-size:0.75rem; color:var(--text-muted); white-space:nowrap;">
                <?php echo date('M d, Y g:i A', strtotime($n['created_at'])); ?>
            </span>
        </div>

        <!-- Message body -->
        <div style="font-size:0.9rem; color:var(--text-secondary); line-height:1.6; margin-bottom:<?php echo ($owner_note || $is_unread) ? '14px' : '0'; ?>;">
            <?php
            $clean_msg = preg_replace('/\s*Owner note:.+$/i', '', $n['message']);
            echo htmlspecialchars($clean_msg);
            ?>
        </div>

        <!-- Owner note (if any) -->
        <?php if (!empty($owner_note)): ?>
        <div style="background:var(--bg-wood); border-radius:var(--radius-sm); padding:10px 14px;
                    border-left:3px solid var(--border-mid); margin-bottom:14px;
                    font-size:0.85rem; color:var(--text-secondary); line-height:1.6;">
            <strong style="font-size:0.7rem; font-weight:700; color:var(--text-muted);
                            text-transform:uppercase; letter-spacing:0.5px; display:block; margin-bottom:4px;">
                Owner's Note
            </strong>
            <?php echo htmlspecialchars($owner_note); ?>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <a href="view_logs.php" class="btn-farm btn-dark btn-sm" style="font-size:0.85rem;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>View My Logs
            </a>

            <?php if ($is_unread): ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="notif_action" value="mark_read">
                <input type="hidden" name="notif_id" value="<?php echo (int)$n['notif_id']; ?>">
                <button type="submit" class="btn-farm btn-outline btn-sm" style="font-size:0.85rem;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>Mark as Seen
                </button>
            </form>
            <?php endif; ?>

            <?php if (!empty($n['read_at'])): ?>
            <span style="font-size:0.72rem; color:var(--text-muted);">
                Seen <?php echo date('M d · g:i A', strtotime($n['read_at'])); ?>
            </span>
            <?php endif; ?>
        </div>

    </div>
    <?php endforeach; endif; ?>

</div>

<?php include('../includes/footer.php'); ?>