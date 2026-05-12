<?php
$page_title = 'Data Management';

session_start();
include('../../includes/db.php');
include('../../includes/header.php');
include('../../includes/log_activity.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header("Location: ../../portal/login.php");
    exit();
}

$owner_id = (int)$_SESSION['user_id'];
$message  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'delete_sales') {
        $mode = $_POST['sales_mode'] ?? 'range';
        $from = $_POST['sales_from'] ?? '';
        $to   = $_POST['sales_to']   ?? '';
        if ($mode === 'all') {
            $count_q = $conn->query("SELECT COUNT(*) AS c FROM sales");
            $count   = (int)$count_q->fetch_assoc()['c'];
            $conn->query("DELETE FROM sales");
            log_activity($conn, $owner_id, 'Owner', 'Data Deleted', "Deleted ALL {$count} sales record(s).");
            $message = "<div class='alert success'>Deleted all {$count} sales records.</div>";
        } elseif ($from && $to) {
            $fe = $conn->real_escape_string($from);
            $te = $conn->real_escape_string($to);
            $count_q = $conn->query("SELECT COUNT(*) AS c FROM sales WHERE DATE(date_sold) BETWEEN '$fe' AND '$te'");
            $count   = (int)$count_q->fetch_assoc()['c'];
            $conn->query("DELETE FROM sales WHERE DATE(date_sold) BETWEEN '$fe' AND '$te'");
            log_activity($conn, $owner_id, 'Owner', 'Data Deleted', "Deleted {$count} sales record(s) from {$from} to {$to}.");
            $message = "<div class='alert success'>Deleted {$count} sales records ({$from} to {$to}).</div>";
        } else {
            $message = "<div class='alert error'>Select a valid date range.</div>";
        }
    }
    elseif ($action === 'delete_harvests') {
        $mode = $_POST['harvest_mode'] ?? 'range';
        $from = $_POST['harvest_from'] ?? '';
        $to   = $_POST['harvest_to']   ?? '';
        if ($mode === 'all') {
            $count_q = $conn->query("SELECT COUNT(*) AS c FROM harvests");
            $count   = (int)$count_q->fetch_assoc()['c'];
            $conn->query("DELETE FROM harvests");
            log_activity($conn, $owner_id, 'Owner', 'Data Deleted', "Deleted ALL {$count} harvest record(s).");
            $message = "<div class='alert success'>Deleted all {$count} harvest records.</div>";
        } elseif ($from && $to) {
            $fe = $conn->real_escape_string($from);
            $te = $conn->real_escape_string($to);
            $count_q = $conn->query("SELECT COUNT(*) AS c FROM harvests WHERE DATE(date_logged) BETWEEN '$fe' AND '$te'");
            $count   = (int)$count_q->fetch_assoc()['c'];
            $conn->query("DELETE FROM harvests WHERE DATE(date_logged) BETWEEN '$fe' AND '$te'");
            log_activity($conn, $owner_id, 'Owner', 'Data Deleted', "Deleted {$count} harvest record(s) from {$from} to {$to}.");
            $message = "<div class='alert success'>Deleted {$count} harvest records ({$from} to {$to}).</div>";
        } else {
            $message = "<div class='alert error'>Select a valid date range.</div>";
        }
    }
    elseif ($action === 'delete_batch') {
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        if ($batch_id > 0) {
            $hcheck = $conn->query("SELECT COUNT(*) AS c FROM harvests WHERE batch_id = $batch_id");
            $hcount = (int)$hcheck->fetch_assoc()['c'];
            if ($hcount > 0) {
                $message = "<div class='alert error'>Batch #{$batch_id} has {$hcount} harvest record(s). Delete harvests first.</div>";
            } else {
                $bq    = $conn->query("SELECT breed, coop_number FROM batches WHERE batch_id = $batch_id");
                $binfo = ($bq && $bq->num_rows > 0) ? $bq->fetch_assoc() : ['breed' => 'Unknown', 'coop_number' => '?'];
                $conn->query("DELETE FROM batches WHERE batch_id = $batch_id");
                log_activity($conn, $owner_id, 'Owner', 'Data Deleted', "Deleted Batch #{$batch_id} ({$binfo['breed']}, Coop {$binfo['coop_number']}).");
                $message = "<div class='alert success'>Batch #{$batch_id} deleted.</div>";
            }
        }
    }
    elseif ($action === 'fix_null_batch') {
        $assign_batch = (int)($_POST['assign_batch_id'] ?? 0);
        if ($assign_batch > 0) {
            $count_q = $conn->query("SELECT COUNT(*) AS c FROM sales WHERE batch_id IS NULL");
            $count   = (int)$count_q->fetch_assoc()['c'];
            $conn->query("UPDATE sales SET batch_id = $assign_batch WHERE batch_id IS NULL");
            log_activity($conn, $owner_id, 'Owner', 'Data Fixed', "Assigned {$count} unlinked sale(s) to Batch #{$assign_batch}.");
            $message = "<div class='alert success'>Assigned {$count} unlinked sale(s) to Batch #{$assign_batch}.</div>";
        } else {
            $message = "<div class='alert error'>Select a batch to assign to.</div>";
        }
    }
}

