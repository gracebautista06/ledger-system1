<?php
/*
 * owner/manage_batches.php — Flock Batch Management
 *
 * Coop tab filtering integration: coop_number identifies batches in inventory.
 * Duplicate coop check prevents assigning the same coop to two active batches.
 */

$page_title = 'Manage Batches';

session_start();
include('../../includes/db.php');
include('../../includes/header.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header("Location: ../../portal/login.php");
    exit();
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $breed       = $conn->real_escape_string(trim($_POST['breed'] ?? ''));
        $quantity    = max(1, intval($_POST['quantity']));
        $coop_number = intval($_POST['coop_number'] ?? 0);
        $coop_label  = $conn->real_escape_string(trim($_POST['coop_label'] ?? ''));
        $acquired    = $conn->real_escape_string($_POST['date_acquired'] ?? '');
        $replacement = $conn->real_escape_string($_POST['expected_replacement'] ?? '');
        $notes       = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        $repl_sql    = $replacement ? "'$replacement'" : 'NULL';
        $coop_sql    = $coop_number > 0 ? $coop_number : 'NULL';
        $label_sql   = $coop_label ? "'$coop_label'" : 'NULL';

        if (empty($breed)) {
            $message = "<div class='alert error'>Breed name is required.</div>";
        } elseif ($coop_number < 1) {
            $message = "<div class='alert error'>Coop number must be 1 or higher.</div>";
        } else {
            $coop_check = $conn->query("SELECT batch_id FROM batches WHERE coop_number=$coop_sql AND status='Active' LIMIT 1");
            if ($coop_check && $coop_check->num_rows > 0) {
                $existing = $coop_check->fetch_assoc();
                $message = "<div class='alert error'>Coop #$coop_number is already assigned to active Batch #{$existing['batch_id']}. Retire that batch first or choose a different coop number.</div>";
            } else {
                $sql = "INSERT INTO batches (breed, coop_number, coop_label, initial_count, status, date_acquired, expected_replacement, notes)
                        VALUES ('$breed', $coop_sql, $label_sql, $quantity, 'Active', '$acquired', $repl_sql, '$notes')";
                if ($conn->query($sql)) {
                    $message = "<div class='alert success'>Batch added to Coop #$coop_number.</div>";
                } else {
                    // Fallback column name
                    $sql2 = "INSERT INTO batches (breed, coop_number, coop_label, quantity, status, date_acquired, expected_replacement, notes)
                             VALUES ('$breed', $coop_sql, $label_sql, $quantity, 'Active', '$acquired', $repl_sql, '$notes')";
                    if ($conn->query($sql2)) {
                        $message = "<div class='alert success'>Batch added to Coop #$coop_number.</div>";
                    } else {
                        $message = "<div class='alert error'>Database error: " . htmlspecialchars($conn->error) . "</div>";
                    }
                }
            }
        }

    } elseif ($action === 'edit') {
        $id          = intval($_POST['batch_id']);
        $breed       = $conn->real_escape_string(trim($_POST['breed'] ?? ''));
        $quantity    = max(1, intval($_POST['quantity']));
        $coop_number = intval($_POST['coop_number'] ?? 0);
        $coop_label  = $conn->real_escape_string(trim($_POST['coop_label'] ?? ''));
        $acquired    = $conn->real_escape_string($_POST['date_acquired'] ?? '');
        $replacement = $conn->real_escape_string($_POST['expected_replacement'] ?? '');
        $notes       = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        $repl_sql    = $replacement ? "'$replacement'" : 'NULL';
        $coop_sql    = $coop_number > 0 ? $coop_number : 'NULL';
        $label_sql   = $coop_label ? "'$coop_label'" : 'NULL';

        if (empty($breed)) {
            $message = "<div class='alert error'>Breed name is required.</div>";
        } elseif ($coop_number < 1) {
            $message = "<div class='alert error'>Coop number must be 1 or higher.</div>";
        } else {
            // Check duplicate coop only if coop_number changed
            $coop_check = $conn->query("SELECT batch_id FROM batches WHERE coop_number=$coop_sql AND status='Active' AND batch_id != $id LIMIT 1");
            if ($coop_check && $coop_check->num_rows > 0) {
                $existing = $coop_check->fetch_assoc();
                $message = "<div class='alert error'>Coop #$coop_number is already assigned to active Batch #{$existing['batch_id']}.</div>";
            } else {
                $upd = $conn->query("UPDATE batches SET breed='$breed', coop_number=$coop_sql, coop_label=$label_sql,
                                     initial_count=$quantity, date_acquired='$acquired',
                                     expected_replacement=$repl_sql, notes='$notes'
                                     WHERE batch_id=$id");
                if (!$upd) {
                    // Fallback for alternate column name
                    $conn->query("UPDATE batches SET breed='$breed', coop_number=$coop_sql, coop_label=$label_sql,
                                  quantity=$quantity, date_acquired='$acquired',
                                  expected_replacement=$repl_sql, notes='$notes'
                                  WHERE batch_id=$id");
                }
                $message = "<div class='alert success'>Batch #$id updated successfully.</div>";
            }
        }

    } elseif ($action === 'retire') {
        $id = intval($_POST['batch_id']);
        $conn->query("UPDATE batches SET status='Retired' WHERE batch_id=$id");
        $message = "<div class='alert warning'>Batch #$id marked as retired.</div>";

    } elseif ($action === 'delete') {
        $id    = intval($_POST['batch_id']);
        $check = $conn->query("SELECT status FROM batches WHERE batch_id=$id LIMIT 1");
        if ($check && $check->num_rows > 0) {
            $b = $check->fetch_assoc();
            if ($b['status'] === 'Active') {
                $message = "<div class='alert error'>Cannot delete an active batch. Retire it first.</div>";
            } else {
                $conn->query("DELETE FROM batches WHERE batch_id=$id");
                $message = "<div class='alert warning'>Batch #$id deleted.</div>";
            }
        }
    }
}

// Suggest next available coop number
$next_coop_q = $conn->query("SELECT COALESCE(MAX(coop_number),0)+1 AS next_coop FROM batches WHERE status='Active'");
$next_coop   = $next_coop_q ? (int)$next_coop_q->fetch_assoc()['next_coop'] : 1;

$batches = $conn->query("SELECT * FROM batches ORDER BY FIELD(status,'Active','Retired','Sold'), batch_id DESC");
$total   = $batches ? $batches->num_rows : 0;
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">

<div class="page-container">

    <div class="page-header">
        <div>
            <h2>Manage Batches</h2>
            <p><?php echo $total; ?> batch<?php echo $total !== 1 ? 'es' : ''; ?> on record.</p>
        </div>
        <button class="btn-farm btn-green btn-sm" onclick="toggleAddPanel()">
            + Add Batch
        </button>
    </div>

    <?php echo $message; ?>

    <!-- ADD BATCH FORM -->
    <div id="add-panel" style="display:none; margin-bottom:1.5rem;">
        <div class="card form-card">
            <h4 class="form-card__title">New Batch</h4>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Breed / Variety <span class="required">*</span></label>
                        <input type="text" name="breed" class="form-input" placeholder="e.g. Lohmann Brown" required>
                    </div>
                    <div class="form-group">
                        <label>Number of Birds <span class="required">*</span></label>
                        <input type="number" name="quantity" class="form-input" placeholder="e.g. 500" min="1" required>
                    </div>
                    <div class="form-group">
                        <label>
                            Coop Number <span class="required">*</span>
                            <span class="label-hint">used for inventory tab filtering</span>
                        </label>
                        <input type="number" name="coop_number" id="coop_number_input" class="form-input"
                               value="<?php echo $next_coop; ?>" min="1" required>
                        <small class="form-hint">Next available: <?php echo $next_coop; ?></small>
                    </div>
                    <div class="form-group">
                        <label>Coop Label <span class="label-hint">(optional)</span></label>
                        <input type="text" name="coop_label" class="form-input" placeholder="e.g. Layer House A">
                    </div>
                    <div class="form-group">
                        <label>Date Acquired</label>
                        <input type="date" name="date_acquired" class="form-input" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Expected Replacement</label>
                        <input type="date" name="expected_replacement" class="form-input"
                               value="<?php echo date('Y-m-d', strtotime('+18 months')); ?>">
                    </div>
                </div>
                <div class="form-group" style="margin-top:14px;">
                    <label>Notes</label>
                    <input type="text" name="notes" class="form-input" placeholder="Optional notes about this batch">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-farm btn-green">Add Batch</button>
                    <button type="button" class="btn-farm btn-dark" onclick="toggleAddPanel()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- BATCHES TABLE -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrapper" style="border:none; border-radius:0;">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Breed</th>
                        <th>Coop</th>
                        <th>Birds</th>
                        <th>Status</th>
                        <th>Acquired</th>
                        <th>Replacement Due</th>
                        <th>Notes</th>
                        <th style="text-align:center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($total > 0):
                        while ($row = $batches->fetch_assoc()):
                            $bird_count = $row['initial_count'] ?? $row['quantity'] ?? 0;
                            $acq_date   = $row['arrival_date']  ?? $row['date_acquired'] ?? null;
                            $repl_date  = $row['expected_replacement'] ?? null;
                            $days_left  = $repl_date ? (int)ceil((strtotime($repl_date) - time()) / 86400) : null;
                            $coop_num   = $row['coop_number'] ?? null;
                            $coop_lbl   = $row['coop_label']  ?? null;
                    ?>
                    <tr>
                        <td class="text-muted text-sm">#<?php echo $row['batch_id']; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['breed']); ?></strong></td>
                        <td>
                            <?php if ($coop_num): ?>
                                <span class="badge badge-healthy badge-sm">Coop <?php echo $coop_num; ?></span>
                                <?php if ($coop_lbl): ?>
                                    <div class="text-muted text-xs" style="margin-top:2px;">
                                        <?php echo htmlspecialchars($coop_lbl); ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted text-sm">Not set</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-secondary"><?php echo number_format($bird_count); ?></td>
                        <td>
                            <span class="badge <?php echo $row['status'] === 'Active' ? 'badge-healthy' : 'badge-rejected'; ?>">
                                <?php echo $row['status']; ?>
                            </span>
                        </td>
                        <td class="text-muted text-sm">
                            <?php echo $acq_date ? date('M d, Y', strtotime($acq_date)) : '—'; ?>
                        </td>
                        <td class="text-sm">
                            <?php if ($repl_date): ?>
                                <div style="color:<?php echo ($days_left !== null && $days_left <= 30) ? 'var(--danger)' : 'var(--text-secondary)'; ?>; font-weight:600;">
                                    <?php echo date('M d, Y', strtotime($repl_date)); ?>
                                </div>
                                <?php if ($days_left !== null): ?>
                                <div style="margin-top:3px;">
                                    <?php if ($days_left <= 0): ?>
                                        <span class="badge badge-critical">Overdue</span>
                                    <?php elseif ($days_left <= 30): ?>
                                        <span class="badge badge-warning"><?php echo $days_left; ?>d left</span>
                                    <?php else: ?>
                                        <span class="text-muted text-xs"><?php echo $days_left; ?> days</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted text-sm" style="max-width:160px;">
                            <?php echo htmlspecialchars($row['notes'] ?: '—'); ?>
                        </td>
                        <td style="text-align:center; white-space:nowrap;">
                            <!-- Edit button (always visible) -->
                            <button type="button" class="icon-btn icon-btn--blue" title="Edit batch"
                                onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                                    'batch_id'             => $row['batch_id'],
                                    'breed'                => $row['breed'],
                                    'quantity'             => $bird_count,
                                    'coop_number'          => $coop_num,
                                    'coop_label'           => $coop_lbl,
                                    'date_acquired'        => $acq_date,
                                    'expected_replacement' => $repl_date,
                                    'notes'                => $row['notes'] ?? '',
                                ]), ENT_QUOTES); ?>)">
                                <span class="material-symbols-outlined">edit</span>
                            </button>
                            <?php if ($row['status'] === 'Active'): ?>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Retire Batch #<?php echo $row['batch_id']; ?>?')">
                                    <input type="hidden" name="action"   value="retire">
                                    <input type="hidden" name="batch_id" value="<?php echo $row['batch_id']; ?>">
                                    <button type="submit" class="icon-btn icon-btn--amber" title="Retire batch">
                                        <span class="material-symbols-outlined">archive</span>
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('Permanently delete Batch #<?php echo $row['batch_id']; ?>?')">
                                    <input type="hidden" name="action"   value="delete">
                                    <input type="hidden" name="batch_id" value="<?php echo $row['batch_id']; ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger" title="Delete batch">
                                        <span class="material-symbols-outlined">delete</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile;
                    else: ?>
                    <tr><td colspan="9">
                        <div class="empty-state">
                            <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="2" y="7" width="20" height="14" rx="2"/>
                                <path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/>
                            </svg>
                            <p>No batches yet.</p>
                            <small>Click <strong>+ Add Batch</strong> above to get started.</small>
                        </div>
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <a href="../dashboard.php" class="back-link">&larr; Back to Dashboard</a>
</div>

