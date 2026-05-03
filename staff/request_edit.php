<?php
/*  staff/request_edit.php — Submit a Log Correction Request  */
$page_title = 'Request Edit';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');
include('../includes/notify_owner.php'); // ← ADD THIS

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header("Location: ../portal/login.php"); exit();
}

$staff_id = (int) $_SESSION['user_id'];

$allowed_types = ['Harvest', 'Health', 'Sale'];
$type = (isset($_GET['type']) && in_array($_GET['type'], $allowed_types)) ? $_GET['type'] : null;
$id   = (int) ($_GET['id'] ?? 0);

if (!$type || $id <= 0) { header("Location: view_logs.php"); exit(); }

// Ownership check
$original = null;
if ($type === 'Harvest') {
    $res_stmt = $conn->prepare("SELECT h.*, b.breed, b.batch_id FROM harvests h JOIN batches b ON h.batch_id=b.batch_id WHERE h.harvest_id=? AND h.staff_id=? LIMIT 1");
    $res_stmt->bind_param("ii", $id, $staff_id);
} elseif ($type === 'Sale') {
    $res_stmt = $conn->prepare("SELECT s.*, NULL AS breed, NULL AS batch_id FROM sales s WHERE s.sale_id=? AND s.staff_id=? LIMIT 1");
    $res_stmt->bind_param("ii", $id, $staff_id);
} else {
    $res_stmt = $conn->prepare("SELECT fh.*, b.breed, b.batch_id FROM flock_health fh JOIN batches b ON fh.batch_id=b.batch_id WHERE fh.report_id=? AND fh.staff_id=? LIMIT 1");
    $res_stmt->bind_param("ii", $id, $staff_id);
}
$res_stmt->execute();
$res = $res_stmt->get_result();
if ($res->num_rows === 0) { header("Location: view_logs.php"); exit(); }
$original = $res->fetch_assoc();
$res_stmt->close();

// Check for already-pending request
$dup_stmt = $conn->prepare("SELECT request_id FROM edit_requests WHERE staff_id=? AND record_type=? AND record_id=? AND status='Pending' LIMIT 1");
$dup_stmt->bind_param("isi", $staff_id, $type, $id);
$dup_stmt->execute();
$dup_stmt->store_result();
$already_pending = $dup_stmt->num_rows > 0;
$dup_stmt->close();

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$already_pending) {
    $reason   = trim($_POST['reason'] ?? '');
    $new_data = match($type) {
        'Harvest' => json_encode(['total_eggs'      => max(0, (int)($_POST['new_total']     ?? 0))]),
        'Health'  => json_encode(['mortality_count' => max(0, (int)($_POST['new_mortality'] ?? 0))]),
        'Sale'    => json_encode(['quantity_sold'   => max(0, (int)($_POST['new_quantity']  ?? 0))]),
        default   => json_encode([]),
    };

    if (empty($reason)) {
        $message = "<div class='alert error'>⚠️ Please provide a reason for the correction.</div>";
    } else {
        $ins = $conn->prepare("INSERT INTO edit_requests (staff_id, record_type, record_id, new_data, reason) VALUES (?,?,?,?,?)");
        $ins->bind_param("isiss", $staff_id, $type, $id, $new_data, $reason);

        if ($ins->execute()) {
            $ins->close();

            // ── FIRE NOTIFICATION TO OWNER ──────────────────────────────
            $batch_id    = (int)($original['batch_id'] ?? 0) ?: null;
            $staff_name  = $_SESSION['name'] ?? 'A staff member';
            $record_label = $type === 'Sale'
                ? "Sale to " . ($original['customer_name'] ?? "#$id")
                : "$type (Batch: {$original['breed']})";
            $notif_msg   = "$staff_name requested a correction on $type record #$id"
                         . " ($record_label). Reason: $reason";

            notify_owner(
                $conn,
                $staff_id,
                'edit_request',
                $notif_msg,
                $batch_id,
                $type,
                $id
            );
            // ────────────────────────────────────────────────────────────

            log_session_activity($conn, 'Edit Request Sent', "Requested edit on $type #$id — reason: $reason");
            header("Location: view_logs.php?request_sent=1"); exit();
        } else {
            $message = "<div class='alert error'>Database error. Please try again.</div>";
        }
        $ins->close();
    }
}
?>