$sales_count   = (int)$conn->query("SELECT COUNT(*) AS c FROM sales")->fetch_assoc()['c'];
$harvest_count = (int)$conn->query("SELECT COUNT(*) AS c FROM harvests")->fetch_assoc()['c'];
$batch_count   = (int)$conn->query("SELECT COUNT(*) AS c FROM batches")->fetch_assoc()['c'];
$null_sales    = (int)$conn->query("SELECT COUNT(*) AS c FROM sales WHERE batch_id IS NULL")->fetch_assoc()['c'];

$sales_range_q = $conn->query("SELECT MIN(DATE(date_sold)) AS mn, MAX(DATE(date_sold)) AS mx FROM sales");
$sales_range   = $sales_range_q ? $sales_range_q->fetch_assoc() : ['mn'=>null,'mx'=>null];

$harv_range_q  = $conn->query("SELECT MIN(DATE(date_logged)) AS mn, MAX(DATE(date_logged)) AS mx FROM harvests");
$harv_range    = $harv_range_q ? $harv_range_q->fetch_assoc() : ['mn'=>null,'mx'=>null];

$batches_q = $conn->query("
    SELECT b.batch_id, b.breed, b.coop_number, b.coop_label, b.status,
           COUNT(h.harvest_id) AS harvest_count
    FROM batches b
    LEFT JOIN harvests h ON h.batch_id = b.batch_id
    GROUP BY b.batch_id
    ORDER BY b.coop_number ASC, b.batch_id ASC
");
$all_batches = [];
if ($batches_q) while ($row = $batches_q->fetch_assoc()) $all_batches[] = $row;

$audit_q = $conn->query("
    SELECT a.action_type, a.description, a.timestamp, u.username
    FROM activity_logs a
    JOIN users u ON a.user_id = u.user_id
    WHERE a.action_type IN ('Data Deleted','Data Fixed')
    ORDER BY a.timestamp DESC
    LIMIT 10
");
$audit_log = [];
if ($audit_q) while ($row = $audit_q->fetch_assoc()) $audit_log[] = $row;
?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script>
<style>
.dm-wrap{max-width:860px;margin:0 auto}
.dm-page-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.8rem}
.dm-page-header h2{margin:0 0 4px;font-family:'Playfair Display',serif;font-size:1.5rem}
.dm-page-header p{margin:0;font-size:0.82rem;color:var(--text-muted)}
.dm-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:1.6rem}
.dm-stat{background:var(--bg-wood);border:1px solid var(--border-subtle);border-radius:var(--radius);padding:14px 18px}
.dm-stat__val{font-size:1.6rem;font-weight:800;color:var(--text-primary);font-family:'Playfair Display',serif;line-height:1;margin-bottom:4px}
.dm-stat__label{font-size:0.7rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.7px}
.dm-stat__sub{font-size:0.72rem;color:var(--text-muted);margin-top:4px}
.dm-banner{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;background:rgba(212,144,10,0.08);border:1px solid rgba(212,144,10,0.3);border-left:4px solid var(--warning);border-radius:var(--radius);padding:14px 18px;margin-bottom:1.4rem}
.dm-banner__title{font-size:0.72rem;font-weight:700;color:var(--warning);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:3px}
.dm-banner__body{font-size:0.88rem;font-weight:600;color:var(--text-primary)}
.dm-banner__sub{font-size:0.75rem;color:var(--text-muted);margin-top:2px}
.dm-card{background:var(--bg-soil);border:1px solid var(--border-subtle);border-radius:var(--radius);overflow:hidden;margin-bottom:1.2rem}
.dm-card__head{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid var(--border-subtle)}
.dm-card__title{display:flex;align-items:center;gap:9px;font-size:0.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.8px}
.dm-card__count{font-size:0.72rem;color:var(--text-muted);background:var(--bg-wood);border:1px solid var(--border-subtle);border-radius:20px;padding:2px 10px}
.dm-card__body{padding:18px}
.dm-row{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap}
.dm-row .form-group{margin:0;flex:1;min-width:130px}
.dm-row label{font-size:0.7rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:5px}
.dm-sep{display:flex;align-items:center;gap:10px;margin:14px 0;color:var(--text-muted);font-size:0.7rem;text-transform:uppercase;letter-spacing:0.6px}
.dm-sep::before,.dm-sep::after{content:'';flex:1;height:1px;background:var(--border-subtle)}
.dm-all-row{display:flex;justify-content:space-between;align-items:center}
.dm-all-row span{font-size:0.82rem;color:var(--text-muted)}
.btn-icon{display:inline-flex;align-items:center;gap:7px;white-space:nowrap}
.dm-table-wrap{overflow-x:auto}
.dm-table{width:100%;border-collapse:collapse;font-size:0.83rem}
.dm-table th{padding:9px 14px;text-align:left;font-size:0.68rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.7px;border-bottom:1px solid var(--border-subtle);white-space:nowrap}
.dm-table td{padding:10px 14px;border-bottom:1px solid var(--border-subtle);color:var(--text-secondary);vertical-align:middle}
.dm-table tr:last-child td{border-bottom:none}
.dm-table tr:hover td{background:var(--bg-wood)}
.dm-table .col-c{text-align:center}
.dm-locked{display:inline-flex;align-items:center;gap:5px;font-size:0.72rem;color:var(--text-muted)}
.dm-audit-row{display:flex;gap:14px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--border-subtle)}
.dm-audit-row:last-child{border-bottom:none}
.dm-audit-time{font-size:0.72rem;color:var(--text-muted);white-space:nowrap;min-width:100px;padding-top:2px}
.dm-audit-user{font-size:0.78rem;font-weight:700;color:var(--text-primary);margin-bottom:2px}
.dm-audit-desc{font-size:0.78rem;color:var(--text-muted);line-height:1.5}
.dm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.72);z-index:999}
.dm-modal{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:1000;width:min(500px,94vw);background:var(--bg-soil);border:1px solid var(--border-mid);border-top:3px solid var(--danger);border-radius:var(--radius-lg);padding:1.6rem;box-shadow:var(--shadow-raised)}
.dm-modal h3{font-family:'Playfair Display',serif;color:var(--text-primary);margin:0 0 6px;font-size:1.1rem}
.dm-modal p{font-size:0.82rem;color:var(--text-muted);margin:0 0 1.2rem;line-height:1.6}
.dm-count-box{background:var(--bg-wood);border:1px solid rgba(194,58,58,0.25);border-left:3px solid var(--danger);border-radius:var(--radius);padding:14px 18px;margin-bottom:1.2rem}
.dm-count-box__n{font-size:2rem;font-weight:800;color:var(--danger);font-family:'Playfair Display',serif;line-height:1}
.dm-count-box__l{font-size:0.75rem;color:var(--text-muted);margin-top:3px}
.dm-warn-box{background:var(--bg-wood);border-radius:var(--radius);padding:10px 14px;margin-bottom:1.2rem;font-size:0.8rem;color:var(--text-muted);line-height:1.6}
.dm-warn-box strong{color:var(--text-primary)}
.dm-modal-actions{display:flex;gap:8px}
.dm-modal-actions button{flex:1;padding:11px}
</style>

