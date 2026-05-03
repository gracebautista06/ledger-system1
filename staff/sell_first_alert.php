<?php
/*  staff/sell_first_alert.php — Sell-First Notification Banner
    Included inside staff/dashboard.php AFTER auth check.
    $conn and $_SESSION are already available.
*/

// Guard: only run if we actually have a DB connection and a Staff session
if (!isset($conn) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') return;

// FIX: Scope remaining_trays to the specific batch (not all sales)
$notif_q = $conn->query("
    SELECT n.*, b.breed, b.arrival_date,
        GREATEST(0,
            FLOOR(COALESCE((SELECT SUM(h.total_eggs) FROM harvests h WHERE h.batch_id = n.batch_id), 0) / 30) -
            FLOOR(COALESCE((SELECT SUM(s.quantity_sold) FROM sales s
                            JOIN harvests hh ON hh.batch_id = n.batch_id
                            WHERE s.date_sold >= COALESCE(b.arrival_date, '2000-01-01')
                            LIMIT 1), 0))
        ) AS remaining_trays
    FROM notifications n
    JOIN batches b ON n.batch_id = b.batch_id
    WHERE n.status IN ('unread','read')
    ORDER BY n.created_at DESC
    LIMIT 1
");

if (!$notif_q || $notif_q->num_rows === 0) return;
$n = $notif_q->fetch_assoc();

// Handle "Mark as Seen"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['notif_action'] ?? '') === 'mark_read') {
    $nid = (int) ($_POST['notif_id'] ?? 0);
    if ($nid > 0) {
        $upd = $conn->prepare("UPDATE notifications SET status='read', read_at=NOW() WHERE notif_id=? AND status='unread'");
        $upd->bind_param("i", $nid);
        $upd->execute();
        $upd->close();
        header("Location: " . $_SERVER['PHP_SELF']); exit();
    }
}
$remaining = max(0, (int)$n['remaining_trays']);
?>

<div style="background:rgba(194,58,58,0.08); border:1px solid rgba(194,58,58,0.35);
            border-left:6px solid var(--danger); border-radius:var(--radius);
            padding:16px 20px; margin-bottom:1.5rem; position:relative;">
    <span class="red-dot" style="position:absolute; top:16px; right:16px;"></span>
    <div style="font-size:0.68rem; font-weight:700; color:var(--danger); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:8px;">
        ⚠️ Priority Alert from Owner
    </div>
    <div style="font-size:1rem; font-weight:700; color:var(--text-primary); margin-bottom:5px;">
        Batch #<?php echo $n['batch_id']; ?> — <?php echo htmlspecialchars($n['breed']); ?> is Old Stock
    </div>
    <div style="font-size:0.87rem; color:var(--text-secondary); margin-bottom:12px; line-height:1.6;">
        <?php echo htmlspecialchars($n['message']); ?>
    </div>
    <div style="display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
        <div style="background:var(--bg-wood); border-radius:var(--radius-sm); padding:10px 16px; border:1px solid var(--border-mid); text-align:center; min-width:100px;">
            <div style="font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px;">Target</div>
            <div style="font-size:1.5rem; font-weight:800; color:var(--danger); font-family:'Playfair Display',serif; line-height:1.1;"><?php echo number_format($n['target_trays']); ?></div>
            <div style="font-size:0.7rem; color:var(--text-muted);">trays to sell</div>
        </div>
        <div style="background:var(--bg-wood); border-radius:var(--radius-sm); padding:10px 16px; border:1px solid var(--border-mid); text-align:center; min-width:100px;">
            <div style="font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px;">Still on Hand</div>
            <div style="font-size:1.5rem; font-weight:800; color:var(--gold); font-family:'Playfair Display',serif; line-height:1.1;"><?php echo $remaining; ?></div>
            <div style="font-size:0.7rem; color:var(--text-muted);">trays remaining</div>
        </div>
        <div style="display:flex; flex-direction:column; gap:8px; margin-left:auto;">
            <a href="log_sale.php" class="btn-farm btn-danger" style="font-size:0.85rem; padding:10px 18px; white-space:nowrap;">💰 Record Sale Now</a>
            <?php if ($n['status'] === 'unread'): ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="notif_action" value="mark_read">
                <input type="hidden" name="notif_id" value="<?php echo $n['notif_id']; ?>">
                <button type="submit" class="btn-farm btn-dark btn-sm btn-full" style="font-size:0.75rem;">✓ Mark as Seen</button>
            </form>
            <?php else: ?>
            <span style="font-size:0.75rem; color:var(--success); text-align:center;">✓ Acknowledged</span>
            <?php endif; ?>
        </div>
    </div>
    <div style="font-size:0.72rem; color:var(--text-muted); margin-top:10px; border-top:1px solid var(--border-subtle); padding-top:8px;">
        Sent by Owner on <?php echo date('M d, Y g:i A', strtotime($n['created_at'])); ?>
    </div>
</div>