<!-- EDIT BATCH MODAL -->
<div id="edit-modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:1000; align-items:center; justify-content:center;">
    <div class="card edit-modal">
        <div class="edit-modal__header">
            <h4 class="form-card__title" style="margin:0;">Edit Batch <span id="edit-modal-id"></span></h4>
            <button type="button" class="icon-btn icon-btn--ghost" onclick="closeEditModal()" title="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="POST" id="edit-form">
            <input type="hidden" name="action"   value="edit">
            <input type="hidden" name="batch_id" id="edit_batch_id">
            <div class="form-grid">
                <div class="form-group">
                    <label>Breed / Variety <span class="required">*</span></label>
                    <input type="text" name="breed" id="edit_breed" class="form-input" required>
                </div>
                <div class="form-group">
                    <label>Number of Birds <span class="required">*</span></label>
                    <input type="number" name="quantity" id="edit_quantity" class="form-input" min="1" required>
                </div>
                <div class="form-group">
                    <label>Coop Number <span class="required">*</span></label>
                    <input type="number" name="coop_number" id="edit_coop_number" class="form-input" min="1" required>
                </div>
                <div class="form-group">
                    <label>Coop Label <span class="label-hint">(optional)</span></label>
                    <input type="text" name="coop_label" id="edit_coop_label" class="form-input">
                </div>
                <div class="form-group">
                    <label>Date Acquired</label>
                    <input type="date" name="date_acquired" id="edit_date_acquired" class="form-input">
                </div>
                <div class="form-group">
                    <label>Expected Replacement</label>
                    <input type="date" name="expected_replacement" id="edit_expected_replacement" class="form-input">
                </div>
            </div>
            <div class="form-group" style="margin-top:14px;">
                <label>Notes</label>
                <input type="text" name="notes" id="edit_notes" class="form-input">
            </div>
            <div class="form-actions" style="margin-top:18px;">
                <button type="submit" class="btn-farm btn-green">Save Changes</button>
                <button type="button" class="btn-farm btn-dark" onclick="closeEditModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