<div class="dm-wrap">
    <div class="dm-page-header">
        <div>
            <h2>Data Management</h2>
            <p>Delete records and manage batches. All actions are logged.</p>
        </div>
    </div>

    <?php echo $message; ?>

    <?php if ($null_sales > 0): ?>
    <div class="dm-banner">
        <div>
            <div class="dm-banner__title">Unlinked Sales</div>
            <div class="dm-banner__body"><?php echo number_format($null_sales); ?> sale(s) have no batch assigned</div>
            <div class="dm-banner__sub">Recorded before coop tracking was added.</div>
        </div>
        <button class="btn-farm btn-orange btn-icon btn-sm" onclick="openModal('fix-null-modal')" style="padding:8px 14px;font-size:0.8rem;">
            <i data-lucide="wrench" style="width:14px;height:14px;"></i>
            Fix
        </button>
    </div>
    <?php endif; ?>

    <div class="dm-stats">
        <div class="dm-stat">
            <div class="dm-stat__val"><?php echo number_format($sales_count); ?></div>
            <div class="dm-stat__label">Sales</div>
            <div class="dm-stat__sub"><?php echo $sales_range['mn'] ? date('M d Y',strtotime($sales_range['mn'])).' — '.date('M d Y',strtotime($sales_range['mx'])) : 'No records'; ?></div>
        </div>
        <div class="dm-stat">
            <div class="dm-stat__val"><?php echo number_format($harvest_count); ?></div>
            <div class="dm-stat__label">Harvests</div>
            <div class="dm-stat__sub"><?php echo $harv_range['mn'] ? date('M d Y',strtotime($harv_range['mn'])).' — '.date('M d Y',strtotime($harv_range['mx'])) : 'No records'; ?></div>
        </div>
        <div class="dm-stat">
            <div class="dm-stat__val"><?php echo number_format($batch_count); ?></div>
            <div class="dm-stat__label">Batches</div>
            <div class="dm-stat__sub">all statuses</div>
        </div>
    </div>

    <!-- Sales -->
    <div class="dm-card" style="border-top:3px solid var(--gold);">
        <div class="dm-card__head">
            <div class="dm-card__title">
                <i data-lucide="dollar-sign" style="width:15px;height:15px;"></i>
                Sales
            </div>
            <span class="dm-card__count"><?php echo number_format($sales_count); ?> records</span>
        </div>
        <div class="dm-card__body">
            <div class="dm-row">
                <div class="form-group">
                    <label>From</label>
                    <input type="date" id="sales_from" class="form-input" value="<?php echo $sales_range['mn']??''; ?>">
                </div>
                <div class="form-group">
                    <label>To</label>
                    <input type="date" id="sales_to" class="form-input" value="<?php echo $sales_range['mx']??''; ?>">
                </div>
                <button class="btn-farm btn-danger btn-icon btn-sm" onclick="previewDelete('sales','range')" style="padding:9px 14px;font-size:0.8rem;margin-bottom:1px;">
                    <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                    
                </button>
            </div>
            <div class="dm-sep">or</div>
            <div class="dm-all-row">
                <span>Remove all sales records permanently</span>
                <button class="btn-farm btn-danger btn-icon btn-sm" onclick="previewDelete('sales','all')" style="padding:9px 14px;font-size:0.8rem;">
                    <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                    
                </button>
            </div>
        </div>
    </div>

    <!-- Harvests -->
    <div class="dm-card" style="border-top:3px solid var(--terra-lt);">
        <div class="dm-card__head">
            <div class="dm-card__title">
                <i data-lucide="package" style="width:15px;height:15px;"></i>
                Harvests
            </div>
            <span class="dm-card__count"><?php echo number_format($harvest_count); ?> records</span>
        </div>
        <div class="dm-card__body">
            <div class="dm-row">
                <div class="form-group">
                    <label>From</label>
                    <input type="date" id="harvest_from" class="form-input" value="<?php echo $harv_range['mn']??''; ?>">
                </div>
                <div class="form-group">
                    <label>To</label>
                    <input type="date" id="harvest_to" class="form-input" value="<?php echo $harv_range['mx']??''; ?>">
                </div>
                <button class="btn-farm btn-danger btn-icon btn-sm" onclick="previewDelete('harvests','range')" style="padding:9px 14px;font-size:0.8rem;margin-bottom:1px;">
                    <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                    
                </button>
            </div>
            <div class="dm-sep">or</div>
            <div class="dm-all-row">
                <span>Remove all harvest records permanently</span>
                <button class="btn-farm btn-danger btn-icon btn-sm" onclick="previewDelete('harvests','all')" style="padding:9px 14px;font-size:0.8rem;">
                    <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                    
                </button>
            </div>
        </div>
    </div>

    <!-- Batches -->
    <div class="dm-card" style="border-top:3px solid var(--success);">
        <div class="dm-card__head">
            <div class="dm-card__title">
                <i data-lucide="layers" style="width:15px;height:15px;"></i>
                Batches
            </div>
            <span class="dm-card__count"><?php echo number_format($batch_count); ?> total</span>
        </div>
        <div class="dm-table-wrap">
            <table class="dm-table">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Coop</th>
                        <th>Breed</th>
                        <th>Status</th>
                        <th class="col-c">Harvests</th>
                        <th class="col-c"><i data-lucide="trash-2" style="width:13px;height:13px;"></i></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all_batches as $b):
                    $can_delete = ((int)$b['harvest_count'] === 0);
                    $coop_disp  = !empty($b['coop_label']) ? $b['coop_label'] : ($b['coop_number'] ? 'Coop '.$b['coop_number'] : '—');
                ?>
                    <tr>
                        <td style="color:var(--text-primary);font-weight:600;">#<?php echo $b['batch_id']; ?></td>
                        <td><?php echo htmlspecialchars($coop_disp); ?></td>
                        <td><?php echo htmlspecialchars($b['breed']); ?></td>
                        <td><span class="badge <?php echo $b['status']==='Active'?'badge-healthy':'badge-pending'; ?>"><?php echo $b['status']; ?></span></td>
                        <td class="col-c" style="font-weight:<?php echo (int)$b['harvest_count']>0?'700':'400'; ?>;color:<?php echo (int)$b['harvest_count']>0?'var(--warning)':'var(--text-muted)'; ?>;">
                            <?php echo $b['harvest_count']; ?>
                        </td>
                        <td class="col-c">
                            <?php if ($can_delete): ?>
                            <button class="btn-farm btn-danger btn-icon btn-sm" style="padding:6px 12px;font-size:0.75rem;"
                                    onclick="confirmBatchDelete(<?php echo $b['batch_id']; ?>,'<?php echo addslashes(htmlspecialchars($b['breed'])); ?>','<?php echo addslashes(htmlspecialchars($coop_disp)); ?>')">
                                <i data-lucide="trash-2" style="width:13px;height:13px;"></i>
                                
                            </button>
                            <?php else: ?>
                            <span class="dm-locked">
                                <i data-lucide="lock" style="width:13px;height:13px;opacity:0.4;"></i>
                                Locked
                            </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Audit log -->
    <?php if (!empty($audit_log)): ?>
    <div class="dm-card">
        <div class="dm-card__head">
            <div class="dm-card__title">
                <i data-lucide="file-text" style="width:15px;height:15px;"></i>
                Deletion Log
            </div>
            <span class="dm-card__count">last 10</span>
        </div>
        <div class="dm-card__body" style="padding-top:8px;padding-bottom:8px;">
            <?php foreach ($audit_log as $log): ?>
            <div class="dm-audit-row">
                <div class="dm-audit-time">
                    <?php echo date('M d, Y', strtotime($log['timestamp'])); ?><br>
                    <span style="font-size:0.68rem;"><?php echo date('g:i A', strtotime($log['timestamp'])); ?></span>
                </div>
                <div>
                    <div class="dm-audit-user"><?php echo htmlspecialchars($log['username']); ?></div>
                    <div class="dm-audit-desc"><?php echo htmlspecialchars($log['description']); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="dm-overlay" id="dm-overlay" onclick="closeAllModals()"></div>

