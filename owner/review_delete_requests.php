<?php
$page_title = 'Review Delete Requests';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header('Location: ../portal/login.php');
    exit();
}

$owner_id = (int)$_SESSION['user_id'];
$flash    = '';

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
        $type   = 'Delete';
        $status = 'Pending';
        $req_stmt->bind_param('iss', $request_id, $type, $status);
        $req_stmt->execute();
        $req_row = $req_stmt->get_result()->fetch_assoc();
        $req_stmt->close();

        if ($req_row) {
            $record_type = $req_row['record_type'];
            $record_id   = (int)$req_row['record_id'];
            $owner_note  = trim($_POST['owner_note'] ?? '');

            if ($action === 'approve') {
                $table_map = [
                    'Harvest' => ['table' => 'harvests',    'pk' => 'harvest_id'],
                    'Health'  => ['table' => 'flock_health', 'pk' => 'report_id'],
                    'Sale'    => ['table' => 'sales',        'pk' => 'sale_id'],
                ];

                $delete_ok = false;

                if (isset($table_map[$record_type])) {
                    $t   = $table_map[$record_type];
                    $del = $conn->prepare("DELETE FROM {$t['table']} WHERE {$t['pk']} = ?");
                    $del->bind_param('i', $record_id);
                    $delete_ok = $del->execute();
                    $del->close();
                }

                if ($delete_ok) {
                    $upd = $conn->prepare('
                        UPDATE edit_requests
                        SET status = ?, reviewed_by = ?, reviewed_at = NOW(), owner_note = ?
                        WHERE request_id = ?
                    ');
                    $approved = 'Approved';
                    $upd->bind_param('sisi', $approved, $owner_id, $owner_note, $request_id);
                    $upd->execute();
                    $upd->close();

                    $clean = $conn->prepare('
                        UPDATE edit_requests
                        SET status = ?, reviewed_by = ?, reviewed_at = NOW(), owner_note = ?
                        WHERE record_type = ? AND record_id = ? AND status = ? AND request_id != ?
                    ');
                    $rejected       = 'Rejected';
                    $pending        = 'Pending';
                    $supersede_note = 'Record deleted — superseded.';
                    $clean->bind_param('sissisi', $rejected, $owner_id, $supersede_note, $record_type, $record_id, $pending, $request_id);
                    $clean->execute();
                    $clean->close();

                    log_activity($conn, $owner_id, 'Owner', 'Delete Request Approved',
                        "Approved deletion of {$record_type} #{$record_id} (request #{$request_id}) by {$req_row['staff_name']}");

                    $flash = "<div class='alert success'>Record deleted.</div>";
                } else {
                    $flash = "<div class='alert error'>Failed to delete the record. It may have already been removed.</div>";
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

                log_activity($conn, $owner_id, 'Owner', 'Delete Request Rejected',
                    "Rejected deletion request #{$request_id} ({$record_type} #{$record_id}) by {$req_row['staff_name']}");

                $flash = "<div class='alert warning'>Deletion request rejected. Record kept.</div>";
            }
        }
    }
}

$reqs_q = $conn->query("
    SELECT er.*, u.username AS staff_name
    FROM edit_requests er
    JOIN users u ON er.staff_id = u.user_id
    WHERE er.request_type = 'Delete'
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
.request-card         { margin-bottom: 1.3rem; padding: 1.4rem 1.6rem; }
.record-snapshot      { background: var(--bg-wood); border-radius: var(--radius-sm); padding: 12px 16px; margin-bottom: 14px; }
.staff-reason         { font-size: 0.85rem; color: var(--text-secondary); line-height: 1.6;
                        background: var(--bg-wood); border-radius: var(--radius-sm);
                        padding: 10px 14px; margin-bottom: 14px; border-left: 3px solid var(--border-mid); }
.delete-warning       { background: rgba(194,58,58,.08); border: 1px solid rgba(194,58,58,.3);
                        border-radius: var(--radius-sm); padding: 10px 14px; margin-bottom: 14px;
                        font-size: 0.82rem; color: var(--danger); }
.section-label        { font-size: 0.7rem; font-weight: 700; color: var(--text-muted);
                        text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 8px; }
.reviewed-meta        { font-size: 0.78rem; color: var(--text-muted); }
</style>

<div style="max-width:960px; margin:2rem auto;">

    <div class="page-header">
        <div>
            <h2>
                Review Delete Requests
                <?php if ($pending_count > 0): ?>
                <span class="badge badge-critical" style="font-size:0.68rem; vertical-align:middle; margin-left:8px;">
                    <?php echo $pending_count; ?> PENDING
                </span>
                <?php endif; ?>
            </h2>
            <p style="color:var(--text-muted); font-size:0.9rem; margin:0;">
                Staff-submitted deletion requests. <strong style="color:var(--danger);">Approval permanently removes the record.</strong>
            </p>
        </div>
        <a href="staff_notifications.php" class="back-link" style="margin:0;">← Notifications</a>
    </div>

    <?php echo $flash; ?>

    <?php if (empty($requests)): ?>
    <div class="card">
        <div class="empty-state">
            <p>No deletion requests yet.</p>
            <small>Requests submitted by staff will appear here.</small>
        </div>
    </div>

    <?php else:
        foreach ($requests as $req):
            $is_pending  = $req['status'] === 'Pending';
            $is_approved = $req['status'] === 'Approved';
            $record_type = $req['record_type'];
            $record_id   = (int)$req['record_id'];
            $snapshot    = fetch_record_snapshot($conn, $record_type, $record_id);

            $border_color = match($req['status']) {
                'Pending'  => 'var(--danger)',
                'Approved' => 'var(--success)',
                default    => 'var(--border-mid)',
            };
    ?>

    <div class="card request-card"
         style="border-left: 5px solid <?php echo $border_color; ?>;
                <?php echo $is_pending ? 'background: rgba(194,58,58,.04);' : ''; ?>">

        <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:12px;">
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">

                <?php if ($is_pending): ?>
                    <span class="badge badge-critical" style="font-size:0.62rem;">PENDING</span>
                <?php elseif ($is_approved): ?>
                    <span class="badge badge-healthy" style="font-size:0.62rem;">DELETED</span>
                <?php else: ?>
                    <span class="badge badge-pending" style="font-size:0.62rem;">REJECTED</span>
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

        <div class="record-snapshot" style="border-left: 3px solid var(--danger);">
            <p class="section-label">Record Marked for Deletion</p>

            <?php if ($snapshot): ?>
                <?php if ($record_type === 'Harvest'): ?>
                    <p style="margin:0; font-size:0.9rem;">
                        Batch: <strong><?php echo htmlspecialchars($snapshot['breed']); ?></strong>
                        &nbsp;&middot;&nbsp;
                        Total Eggs: <strong style="color:var(--gold);"><?php echo number_format((int)$snapshot['total_eggs']); ?></strong><br>
                        <small style="color:var(--text-muted);">Logged: <?php echo date('M d, Y g:i A', strtotime($snapshot['date_logged'])); ?></small>
                    </p>
                <?php elseif ($record_type === 'Sale'): ?>
                    <p style="margin:0; font-size:0.9rem;">
                        Customer: <strong><?php echo htmlspecialchars($snapshot['customer_name']); ?></strong>
                        &nbsp;&middot;&nbsp;
                        Qty: <strong style="color:var(--gold);"><?php echo number_format((int)$snapshot['quantity_sold']); ?> trays</strong>
                        &nbsp;&middot;&nbsp;
                        Total: <strong style="color:var(--success);">&#8369;<?php echo number_format((float)$snapshot['total_amount'], 2); ?></strong><br>
                        <small style="color:var(--text-muted);">Sold: <?php echo date('M d, Y g:i A', strtotime($snapshot['date_sold'])); ?></small>
                    </p>
                <?php else: ?>
                    <p style="margin:0; font-size:0.9rem;">
                        Batch: <strong><?php echo htmlspecialchars($snapshot['breed']); ?></strong>
                        &nbsp;&middot;&nbsp;
                        Status: <strong><?php echo htmlspecialchars($snapshot['status_level']); ?></strong>
                        &nbsp;&middot;&nbsp;
                        Mortality: <strong style="color:var(--gold);"><?php echo (int)$snapshot['mortality_count']; ?></strong><br>
                        <small style="color:var(--text-muted);">Reported: <?php echo date('M d, Y g:i A', strtotime($snapshot['date_reported'])); ?></small>
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <p style="margin:0; font-size:0.85rem; color:var(--text-muted); font-style:italic;">
                    Record no longer exists.
                </p>
            <?php endif; ?>
        </div>

        <?php if (!empty($req['reason'])): ?>
        <div class="staff-reason">
            <strong style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.5px;
                            color:var(--text-muted); display:block; margin-bottom:4px;">Staff Reason</strong>
            <?php echo htmlspecialchars($req['reason']); ?>
        </div>
        <?php endif; ?>

        <?php if (!$is_pending): ?>
        <div class="reviewed-meta">
            <?php echo $is_approved ? 'Deleted' : 'Rejected'; ?>
            on <?php echo date('M d, Y g:i A', strtotime($req['reviewed_at'])); ?>
            <?php if (!empty($req['owner_note'])): ?>
                &mdash; <em><?php echo htmlspecialchars($req['owner_note']); ?></em>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <div class="delete-warning">
            <strong>Approving will permanently delete this record and cannot be undone.</strong>
        </div>

        <form method="POST" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
            <input type="hidden" name="request_id" value="<?php echo (int)$req['request_id']; ?>">

            <div class="form-group" style="flex:1; min-width:200px; margin:0;">
                <label style="font-size:0.78rem; color:var(--text-muted); margin-bottom:4px; display:block;">
                    Note to staff (optional)
                </label>
                <input type="text" name="owner_note" class="form-input"
                       placeholder="e.g. Confirmed duplicate — deleted."
                       style="padding:8px 10px; font-size:0.85rem;">
            </div>

            <button type="submit" name="action" value="approve"
                    class="btn-farm btn-danger btn-sm"
                    style="padding:9px 18px; font-size:0.85rem; white-space:nowrap;"
                    onclick="return confirm('This will permanently delete the record. Continue?')">
                Approve &amp; Delete
            </button>
            <button type="submit" name="action" value="reject"
                    class="btn-farm btn-outline btn-sm"
                    style="padding:9px 18px; font-size:0.85rem; white-space:nowrap;"
                    onclick="return confirm('Reject this deletion request?')">
                Reject
            </button>
        </form>
        <?php endif; ?>

    </div>
    <?php endforeach; endif; ?>

</div>

<?php include('../includes/footer.php'); ?>