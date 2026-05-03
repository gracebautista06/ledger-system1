<?php
/*  includes/notifications.php — Reusable Notification Bell Component
    render_notification_bell($conn, $role) — outputs the bell icon with badge
    render_notification_panel($conn, $role) — outputs the dropdown panel

    Staff bell:  counts active sell-first alerts (unread + read, NOT completed)
    Owner bell:  counts unread staff_notifications + unread sell-first alerts
*/

function get_notification_count($conn, $role) {
    if (!$conn || !$role) return 0;

    if ($role === 'Staff') {
        // Only count active (unread + read) sell-first alerts — completed ones are invisible to staff
        $q = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE status IN ('unread','read')");
        return $q ? (int)$q->fetch_assoc()['c'] : 0;
    } else {
        // Owner: unread staff edit/delete requests + unread sell-first alerts
        $q1 = $conn->query("SELECT COUNT(*) AS c FROM staff_notifications WHERE status = 'unread'");
        $q2 = $conn->query("SELECT COUNT(*) AS c FROM notifications WHERE status = 'unread'");
        $c1 = $q1 ? (int)$q1->fetch_assoc()['c'] : 0;
        $c2 = $q2 ? (int)$q2->fetch_assoc()['c'] : 0;
        return $c1 + $c2;
    }
}

function render_notification_bell($conn, $role) {
    $count = get_notification_count($conn, $role);
    // ← Each role now links to their own notification page
    $link  = $role === 'Staff' ? 'my_notifications.php' : '../owner/staff_notifications.php';
    ?>
    <a href="<?php echo $link; ?>"
       style="position:relative; display:inline-flex; align-items:center; justify-content:center;
              background:var(--bg-wood); border:1px solid var(--border-mid);
              border-radius:var(--radius); padding:8px 12px; text-decoration:none;
              color:var(--text-primary); font-size:1.1rem; line-height:1;
              transition:background 0.2s;"
       title="Notifications"
       onmouseover="this.style.background='var(--bg-plank)'"
       onmouseout="this.style.background='var(--bg-wood)'">
        🔔
        <?php if ($count > 0): ?>
        <span style="position:absolute; top:-6px; right:-6px;
                     background:var(--danger); color:#fff;
                     border-radius:999px; font-size:0.62rem; font-weight:800;
                     min-width:18px; height:18px; line-height:18px;
                     text-align:center; padding:0 4px; display:inline-block;">
            <?php echo $count > 99 ? '99+' : $count; ?>
        </span>
        <?php endif; ?>
    </a>
    <?php
}

