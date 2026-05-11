<?php
/*  staff/view_logs.php — Staff Log History (Harvests + Health + Sales)  */
$page_title = 'My Logs';

include('../includes/db.php');
include('../includes/header.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header("Location: ../portal/login.php"); exit();
}

$staff_id = (int) $_SESSION['user_id'];

// ── Withdraw (cancel) a pending request ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw_request_id'])) {
    $wid = (int)$_POST['withdraw_request_id'];
    if ($wid > 0) {
        // Grab record info before deleting so we can clean up the notification
        $wi = $conn->prepare("SELECT record_type, record_id, request_type FROM edit_requests WHERE request_id = ? AND staff_id = ? AND status = 'Pending'");
        $wi->bind_param('ii', $wid, $staff_id);
        $wi->execute();
        $wrow = $wi->get_result()->fetch_assoc();
        $wi->close();

        if ($wrow) {
            // Delete the request
            $wd = $conn->prepare("DELETE FROM edit_requests WHERE request_id = ? AND staff_id = ? AND status = 'Pending'");
            $wd->bind_param('ii', $wid, $staff_id);
            $wd->execute();
            $wd->close();

            // Mark the matching owner notification as read
            $notif_type = strtolower($wrow['request_type']) . '_request'; // edit_request or delete_request
            $wn = $conn->prepare("
                UPDATE staff_notifications
                SET status = 'read', read_at = NOW()
                WHERE staff_id   = ?
                  AND notif_type = ?
                  AND record_type = ?
                  AND record_id   = ?
                  AND status      = 'unread'
            ");
            $wn->bind_param('issi', $staff_id, $notif_type, $wrow['record_type'], $wrow['record_id']);
            $wn->execute();
            $wn->close();
        }
    }
    header('Location: view_logs.php?withdrawn=1');
    exit();
}

// Flash messages
$flash = "";
if (isset($_GET['harvest_saved'])) {
    $flash = "<div class='alert success'>Harvest logged successfully.</div>";
} elseif (isset($_GET['health_saved'])) {
    $flash = "<div class='alert success'>Health report submitted.</div>";
} elseif (isset($_GET['sale_saved'])) {
    $flash = "<div class='alert success'>Sale recorded successfully.</div>";
} elseif (isset($_GET['request_sent'])) {
    $flash = "<div class='alert info'>Edit request sent to the Owner for review.</div>";
} elseif (isset($_GET['delete_sent'])) {
    $flash = "<div class='alert info'>Deletion request sent to the Owner for review.</div>";
} elseif (isset($_GET['withdrawn'])) {
    $flash = "<div class='alert info'>Request withdrawn successfully.</div>";
}