.page-container   { max-width:1040px; margin:2rem auto; }
.form-card        { border:1px solid var(--border-mid); border-top:3px solid var(--success);
                    padding:1.4rem 1.8rem; margin-bottom:0; }
.form-card__title { color:var(--gold); margin-bottom:1.2rem; font-family:'Playfair Display',serif; font-size:1rem; }
.form-grid        { display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:14px; }
.form-actions     { display:flex; gap:10px; margin-top:14px; }
.required         { color:var(--danger); }
.label-hint       { font-weight:400; color:var(--text-muted); font-size:0.75rem; }
.form-hint        { color:var(--text-muted); font-size:0.73rem; display:block; margin-top:3px; }
.text-muted       { color:var(--text-muted); }
.text-secondary   { color:var(--text-secondary); }
.text-sm          { font-size:0.82rem; }
.text-xs          { font-size:0.72rem; }
.badge-sm         { font-size:0.72rem; }

/* Icon action buttons */
.icon-btn         { display:inline-flex; align-items:center; justify-content:center;
                    width:32px; height:32px; border-radius:6px; border:none;
                    cursor:pointer; transition:background 0.15s, color 0.15s; }
.icon-btn .material-symbols-outlined { font-size:18px; }
.icon-btn--amber  { background:rgba(255,168,38,0.12); color:var(--warning); }
.icon-btn--amber:hover { background:rgba(255,168,38,0.25); }
.icon-btn--danger { background:rgba(194,58,58,0.1); color:var(--danger); }
.icon-btn--danger:hover { background:rgba(194,58,58,0.22); }