function render_notification_panel($conn, $role) {
    /*
        Staff  panel: active sell-first alerts (NOT completed), newest first
        Owner  panel: unread staff edit/delete requests (staff_notifications, newest 3)
                    + unread sell-first alerts (notifications, newest 3)
    */
    if ($role === 'Staff') {
        $notifs_q = $conn->query("
            SELECT n.*, b.breed
            FROM notifications n
            JOIN batches b ON n.batch_id = b.batch_id
            WHERE n.status IN ('unread', 'read')
            ORDER BY FIELD(n.status,'unread','read'), n.created_at DESC
            LIMIT 5
        ");
        $notifs   = $notifs_q ? $notifs_q->fetch_all(MYSQLI_ASSOC) : [];
        $requests = [];
        $view_all_link  = 'my_notifications.php';
        $view_all_label = 'View All Notifications →';
    } else {
        // Owner — fetch unread staff edit/delete requests from staff_notifications
        $req_q = $conn->query("
            SELECT sn.*, u.username
            FROM staff_notifications sn
            JOIN users u ON sn.staff_id = u.user_id
            WHERE sn.status = 'unread'
            ORDER BY sn.created_at DESC
            LIMIT 3
        ");
        $requests = $req_q ? $req_q->fetch_all(MYSQLI_ASSOC) : [];

        // Owner — fetch unread sell-first alerts
        $notif_q2 = $conn->query("
            SELECT n.*, b.breed
            FROM notifications n
            JOIN batches b ON n.batch_id = b.batch_id
            WHERE n.status = 'unread'
            ORDER BY n.created_at DESC
            LIMIT 3
        ");
        $notifs = $notif_q2 ? $notif_q2->fetch_all(MYSQLI_ASSOC) : [];
        $view_all_link  = '../owner/staff_notifications.php';
        $view_all_label = 'View All Notifications →';
    }
    ?>

<!-- Notification Panel Backdrop -->
<div id="notif-backdrop"
     onclick="closeNotifPanel()"
     style="display:none; position:fixed; inset:0; z-index:990;"></div>

<!-- Notification Dropdown -->
<div id="notif-panel"
     style="display:none; position:fixed; top:70px; right:20px; z-index:991;
            width:min(360px, 94vw);
            background:var(--bg-soil); border:1px solid var(--border-mid);
            border-radius:var(--radius-lg); box-shadow:var(--shadow-raised);
            overflow:hidden;">

    <div style="padding:13px 16px; border-bottom:1px solid var(--border-subtle);
                display:flex; justify-content:space-between; align-items:center;">
        <span style="font-weight:700; font-size:0.88rem; color:var(--gold);">🔔 Notifications</span>
        <button onclick="closeNotifPanel()"
                style="background:none; border:none; color:var(--text-muted);
                       cursor:pointer; font-size:1.1rem; line-height:1;">&times;</button>
    </div>

    <?php
    $has_content = false;

    // Owner: show unread staff edit/delete request previews (from staff_notifications)
    if ($role === 'Owner' && !empty($requests)):
        $has_content = true;
        foreach ($requests as $r):
            $req_type = $r['notif_type'] ?? 'edit_request';
            $is_delete = strpos($req_type, 'delete') !== false;
    ?>
    <div style="padding:11px 16px; border-bottom:1px solid var(--border-subtle);
                background:rgba(194,108,34,0.05);">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
            <div style="flex:1;">
                <div style="font-size:0.75rem; font-weight:700; color:var(--terra-lt); margin-bottom:2px;">
                    <?php echo $is_delete ? '🗑️ Delete' : '✏️ Edit'; ?> Request
                    — <?php echo htmlspecialchars($r['record_type'] ?? ''); ?>
                    <?php if (!empty($r['record_id'])): ?>#<?php echo (int)$r['record_id']; ?><?php endif; ?>
                </div>
                <div style="font-size:0.8rem; color:var(--text-secondary);">
                    <strong><?php echo htmlspecialchars($r['username']); ?></strong>:
                    "<?php echo htmlspecialchars(mb_strimwidth($r['message'], 0, 55, '…')); ?>"
                </div>
                <div style="font-size:0.68rem; color:var(--text-muted); margin-top:3px;">
                    <?php echo date('M d, g:i A', strtotime($r['created_at'])); ?>
                </div>
            </div>
            <span class="badge badge-pending" style="font-size:0.58rem; white-space:nowrap;">Pending</span>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <?php
    // Staff + Owner: show active sell-first alert previews
    if (!empty($notifs)):
        $has_content = true;
        foreach ($notifs as $n):
            $status_icon = $n['status'] === 'unread' ? '🔴' : '🟡';
    ?>
    <div style="padding:11px 16px; border-bottom:1px solid var(--border-subtle);
                <?php echo $n['status'] === 'unread' ? 'background:rgba(194,58,58,0.05);' : ''; ?>">
        <div style="font-size:0.75rem; font-weight:700; color:var(--danger); margin-bottom:2px;">
            <?php echo $status_icon; ?> Sell-First Alert
            — Batch #<?php echo (int)$n['batch_id']; ?> <?php echo htmlspecialchars($n['breed']); ?>
        </div>
        <div style="font-size:0.8rem; color:var(--text-secondary);">
            Target: <strong><?php echo number_format($n['target_trays']); ?></strong> trays
            <?php if ($role === 'Staff' && $n['status'] === 'unread'): ?>
            <span style="color:var(--danger); font-size:0.72rem;"> · Tap to view</span>
            <?php elseif ($role === 'Owner'): ?>
            <span style="color:var(--danger); font-size:0.72rem;"> · Not seen by staff yet</span>
            <?php endif; ?>
        </div>
        <div style="font-size:0.68rem; color:var(--text-muted); margin-top:3px;">
            <?php echo date('M d, g:i A', strtotime($n['created_at'])); ?>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <?php if (!$has_content): ?>
    <div style="padding:2rem; text-align:center; color:var(--text-muted); font-size:0.85rem;">
        <div style="font-size:2rem; margin-bottom:8px;">🔕</div>
        No notifications.
    </div>
    <?php endif; ?>

    <div style="padding:10px 16px; text-align:center; border-top:1px solid var(--border-subtle);">
        <a href="<?php echo $view_all_link; ?>"
           style="font-size:0.8rem; color:var(--gold); text-decoration:none; font-weight:700;">
            <?php echo $view_all_label; ?>
        </a>
    </div>
</div>

<script>
function toggleNotifPanel() {
    const p = document.getElementById('notif-panel');
    const b = document.getElementById('notif-backdrop');
    const isOpen = p.style.display !== 'none';
    p.style.display = isOpen ? 'none' : 'block';
    b.style.display = isOpen ? 'none' : 'block';
}
function closeNotifPanel() {
    document.getElementById('notif-panel').style.display   = 'none';
    document.getElementById('notif-backdrop').style.display = 'none';
}
</script>
    <?php
}
?>