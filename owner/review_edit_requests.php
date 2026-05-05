<?php
$page_title = 'Review Edit Requests';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header('Location: ../portal/login.php');
    exit();
}

$owner_id = (int)$_SESSION['user_id'];
$flash    = match($_GET['flash'] ?? '') {
    'approved' => "<div class='alert success'>Edit approved and record updated.</div>",
    'rejected'  => "<div class='alert warning'>Edit request rejected.</div>",
    default     => '',
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $request_id = (int)($_POST['request_id'] ?? 0);

    if ($request_id > 0 && in_array($action, ['approve', 'reject'], true)) {
        $req_stmt = $conn->prepare('
            SELECT er.*, u.username AS staff_name
            FROM edit_requests er
            JOIN users u ON er.staff_id = u.user_id
            WHERE er.request_id = ? AND er.request_type = ? AND er.status = ?
            LIMIT 1
        ');
        $type   = 'Edit';
        $status = 'Pending';
        $req_stmt->bind_param('iss', $request_id, $type, $status);
        $req_stmt->execute();
        $req_row = $req_stmt->get_result()->fetch_assoc();
        $req_stmt->close();

        if ($req_row) {
            $record_type = $req_row['record_type'];
            $record_id   = (int)$req_row['record_id'];
            $new_data    = json_decode($req_row['new_data'] ?? '{}', true) ?? [];
            $owner_note  = trim($_POST['owner_note'] ?? '');

            if ($action === 'approve') {
                $apply_ok = false;

                if ($record_type === 'Harvest' && isset($new_data['total_eggs'])) {
                    $upd = $conn->prepare('UPDATE harvests SET total_eggs = ? WHERE harvest_id = ?');
                    $upd->bind_param('ii', $new_data['total_eggs'], $record_id);
                    $apply_ok = $upd->execute();
                    $upd->close();

                } elseif ($record_type === 'Health' && isset($new_data['mortality_count'])) {
                    $upd = $conn->prepare('UPDATE flock_health SET mortality_count = ? WHERE report_id = ?');
                    $upd->bind_param('ii', $new_data['mortality_count'], $record_id);
                    $apply_ok = $upd->execute();
                    $upd->close();

                } elseif ($record_type === 'Sale' && isset($new_data['quantity_sold'])) {
                    $upd = $conn->prepare('UPDATE sales SET quantity_sold = ? WHERE sale_id = ?');
                    $upd->bind_param('ii', $new_data['quantity_sold'], $record_id);
                    $apply_ok = $upd->execute();
                    $upd->close();

                } else {
                    $apply_ok = true;
                }

                if ($apply_ok) {
                    $upd = $conn->prepare('
                        UPDATE edit_requests
                        SET status = ?, reviewed_by = ?, reviewed_at = NOW(), owner_note = ?
                        WHERE request_id = ?
                    ');
                    $approved = 'Approved';
                    $upd->bind_param('sisi', $approved, $owner_id, $owner_note, $request_id);
                    $upd->execute();
                    $upd->close();

                    log_activity($conn, $owner_id, 'Owner', 'Edit Request Approved',
                        "Approved edit request #{$request_id} ({$record_type} #{$record_id}) by {$req_row['staff_name']}");

                    // Auto-mark the owner's notification as read now that the request is resolved
                    $conn->query("
                        UPDATE staff_notifications
                        SET status = 'read', read_at = NOW()
                        WHERE notif_type = 'edit_request'
                          AND record_type = '{$conn->real_escape_string($record_type)}'
                          AND record_id   = {$record_id}
                          AND status      = 'unread'
                    ");

                    // Notify the staff member their edit was approved
                    $notif_msg_approve = "Your edit request on {$record_type} #{$record_id} was approved.";
                    if (!empty($owner_note)) $notif_msg_approve .= " Owner note: {$owner_note}";
                    $sn = $conn->prepare("
                        INSERT INTO staff_notifications (staff_id, notif_type, message, record_type, record_id, status, created_at)
                        VALUES (?, 'request_outcome', ?, ?, ?, 'unread', NOW())
                    ");
                    $sn->bind_param('issi', $req_row['staff_id'], $notif_msg_approve, $record_type, $record_id);
                    $sn->execute();
                    $sn->close();

                    header('Location: review_edit_requests.php?flash=approved');
                    exit();
                } else {
                    $flash = "<div class='alert error'>Failed to apply the change. Please try again.</div>";
                }

            } else {
                $upd = $conn->prepare('
                    UPDATE edit_requests
                    SET status = ?, reviewed_by = ?, reviewed_at = NOW(), owner_note = ?
                    WHERE request_id = ?
                ');
                $rejected = 'Rejected';
                $upd->bind_param('sisi', $rejected, $owner_id, $owner_note, $request_id);
                $upd->execute();
                $upd->close();

                log_activity($conn, $owner_id, 'Owner', 'Edit Request Rejected',
                    "Rejected edit request #{$request_id} ({$record_type} #{$record_id}) by {$req_row['staff_name']}");

                // Auto-mark the owner's notification as read now that the request is resolved
                $conn->query("
                    UPDATE staff_notifications
                    SET status = 'read', read_at = NOW()
                    WHERE notif_type = 'edit_request'
                      AND record_type = '{$conn->real_escape_string($record_type)}'
                      AND record_id   = {$record_id}
                      AND status      = 'unread'
                ");

                // Notify the staff member their edit was rejected
                $notif_msg_reject = "Your edit request on {$record_type} #{$record_id} was rejected.";
                if (!empty($owner_note)) $notif_msg_reject .= " Owner note: {$owner_note}";
                $sn = $conn->prepare("
                    INSERT INTO staff_notifications (staff_id, notif_type, message, record_type, record_id, status, created_at)
                    VALUES (?, 'request_outcome', ?, ?, ?, 'unread', NOW())
                ");
                $sn->bind_param('issi', $req_row['staff_id'], $notif_msg_reject, $record_type, $record_id);
                $sn->execute();
                $sn->close();

                header('Location: review_edit_requests.php?flash=rejected');
                exit();
            }
        }
    }
}

$reqs_q = $conn->query("
    SELECT er.*, u.username AS staff_name
    FROM edit_requests er
    JOIN users u ON er.staff_id = u.user_id
    WHERE er.request_type = 'Edit'
    ORDER BY FIELD(er.status, 'Pending', 'Approved', 'Rejected'), er.created_at DESC
    LIMIT 60
");

$pending_count = 0;
$requests      = [];

if ($reqs_q) {
    while ($row = $reqs_q->fetch_assoc()) {
        if ($row['status'] === 'Pending') $pending_count++;
        $requests[] = $row;
    }
}

function fetch_record_snapshot(mysqli $conn, string $type, int $id): ?array
{
    $queries = [
        'Harvest' => 'SELECT h.total_eggs, h.date_logged, b.breed FROM harvests h JOIN batches b ON h.batch_id = b.batch_id WHERE h.harvest_id = ? LIMIT 1',
        'Sale'    => 'SELECT s.quantity_sold, s.total_amount, s.customer_name, s.date_sold FROM sales s WHERE s.sale_id = ? LIMIT 1',
        'Health'  => 'SELECT fh.mortality_count, fh.status_level, fh.date_reported, b.breed FROM flock_health fh JOIN batches b ON fh.batch_id = b.batch_id WHERE fh.report_id = ? LIMIT 1',
    ];

    if (!isset($queries[$type])) return null;

    $stmt = $conn->prepare($queries[$type]);
    if (!$stmt) return null;

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}
?>

<style>
.request-card  { margin-bottom: 1.3rem; padding: 1.4rem 1.6rem; }
.diff-grid     { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
.diff-panel    { background: var(--bg-wood); border-radius: var(--radius-sm); padding: 10px 14px; }
.section-label { font-size: 0.7rem; font-weight: 700; color: var(--text-muted);
                 text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 6px; }
.reviewed-meta { font-size: 0.78rem; color: var(--text-muted); margin-bottom: 10px; }
</style>

<div style="max-width:960px; margin:2rem auto;">

    <div class="page-header">
        <div>
            <h2>
                Review Edit Requests
                <?php if ($pending_count > 0): ?>
                <span class="badge badge-warning" style="font-size:0.68rem; vertical-align:middle; margin-left:8px;">
                    <?php echo $pending_count; ?> PENDING
                </span>
                <?php endif; ?>
            </h2>
            <p style="color:var(--text-muted); font-size:0.9rem; margin:0;">
                Staff-submitted correction requests for Harvest, Health, and Sale records.
            </p>
        </div>
        <a href="staff_notifications.php" class="back-link" style="margin:0;">← Notifications</a>
    </div>

    <?php echo $flash; ?>

    <?php if (empty($requests)): ?>
    <div class="card">
        <div class="empty-state">
            <p>No edit requests yet.</p>
            <small>Correction requests submitted by staff will appear here.</small>
        </div>
    </div>

    <?php else:
        foreach ($requests as $req):
            $is_pending  = $req['status'] === 'Pending';
            $is_approved = $req['status'] === 'Approved';
            $record_type = $req['record_type'];
            $record_id   = (int)$req['record_id'];
            $new_data    = json_decode($req['new_data'] ?? '{}', true) ?? [];
            $snapshot    = fetch_record_snapshot($conn, $record_type, $record_id);

            $border_color = match($req['status']) {
                'Pending'  => 'var(--gold)',
                'Approved' => 'var(--success)',
                default    => 'var(--border-mid)',
            };

            $old_val = $new_val = '—';
            $field_label = '';

            if ($record_type === 'Harvest') {
                $field_label = 'Total Eggs';
                $old_val     = $snapshot ? number_format((int)$snapshot['total_eggs']) : '?';
                $new_val     = isset($new_data['total_eggs']) ? number_format((int)$new_data['total_eggs']) : '?';
            } elseif ($record_type === 'Health') {
                $field_label = 'Mortality Count';
                $old_val     = $snapshot ? (string)(int)$snapshot['mortality_count'] : '?';
                $new_val     = isset($new_data['mortality_count']) ? (string)(int)$new_data['mortality_count'] : '?';
            } elseif ($record_type === 'Sale') {
                $field_label = 'Quantity Sold';
                $old_val     = $snapshot ? number_format((int)$snapshot['quantity_sold']) . ' trays' : '?';
                $new_val     = isset($new_data['quantity_sold']) ? number_format((int)$new_data['quantity_sold']) . ' trays' : '?';
            }
    ?>

    <div class="card request-card"
         style="border-left: 5px solid <?php echo $border_color; ?>;
                <?php echo $is_pending ? 'background: rgba(212,175,55,.03);' : ''; ?>">

        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">

                <?php if ($is_pending): ?>
                    <span class="badge badge-warning" style="font-size:0.62rem;">PENDING</span>
                <?php elseif ($is_approved): ?>
                    <span class="badge badge-healthy" style="font-size:0.62rem;">APPROVED</span>
                <?php else: ?>
                    <span class="badge badge-critical" style="font-size:0.62rem;">REJECTED</span>
                <?php endif; ?>

                <span class="badge badge-pending" style="font-size:0.62rem;">
                    <?php echo htmlspecialchars($record_type); ?> #<?php echo $record_id; ?>
                </span>

                <span style="font-weight:700; font-size:0.95rem; color:var(--text-primary);">
                    <?php echo htmlspecialchars($req['staff_name']); ?>
                </span>
            </div>
            <span style="font-size:0.75rem; color:var(--text-muted); white-space:nowrap;">
                Submitted: <?php echo date('M d, Y g:i A', strtotime($req['created_at'])); ?>
            </span>
        </div>

        <div class="diff-grid">
            <div class="diff-panel" style="border-left: 3px solid var(--border-mid);">
                <p class="section-label">Current</p>
                <?php if ($snapshot): ?>
                    <?php if ($record_type === 'Harvest'): ?>
                        <p style="margin:0; font-size:0.88rem;">
                            Batch: <strong><?php echo htmlspecialchars($snapshot['breed']); ?></strong><br>
                            <?php echo $field_label; ?>: <strong style="color:var(--gold);"><?php echo $old_val; ?></strong><br>
                            <small style="color:var(--text-muted);"><?php echo date('M d, Y', strtotime($snapshot['date_logged'])); ?></small>
                        </p>
                    <?php elseif ($record_type === 'Sale'): ?>
                        <p style="margin:0; font-size:0.88rem;">
                            Customer: <strong><?php echo htmlspecialchars($snapshot['customer_name']); ?></strong><br>
                            <?php echo $field_label; ?>: <strong style="color:var(--gold);"><?php echo $old_val; ?></strong><br>
                            Total: <strong style="color:var(--success);">&#8369;<?php echo number_format((float)$snapshot['total_amount'], 2); ?></strong><br>
                            <small style="color:var(--text-muted);"><?php echo date('M d, Y', strtotime($snapshot['date_sold'])); ?></small>
                        </p>
                    <?php else: ?>
                        <p style="margin:0; font-size:0.88rem;">
                            Batch: <strong><?php echo htmlspecialchars($snapshot['breed']); ?></strong><br>
                            Status: <strong><?php echo htmlspecialchars($snapshot['status_level']); ?></strong><br>
                            <?php echo $field_label; ?>: <strong style="color:var(--gold);"><?php echo $old_val; ?></strong><br>
                            <small style="color:var(--text-muted);"><?php echo date('M d, Y', strtotime($snapshot['date_reported'])); ?></small>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="margin:0; font-size:0.85rem; color:var(--text-muted); font-style:italic;">Record not found.</p>
                <?php endif; ?>
            </div>

            <div class="diff-panel" style="border-left: 3px solid var(--gold);">
                <p class="section-label">Proposed Change</p>
                <p style="margin:0; font-size:0.88rem;">
                    <?php echo $field_label; ?>:
                    <strong style="color:var(--gold); font-size:1rem;"><?php echo $new_val; ?></strong>
                </p>
                <?php if (!empty($req['reason'])): ?>
                <p style="margin:8px 0 0; font-size:0.8rem; color:var(--text-secondary); font-style:italic;">
                    &ldquo;<?php echo htmlspecialchars($req['reason']); ?>&rdquo;
                </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$is_pending): ?>
        <div class="reviewed-meta">
            Reviewed <?php echo date('M d, Y g:i A', strtotime($req['reviewed_at'])); ?>
            <?php if (!empty($req['owner_note'])): ?>
                &mdash; <em><?php echo htmlspecialchars($req['owner_note']); ?></em>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <form method="POST" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-top:4px;">
            <input type="hidden" name="request_id" value="<?php echo (int)$req['request_id']; ?>">

            <div class="form-group" style="flex:1; min-width:200px; margin:0;">
                <label style="font-size:0.78rem; color:var(--text-muted); margin-bottom:4px; display:block;">
                    Note to staff (optional)
                </label>
                <input type="text" name="owner_note" class="form-input"
                       placeholder="e.g. Verified, count corrected."
                       style="padding:8px 10px; font-size:0.85rem;">
            </div>

            <button type="submit" name="action" value="approve"
                    class="btn-farm btn-green btn-sm"
                    style="padding:9px 18px; font-size:0.85rem; white-space:nowrap;"
                    onclick="return confirm('Approve this edit and update the record?')">
                Approve &amp; Apply
            </button>
            <button type="submit" name="action" value="reject"
                    class="btn-farm btn-danger btn-sm"
                    style="padding:9px 18px; font-size:0.85rem; white-space:nowrap;"
                    onclick="return confirm('Reject this edit request?')">
                Reject
            </button>
        </form>
        <?php endif; ?>

    </div>
    <?php endforeach; endif; ?>

</div>

<?php include('../includes/footer.php'); ?>