.icon-btn--blue   { background:rgba(59,130,246,0.12); color:#3b82f6; }
.icon-btn--blue:hover { background:rgba(59,130,246,0.25); }
.icon-btn--ghost  { background:transparent; color:var(--text-muted); }
.icon-btn--ghost:hover { background:rgba(0,0,0,0.08); }

.edit-modal       { padding:1.6rem 1.8rem; width:100%; max-width:640px; border-top:3px solid #3b82f6;
                    max-height:90vh; overflow-y:auto; }
.edit-modal__header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.2rem; }

#edit-modal-overlay { display:none; }
#edit-modal-overlay.open { display:flex !important; }

.empty-icon  { width:36px; height:36px; color:var(--text-muted); margin:0 auto 10px; display:block; }
</style>

<script>
function toggleAddPanel() {
    const panel = document.getElementById('add-panel');
    panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    if (panel.style.display === 'block') {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

function openEditModal(data) {
    document.getElementById('edit_batch_id').value           = data.batch_id;
    document.getElementById('edit-modal-id').textContent     = '#' + data.batch_id;
    document.getElementById('edit_breed').value              = data.breed          || '';
    document.getElementById('edit_quantity').value           = data.quantity       || '';
    document.getElementById('edit_coop_number').value        = data.coop_number    || '';
    document.getElementById('edit_coop_label').value         = data.coop_label     || '';
    document.getElementById('edit_date_acquired').value      = data.date_acquired  ? data.date_acquired.substring(0,10) : '';
    document.getElementById('edit_expected_replacement').value = data.expected_replacement ? data.expected_replacement.substring(0,10) : '';
    document.getElementById('edit_notes').value              = data.notes          || '';
    document.getElementById('edit-modal-overlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeEditModal() {
    document.getElementById('edit-modal-overlay').classList.remove('open');
    document.body.style.overflow = '';
}

// Close on overlay click
document.getElementById('edit-modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});

// Close on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEditModal();
});
</script>

<?php include('../../includes/footer.php'); ?>