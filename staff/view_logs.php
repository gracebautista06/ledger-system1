<?php
/*  staff/view_logs.php — Staff Log History (Harvests + Health + Sales)  */
$page_title = 'My Logs';

include('../includes/db.php');
include('../includes/header.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header("Location: ../portal/login.php"); exit();
}

$staff_id = (int) $_SESSION['user_id'];

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
    $flash = "<div class='alert info'>Delete request sent to the Owner for review.</div>";
}

// Fetch Harvests (with pending edit/delete request flag)
$h_stmt = $conn->prepare("
    SELECT h.*, b.breed,
        MAX(CASE WHEN er.request_type='Edit'   AND er.status='Pending' THEN 'Pending' END) AS edit_status,
        MAX(CASE WHEN er.request_type='Delete' AND er.status='Pending' THEN 'Pending' END) AS delete_status
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
        MAX(CASE WHEN er.request_type='Delete' AND er.status='Pending' THEN 'Pending' END) AS delete_status
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
(
    SELECT COUNT(*) 
    FROM edit_requests er
    WHERE er.record_id = s.sale_id
      AND er.record_type = 'Sale'
      AND er.request_type = 'Edit'
      AND er.status = 'Pending'
) AS edit_pending,

(
    SELECT COUNT(*) 
    FROM edit_requests er
    WHERE er.record_id = s.sale_id
      AND er.record_type = 'Sale'
      AND er.request_type = 'Delete'
      AND er.status = 'Pending'
) AS delete_pending
FROM sales s
WHERE s.staff_id = ?
ORDER BY s.date_sold DESC
LIMIT 10");
$s_stmt->bind_param("i", $staff_id);
$s_stmt->execute();
$sale_logs = $s_stmt->get_result();
$s_stmt->close();

// Predefined reasons
$edit_reasons = [
    'Incorrect egg count entered',
    'Wrong batch selected',
    'Typo in the numbers',
    'System error / double entry',
    'Other (please specify)',
];
$delete_reasons = [
    'Duplicate entry',
    'Logged by mistake',
    'Wrong date recorded',
    'Test entry / training',
    'Other (please specify)',
];
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
                                <span class="badge badge-pending">⏳ Pending Review</span>
                            <?php else: ?>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <button class="btn-farm btn-outline btn-sm"
                                            onclick="openEditModal('Harvest', <?php echo $row['harvest_id']; ?>, <?php echo $row['total_eggs']; ?>)"
                                            title="Request edit">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <button class="btn-farm btn-danger btn-sm"
                                            onclick="openDeleteModal('Harvest', <?php echo $row['harvest_id']; ?>)"
                                            title="Request deletion">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                    </button>
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
                                <span class="badge badge-pending">Pending Review</span>
                                <?php else: ?>
                            <div style="display:flex; gap:6px;">
                                <button class="btn-farm btn-outline btn-sm" onclick="openEditModal('Sale', <?php echo $row['sale_id']; ?>, <?php echo $row['quantity_sold']; ?>)" title="Request edit">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <button class="btn-farm btn-danger btn-sm" onclick="openDeleteModal('Sale', <?php echo $row['sale_id']; ?>)" title="Request deletion">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                </button>
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
                                <span class="badge badge-pending">Pending Review</span>
                            <?php else: ?>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <button class="btn-farm btn-outline btn-sm"
                                            onclick="openEditModal('Health', <?php echo $row['report_id']; ?>, <?php echo $row['mortality_count']; ?>)"
                                            title="Request edit">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <button class="btn-farm btn-danger btn-sm"
                                            onclick="openDeleteModal('Health', <?php echo $row['report_id']; ?>)"
                                            title="Request deletion">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                    </button>
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

<!-- ── EDIT REQUEST MODAL ──────────────────────────────────── -->
<div id="edit-overlay" onclick="closeEditModal()"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.72); z-index:999;"></div>

<div id="edit-modal"
     style="display:none; position:fixed; top:50%; left:50%;
            transform:translate(-50%,-50%); z-index:1000;
            width:min(480px,94vw);
            background:var(--bg-soil); border:1px solid var(--border-mid);
            border-top:4px solid var(--gold); border-radius:var(--radius-lg);
            padding:1.6rem 1.8rem; box-shadow:var(--shadow-raised);">
    <h3 style="color:var(--gold); font-family:'Playfair Display',serif; margin-bottom:0.3rem;">
        Request Edit
    </h3>
    <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:1.4rem;">
        This sends a correction request to the Owner for approval.
    </p>
    <form method="GET" action="request_edit.php">
        <input type="hidden" name="type" id="edit-type">
        <input type="hidden" name="id"   id="edit-id">

        <div class="form-group">
            <label>Reason for Edit <span style="color:var(--danger);">*</span></label>
            <select name="reason_preset" id="edit-reason-select" class="form-input" required
                    onchange="toggleEditOther(this.value)">
                <option value="" disabled selected>— Select a reason —</option>
                <?php foreach ($edit_reasons as $r): ?>
                <option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" id="edit-other-group" style="display:none;">
            <label>Please specify</label>
            <textarea name="reason_other" class="form-input" rows="2" placeholder="Describe the issue…"></textarea>
        </div>

        <div class="form-group" id="edit-harvest-field" style="display:none;">
            <label>Corrected Total Eggs</label>
            <input type="number" name="new_total" id="edit-new-total" class="form-input" min="0">
        </div>
        <div class="form-group" id="edit-health-field" style="display:none;">
            <label>Corrected Mortality Count</label>
            <input type="number" name="new_mortality" id="edit-new-mortality" class="form-input" min="0">
        </div>
        <div class="form-group" id="edit-sale-field" style="display:none;">
            <label>Corrected Quantity Sold (trays)</label>
            <input type="number" name="new_quantity" id="edit-new-quantity" class="form-input" min="0">
        </div>

        <div style="display:flex; gap:10px; margin-top:0.5rem;">
            <button type="submit" class="btn-farm btn-orange" style="flex:1; padding:13px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:5px;"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>Send to Owner
            </button>
            <button type="button" class="btn-farm btn-dark" onclick="closeEditModal()"
                    style="padding:13px; min-width:90px;">Cancel</button>
        </div>
    </form>
