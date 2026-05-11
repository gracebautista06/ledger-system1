<?php
$page_title = 'Review Edit Requests';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header('Location: ../portal/login.php');
    exit();
}

function notify_staff_outcome(mysqli $conn, int $staff_id, string $message, string $record_type, int $record_id): void {
    if ($staff_id <= 0) return;
    $sn = $conn->prepare(
        "INSERT INTO staff_notifications (staff_id, notif_type, message, record_type, record_id, status, created_at)
         VALUES (?, 'request_outcome', ?, ?, ?, 'unread', NOW())"
    );
    if (!$sn) return;
    $sn->bind_param('issi', $staff_id, $message, $record_type, $record_id);
    $sn->execute();
    $sn->close();
}

$owner_id = (int)$_SESSION['user_id'];
$flash    = match($_GET['flash'] ?? '') {
    'approved' => "<div class='alert success'>Edit approved and record updated.</div>",
    'rejected'  => "<div class='alert warning'>Edit request rejected.</div>",
    default     => '',
};

// ── Field map: record_type → field → [table, pk_col, bind_type] ──────────────
$field_map = [
    'Harvest' => [
        'size_pw'     => ['harvests',    'harvest_id', 'i'],
        'size_s'      => ['harvests',    'harvest_id', 'i'],
        'size_m'      => ['harvests',    'harvest_id', 'i'],
        'size_l'      => ['harvests',    'harvest_id', 'i'],
        'size_xl'     => ['harvests',    'harvest_id', 'i'],
        'size_j'      => ['harvests',    'harvest_id', 'i'],
        'date_logged' => ['harvests',    'harvest_id', 's'],
        'batch_id'    => ['harvests',    'harvest_id', 'i'],
    ],
    'Health' => [
        'mortality_count' => ['flock_health', 'report_id', 'i'],
        'status_level'    => ['flock_health', 'report_id', 's'],
        'date_reported'   => ['flock_health', 'report_id', 's'],
        'batch_id'        => ['flock_health', 'report_id', 'i'],
    ],
    'Sale' => [
        'qty_pw'        => ['sales', 'sale_id', 'i'],
        'qty_s'         => ['sales', 'sale_id', 'i'],
        'qty_m'         => ['sales', 'sale_id', 'i'],
        'qty_l'         => ['sales', 'sale_id', 'i'],
        'qty_xl'        => ['sales', 'sale_id', 'i'],
        'qty_j'         => ['sales', 'sale_id', 'i'],
        'total_amount'  => ['sales', 'sale_id', 'd'],
        'customer_name' => ['sales', 'sale_id', 's'],
        'date_sold'     => ['sales', 'sale_id', 's'],
    ],
    'Batch' => [
        'breed'                => ['batches', 'batch_id', 's'],
        'initial_count'        => ['batches', 'batch_id', 'i'],
        'coop_number'          => ['batches', 'batch_id', 'i'],
        'coop_label'           => ['batches', 'batch_id', 's'],
        'date_acquired'        => ['batches', 'batch_id', 's'],
        'expected_replacement' => ['batches', 'batch_id', 's'],
        'notes'                => ['batches', 'batch_id', 's'],
    ],
];

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

                // ── Normalise to the multi-field `changes` array ──────────────
                // Support both old single-field format and new multi-field format
                if (isset($new_data['changes']) && is_array($new_data['changes'])) {
                    $changes = $new_data['changes'];
                } elseif (isset($new_data['field'])) {
                    // Legacy single-field request — wrap it so the loop below works
                    $changes = [[
                        'field'     => $new_data['field'],
                        'old_value' => $new_data['old_value'] ?? '',
                        'new_value' => $new_data['new_value'] ?? '',
                        'label'     => $new_data['label']     ?? $new_data['field'],
                    ]];
                } else {
                    $changes = [];
                }

                $all_ok        = true;
                $applied_count = 0;
                $skipped       = [];
                $harvest_size_touched = false;
                $sale_qty_touched     = false;

                foreach ($changes as $change) {
                    $field     = $change['field']     ?? '';
                    $new_value = $change['new_value'] ?? '';

                    if (!$field || !isset($field_map[$record_type][$field])) {
                        $skipped[] = $field ?: '(unknown)';
                        continue;
                    }

                    [$table, $pk_col, $bind_type] = $field_map[$record_type][$field];

                    $typed_value = match($bind_type) {
                        'i'     => (int)$new_value,
                        'd'     => (float)$new_value,
                        default => (string)$new_value,
                    };

                    $safe_field = $conn->real_escape_string($field);
                    $safe_table = $conn->real_escape_string($table);
                    $safe_pk    = $conn->real_escape_string($pk_col);

                    $upd = $conn->prepare("UPDATE `{$safe_table}` SET `{$safe_field}` = ? WHERE `{$safe_pk}` = ?");
                    $upd->bind_param($bind_type . 'i', $typed_value, $record_id);
                    $ok = $upd->execute();
                    $upd->close();

                    if (!$ok) { $all_ok = false; continue; }
                    $applied_count++;

                    // Track which derived columns need recalculating
                    $harvest_size_fields = ['size_pw','size_s','size_m','size_l','size_xl','size_j'];
                    $sale_qty_fields     = ['qty_pw','qty_s','qty_m','qty_l','qty_xl','qty_j'];
                    if (in_array($field, $harvest_size_fields)) $harvest_size_touched = true;
                    if (in_array($field, $sale_qty_fields))     $sale_qty_touched     = true;
                }

                // ── Recalculate derived totals once, after all fields applied ──
                if ($harvest_size_touched) {
                    $conn->query("
                        UPDATE harvests
                        SET total_eggs = COALESCE(size_pw,0) + COALESCE(size_s,0) + COALESCE(size_m,0)
                                       + COALESCE(size_l,0) + COALESCE(size_xl,0) + COALESCE(size_j,0)
                        WHERE harvest_id = {$record_id}
                    ");

                    // ── Stock-safety check: warn if new harvest totals are below sold qty ──
                    $hbq = $conn->query("SELECT batch_id FROM harvests WHERE harvest_id = {$record_id} LIMIT 1");
                    $hb  = $hbq ? $hbq->fetch_assoc() : null;
                    if ($hb) {
                        $hbatch = (int)$hb['batch_id'];

                        // All harvested eggs per size for this batch (including the just-updated record)
                        $th_q = $conn->query("
                            SELECT COALESCE(SUM(size_pw),0) AS pw, COALESCE(SUM(size_s),0)  AS s,
                                   COALESCE(SUM(size_m),0)  AS m,  COALESCE(SUM(size_l),0)  AS l,
                                   COALESCE(SUM(size_xl),0) AS xl, COALESCE(SUM(size_j),0)  AS j
                            FROM harvests WHERE batch_id = {$hbatch}
                        ");
                        $total_harv = $th_q ? $th_q->fetch_assoc() : [];

                        $has_bc = $conn->query("SHOW COLUMNS FROM sales LIKE 'batch_id'")->num_rows > 0;
                        if ($has_bc) {
                            $ts_q = $conn->query("
                                SELECT COALESCE(SUM(qty_pw),0) AS pw, COALESCE(SUM(qty_s),0)  AS s,
                                       COALESCE(SUM(qty_m),0)  AS m,  COALESCE(SUM(qty_l),0)  AS l,
                                       COALESCE(SUM(qty_xl),0) AS xl, COALESCE(SUM(qty_j),0)  AS j
                                FROM sales WHERE batch_id = {$hbatch}
                            ");
                        } else {
                            $arrq = $conn->query("SELECT COALESCE(date_acquired, '2000-01-01') AS arr FROM batches WHERE batch_id = {$hbatch}");
                            $arr_esc2 = $conn->real_escape_string($arrq ? $arrq->fetch_assoc()['arr'] : '2000-01-01');
                            $ts_q = $conn->query("
                                SELECT COALESCE(SUM(qty_pw),0) AS pw, COALESCE(SUM(qty_s),0)  AS s,
                                       COALESCE(SUM(qty_m),0)  AS m,  COALESCE(SUM(qty_l),0)  AS l,
                                       COALESCE(SUM(qty_xl),0) AS xl, COALESCE(SUM(qty_j),0)  AS j
                                FROM sales WHERE DATE(date_sold) >= '{$arr_esc2}'
                            ");
                        }
                        $total_sold_trays = $ts_q ? $ts_q->fetch_assoc() : [];

                        $inv_warnings = [];
                        $sz_labels2   = ['pw'=>'Peewee','s'=>'Small','m'=>'Medium','l'=>'Large','xl'=>'XL','j'=>'Jumbo'];
                        foreach ($sz_labels2 as $sz => $lbl) {
                            $harv_trays2 = (int)floor((int)($total_harv[$sz] ?? 0) / 30);
                            $sold_trays2 = (int)($total_sold_trays[$sz] ?? 0);
                            if ($sold_trays2 > $harv_trays2) {
                                $inv_warnings[] = "{$lbl}: {$sold_trays2} tray(s) sold but only {$harv_trays2} tray(s) harvested after edit";
                            }
                        }
                        if (!empty($inv_warnings)) {
                            // Append inventory warning to the owner note so it's visible in the log
                            $inv_note = ' [Inventory warning after edit: ' . implode('; ', $inv_warnings) . ']';
                            $owner_note .= $inv_note;
                        }
                    }
                }
                if ($sale_qty_touched) {
                    // Step 1: get the updated qty_* values and the batch breed
                    $sale_row_q = $conn->query("
                        SELECT s.qty_pw, s.qty_s, s.qty_m, s.qty_l, s.qty_xl, s.qty_j,
                               s.total_amount AS existing_total,
                               b.breed
                        FROM sales s
                        LEFT JOIN batches b ON s.batch_id = b.batch_id
                        WHERE s.sale_id = {$record_id}
                        LIMIT 1
                    ");
                    $sale_row = $sale_row_q ? $sale_row_q->fetch_assoc() : null;

                    if ($sale_row) {
                        $breed = $sale_row['breed'] ?? '';

                        // Step 2: get per-size prices for this breed
                        $prices = [];
                        if ($breed !== '') {
                            $pr_q = $conn->query("
                                SELECT size_code, price_per_tray
                                FROM breed_prices
                                WHERE breed = '" . $conn->real_escape_string($breed) . "'
                            ");
                            if ($pr_q) {
                                while ($pr = $pr_q->fetch_assoc()) {
                                    $prices[$pr['size_code']] = (float)$pr['price_per_tray'];
                                }
                            }
                        }

                        // Step 3: recalculate totals using actual per-size prices
                        $size_map = [
                            'PW' => (int)$sale_row['qty_pw'],
                            'S'  => (int)$sale_row['qty_s'],
                            'M'  => (int)$sale_row['qty_m'],
                            'L'  => (int)$sale_row['qty_l'],
                            'XL' => (int)$sale_row['qty_xl'],
                            'J'  => (int)$sale_row['qty_j'],
                        ];
                        $new_total_trays  = array_sum($size_map);
                        $new_total_amount = 0.0;
                        foreach ($size_map as $code => $qty) {
                            $new_total_amount += $qty * ($prices[$code] ?? 0);
                        }
                        $new_total_amount = round($new_total_amount, 2);

                        // Guard: if no prices are available, keep the existing total_amount
                        // rather than writing ₱0.00 silently.
                        if ($new_total_amount <= 0 && empty($prices)) {
                            $new_total_amount = (float)($sale_row['existing_total'] ?? 0);
                        }

                        $new_unit_price = $new_total_trays > 0
                            ? round($new_total_amount / $new_total_trays, 2)
                            : 0.00;

                        $conn->query("
                            UPDATE sales
                            SET quantity_sold = {$new_total_trays},
                                total_amount  = {$new_total_amount},
                                unit_price    = {$new_unit_price}
                            WHERE sale_id = {$record_id}
                        ");
                    }
                }

                if ($applied_count > 0) {
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
                        "Approved edit request #{$request_id} ({$record_type} #{$record_id}) by {$req_row['staff_name']} — {$applied_count} field(s) updated");

                    $conn->query("
                        UPDATE staff_notifications
                        SET status = 'read', read_at = NOW()
                        WHERE notif_type = 'edit_request'
                          AND record_type = '{$conn->real_escape_string($record_type)}'
                          AND record_id   = {$record_id}
                          AND status      = 'unread'
                    ");

                    $notif_msg = "Your edit request on {$record_type} #{$record_id} was approved ({$applied_count} field(s) updated).";
                    if (!empty($skipped))   $notif_msg .= " Skipped: " . implode(', ', $skipped) . ".";
                    if (!empty($owner_note)) $notif_msg .= " Owner note: {$owner_note}";
                    notify_staff_outcome($conn, (int)$req_row['staff_id'], $notif_msg, $record_type, $record_id);

                    header('Location: review_edit_requests.php?flash=approved');
                    exit();
                } else {
                    $flash = "<div class='alert error'>Failed to apply any changes. Please try again.</div>";
                }

            } else { // reject
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

                $conn->query("
                    UPDATE staff_notifications
                    SET status = 'read', read_at = NOW()
                    WHERE notif_type = 'edit_request'
                      AND record_type = '{$conn->real_escape_string($record_type)}'
                      AND record_id   = {$record_id}
                      AND status      = 'unread'
                ");

                $notif_msg = "Your edit request on {$record_type} #{$record_id} was rejected.";
                if (!empty($owner_note)) $notif_msg .= " Owner note: {$owner_note}";
                notify_staff_outcome($conn, (int)$req_row['staff_id'], $notif_msg, $record_type, $record_id);

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
        'Harvest' => 'SELECT h.*, b.breed FROM harvests h JOIN batches b ON h.batch_id = b.batch_id WHERE h.harvest_id = ? LIMIT 1',
        'Sale'    => 'SELECT s.* FROM sales s WHERE s.sale_id = ? LIMIT 1',
        'Health'  => 'SELECT fh.*, b.breed FROM flock_health fh JOIN batches b ON fh.batch_id = b.batch_id WHERE fh.report_id = ? LIMIT 1',
        'Batch'   => 'SELECT b.* FROM batches b WHERE b.batch_id = ? LIMIT 1',
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

/**
 * Format a single field value for display.
 */
function format_field_value(string $field_key, string $value): string {
    if ($value === '') return '—';
    if (in_array($field_key, ['date_logged','date_sold','date_reported'])) {
        return date('M d, Y g:i A', strtotime(str_replace('T', ' ', $value)));
    }
    if (in_array($field_key, ['date_acquired','expected_replacement'])) {
        return date('M d, Y', strtotime($value));
    }
    if ($field_key === 'total_amount') {
        return '₱' . number_format((float)$value, 2);
    }
    return htmlspecialchars($value);
}
?>

<style>
.request-card  { margin-bottom: 1.3rem; padding: 1.4rem 1.6rem; }
.diff-grid     { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
.diff-panel    { background: var(--bg-wood); border-radius: var(--radius-sm); padding: 10px 14px; }
.section-label { font-size: 0.7rem; font-weight: 700; color: var(--text-muted);
                 text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 6px; }
.reviewed-meta { font-size: 0.78rem; color: var(--text-muted); margin-bottom: 10px; }
.changes-table { width:100%; border-collapse:collapse; font-size:0.85rem; margin-top:6px; }
.changes-table th { font-size:0.7rem; font-weight:700; color:var(--text-muted); text-transform:uppercase;
                    letter-spacing:0.4px; padding:4px 8px; text-align:left; border-bottom:1px solid var(--border-mid); }
.changes-table td { padding:5px 8px; border-bottom:1px solid var(--border-mid); vertical-align:top; }
.changes-table tr:last-child td { border-bottom:none; }
.val-old { color:var(--text-muted); text-decoration:line-through; }
.val-new { color:var(--terra-lt); font-weight:600; }
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
                Staff-submitted correction requests for Harvest, Health, Sale, and Batch records.
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

            // ── Normalise to the multi-field changes array ──────────────────
            if (isset($new_data['changes']) && is_array($new_data['changes'])) {
                $changes = $new_data['changes'];
            } elseif (isset($new_data['field'])) {
                $changes = [[
                    'field'     => $new_data['field'],
                    'old_value' => $new_data['old_value'] ?? '',
                    'new_value' => $new_data['new_value'] ?? '',
                    'label'     => $new_data['label']     ?? $new_data['field'],
                ]];
            } else {
                $changes = [];
            }
    ?>

    <div class="card request-card"
         style="border-left: 5px solid <?php echo $border_color; ?>;
                <?php echo $is_pending ? 'background: rgba(212,175,55,.03);' : ''; ?>">

        <!-- ── Header row ── -->
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
                <span class="badge" style="font-size:0.62rem; background:var(--bg-wood); color:var(--text-muted); border:1px solid var(--border-mid);">
                    <?php echo count($changes); ?> field<?php echo count($changes) !== 1 ? 's' : ''; ?>
                </span>
                <span style="font-weight:700; font-size:0.95rem; color:var(--text-primary);">
                    <?php echo htmlspecialchars($req['staff_name']); ?>
                </span>
            </div>
            <span style="font-size:0.75rem; color:var(--text-muted); white-space:nowrap;">
                Submitted: <?php echo date('M d, Y g:i A', strtotime($req['created_at'])); ?>
            </span>
        </div>

        <!-- ── Current record snapshot ── -->
        <?php if ($snapshot): ?>
        <div style="background:var(--bg-wood); border-radius:var(--radius-sm); padding:10px 14px; border-left:3px solid var(--border-mid); margin-bottom:12px;">
            <p class="section-label" style="margin-bottom:6px;">Current Record State</p>
            <?php if ($record_type === 'Harvest'): ?>
                <p style="margin:0; font-size:0.87rem;">
                    Batch: <strong><?php echo htmlspecialchars($snapshot['breed'] ?? '—'); ?></strong> &nbsp;|&nbsp;
                    Total: <strong style="color:var(--gold);"><?php echo number_format((int)($snapshot['total_eggs'] ?? 0)); ?> eggs</strong> &nbsp;|&nbsp;
                    <small style="color:var(--text-muted);"><?php echo date('M d, Y', strtotime($snapshot['date_logged'])); ?></small>
                </p>
            <?php elseif ($record_type === 'Sale'): ?>
                <p style="margin:0; font-size:0.87rem;">
                    Customer: <strong><?php echo htmlspecialchars($snapshot['customer_name'] ?? '—'); ?></strong> &nbsp;|&nbsp;
                    Total: <strong style="color:var(--success);">₱<?php echo number_format((float)($snapshot['total_amount'] ?? 0), 2); ?></strong> &nbsp;|&nbsp;
                    Qty: <strong style="color:var(--gold);"><?php echo number_format((int)($snapshot['quantity_sold'] ?? 0)); ?></strong> &nbsp;|&nbsp;
                    <small style="color:var(--text-muted);"><?php echo date('M d, Y', strtotime($snapshot['date_sold'])); ?></small>
                </p>
            <?php elseif ($record_type === 'Health'): ?>
                <p style="margin:0; font-size:0.87rem;">
                    Batch: <strong><?php echo htmlspecialchars($snapshot['breed'] ?? '—'); ?></strong> &nbsp;|&nbsp;
                    Status: <strong><?php echo htmlspecialchars($snapshot['status_level'] ?? '—'); ?></strong> &nbsp;|&nbsp;
                    Mortality: <strong style="color:var(--gold);"><?php echo (int)($snapshot['mortality_count'] ?? 0); ?></strong> &nbsp;|&nbsp;
                    <small style="color:var(--text-muted);"><?php echo date('M d, Y', strtotime($snapshot['date_reported'])); ?></small>
                </p>
            <?php else: ?>
                <p style="margin:0; font-size:0.87rem;">
                    Breed: <strong><?php echo htmlspecialchars($snapshot['breed'] ?? '—'); ?></strong> &nbsp;|&nbsp;
                    Birds: <strong style="color:var(--gold);"><?php echo number_format((int)($snapshot['initial_count'] ?? 0)); ?></strong> &nbsp;|&nbsp;
                    Coop: <strong>#<?php echo htmlspecialchars((string)($snapshot['coop_number'] ?? '—')); ?></strong>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── Multi-field diff table ── -->
        <div class="diff-grid">
            <div class="diff-panel" style="border-left: 3px solid var(--border-mid); grid-column: 1 / -1;">
                <p class="section-label">Proposed Changes</p>
                <?php if (!empty($changes)): ?>
                <table class="changes-table">
                    <thead>
                        <tr>
                            <th>Field</th>
                            <th>Current Value</th>
                            <th>→</th>
                            <th>Requested Value</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($changes as $ch):
                        $fkey   = $ch['field']     ?? '';
                        $flabel = $ch['label']      ?? ucfirst(str_replace('_', ' ', $fkey));
                        $fold   = $ch['old_value']  ?? '';
                        $fnew   = $ch['new_value']  ?? '';
                        // Use live snapshot value as "current" if available
                        $live_old = ($snapshot && array_key_exists($fkey, $snapshot))
                            ? (string)$snapshot[$fkey]
                            : $fold;
                    ?>
                        <tr>
                            <td style="font-weight:600; color:var(--text-primary);"><?php echo htmlspecialchars($flabel); ?></td>
                            <td class="val-old"><?php echo format_field_value($fkey, $live_old); ?></td>
                            <td style="color:var(--text-muted); font-size:1rem;">→</td>
                            <td class="val-new"><?php echo format_field_value($fkey, $fnew); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p style="margin:0; font-size:0.85rem; color:var(--text-muted); font-style:italic;">No change data found.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($req['reason'])): ?>
        <p style="margin:0 0 12px; font-size:0.85rem; color:var(--text-secondary); font-style:italic;">
            &ldquo;<?php echo htmlspecialchars($req['reason']); ?>&rdquo;
        </p>
        <?php endif; ?>

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
                       placeholder="e.g. Verified, counts corrected."
                       style="padding:8px 10px; font-size:0.85rem;">
            </div>

            <button type="submit" name="action" value="approve"
                    class="btn-farm btn-green btn-sm"
                    style="padding:9px 18px; font-size:0.85rem; white-space:nowrap;"
                    onclick="return confirm('Approve this edit and apply all <?php echo count($changes); ?> change(s) to the record?')">
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