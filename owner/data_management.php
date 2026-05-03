<?php
/*
 * owner/data_management.php — Data Management (Owner Only)
 *
 * Features:
 * - Delete sales by date range OR all
 * - Delete harvests by date range OR all
 * - Delete batches (only if no harvests linked — safety guard)
 * - Confirmation modals showing exact record counts before deletion
 * - Full audit log entry for every destructive action
 */

$page_title = 'Data Management';

session_start();
include('../ includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header("Location: ../../portal/login.php");
    exit();
}

$owner_id = (int)$_SESSION['user_id'];
$message  = '';

// ── HANDLE DELETE ACTIONS ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── Delete Sales ──────────────────────────────────────────
    if ($action === 'delete_sales') {
        $mode     = $_POST['sales_mode'] ?? 'range';
        $from     = $_POST['sales_from'] ?? '';
        $to       = $_POST['sales_to']   ?? '';

        if ($mode === 'all') {
            $count_q = $conn->query("SELECT COUNT(*) AS c FROM sales");
            $count   = (int)$count_q->fetch_assoc()['c'];
            $conn->query("DELETE FROM sales");
            log_activity($conn, $owner_id, 'Owner', 'Data Deleted',
                "Deleted ALL {$count} sales record(s).");
            $message = "<div class='alert success'>✅ Deleted all {$count} sales record(s).</div>";
        } elseif ($from && $to) {
            $from_esc = $conn->real_escape_string($from);
            $to_esc   = $conn->real_escape_string($to);
            $count_q  = $conn->query("SELECT COUNT(*) AS c FROM sales WHERE DATE(date_sold) BETWEEN '$from_esc' AND '$to_esc'");
            $count    = (int)$count_q->fetch_assoc()['c'];
            $conn->query("DELETE FROM sales WHERE DATE(date_sold) BETWEEN '$from_esc' AND '$to_esc'");
            log_activity($conn, $owner_id, 'Owner', 'Data Deleted',
                "Deleted {$count} sales record(s) from {$from} to {$to}.");
            $message = "<div class='alert success'>✅ Deleted {$count} sales record(s) between {$from} and {$to}.</div>";
        } else {
            $message = "<div class='alert error'>⚠️ Please select a valid date range.</div>";
        }
    }

    // ── Delete Harvests ───────────────────────────────────────
    elseif ($action === 'delete_harvests') {
        $mode = $_POST['harvest_mode'] ?? 'range';
        $from = $_POST['harvest_from'] ?? '';
        $to   = $_POST['harvest_to']   ?? '';

        if ($mode === 'all') {
            $count_q = $conn->query("SELECT COUNT(*) AS c FROM harvests");
            $count   = (int)$count_q->fetch_assoc()['c'];
            $conn->query("DELETE FROM harvests");
            log_activity($conn, $owner_id, 'Owner', 'Data Deleted',
                "Deleted ALL {$count} harvest record(s).");
            $message = "<div class='alert success'>✅ Deleted all {$count} harvest record(s).</div>";
        } elseif ($from && $to) {
            $from_esc = $conn->real_escape_string($from);
            $to_esc   = $conn->real_escape_string($to);
            $count_q  = $conn->query("SELECT COUNT(*) AS c FROM harvests WHERE DATE(date_logged) BETWEEN '$from_esc' AND '$to_esc'");
            $count    = (int)$count_q->fetch_assoc()['c'];
            $conn->query("DELETE FROM harvests WHERE DATE(date_logged) BETWEEN '$from_esc' AND '$to_esc'");
            log_activity($conn, $owner_id, 'Owner', 'Data Deleted',
                "Deleted {$count} harvest record(s) from {$from} to {$to}.");
            $message = "<div class='alert success'>✅ Deleted {$count} harvest record(s) between {$from} and {$to}.</div>";
        } else {
            $message = "<div class='alert error'>⚠️ Please select a valid date range.</div>";
        }
    }

    // ── Delete Batch ──────────────────────────────────────────
    elseif ($action === 'delete_batch') {
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        if ($batch_id > 0) {
            // Safety guard — block if harvests exist
            $hcheck = $conn->query("SELECT COUNT(*) AS c FROM harvests WHERE batch_id = $batch_id");
            $hcount = (int)$hcheck->fetch_assoc()['c'];
            if ($hcount > 0) {
                $message = "<div class='alert error'>⚠️ Cannot delete Batch #{$batch_id} — it has {$hcount} harvest record(s) linked. Delete its harvests first.</div>";
            } else {
                $bq    = $conn->query("SELECT breed, coop_number FROM batches WHERE batch_id = $batch_id");
                $binfo = $bq ? $bq->fetch_assoc() : [];
                $conn->query("DELETE FROM batches WHERE batch_id = $batch_id");
                log_activity($conn, $owner_id, 'Owner', 'Data Deleted',
                    "Deleted Batch #{$batch_id} ({$binfo['breed']}, Coop {$binfo['coop_number']}).");
                $message = "<div class='alert success'>✅ Batch #{$batch_id} deleted successfully.</div>";
            }
        }
    }

    // ── Fix NULL batch_id in sales ────────────────────────────
    elseif ($action === 'fix_null_batch') {
        $assign_batch = (int)($_POST['assign_batch_id'] ?? 0);
        if ($assign_batch > 0) {
            $count_q = $conn->query("SELECT COUNT(*) AS c FROM sales WHERE batch_id IS NULL");
            $count   = (int)$count_q->fetch_assoc()['c'];
            $conn->query("UPDATE sales SET batch_id = $assign_batch WHERE batch_id IS NULL");
            log_activity($conn, $owner_id, 'Owner', 'Data Fixed',
                "Assigned {$count} unlinked sale(s) to Batch #{$assign_batch}.");
            $message = "<div class='alert success'>✅ Assigned {$count} unlinked sale(s) to Batch #{$assign_batch}.</div>";
        } else {
            $message = "<div class='alert error'>⚠️ Please select a batch to assign to.</div>";
        }
    }
}

