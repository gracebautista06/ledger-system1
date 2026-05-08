<?php

function get_notification_count($conn, $role) {
    if (!$conn || !$role) return 0;

    if ($role === 'Staff') {
        return 0;
    } else {
        // Owner: only count unread staff-submitted requests (not outcome notifications going back to staff)
        $q1 = $conn->query("SELECT COUNT(*) AS c FROM staff_notifications WHERE status = 'unread' AND notif_type IN ('edit_request','delete_request','progress_update','sale_request')");
        $c1 = $q1 ? (int)$q1->fetch_assoc()['c'] : 0;
        return $c1;
    }
}

function render_notification_bell($conn, $role) {
    $count = get_notification_count($conn, $role);
    // ← Each role now links to their own notification page
    $link  = $role === 'Staff' ? '#' : '../owner/staff_notifications.php';
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
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
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
        $notifs        = [];
        $requests      = [];
        $view_all_link  = '#';
        $view_all_label = '';
    } else {
        // Owner — fetch unread staff edit/delete requests from staff_notifications
        $req_q = $conn->query("
            SELECT sn.*, u.username
            FROM staff_notifications sn
            JOIN users u ON sn.staff_id = u.user_id
            WHERE sn.status = 'unread'
              AND sn.notif_type IN ('edit_request','delete_request','progress_update','sale_request')
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
        <span style="font-weight:700; font-size:0.88rem; color:var(--gold); display:inline-flex; align-items:center; gap:6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            Notifications
        </span>
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
                <div style="font-size:0.75rem; font-weight:700; color:var(--terra-lt); margin-bottom:2px; display:inline-flex; align-items:center; gap:4px;">
                    <?php if ($is_delete): ?>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg> Delete
                    <?php else: ?>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit
                    <?php endif; ?>
                    Request — <?php echo htmlspecialchars($r['record_type'] ?? ''); ?>
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
    // Staff panel: show request outcome cards (approved/rejected decisions)
    if ($role === 'Staff' && !empty($notifs)):
        $has_content = true;
        foreach ($notifs as $n):
            $is_unread  = $n['status'] === 'unread';
            $msg_lower  = strtolower($n['message']);
            $is_approved = strpos($msg_lower, 'approved') !== false;
            $dot_color  = $is_unread ? 'var(--danger)' : 'var(--border-mid)';
            $label_color = $is_approved ? 'var(--success)' : 'var(--danger)';
            $label_text  = $is_approved ? 'Approved' : 'Rejected';
    ?>
    <div style="padding:11px 16px; border-bottom:1px solid var(--border-subtle);
                <?php echo $is_unread ? 'background:rgba(194,58,58,0.05);' : ''; ?>">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
            <div style="flex:1;">
                <div style="font-size:0.75rem; font-weight:700; color:<?php echo $label_color; ?>; margin-bottom:2px; display:inline-flex; align-items:center; gap:4px;">
                    <?php if ($is_approved): ?>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <?php endif; ?>
                    <?php echo $label_text; ?>
                    <?php if (!empty($n['record_type']) && !empty($n['record_id'])): ?>
                        — <?php echo htmlspecialchars($n['record_type']); ?> #<?php echo (int)$n['record_id']; ?>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.8rem; color:var(--text-secondary);">
                    <?php echo htmlspecialchars(mb_strimwidth($n['message'], 0, 65, '…')); ?>
                </div>
                <div style="font-size:0.68rem; color:var(--text-muted); margin-top:3px;">
                    <?php echo date('M d, g:i A', strtotime($n['created_at'])); ?>
                </div>
            </div>
            <?php if ($is_unread): ?>
            <span style="display:inline-block; width:7px; height:7px; border-radius:50%;
                         background:var(--danger); flex-shrink:0; margin-top:4px;"></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <?php
    // Owner panel: sell-first alerts section has been removed since owner no longer uses that feature.
    // $notifs for Owner is always empty now.

    if (!$has_content): ?>
    <div style="padding:2rem; text-align:center; color:var(--text-muted); font-size:0.85rem;">
        <div style="margin-bottom:8px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;"><path d="M13.73 21a2 2 0 0 1-3.46 0"/><path d="M18.63 13A17.9 17.9 0 0 1 18 8"/><path d="M6.26 6.26A5.86 5.86 0 0 0 6 8c0 7-3 9-3 9h14"/><path d="M18 8a6 6 0 0 0-9.33-5"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
        </div>
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