<!-- Delete confirm modal -->
<div class="dm-modal" id="delete-confirm-modal">
    <h3 id="modal-title">Confirm Deletion</h3>
    <p id="modal-subtitle"></p>
    <div class="dm-count-box">
        <div class="dm-count-box__n" id="modal-count">—</div>
        <div class="dm-count-box__l" id="modal-count-label"></div>
    </div>
    <div class="dm-warn-box">This action is <strong>permanent and cannot be undone.</strong> A record will be saved to the deletion log.</div>
    <form method="POST">
        <input type="hidden" name="action"       id="form-action">
        <input type="hidden" name="sales_mode"   id="form-sales-mode">
        <input type="hidden" name="sales_from"   id="form-sales-from">
        <input type="hidden" name="sales_to"     id="form-sales-to">
        <input type="hidden" name="harvest_mode" id="form-harvest-mode">
        <input type="hidden" name="harvest_from" id="form-harvest-from">
        <input type="hidden" name="harvest_to"   id="form-harvest-to">
        <div class="dm-modal-actions">
            <button type="submit" class="btn-farm btn-danger btn-icon">
                <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                Delete Permanently
            </button>
            <button type="button" class="btn-farm btn-dark" onclick="closeAllModals()">Cancel</button>
        </div>
    </form>