// ── FETCH SUMMARY COUNTS ──────────────────────────────────────
$sales_count   = (int)$conn->query("SELECT COUNT(*) AS c FROM sales")->fetch_assoc()['c'];
$harvest_count = (int)$conn->query("SELECT COUNT(*) AS c FROM harvests")->fetch_assoc()['c'];
$batch_count   = (int)$conn->query("SELECT COUNT(*) AS c FROM batches")->fetch_assoc()['c'];
$null_sales    = (int)$conn->query("SELECT COUNT(*) AS c FROM sales WHERE batch_id IS NULL")->fetch_assoc()['c'];

// Sales date range
$sales_range_q = $conn->query("SELECT MIN(DATE(date_sold)) AS mn, MAX(DATE(date_sold)) AS mx FROM sales");
$sales_range   = $sales_range_q ? $sales_range_q->fetch_assoc() : ['mn' => null, 'mx' => null];

// Harvest date range
$harv_range_q  = $conn->query("SELECT MIN(DATE(date_logged)) AS mn, MAX(DATE(date_logged)) AS mx FROM harvests");
$harv_range    = $harv_range_q ? $harv_range_q->fetch_assoc() : ['mn' => null, 'mx' => null];

// All batches with harvest count
$batches_q = $conn->query("
    SELECT b.batch_id, b.breed, b.coop_number, b.coop_label, b.status,
           COUNT(h.harvest_id) AS harvest_count
    FROM batches b
    LEFT JOIN harvests h ON h.batch_id = b.batch_id
    GROUP BY b.batch_id
    ORDER BY b.coop_number ASC, b.batch_id ASC
");
$all_batches = [];
if ($batches_q) {
    while ($row = $batches_q->fetch_assoc()) {
        $all_batches[] = $row;
    }
}

// Recent deletion audit log
$audit_q = $conn->query("
    SELECT a.action_type, a.description, a.created_at, u.username
    FROM activity_log a
    JOIN users u ON a.user_id = u.user_id
    WHERE a.action_type IN ('Data Deleted', 'Data Fixed')
    ORDER BY a.created_at DESC
    LIMIT 10
");
$audit_log = [];
if ($audit_q) {
    while ($row = $audit_q->fetch_assoc()) {
        $audit_log[] = $row;
    }
}
?>

<div class="page-container">

    <div class="page-header">
        <div>
            <h2>Data Management</h2>
            <p>Delete records, fix data issues, manage batches.</p>
        </div>
        <a href="../dashboard.php" class="back-link" style="margin:0;">← Dashboard</a>
    </div>

    <?php echo $message; ?>

    <!-- NULL BATCH WARNING BANNER -->
    <?php if ($null_sales > 0): ?>
    <div style="background:rgba(212,144,10,0.1); border:1px solid rgba(212,144,10,0.35);
                border-left:5px solid var(--warning); border-radius:var(--radius);
                padding:16px 20px; margin-bottom:1.5rem;
                display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <div style="font-size:0.7rem; font-weight:700; color:var(--warning);
                        text-transform:uppercase; letter-spacing:0.7px; margin-bottom:4px;">
                ⚠️ Unlinked Sales Detected
            </div>
            <div style="font-size:0.92rem; font-weight:700; color:var(--text-primary);">
                <?php echo number_format($null_sales); ?> sale(s) have no batch linked
            </div>
            <div style="font-size:0.8rem; color:var(--text-muted); margin-top:3px;">
                These are old records recorded before coop tracking was added. Assign them to a batch below.
            </div>
        </div>
        <button class="btn-farm btn-orange btn-sm" onclick="openModal('fix-null-modal')">
            🔧 Fix Now
        </button>
    </div>
    <?php endif; ?>

    <!-- SUMMARY STAT CARDS -->
    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:2rem;">
        <div class="stat-card" style="border-top:3px solid var(--gold);">
            <div class="stat-label">Sales Records</div>
            <div class="stat-value"><?php echo number_format($sales_count); ?></div>
            <div class="stat-sub">
                <?php if ($sales_range['mn']): ?>
                    <?php echo date('M d, Y', strtotime($sales_range['mn'])); ?>
                    — <?php echo date('M d, Y', strtotime($sales_range['mx'])); ?>
                <?php else: ?>
                    No records
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card" style="border-top:3px solid var(--terra-lt);">
            <div class="stat-label">Harvest Records</div>
            <div class="stat-value"><?php echo number_format($harvest_count); ?></div>
            <div class="stat-sub">
                <?php if ($harv_range['mn']): ?>
                    <?php echo date('M d, Y', strtotime($harv_range['mn'])); ?>
                    — <?php echo date('M d, Y', strtotime($harv_range['mx'])); ?>
                <?php else: ?>
                    No records
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-card" style="border-top:3px solid var(--success);">
            <div class="stat-label">Total Batches</div>
            <div class="stat-value"><?php echo number_format($batch_count); ?></div>
            <div class="stat-sub">across all statuses</div>
        </div>
    </div>

    <!-- ── SALES ─────────────────────────────────────────────── -->
    <div class="card dm-card" style="border-top:4px solid var(--gold); margin-bottom:1.5rem;">
        <div class="dm-card__header">
            <div>
                <div class="dm-card__icon">💰</div>
                <div>
                    <h3 style="margin:0;">Sales Records</h3>
                    <p style="margin:4px 0 0; font-size:0.8rem; color:var(--text-muted);">
                        <?php echo number_format($sales_count); ?> total records
                        <?php if ($null_sales > 0): ?>
                            &nbsp;·&nbsp;
                            <span style="color:var(--warning);"><?php echo $null_sales; ?> unlinked</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="dm-card__body">
            <!-- Date range delete -->
            <div class="dm-section">
                <div class="dm-section__label">Delete by Date Range</div>
                <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                    <div class="form-group" style="margin:0; flex:1; min-width:140px;">
                        <label style="font-size:0.75rem;">From</label>
                        <input type="date" id="sales_from" class="form-input"
                               value="<?php echo $sales_range['mn'] ?? ''; ?>">
                    </div>
                    <div class="form-group" style="margin:0; flex:1; min-width:140px;">
                        <label style="font-size:0.75rem;">To</label>
                        <input type="date" id="sales_to" class="form-input"
                               value="<?php echo $sales_range['mx'] ?? ''; ?>">
                    </div>
                    <button class="btn-farm btn-danger btn-sm"
                            onclick="previewDelete('sales','range')"
                            style="white-space:nowrap;">
                        🗑 Delete Range
                    </button>
                </div>
            </div>

            <div class="dm-divider">or</div>

            <!-- Delete all -->
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:0.85rem; color:var(--text-muted);">
                    Remove every sales record from the database permanently.
                </div>
                <button class="btn-farm btn-danger btn-sm"
                        onclick="previewDelete('sales','all')">
                    🗑 Delete All Sales
                </button>
            </div>
        </div>
    </div>

    <!-- ── HARVESTS ───────────────────────────────────────────── -->
    <div class="card dm-card" style="border-top:4px solid var(--terra-lt); margin-bottom:1.5rem;">
        <div class="dm-card__header">
            <div>
                <div class="dm-card__icon">🧺</div>
                <div>
                    <h3 style="margin:0;">Harvest Records</h3>
                    <p style="margin:4px 0 0; font-size:0.8rem; color:var(--text-muted);">
                        <?php echo number_format($harvest_count); ?> total records
                    </p>
                </div>
            </div>
        </div>

        <div class="dm-card__body">
            <div class="dm-section">
                <div class="dm-section__label">Delete by Date Range</div>
                <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                    <div class="form-group" style="margin:0; flex:1; min-width:140px;">
                        <label style="font-size:0.75rem;">From</label>
                        <input type="date" id="harvest_from" class="form-input"
                               value="<?php echo $harv_range['mn'] ?? ''; ?>">
                    </div>
                    <div class="form-group" style="margin:0; flex:1; min-width:140px;">
                        <label style="font-size:0.75rem;">To</label>
                        <input type="date" id="harvest_to" class="form-input"
                               value="<?php echo $harv_range['mx'] ?? ''; ?>">
                    </div>
                    <button class="btn-farm btn-danger btn-sm"
                            onclick="previewDelete('harvests','range')"
                            style="white-space:nowrap;">
                        🗑 Delete Range
                    </button>
                </div>
            </div>

            <div class="dm-divider">or</div>

            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div style="font-size:0.85rem; color:var(--text-muted);">
                    Remove every harvest record from the database permanently.
                </div>
                <button class="btn-farm btn-danger btn-sm"
                        onclick="previewDelete('harvests','all')">
                    🗑 Delete All Harvests
                </button>
            </div>
        </div>
    </div>

    <!-- ── BATCHES ────────────────────────────────────────────── -->
    <div class="card card--table" style="border-top:4px solid var(--success); margin-bottom:1.5rem;">
        <div class="card__header" style="display:flex; justify-content:space-between; align-items:center;">
            <div>
                <h3>Batches</h3>
                <p class="card__subtext">
                    A batch can only be deleted if it has no harvest records linked.
                </p>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Breed</th>
                        <th>Coop</th>
                        <th>Status</th>
                        <th class="col-center">Harvests</th>
                        <th class="col-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_batches as $b):
                        $can_delete = ((int)$b['harvest_count'] === 0);
                        $coop_disp  = !empty($b['coop_label'])
                                      ? $b['coop_label']
                                      : ($b['coop_number'] ? 'Coop ' . $b['coop_number'] : '—');
                    ?>
                    <tr>
                        <td><strong>#<?php echo $b['batch_id']; ?></strong></td>
                        <td class="text-secondary"><?php echo htmlspecialchars($b['breed']); ?></td>
                        <td><?php echo htmlspecialchars($coop_disp); ?></td>
                        <td>
                            <span class="badge <?php echo $b['status'] === 'Active' ? 'badge-healthy' : 'badge-pending'; ?>">
                                <?php echo $b['status']; ?>
                            </span>
                        </td>
                        <td class="col-center">
                            <?php if ((int)$b['harvest_count'] > 0): ?>
                                <span style="color:var(--warning); font-weight:700;">
                                    <?php echo $b['harvest_count']; ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-center">
                            <?php if ($can_delete): ?>
                                <button class="btn-farm btn-danger btn-sm"
                                        onclick="confirmBatchDelete(<?php echo $b['batch_id']; ?>, '<?php echo addslashes(htmlspecialchars($b['breed'])); ?>', '<?php echo addslashes(htmlspecialchars($coop_disp)); ?>')">
                                    🗑 Delete
                                </button>
                            <?php else: ?>
                                <span style="font-size:0.75rem; color:var(--text-muted);">
                                    🔒 Has harvests
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── AUDIT LOG ─────────────────────────────────────────── -->
    <?php if (!empty($audit_log)): ?>
    <div class="card card--table" style="margin-bottom:1.5rem;">
        <div class="card__header">
            <h3>Recent Deletions</h3>
            <p class="card__subtext">Last 10 destructive actions by the owner.</p>
        </div>
        <div class="table-wrapper">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>By</th>
                        <th>Action</th>
                        <th>Detail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit_log as $log): ?>
                    <tr>
                        <td class="text-muted text-sm">
                            <?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?>
                        </td>
                        <td><strong><?php echo htmlspecialchars($log['username']); ?></strong></td>
                        <td>
                            <span class="badge badge-critical" style="font-size:0.65rem;">
                                <?php echo htmlspecialchars($log['action_type']); ?>
                            </span>
                        </td>
                        <td class="text-sm text-secondary">
                            <?php echo htmlspecialchars($log['description']); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
</div>

<!-- ── MODALS ──────────────────────────────────────────────────── -->

<!-- Overlay -->
<div id="modal-overlay" class="modal-overlay" onclick="closeAllModals()"></div>

<!-- Sales / Harvest delete confirmation modal -->
<div id="delete-confirm-modal" class="modal">
    <h3 class="modal__title" id="modal-title">Confirm Deletion</h3>
    <p class="modal__subtitle" id="modal-subtitle"></p>

    <div style="background:var(--danger-bg); border:1px solid rgba(194,58,58,0.3);
                border-left:4px solid var(--danger); border-radius:var(--radius);
                padding:14px 18px; margin-bottom:1.4rem;">
        <div style="font-size:0.7rem; font-weight:700; color:var(--danger);
                    text-transform:uppercase; letter-spacing:0.7px; margin-bottom:6px;">
            Records to be deleted
        </div>
        <div id="modal-count"
             style="font-size:2rem; font-weight:800; color:var(--danger);
                    font-family:'Playfair Display',serif; line-height:1;">—</div>
        <div id="modal-count-label"
             style="font-size:0.78rem; color:var(--text-muted); margin-top:4px;"></div>
    </div>

    <div style="background:var(--bg-wood); border-radius:var(--radius);
                padding:12px 16px; margin-bottom:1.4rem;
                font-size:0.82rem; color:var(--text-muted); line-height:1.6;">
        ⚠️ This action is <strong style="color:var(--text-primary);">permanent and cannot be undone.</strong>
        A record of this deletion will be saved to the audit log.
    </div>

    <form method="POST" id="delete-form">
        <input type="hidden" name="action"       id="form-action">
        <input type="hidden" name="sales_mode"   id="form-sales-mode">
        <input type="hidden" name="sales_from"   id="form-sales-from">
        <input type="hidden" name="sales_to"     id="form-sales-to">
        <input type="hidden" name="harvest_mode" id="form-harvest-mode">
        <input type="hidden" name="harvest_from" id="form-harvest-from">
        <input type="hidden" name="harvest_to"   id="form-harvest-to">

        <div style="display:flex; gap:10px;">
            <button type="submit" id="modal-confirm-btn" class="btn-farm btn-danger"
                    style="flex:1; padding:13px;">
                Yes, Delete Permanently
            </button>
            <button type="button" class="btn-farm btn-dark"
                    onclick="closeAllModals()" style="padding:13px; min-width:90px;">
                Cancel
            </button>
        </div>
    </form>
</div>

<!-- Batch delete confirmation modal -->
<div id="batch-delete-modal" class="modal">
    <h3 class="modal__title">Delete Batch</h3>
    <p class="modal__subtitle">This batch has no harvest records and can be safely removed.</p>

    <div style="background:var(--bg-wood); border-radius:var(--radius);
                border-left:4px solid var(--danger); padding:14px 18px; margin-bottom:1.4rem;">
        <div style="font-size:0.65rem; font-weight:700; color:var(--text-muted);
                    text-transform:uppercase; letter-spacing:0.6px; margin-bottom:4px;">Batch to delete</div>
        <div id="batch-delete-label"
             style="font-size:1rem; font-weight:700; color:var(--text-primary);">—</div>
    </div>

    <div style="background:var(--bg-wood); border-radius:var(--radius);
                padding:12px 16px; margin-bottom:1.4rem;
                font-size:0.82rem; color:var(--text-muted); line-height:1.6;">
        ⚠️ This action is <strong style="color:var(--text-primary);">permanent.</strong>
        The batch record will be removed from the system.
    </div>

    <form method="POST">
        <input type="hidden" name="action"    value="delete_batch">
        <input type="hidden" name="batch_id"  id="batch-delete-id">
        <div style="display:flex; gap:10px;">
            <button type="submit" class="btn-farm btn-danger" style="flex:1; padding:13px;">
                Yes, Delete Batch
            </button>
            <button type="button" class="btn-farm btn-dark"
                    onclick="closeAllModals()" style="padding:13px; min-width:90px;">
                Cancel
            </button>
        </div>
    </form>
</div>

<!-- Fix NULL batch_id modal -->
<div id="fix-null-modal" class="modal">
    <h3 class="modal__title">Fix Unlinked Sales</h3>
    <p class="modal__subtitle">
        <?php echo number_format($null_sales); ?> sale(s) have no batch linked.
        Assign them all to a batch so inventory calculations are accurate.
    </p>

    <div style="background:var(--bg-wood); border-radius:var(--radius);
                padding:12px 16px; margin-bottom:1.2rem;
                font-size:0.82rem; color:var(--text-muted); line-height:1.6;">
        💡 Assign to whichever batch was active when these sales were recorded.
        If you're not sure, pick the oldest active batch.
    </div>

    <form method="POST">
        <input type="hidden" name="action" value="fix_null_batch">
        <div class="form-group">
            <label>Assign unlinked sales to:</label>
            <select name="assign_batch_id" class="form-input" required>
                <option value="">— Select batch —</option>
                <?php foreach ($all_batches as $b):
                    $lbl = !empty($b['coop_label'])
                           ? $b['coop_label']
                           : ($b['coop_number'] ? 'Coop ' . $b['coop_number'] : 'Batch #' . $b['batch_id']);
                ?>
                <option value="<?php echo $b['batch_id']; ?>">
                    <?php echo htmlspecialchars($lbl . ' — ' . $b['breed']); ?>
                    (<?php echo $b['status']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex; gap:10px; margin-top:1rem;">
            <button type="submit" class="btn-farm btn-orange" style="flex:1; padding:13px;">
                🔧 Assign & Fix
            </button>
            <button type="button" class="btn-farm btn-dark"
                    onclick="closeAllModals()" style="padding:13px; min-width:90px;">
                Cancel
            </button>
        </div>
    </form>
</div>

<style>
.page-container      { max-width:900px; margin:2rem auto; }
.dm-card             { padding:0; overflow:hidden; }
.dm-card__header     { display:flex; align-items:center; gap:14px;
                       padding:1.1rem 1.4rem; border-bottom:1px solid var(--border-subtle); }
.dm-card__header > div { display:flex; align-items:center; gap:14px; }
.dm-card__icon       { font-size:1.6rem; line-height:1; flex-shrink:0; }
.dm-card__body       { padding:1.2rem 1.4rem; }
.dm-section          { margin-bottom:1rem; }
.dm-section__label   { font-size:0.72rem; font-weight:700; color:var(--text-muted);
                       text-transform:uppercase; letter-spacing:0.7px; margin-bottom:10px; }
.dm-divider          { text-align:center; font-size:0.75rem; color:var(--text-muted);
                       margin:1rem 0; position:relative; }
.dm-divider::before  { content:''; position:absolute; top:50%; left:0; right:0;
                       height:1px; background:var(--border-subtle); }
.dm-divider          { position:relative; }
.dm-divider span,
.dm-divider          { background:transparent; }

/* reuse existing modal styles */
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:999; }
.modal         { display:none; position:fixed; top:50%; left:50%;
                 transform:translate(-50%,-50%); z-index:1000;
                 width:min(520px,95vw); background:var(--bg-soil);
                 border:1px solid var(--border-mid); border-top:4px solid var(--danger);
                 border-radius:var(--radius-lg); padding:1.8rem;
                 box-shadow:var(--shadow-raised); }
.modal__title    { color:var(--gold); font-family:'Playfair Display',serif; margin-bottom:0.3rem; }
.modal__subtitle { font-size:0.83rem; color:var(--text-muted); margin-bottom:1.4rem; line-height:1.6; }

.col-center  { text-align:center; }
.text-sm     { font-size:0.85rem; }
.text-muted  { color:var(--text-muted); }
.text-secondary { color:var(--text-secondary); }
.card--table { padding:0; overflow:hidden; margin-bottom:24px; }
.card__header { padding:1.2rem 1.6rem; border-bottom:1px solid var(--border-subtle); }
.card__header h3 { margin:0; }
.card__subtext { font-size:0.78rem; color:var(--text-muted); margin-top:4px; margin-bottom:0; }
</style>

<script>
// Count records via fetch before showing modal
async function previewDelete(type, mode) {
    let from = '', to = '', url = '';

    if (type === 'sales') {
        from = document.getElementById('sales_from').value;
        to   = document.getElementById('sales_to').value;
        if (mode === 'range' && (!from || !to)) {
            alert('Please select both a From and To date.');
            return;
        }
    } else {
        from = document.getElementById('harvest_from').value;
        to   = document.getElementById('harvest_to').value;
        if (mode === 'range' && (!from || !to)) {
            alert('Please select both a From and To date.');
            return;
        }
    }

    // Build count query via inline PHP endpoint
    const params = new URLSearchParams({ type, mode, from, to });
    const resp   = await fetch('data_management_count.php?' + params);
    const data   = await resp.json().catch(() => ({ count: '?' }));
    const count  = data.count;

    // Populate modal
    const isAll    = mode === 'all';
    const typeName = type === 'sales' ? 'Sales' : 'Harvest';
    document.getElementById('modal-title').textContent    = `Delete ${typeName} Records`;
    document.getElementById('modal-subtitle').textContent = isAll
        ? `You are about to delete ALL ${typeName.toLowerCase()} records.`
        : `You are about to delete ${typeName.toLowerCase()} records from ${from} to ${to}.`;
    document.getElementById('modal-count').textContent       = count;
    document.getElementById('modal-count-label').textContent = `${typeName.toLowerCase()} record(s) will be permanently deleted`;

    // Set hidden form fields
    if (type === 'sales') {
        document.getElementById('form-action').value      = 'delete_sales';
        document.getElementById('form-sales-mode').value = mode;
        document.getElementById('form-sales-from').value = from;
        document.getElementById('form-sales-to').value   = to;
        document.getElementById('form-harvest-mode').value = '';
    } else {
        document.getElementById('form-action').value        = 'delete_harvests';
        document.getElementById('form-harvest-mode').value  = mode;
        document.getElementById('form-harvest-from').value  = from;
        document.getElementById('form-harvest-to').value    = to;
        document.getElementById('form-sales-mode').value    = '';
    }

    openModal('delete-confirm-modal');
}

function confirmBatchDelete(batchId, breed, coopLabel) {
    document.getElementById('batch-delete-id').value      = batchId;
    document.getElementById('batch-delete-label').textContent =
        `Batch #${batchId} — ${coopLabel} — ${breed}`;
    openModal('batch-delete-modal');
}

function openModal(id) {
    document.getElementById('modal-overlay').style.display = 'block';
    document.getElementById(id).style.display = 'block';
}

function closeAllModals() {
    document.getElementById('modal-overlay').style.display = 'none';
    document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
}
</script>

<?php include('../../includes/footer.php'); ?>