<!-- HTML below is UNCHANGED from your original -->
<div class="card" style="max-width:540px; margin:2rem auto; border-top:5px solid var(--terra-lt);">
    <h3 style="color:var(--gold); font-family:'Playfair Display',serif; margin-bottom:0.3rem;">✏️ Request Record Correction</h3>
    <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom:2rem;">
        This sends a correction request to the Owner for review.
    </p>

    <div style="background:var(--bg-wood); border-radius:var(--radius); padding:14px 16px; border-left:4px solid var(--border-mid); margin-bottom:1.5rem;">
        <p style="font-size:0.75rem; font-weight:700; color:var(--text-muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.6px;">
            Original Record — <?php echo $type; ?> #<?php echo $id; ?>
        </p>
        <?php if ($type === 'Harvest'): ?>
            <p style="margin:0; font-size:0.9rem; color:var(--text-primary);">
                Batch: <strong><?php echo htmlspecialchars($original['breed']); ?></strong> &nbsp;|&nbsp;
                Total: <strong style="color:var(--gold);"><?php echo number_format($original['total_eggs']); ?> eggs</strong><br>
                <small style="color:var(--text-muted);">Logged: <?php echo date('M d, Y g:i A', strtotime($original['date_logged'])); ?></small>
            </p>
        <?php elseif ($type === 'Sale'): ?>
            <p style="margin:0; font-size:0.9rem; color:var(--text-primary);">
                Customer: <strong><?php echo htmlspecialchars($original['customer_name']); ?></strong> &nbsp;|&nbsp;
                Qty: <strong style="color:var(--gold);"><?php echo number_format($original['quantity_sold']); ?> trays</strong> &nbsp;|&nbsp;
                Total: <strong style="color:var(--success);">₱<?php echo number_format((float)$original['total_amount'], 2); ?></strong><br>
                <small style="color:var(--text-muted);">Sold: <?php echo date('M d, Y g:i A', strtotime($original['date_sold'])); ?></small>
            </p>
        <?php else: ?>
            <p style="margin:0; font-size:0.9rem; color:var(--text-primary);">
                Batch: <strong><?php echo htmlspecialchars($original['breed']); ?></strong> &nbsp;|&nbsp;
                Status: <strong><?php echo $original['status_level']; ?></strong> &nbsp;|&nbsp;
                Mortality: <strong style="color:var(--gold);"><?php echo $original['mortality_count']; ?></strong><br>
                <small style="color:var(--text-muted);">Reported: <?php echo date('M d, Y g:i A', strtotime($original['date_reported'])); ?></small>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($already_pending): ?>
        <div class="alert warning">⏳ You already have a pending request for this record. Wait for the Owner to review it first.</div>
        <a href="view_logs.php" class="btn-farm btn-outline btn-full" style="text-align:center; margin-top:1rem;">← Back to My Logs</a>
    <?php else: ?>
        <?php echo $message; ?>
        <form method="POST">
            <div class="form-group">
                <label>Why do you need to correct this? <span style="color:var(--danger);">*</span></label>
                <textarea name="reason" class="form-input" rows="3" required
                          placeholder="Example: I typed 500 instead of 50 by mistake."></textarea>
            </div>
            <?php if ($type === 'Harvest'): ?>
                <div class="form-group">
                    <label>Corrected Total Eggs</label>
                    <input type="number" name="new_total" class="form-input" min="0" required
                           value="<?php echo $original['total_eggs']; ?>">
                </div>
            <?php elseif ($type === 'Sale'): ?>
                <div class="form-group">
                    <label>Corrected Quantity Sold (trays)</label>
                    <input type="number" name="new_quantity" class="form-input" min="0" required
                           value="<?php echo $original['quantity_sold']; ?>">
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label>Corrected Mortality Count</label>
                    <input type="number" name="new_mortality" class="form-input" min="0" required
                           value="<?php echo $original['mortality_count']; ?>">
                </div>
            <?php endif; ?>
            <button type="submit" class="btn-farm btn-orange btn-full" style="padding:14px; margin-top:0.5rem;">
                📤 Send to Owner for Review
            </button>
        </form>
        <a href="view_logs.php" class="back-link" style="display:block; text-align:center; margin-top:1rem;">← Cancel and Go Back</a>
    <?php endif; ?>
</div>

<?php include('../includes/footer.php'); ?>