</div>

<!-- Batch delete modal -->
<div class="dm-modal" id="batch-delete-modal">
    <h3>Delete Batch</h3>
    <p>This batch has no linked harvests and can be removed.</p>
    <div class="dm-count-box">
        <div class="dm-count-box__n" style="font-size:1rem;" id="batch-delete-label">—</div>
    </div>
    <div class="dm-warn-box">This action is <strong>permanent.</strong></div>
    <form method="POST">
        <input type="hidden" name="action"   value="delete_batch">
        <input type="hidden" name="batch_id" id="batch-delete-id">
        <div class="dm-modal-actions">
            <button type="submit" class="btn-farm btn-danger btn-icon">
                <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                Delete Batch
            </button>
            <button type="button" class="btn-farm btn-dark" onclick="closeAllModals()">Cancel</button>
        </div>
    </form>
</div>

<!-- Fix NULL modal -->
<div class="dm-modal" id="fix-null-modal" style="border-top-color:var(--warning);">
    <h3>Assign Unlinked Sales</h3>
    <p><?php echo number_format($null_sales); ?> sale(s) have no batch linked. Assign them to the batch that was active when they were recorded.</p>
    <form method="POST">
        <input type="hidden" name="action" value="fix_null_batch">
        <div class="form-group">
            <label style="font-size:0.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;display:block;margin-bottom:5px;">Assign to batch</label>
            <select name="assign_batch_id" class="form-input" required>
                <option value="">Select batch</option>
                <?php foreach ($all_batches as $b):
                    $lbl = !empty($b['coop_label']) ? $b['coop_label'] : ($b['coop_number'] ? 'Coop '.$b['coop_number'] : 'Batch #'.$b['batch_id']);
                ?>
                <option value="<?php echo $b['batch_id']; ?>"><?php echo htmlspecialchars($lbl.' — '.$b['breed']); ?> (<?php echo $b['status']; ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="dm-modal-actions" style="margin-top:1rem;">
            <button type="submit" class="btn-farm btn-orange btn-icon">
                <i data-lucide="check" style="width:14px;height:14px;"></i>
                Assign
            </button>
            <button type="button" class="btn-farm btn-dark" onclick="closeAllModals()">Cancel</button>
        </div>
    </form>
</div>

<script>
lucide.createIcons();

async function previewDelete(type, mode) {
    let from = '', to = '';
    if (type === 'sales') { from = document.getElementById('sales_from').value; to = document.getElementById('sales_to').value; }
    else { from = document.getElementById('harvest_from').value; to = document.getElementById('harvest_to').value; }
    if (mode === 'range' && (!from || !to)) { alert('Select both a From and To date.'); return; }
    const params = new URLSearchParams({ type, mode, from, to });
    const resp   = await fetch('data_management_count.php?' + params);
    const data   = await resp.json().catch(() => ({ count: '?' }));
    const label  = type === 'sales' ? 'Sales' : 'Harvest';
    document.getElementById('modal-title').textContent    = `Delete ${label} Records`;
    document.getElementById('modal-subtitle').textContent = mode === 'all' ? `All ${label.toLowerCase()} records will be removed.` : `${label} records from ${from} to ${to}.`;
    document.getElementById('modal-count').textContent       = data.count;
    document.getElementById('modal-count-label').textContent = `${label.toLowerCase()} record(s) to delete`;
    if (type === 'sales') {
        document.getElementById('form-action').value      = 'delete_sales';
        document.getElementById('form-sales-mode').value  = mode;
        document.getElementById('form-sales-from').value  = from;
        document.getElementById('form-sales-to').value    = to;
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
    document.getElementById('batch-delete-id').value          = batchId;
    document.getElementById('batch-delete-label').textContent = `#${batchId} — ${coopLabel} — ${breed}`;
    openModal('batch-delete-modal');
}

function openModal(id) {
    document.getElementById('dm-overlay').style.display = 'block';
    document.getElementById(id).style.display           = 'block';
}

function closeAllModals() {
    document.getElementById('dm-overlay').style.display = 'none';
    document.querySelectorAll('.dm-modal').forEach(m => m.style.display = 'none');
}
</script>