</div>

<!-- ── DELETE REQUEST MODAL ──────────────────────────────────── -->
<div id="delete-overlay" onclick="closeDeleteModal()"
     style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.72); z-index:999;"></div>

<div id="delete-modal"
     style="display:none; position:fixed; top:50%; left:50%;
            transform:translate(-50%,-50%); z-index:1000;
            width:min(460px,94vw);
            background:var(--bg-soil); border:1px solid var(--border-mid);
            border-top:4px solid var(--danger); border-radius:var(--radius-lg);
            padding:1.6rem 1.8rem; box-shadow:var(--shadow-raised);">
    <h3 style="color:var(--danger); font-family:'Playfair Display',serif; margin-bottom:0.3rem;">
        Request Deletion
    </h3>
    <p style="color:var(--text-muted); font-size:0.85rem; margin-bottom:1.4rem;">
        The Owner must approve deletions. The record stays until then.
    </p>
    <form method="GET" action="request_delete.php">
        <input type="hidden" name="type" id="delete-type">
        <input type="hidden" name="id"   id="delete-id">

        <div class="form-group">
            <label>Reason for Deletion <span style="color:var(--danger);">*</span></label>
            <select name="reason_preset" id="delete-reason-select" class="form-input" required
                    onchange="toggleDeleteOther(this.value)">
                <option value="" disabled selected>— Select a reason —</option>
                <?php foreach ($delete_reasons as $r): ?>
                <option value="<?php echo htmlspecialchars($r); ?>"><?php echo htmlspecialchars($r); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" id="delete-other-group" style="display:none;">
            <label>Please specify</label>
            <textarea name="reason_other" class="form-input" rows="2" placeholder="Describe the issue…"></textarea>
        </div>

        <div style="display:flex; gap:10px; margin-top:0.5rem;">
            <button type="submit" class="btn-farm btn-danger" style="flex:1; padding:13px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:5px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>Request Deletion
            </button>
            <button type="button" class="btn-farm btn-dark" onclick="closeDeleteModal()"
                    style="padding:13px; min-width:90px;">Cancel</button>
        </div>
    </form>
</div>

<script>
// ── Edit Modal ──────────────────────────────────────────────
function openEditModal(type, id, currentVal) {
    document.getElementById('edit-type').value = type;
    document.getElementById('edit-id').value   = id;
    document.getElementById('edit-reason-select').value = '';
    document.getElementById('edit-other-group').style.display  = 'none';
    document.getElementById('edit-harvest-field').style.display = 'none';
    document.getElementById('edit-health-field').style.display  = 'none';
    document.getElementById('edit-sale-field').style.display    = 'none';

    if (type === 'Harvest') {
        document.getElementById('edit-harvest-field').style.display = 'block';
        document.getElementById('edit-new-total').value = currentVal;
    } else if (type === 'Health') {
        document.getElementById('edit-health-field').style.display = 'block';
        document.getElementById('edit-new-mortality').value = currentVal;
    } else if (type === 'Sale') {
        document.getElementById('edit-sale-field').style.display = 'block';
        document.getElementById('edit-new-quantity').value = currentVal;
    }
    document.getElementById('edit-overlay').style.display = 'block';
    document.getElementById('edit-modal').style.display   = 'block';
}
function closeEditModal() {
    document.getElementById('edit-overlay').style.display = 'none';
    document.getElementById('edit-modal').style.display   = 'none';
}
function toggleEditOther(val) {
    document.getElementById('edit-other-group').style.display =
        val === 'Other (please specify)' ? 'block' : 'none';
}

// ── Delete Modal ─────────────────────────────────────────────
function openDeleteModal(type, id) {
    document.getElementById('delete-type').value = type;
    document.getElementById('delete-id').value   = id;
    document.getElementById('delete-reason-select').value = '';
    document.getElementById('delete-other-group').style.display = 'none';
    document.getElementById('delete-overlay').style.display = 'block';
    document.getElementById('delete-modal').style.display   = 'block';
}
function closeDeleteModal() {
    document.getElementById('delete-overlay').style.display = 'none';
    document.getElementById('delete-modal').style.display   = 'none';
}
function toggleDeleteOther(val) {
    document.getElementById('delete-other-group').style.display =
        val === 'Other (please specify)' ? 'block' : 'none';
}
</script>

<?php include('../includes/footer.php'); ?>