// Fetch Harvests (with pending edit/delete request flag)
$h_stmt = $conn->prepare("
    SELECT h.*, b.breed,
        MAX(CASE WHEN er.request_type='Edit'   AND er.status='Pending' THEN 'Pending' END) AS edit_status,
        MAX(CASE WHEN er.request_type='Delete' AND er.status='Pending' THEN 'Pending' END) AS delete_status,
        MAX(CASE WHEN er.request_type='Edit'   AND er.status='Pending' THEN er.request_id END) AS edit_request_id,
        MAX(CASE WHEN er.request_type='Delete' AND er.status='Pending' THEN er.request_id END) AS delete_request_id
    FROM harvests h
    JOIN batches b ON h.batch_id = b.batch_id
    LEFT JOIN edit_requests er ON er.record_id = h.harvest_id
        AND er.record_type = 'Harvest'
        AND er.staff_id = ?
    WHERE h.staff_id = ?
    GROUP BY h.harvest_id
    ORDER BY h.date_logged DESC LIMIT 10");
$h_stmt->bind_param("ii", $staff_id, $staff_id);
$h_stmt->execute();
$harvest_logs = $h_stmt->get_result();
$h_stmt->close();

// Fetch Health Reports (with pending flags)
$fh_stmt = $conn->prepare("
    SELECT fh.*, b.breed,
        MAX(CASE WHEN er.request_type='Edit'   AND er.status='Pending' THEN 'Pending' END) AS edit_status,
        MAX(CASE WHEN er.request_type='Delete' AND er.status='Pending' THEN 'Pending' END) AS delete_status,
        MAX(CASE WHEN er.request_type='Edit'   AND er.status='Pending' THEN er.request_id END) AS edit_request_id,
        MAX(CASE WHEN er.request_type='Delete' AND er.status='Pending' THEN er.request_id END) AS delete_request_id
    FROM flock_health fh
    JOIN batches b ON fh.batch_id = b.batch_id
    LEFT JOIN edit_requests er ON er.record_id = fh.report_id
        AND er.record_type = 'Health'
        AND er.staff_id = ?
    WHERE fh.staff_id = ?
    GROUP BY fh.report_id
    ORDER BY fh.date_reported DESC LIMIT 5");
$fh_stmt->bind_param("ii", $staff_id, $staff_id);
$fh_stmt->execute();
$health_logs = $fh_stmt->get_result();
$fh_stmt->close();

// Fetch Sales (with pending flags)
$s_stmt = $conn->prepare("
 SELECT s.*,
(SELECT COUNT(*) FROM edit_requests er WHERE er.record_id = s.sale_id AND er.record_type = 'Sale' AND er.request_type = 'Edit'   AND er.status = 'Pending') AS edit_pending,
(SELECT COUNT(*) FROM edit_requests er WHERE er.record_id = s.sale_id AND er.record_type = 'Sale' AND er.request_type = 'Delete' AND er.status = 'Pending') AS delete_pending,
(SELECT er.request_id FROM edit_requests er WHERE er.record_id = s.sale_id AND er.record_type = 'Sale' AND er.request_type = 'Edit'   AND er.status = 'Pending' LIMIT 1) AS edit_request_id,
(SELECT er.request_id FROM edit_requests er WHERE er.record_id = s.sale_id AND er.record_type = 'Sale' AND er.request_type = 'Delete' AND er.status = 'Pending' LIMIT 1) AS delete_request_id
FROM sales s
WHERE s.staff_id = ?
ORDER BY s.date_sold DESC
LIMIT 10");
$s_stmt->bind_param("i", $staff_id);
$s_stmt->execute();
$sale_logs = $s_stmt->get_result();
$s_stmt->close();

?>

<div style="max-width:1100px; margin:2rem auto;">

    <div class="page-header">
        <div>
            <h2>Your Recent Activity</h2>
        </div>
        <a href="dashboard.php" class="back-link" style="margin:0;">← Back to Menu</a>
    </div>

    <?php echo $flash; ?>

    <!-- ── HARVEST LOGS ─────────────────────────────────── -->
    <div class="card" style="border-top:5px solid var(--gold); margin-bottom:2rem; padding:0; overflow:hidden;">
        <div style="padding:1.4rem 1.8rem 1rem; border-bottom:1px solid var(--border-subtle);">
            <h3 style="margin:0;">Recent Harvests</h3>
        </div>
        <div class="table-wrapper" style="border:none; border-radius:0;">
            <table class="table-farm">
                <thead>
                    <tr><th>Date & Time</th><th>Batch</th><th>Total</th><th>Breakdown (PW-S-M-L-XL-J)</th><th>Notes</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($harvest_logs && $harvest_logs->num_rows > 0):
                        while ($row = $harvest_logs->fetch_assoc()):
                            $has_edit_pending   = ($row['edit_status']   === 'Pending');
                            $has_delete_pending = ($row['delete_status'] === 'Pending');
                            $any_pending        = $has_edit_pending || $has_delete_pending;
                    ?>
                    <tr>
                        <td style="font-size:0.8rem; color:var(--text-muted); white-space:nowrap;"><?php echo date('M d, g:i A', strtotime($row['date_logged'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($row['breed']); ?></strong></td>
                        <td style="font-weight:700; color:var(--gold);"><?php echo number_format($row['total_eggs']); ?></td>
                        <td style="font-family:monospace; font-size:0.8rem; white-space:nowrap;"><?php echo "{$row['size_pw']}-{$row['size_s']}-{$row['size_m']}-{$row['size_l']}-{$row['size_xl']}-{$row['size_j']}"; ?></td>
                        <td style="font-size:0.85rem; max-width:180px;"><?php echo htmlspecialchars($row['notes'] ?: '—'); ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($any_pending): ?>
                                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                    <span class="badge badge-pending">⏳ Pending Review</span>
                                    <?php
                                        $withdraw_id = $has_delete_pending
                                            ? (int)$row['delete_request_id']
                                            : (int)$row['edit_request_id'];
                                    ?>
                                    <form method="POST" style="margin:0;"
                                          onsubmit="return confirm('Withdraw this request? This cannot be undone.')">
                                        <input type="hidden" name="withdraw_request_id" value="<?= $withdraw_id ?>">
                                        <button type="submit" class="btn-farm btn-dark btn-sm"
                                                title="Withdraw request"
                                                style="font-size:0.72rem; padding:3px 8px; opacity:0.8;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Withdraw
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <a href="request_edit.php?type=Harvest&id=<?php echo $row['harvest_id']; ?>"
                                       class="btn-farm btn-outline btn-sm" title="Request edit">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </a>
                                    <a href="request_delete.php?type=Harvest&id=<?php echo $row['harvest_id']; ?>"
                                       class="btn-farm btn-danger btn-sm" title="Request deletion">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="6"><div class="empty-state"><p>No harvest logs yet.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── SALES LOGS ───────────────────────────────────── -->
    <div class="card" style="border-top:5px solid var(--success); margin-bottom:2rem; padding:0; overflow:hidden;">
        <div style="padding:1.4rem 1.8rem 1rem; border-bottom:1px solid var(--border-subtle);">
            <h3 style="margin:0;">Recent Sales</h3>
        </div>
        <div class="table-wrapper" style="border:none; border-radius:0;">
            <table class="table-farm">
                <thead>
                    <tr><th>Date</th><th>Customer</th><th>Trays</th><th>Size Breakdown</th><th>Total</th><th>Payment</th><th>Notes</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($sale_logs && $sale_logs->num_rows > 0):
                        while ($row = $sale_logs->fetch_assoc()):
                            $pm_icon = match($row['payment_method']) {
                                'GCash'         => 'GCash',
                                'Bank Transfer' => 'Bank',
                                default         => 'Cash',
                            };
                            $has_edit_pending   = ((int)$row['edit_pending'] > 0);
                            $has_delete_pending = ((int)$row['delete_pending'] > 0);
                            $any_pending        = $has_edit_pending || $has_delete_pending;
                    ?>
                    <tr>
                        <td style="font-size:0.8rem; color:var(--text-muted); white-space:nowrap;"><?php echo date('M d, g:i A', strtotime($row['date_sold'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($row['customer_name']); ?></strong></td>
                        <td style="text-align:center; font-weight:700;"><?php echo number_format($row['quantity_sold']); ?></td>
                        <td style="font-size:0.78rem; color:var(--text-muted); white-space:nowrap;">
                            <?php
                            $bk = [];
                            $szmap = ['PW'=>'qty_pw','S'=>'qty_s','M'=>'qty_m','L'=>'qty_l','XL'=>'qty_xl','J'=>'qty_j'];
                            foreach ($szmap as $sz => $col) {
                                $v = (int)($row[$col] ?? 0);
                                if ($v > 0) $bk[] = "<strong style='color:var(--text-primary);'>$sz</strong>&times;$v";
                            }
                            echo !empty($bk) ? implode(' ', $bk) : '<span style="color:var(--text-muted)">—</span>';
                            ?>
                        </td>
                        <td style="font-weight:700; color:var(--success);">₱<?php echo number_format((float)$row['total_amount'], 2); ?></td>
                        <td style="font-size:0.82rem; white-space:nowrap;">
                            <?php echo $pm_icon; ?> <?php echo htmlspecialchars($row['payment_method'] ?: '—'); ?>
                        </td>
                        <td style="font-size:0.82rem; color:var(--text-muted); max-width:150px;"><?php echo htmlspecialchars($row['notes'] ?: '—'); ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($any_pending): ?>
                                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                    <span class="badge badge-pending">Pending Review</span>
                                    <?php
                                        $withdraw_id = $has_delete_pending
                                            ? (int)$row['delete_request_id']
                                            : (int)$row['edit_request_id'];
                                    ?>
                                    <form method="POST" style="margin:0;"
                                          onsubmit="return confirm('Withdraw this request? This cannot be undone.')">
                                        <input type="hidden" name="withdraw_request_id" value="<?= $withdraw_id ?>">
                                        <button type="submit" class="btn-farm btn-dark btn-sm"
                                                title="Withdraw request"
                                                style="font-size:0.72rem; padding:3px 8px; opacity:0.8;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Withdraw
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                            <div style="display:flex; gap:6px;">
                                <a href="request_edit.php?type=Sale&id=<?php echo $row['sale_id']; ?>"
                                   class="btn-farm btn-outline btn-sm" title="Request edit">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </a>
                                <a href="request_delete.php?type=Sale&id=<?php echo $row['sale_id']; ?>"
                                   class="btn-farm btn-danger btn-sm" title="Request deletion">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                </a>
                             </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="8"><div class="empty-state"><p>No sales recorded yet.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── HEALTH LOGS ──────────────────────────────────── -->
    <div class="card" style="border-top:5px solid var(--terra-lt); padding:0; overflow:hidden;">
        <div style="padding:1.4rem 1.8rem 1rem; border-bottom:1px solid var(--border-subtle);">
            <h3 style="margin:0;">Health Reports</h3>
        </div>
        <div class="table-wrapper" style="border:none; border-radius:0;">
            <table class="table-farm">
                <thead>
                    <tr><th>Date</th><th>Batch</th><th>Status</th><th>Mortality</th><th>Observations</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if ($health_logs && $health_logs->num_rows > 0):
                        while ($row = $health_logs->fetch_assoc()):
                            $has_edit_pending   = ($row['edit_status']   === 'Pending');
                            $has_delete_pending = ($row['delete_status'] === 'Pending');
                            $any_pending        = $has_edit_pending || $has_delete_pending;
                    ?>
                    <tr>
                        <td style="font-size:0.8rem; color:var(--text-muted); white-space:nowrap;"><?php echo date('M d, g:i A', strtotime($row['date_reported'])); ?></td>
                        <td><?php echo htmlspecialchars($row['breed']); ?></td>
                        <td>
                            <span class="badge <?php echo match($row['status_level']) { 'Healthy'=>'badge-healthy','Warning'=>'badge-warning','Critical'=>'badge-critical',default=>'' }; ?>">
                                <?php echo $row['status_level']; ?>
                            </span>
                        </td>
                        <td style="text-align:center; font-weight:700;"><?php echo $row['mortality_count']; ?></td>
                        <td style="font-size:0.85rem; max-width:200px;"><?php echo htmlspecialchars($row['symptoms'] ?: '—'); ?></td>
                        <td style="white-space:nowrap;">
                            <?php if ($any_pending): ?>
                                <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                    <span class="badge badge-pending">Pending Review</span>
                                    <?php
                                        $withdraw_id = $has_delete_pending
                                            ? (int)$row['delete_request_id']
                                            : (int)$row['edit_request_id'];
                                    ?>
                                    <form method="POST" style="margin:0;"
                                          onsubmit="return confirm('Withdraw this request? This cannot be undone.')">
                                        <input type="hidden" name="withdraw_request_id" value="<?= $withdraw_id ?>">
                                        <button type="submit" class="btn-farm btn-dark btn-sm"
                                                title="Withdraw request"
                                                style="font-size:0.72rem; padding:3px 8px; opacity:0.8;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Withdraw
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <a href="request_edit.php?type=Health&id=<?php echo $row['report_id']; ?>"
                                       class="btn-farm btn-outline btn-sm" title="Request edit">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </a>
                                    <a href="request_delete.php?type=Health&id=<?php echo $row['report_id']; ?>"
                                       class="btn-farm btn-danger btn-sm" title="Request deletion">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="6"><div class="empty-state"><p>No health reports found.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>/* modals removed — edit/delete go directly to their pages */</script>

<?php include('../includes/footer.php'); ?>