<?php
/*  staff/my_notifications.php — All Owner Notifications for Staff
    - Only shows active sell-first alerts (unread / read) — completed ones are hidden
    - Shows progress toward sell target
    - Mark as seen button
    - Links to log_sale.php and view_logs.php
*/
$page_title = 'Notifications';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header("Location: ../portal/login.php"); exit();
}

$staff_id = (int)$_SESSION['user_id'];
$flash    = "";

// Handle mark-as-seen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['notif_action'] ?? '') === 'mark_read') {
    $nid = (int)($_POST['notif_id'] ?? 0);
    if ($nid > 0) {
        $stmt = $conn->prepare("UPDATE notifications SET status='read', read_at=NOW() WHERE notif_id=? AND status='unread'");
        $stmt->bind_param("i", $nid);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, $staff_id, 'Staff', 'Notification Acknowledged',
            "Acknowledged sell-first notification #$nid");
        $flash = "<div class='alert success'>✅ Notification marked as seen.</div>";
    }
}

// Fetch ONLY active notifications (unread + read) — completed ones are hidden from staff
$notifs_q = $conn->query("
    SELECT n.*, b.breed, b.arrival_date,
        GREATEST(0,
            FLOOR(COALESCE((SELECT SUM(h.total_eggs) FROM harvests h WHERE h.batch_id = n.batch_id),0) / 30) -
            COALESCE((SELECT SUM(s.quantity_sold) FROM sales s
                      WHERE s.date_sold >= COALESCE(b.arrival_date,'2000-01-01')),0)
        ) AS remaining_trays
    FROM notifications n
    JOIN batches b ON n.batch_id = b.batch_id
    WHERE n.status IN ('unread', 'read')
    ORDER BY
        FIELD(n.status,'unread','read'),
        n.created_at DESC
    LIMIT 30
");
?>

<div style="max-width:860px; margin:2rem auto;">

    <div class="page-header">
        <div>
            <h2>🔔 Notifications</h2>
            <p>Priority sell-first alerts from the Owner.</p>
        </div>
        <a href="dashboard.php" class="back-link" style="margin:0;">← Dashboard</a>
    </div>

    <?php echo $flash; ?>

    <?php if (!$notifs_q || $notifs_q->num_rows === 0): ?>
    <div class="card">
        <div class="empty-state">
            <span class="empty-icon">🔔</span>
            <p>No active notifications.</p>
            <small>The Owner will send alerts here when action is needed.</small>
        </div>
    </div>
    <?php else:
        while ($n = $notifs_q->fetch_assoc()):
            $remaining = max(0, (int)$n['remaining_trays']);
            $is_unread = $n['status'] === 'unread';
            $border_color = $is_unread ? 'var(--danger)' : 'var(--warning)';
            $pct = $n['target_trays'] > 0
                ? min(100, round((($n['target_trays'] - $remaining) / $n['target_trays']) * 100))
                : 0;
    ?>
    <div class="card" style="margin-bottom:1.2rem; border-left:5px solid <?php echo $border_color; ?>; padding:1.4rem 1.6rem;">

        <!-- Header row -->
        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:10px;">
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <?php if ($is_unread): ?>
                    <span class="badge badge-critical" style="font-size:0.62rem;">🔴 NEW</span>
                <?php else: ?>
                    <span class="badge badge-pending" style="font-size:0.62rem;">👁️ SEEN</span>
                <?php endif; ?>
                <span style="font-weight:700; font-size:0.95rem; color:var(--text-primary);">
                    Batch #<?php echo $n['batch_id']; ?> — <?php echo htmlspecialchars($n['breed']); ?>
                </span>
            </div>
            <span style="font-size:0.75rem; color:var(--text-muted);">
                Sent <?php echo date('M d, Y g:i A', strtotime($n['created_at'])); ?>
            </span>
        </div>

        <!-- Message -->
        <div style="font-size:0.88rem; color:var(--text-secondary); line-height:1.6; margin-bottom:14px;">
            <?php echo htmlspecialchars($n['message']); ?>
        </div>

        <!-- Stats + progress -->
        <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; align-items:center;">
            <div style="background:var(--bg-wood); border-radius:var(--radius-sm); padding:8px 14px;
                        border:1px solid var(--border-mid); text-align:center; min-width:90px;">
                <div style="font-size:0.62rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Target</div>
                <div style="font-size:1.4rem; font-weight:800; color:var(--danger); font-family:'Playfair Display',serif; line-height:1.2;">
                    <?php echo number_format($n['target_trays']); ?>
                </div>
                <div style="font-size:0.68rem; color:var(--text-muted);">trays</div>
            </div>
            <div style="background:var(--bg-wood); border-radius:var(--radius-sm); padding:8px 14px;
                        border:1px solid var(--border-mid); text-align:center; min-width:90px;">
                <div style="font-size:0.62rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Remaining</div>
                <div style="font-size:1.4rem; font-weight:800; color:var(--gold); font-family:'Playfair Display',serif; line-height:1.2;">
                    <?php echo $remaining; ?>
                </div>
                <div style="font-size:0.68rem; color:var(--text-muted);">trays</div>
            </div>
            <div style="flex:1; min-width:180px;">
                <div style="font-size:0.7rem; color:var(--text-muted); margin-bottom:5px;">
                    Progress — <?php echo $pct; ?>% sold
                </div>
                <div style="background:var(--bg-plank); border-radius:4px; height:8px; overflow:hidden;">
                    <div style="width:<?php echo $pct; ?>%; background:<?php echo $pct >= 100 ? 'var(--success)' : 'var(--terra-lt)'; ?>;
                                height:8px; border-radius:4px; transition:width 0.5s;"></div>
                </div>
            </div>
        </div>

        <!-- Action buttons -->
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="log_sale.php" class="btn-farm btn-danger btn-sm" style="font-size:0.85rem;">
                💰 Record Sale Now
            </a>
            <a href="view_logs.php" class="btn-farm btn-dark btn-sm" style="font-size:0.85rem;">
                📋 View My Logs
            </a>
            <?php if ($is_unread): ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="notif_action" value="mark_read">
                <input type="hidden" name="notif_id"     value="<?php echo $n['notif_id']; ?>">
                <button type="submit" class="btn-farm btn-outline btn-sm" style="font-size:0.85rem;">
                    ✓ Mark as Seen
                </button>
            </form>
            <?php endif; ?>
        </div>

    </div>
    <?php endwhile; endif; ?>

</div>

<?php include('../includes/footer.php'); ?>