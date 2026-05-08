<?php
$page_title = 'Request Edit';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');
include('../includes/notify_owner.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header("Location: ../portal/login.php"); exit();
}

$staff_id = (int) $_SESSION['user_id'];

$allowed_types = ['Harvest', 'Health', 'Sale', 'Batch'];
$type = (isset($_GET['type']) && in_array($_GET['type'], $allowed_types)) ? $_GET['type'] : null;
$id   = (int) ($_GET['id'] ?? 0);

if (!$type || $id <= 0) { header("Location: view_logs.php"); exit(); }

// ── Ownership check ──────────────────────────────────────────────────────────
$original = null;
if ($type === 'Harvest') {
    $res_stmt = $conn->prepare("SELECT h.*, b.breed, b.batch_id FROM harvests h JOIN batches b ON h.batch_id=b.batch_id WHERE h.harvest_id=? AND h.staff_id=? LIMIT 1");
    $res_stmt->bind_param("ii", $id, $staff_id);
} elseif ($type === 'Sale') {
    $res_stmt = $conn->prepare("SELECT s.*, NULL AS breed, NULL AS batch_id FROM sales s WHERE s.sale_id=? AND s.staff_id=? LIMIT 1");
    $res_stmt->bind_param("ii", $id, $staff_id);
} elseif ($type === 'Batch') {
    $res_stmt = $conn->prepare("SELECT b.* FROM batches b WHERE b.batch_id=? LIMIT 1");
    $res_stmt->bind_param("i", $id);
} else { // Health
    $res_stmt = $conn->prepare("SELECT fh.*, b.breed, b.batch_id FROM flock_health fh JOIN batches b ON fh.batch_id=b.batch_id WHERE fh.report_id=? AND fh.staff_id=? LIMIT 1");
    $res_stmt->bind_param("ii", $id, $staff_id);
}
$res_stmt->execute();
$res = $res_stmt->get_result();
if ($res->num_rows === 0) { header("Location: view_logs.php"); exit(); }
$original = $res->fetch_assoc();
$res_stmt->close();

// ── Define which fields are editable per record type ────────────────────────
$editable_fields = [];
if ($type === 'Harvest') {
    $editable_fields = [
        'size_pw'     => ['label' => 'Peewee Eggs',       'type' => 'number',         'value' => (int)($original['size_pw']  ?? 0), 'attrs' => 'min="0"'],
        'size_s'      => ['label' => 'Small Eggs',        'type' => 'number',         'value' => (int)($original['size_s']   ?? 0), 'attrs' => 'min="0"'],
        'size_m'      => ['label' => 'Medium Eggs',       'type' => 'number',         'value' => (int)($original['size_m']   ?? 0), 'attrs' => 'min="0"'],
        'size_l'      => ['label' => 'Large Eggs',        'type' => 'number',         'value' => (int)($original['size_l']   ?? 0), 'attrs' => 'min="0"'],
        'size_xl'     => ['label' => 'Extra Large Eggs',  'type' => 'number',         'value' => (int)($original['size_xl']  ?? 0), 'attrs' => 'min="0"'],
        'size_j'      => ['label' => 'Jumbo Eggs',        'type' => 'number',         'value' => (int)($original['size_j']   ?? 0), 'attrs' => 'min="0"'],
        'date_logged' => ['label' => 'Date Logged',       'type' => 'datetime-local', 'value' => date('Y-m-d\TH:i', strtotime($original['date_logged'])), 'attrs' => ''],
        'batch_id'    => ['label' => 'Batch (ID number)', 'type' => 'number',         'value' => (int)($original['batch_id'] ?? 0), 'attrs' => 'min="1"'],
    ];
} elseif ($type === 'Sale') {
    $editable_fields = [
        'qty_pw'        => ['label' => 'Peewee Trays',     'type' => 'number',         'value' => (int)($original['qty_pw']  ?? 0), 'attrs' => 'min="0"'],
        'qty_s'         => ['label' => 'Small Trays',      'type' => 'number',         'value' => (int)($original['qty_s']   ?? 0), 'attrs' => 'min="0"'],
        'qty_m'         => ['label' => 'Medium Trays',     'type' => 'number',         'value' => (int)($original['qty_m']   ?? 0), 'attrs' => 'min="0"'],
        'qty_l'         => ['label' => 'Large Trays',      'type' => 'number',         'value' => (int)($original['qty_l']   ?? 0), 'attrs' => 'min="0"'],
        'qty_xl'        => ['label' => 'Extra Large Trays','type' => 'number',         'value' => (int)($original['qty_xl']  ?? 0), 'attrs' => 'min="0"'],
        'qty_j'         => ['label' => 'Jumbo Trays',      'type' => 'number',         'value' => (int)($original['qty_j']   ?? 0), 'attrs' => 'min="0"'],
        'total_amount'  => ['label' => 'Total Amount (₱)', 'type' => 'number',         'value' => $original['total_amount'],        'attrs' => 'min="0" step="0.01"'],
        'customer_name' => ['label' => 'Customer Name',    'type' => 'text',           'value' => $original['customer_name'],       'attrs' => 'maxlength="120"'],
        'date_sold'     => ['label' => 'Date of Sale',     'type' => 'datetime-local', 'value' => date('Y-m-d\TH:i', strtotime($original['date_sold'])), 'attrs' => ''],
    ];
} elseif ($type === 'Health') {
    $editable_fields = [
        'mortality_count' => ['label' => 'Mortality Count',  'type' => 'number',         'value' => $original['mortality_count'], 'attrs' => 'min="0"'],
        'status_level'    => ['label' => 'Status Level',     'type' => 'text',           'value' => $original['status_level'],    'attrs' => 'maxlength="60"'],
        'date_reported'   => ['label' => 'Date Reported',    'type' => 'datetime-local', 'value' => date('Y-m-d\TH:i', strtotime($original['date_reported'])), 'attrs' => ''],
        'batch_id'        => ['label' => 'Batch (ID number)','type' => 'number',         'value' => $original['batch_id'],        'attrs' => 'min="1"'],
    ];
} else { // Batch
    $editable_fields = [
        'breed'                => ['label' => 'Breed / Variety',     'type' => 'text',   'value' => $original['breed']               ?? '',  'attrs' => 'maxlength="120"'],
        'initial_count'        => ['label' => 'Number of Birds',      'type' => 'number', 'value' => (int)($original['initial_count'] ?? $original['quantity'] ?? 0), 'attrs' => 'min="1"'],
        'coop_number'          => ['label' => 'Coop Number',          'type' => 'number', 'value' => (int)($original['coop_number']   ?? 0),  'attrs' => 'min="1"'],
        'coop_label'           => ['label' => 'Coop Label',           'type' => 'text',   'value' => $original['coop_label']          ?? '',  'attrs' => 'maxlength="80"'],
        'date_acquired'        => ['label' => 'Date Acquired',        'type' => 'date',   'value' => $original['date_acquired']       ?? '',  'attrs' => ''],
        'expected_replacement' => ['label' => 'Expected Replacement', 'type' => 'date',   'value' => $original['expected_replacement'] ?? '', 'attrs' => ''],
        'notes'                => ['label' => 'Notes',                'type' => 'text',   'value' => $original['notes']              ?? '',  'attrs' => 'maxlength="255"'],
    ];
}

// ── Duplicate-pending check ──────────────────────────────────────────────────
$dup_stmt = $conn->prepare("SELECT request_id FROM edit_requests WHERE staff_id=? AND record_type=? AND record_id=? AND request_type='Edit' AND status='Pending' LIMIT 1");
$dup_stmt->bind_param("isi", $staff_id, $type, $id);
$dup_stmt->execute();
$dup_stmt->store_result();
$already_pending = $dup_stmt->num_rows > 0;
$dup_stmt->close();

$errors = [];

// ── POST handler — multi-field ────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$already_pending) {
    $reason         = trim($_POST['reason'] ?? '');
    $new_values_raw = $_POST['new_values'] ?? [];
    $changed_fields = [];

    // Collect only fields the staff actually changed
    foreach ($editable_fields as $field => $meta) {
        if (!isset($new_values_raw[$field])) continue;
        $submitted    = trim($new_values_raw[$field]);
        $original_str = (string)$meta['value'];
        // Normalize datetime comparison to minute precision
        $cmp_orig = in_array($meta['type'], ['datetime-local']) ? substr($original_str, 0, 16) : $original_str;
        $cmp_new  = in_array($meta['type'], ['datetime-local']) ? substr($submitted,    0, 16) : $submitted;

        if ($cmp_new !== $cmp_orig && $submitted !== '') {
            $changed_fields[$field] = $submitted;
        }
    }

    if (empty($reason)) {
        $errors[] = "Please explain why these corrections are needed.";
    }
    if (empty($changed_fields)) {
        $errors[] = "No changes detected. Please update at least one field before submitting.";
    }

    if (empty($errors)) {
        // Store all changes as an array inside new_data
        $changes = [];
        foreach ($changed_fields as $field => $new_val) {
            $changes[] = [
                'field'     => $field,
                'old_value' => (string)($original[$field] ?? ''),
                'new_value' => $new_val,
                'label'     => $editable_fields[$field]['label'],
            ];
        }
        $new_data = json_encode(['changes' => $changes]);

        $ins = $conn->prepare("
            INSERT INTO edit_requests
            (staff_id, record_type, record_id, request_type, new_data, reason, status, created_at)
            VALUES (?,?,?,'Edit',?,?,'Pending',NOW())
        ");
        $ins->bind_param("isiss", $staff_id, $type, $id, $new_data, $reason);

        if ($ins->execute()) {
            $ins->close();

            $batch_id     = (int)($original['batch_id'] ?? ($type === 'Batch' ? $id : 0)) ?: null;
            $staff_name   = $_SESSION['name'] ?? 'A staff member';
            $record_label = $type === 'Sale'
                ? "Sale to " . ($original['customer_name'] ?? "#$id")
                : ($type === 'Batch'
                    ? "Batch #{$id} ({$original['breed']})"
                    : "$type (Batch: {$original['breed']})");

            $field_summary = implode(', ', array_map(fn($f) => $editable_fields[$f]['label'], array_keys($changed_fields)));
            $notif_msg = "$staff_name requested corrections on $type record #$id"
                       . " ($record_label) — fields: $field_summary."
                       . " Reason: $reason";

            notify_owner($conn, $staff_id, 'edit_request', $notif_msg, $batch_id, $type, $id);
            log_session_activity($conn, 'Edit Request Sent', "Requested edit on $type #$id — fields: $field_summary — reason: $reason");
            header("Location: view_logs.php?request_sent=1"); exit();
        } else {
            $errors[] = "Database error. Please try again.";
            $ins->close();
        }
    }
}
?>

<div class="card" style="max-width:620px; margin:2rem auto; border-top:5px solid var(--terra-lt);">
    <h3 style="color:var(--gold); font-family:'Playfair Display',serif; margin-bottom:0.3rem;">Request Record Correction</h3>
    <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom:1.5rem;">
        Update any fields that need correcting below, then explain why. The Owner will review before anything changes.
    </p>

    <!-- Original record summary -->
    <div style="background:var(--bg-wood); border-radius:var(--radius); padding:14px 16px; border-left:4px solid var(--border-mid); margin-bottom:1.5rem;">
        <p style="font-size:0.75rem; font-weight:700; color:var(--text-muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.6px;">
            Original Record — <?php echo $type; ?> #<?php echo $id; ?>
        </p>
        <?php if ($type === 'Harvest'): ?>
            <p style="margin:0 0 6px; font-size:0.9rem; color:var(--text-primary);">
                Batch: <strong><?php echo htmlspecialchars($original['breed']); ?></strong> &nbsp;|&nbsp;
                Total: <strong style="color:var(--gold);"><?php echo number_format($original['total_eggs']); ?> eggs</strong><br>
                <small style="color:var(--text-muted);">Logged: <?php echo date('M d, Y g:i A', strtotime($original['date_logged'])); ?></small>
            </p>
            <p style="margin:0; font-size:0.8rem; color:var(--text-muted);">
                PW: <?php echo (int)($original['size_pw'] ?? 0); ?> &nbsp;
                S: <?php echo (int)($original['size_s']  ?? 0); ?> &nbsp;
                M: <?php echo (int)($original['size_m']  ?? 0); ?> &nbsp;
                L: <?php echo (int)($original['size_l']  ?? 0); ?> &nbsp;
                XL: <?php echo (int)($original['size_xl'] ?? 0); ?> &nbsp;
                J: <?php echo (int)($original['size_j']  ?? 0); ?>
            </p>
        <?php elseif ($type === 'Sale'): ?>
            <p style="margin:0 0 6px; font-size:0.9rem; color:var(--text-primary);">
                Customer: <strong><?php echo htmlspecialchars($original['customer_name']); ?></strong> &nbsp;|&nbsp;
                Total: <strong style="color:var(--success);">₱<?php echo number_format((float)$original['total_amount'], 2); ?></strong> &nbsp;|&nbsp;
                Trays: <strong style="color:var(--gold);"><?php echo number_format($original['quantity_sold']); ?></strong><br>
                <small style="color:var(--text-muted);">Sold: <?php echo date('M d, Y g:i A', strtotime($original['date_sold'])); ?></small>
            </p>
            <p style="margin:0; font-size:0.8rem; color:var(--text-muted);">
                PW: <?php echo (int)($original['qty_pw'] ?? 0); ?> &nbsp;
                S: <?php echo (int)($original['qty_s']  ?? 0); ?> &nbsp;
                M: <?php echo (int)($original['qty_m']  ?? 0); ?> &nbsp;
                L: <?php echo (int)($original['qty_l']  ?? 0); ?> &nbsp;
                XL: <?php echo (int)($original['qty_xl'] ?? 0); ?> &nbsp;
                J: <?php echo (int)($original['qty_j']  ?? 0); ?>
            </p>
        <?php elseif ($type === 'Health'): ?>
            <p style="margin:0; font-size:0.9rem; color:var(--text-primary);">
                Batch: <strong><?php echo htmlspecialchars($original['breed']); ?></strong> &nbsp;|&nbsp;
                Status: <strong><?php echo htmlspecialchars($original['status_level']); ?></strong> &nbsp;|&nbsp;
                Mortality: <strong style="color:var(--gold);"><?php echo $original['mortality_count']; ?></strong><br>
                <small style="color:var(--text-muted);">Reported: <?php echo date('M d, Y g:i A', strtotime($original['date_reported'])); ?></small>
            </p>
        <?php else: /* Batch */ ?>
            <p style="margin:0 0 4px; font-size:0.9rem; color:var(--text-primary);">
                Breed: <strong><?php echo htmlspecialchars($original['breed'] ?? '—'); ?></strong> &nbsp;|&nbsp;
                Birds: <strong style="color:var(--gold);"><?php echo number_format((int)($original['initial_count'] ?? $original['quantity'] ?? 0)); ?></strong> &nbsp;|&nbsp;
                Coop: <strong>#<?php echo htmlspecialchars((string)($original['coop_number'] ?? '—')); ?></strong>
                <?php if (!empty($original['coop_label'])): ?>
                    <span style="color:var(--text-muted); font-size:0.82rem;">(<?php echo htmlspecialchars($original['coop_label']); ?>)</span>
                <?php endif; ?>
            </p>
            <p style="margin:0; font-size:0.8rem; color:var(--text-muted);">
                Status: <strong><?php echo htmlspecialchars($original['status'] ?? '—'); ?></strong> &nbsp;|&nbsp;
                Acquired: <?php echo !empty($original['date_acquired']) ? date('M d, Y', strtotime($original['date_acquired'])) : '—'; ?> &nbsp;|&nbsp;
                Replacement: <?php echo !empty($original['expected_replacement']) ? date('M d, Y', strtotime($original['expected_replacement'])) : '—'; ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($already_pending): ?>
        <div class="alert warning">You already have a pending correction request for this record. Wait for the Owner to review it first.</div>
        <a href="view_logs.php" class="btn-farm btn-outline btn-full" style="text-align:center; margin-top:1rem;">← Back to My Logs</a>
    <?php else: ?>

        <?php if (!empty($errors)): ?>
            <div class="alert error">
                <?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="editRequestForm">

            <!-- ── Field grid — edit any/all fields directly ── -->
            <p style="font-size:0.82rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:10px;">
                Correct the fields that need fixing <span style="color:var(--danger);">*</span>
                <span id="changed-badge" style="display:none; margin-left:8px; background:var(--terra-lt); color:#fff; font-size:0.7rem; padding:2px 8px; border-radius:20px; font-weight:700; letter-spacing:0; vertical-align:middle;">
                    <span id="changed-count">0</span> changed
                </span>
            </p>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:1.4rem;">
                <?php foreach ($editable_fields as $key => $meta):
                    $raw = $meta['value'];
                    if ($meta['type'] === 'datetime-local') {
                        $display_orig = date('M d, Y g:i A', strtotime(str_replace('T', ' ', $raw)));
                    } elseif (is_numeric($raw) && $key === 'total_amount') {
                        $display_orig = '₱' . number_format((float)$raw, 2);
                    } elseif (is_numeric($raw) && !in_array($key, ['batch_id','coop_number'])) {
                        $display_orig = number_format((float)$raw);
                    } else {
                        $display_orig = $raw ?: '—';
                    }
                    // Repopulate on validation error
                    $repop_val = htmlspecialchars(
                        (string)($_POST['new_values'][$key] ?? $meta['value']),
                        ENT_QUOTES
                    );
                ?>
                <div class="field-card" id="card_<?php echo $key; ?>"
                     style="background:var(--bg-wood); border:1.5px solid var(--border-mid); border-radius:var(--radius); padding:10px 12px; transition:border-color 0.15s, background 0.15s;">
                    <label style="display:block; font-size:0.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">
                        <?php echo htmlspecialchars($meta['label']); ?>
                        <span id="dot_<?php echo $key; ?>"
                              style="width:7px; height:7px; background:var(--terra-lt); border-radius:50%; display:inline-block; vertical-align:middle; margin-left:4px; opacity:0; transition:opacity 0.15s;"></span>
                    </label>
                    <small style="display:block; font-size:0.78rem; color:var(--text-muted); margin-bottom:5px;">
                        Current: <strong><?php echo htmlspecialchars($display_orig); ?></strong>
                    </small>
                    <input
                        type="<?php echo $meta['type']; ?>"
                        name="new_values[<?php echo $key; ?>]"
                        id="field_<?php echo $key; ?>"
                        class="form-input field-input"
                        data-original="<?php echo htmlspecialchars((string)$meta['value'], ENT_QUOTES); ?>"
                        data-key="<?php echo $key; ?>"
                        data-ftype="<?php echo $meta['type']; ?>"
                        value="<?php echo $repop_val; ?>"
                        <?php echo $meta['attrs']; ?>
                        style="padding:6px 8px; font-size:0.88rem;">
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ── Change summary (live preview) ── -->
            <div id="change-summary" style="display:none; background:var(--bg-wood); border:1px solid var(--border-mid); border-radius:var(--radius); padding:10px 14px; margin-bottom:1rem;">
                <p style="font-size:0.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin:0 0 6px;">Changes to be submitted</p>
                <ul id="summary-list" style="margin:0; padding-left:1.1rem; font-size:0.85rem; color:var(--text-primary);"></ul>
            </div>

            <!-- ── Reason ── -->
            <div class="form-group">
                <label style="font-weight:600;">Why do these fields need to be corrected? <span style="color:var(--danger);">*</span></label>
                <textarea name="reason" id="reason" class="form-input" rows="3"
                          placeholder="Example: I entered the wrong batch number and the small/medium egg counts were swapped."
                          required><?php echo htmlspecialchars($_POST['reason'] ?? '', ENT_QUOTES); ?></textarea>
            </div>

            <button type="submit" id="submit-btn" class="btn-farm btn-orange btn-full" style="padding:14px; margin-top:0.5rem; opacity:0.5; cursor:not-allowed;" disabled>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                     stroke-linecap="round" stroke-linejoin="round"
                     style="vertical-align:middle;margin-right:6px;">
                    <line x1="22" y1="2" x2="11" y2="13"/>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>Send Correction Request to Owner
            </button>
        </form>

        <a href="view_logs.php" class="back-link" style="display:block; text-align:center; margin-top:1rem;">← Cancel and Go Back</a>
    <?php endif; ?>
</div>

<script>
const fieldMeta = <?php echo json_encode(array_map(fn($k, $v) => [
    'key'   => $k,
    'label' => $v['label'],
    'type'  => $v['type'],
    'value' => (string)$v['value'],
], array_keys($editable_fields), $editable_fields)); ?>;

function getChanges() {
    const changes = [];
    document.querySelectorAll('.field-input').forEach(input => {
        const orig  = input.dataset.original;
        const cur   = input.value;
        const ftype = input.dataset.ftype;
        const normOrig = ftype === 'datetime-local' ? orig.substring(0, 16) : orig;
        const normCur  = ftype === 'datetime-local' ? cur.substring(0, 16)  : cur;
        if (normCur !== normOrig && cur.trim() !== '') {
            const meta = fieldMeta.find(f => f.key === input.dataset.key);
            changes.push({ key: input.dataset.key, label: meta ? meta.label : input.dataset.key, orig, cur });
        }
    });
    return changes;
}

function updateUI() {
    const changes = getChanges();

    // Dot + card highlight per field
    document.querySelectorAll('.field-input').forEach(input => {
        const dot  = document.getElementById('dot_' + input.dataset.key);
        const card = document.getElementById('card_' + input.dataset.key);
        const changed = changes.some(c => c.key === input.dataset.key);
        if (dot)  dot.style.opacity  = changed ? '1' : '0';
        if (card) {
            card.style.borderColor = changed ? 'var(--terra-lt)' : 'var(--border-mid)';
            card.style.background  = changed ? 'rgba(160,80,30,0.07)' : 'var(--bg-wood)';
        }
    });

    // Badge
    const badge = document.getElementById('changed-badge');
    document.getElementById('changed-count').textContent = changes.length;
    badge.style.display = changes.length > 0 ? 'inline-block' : 'none';

    // Summary list
    const summary = document.getElementById('change-summary');
    const list    = document.getElementById('summary-list');
    if (changes.length > 0) {
        summary.style.display = 'block';
        list.innerHTML = changes.map(c =>
            `<li style="margin-bottom:3px;">
                <strong>${c.label}</strong>:
                <span style="color:var(--text-muted); text-decoration:line-through;">${c.orig || '(empty)'}</span>
                → <span style="color:var(--terra-lt); font-weight:600;">${c.cur}</span>
            </li>`
        ).join('');
    } else {
        summary.style.display = 'none';
    }

    // Enable submit only when at least one change exists
    const btn = document.getElementById('submit-btn');
    btn.disabled       = changes.length === 0;
    btn.style.opacity  = changes.length > 0 ? '1' : '0.5';
    btn.style.cursor   = changes.length > 0 ? 'pointer' : 'not-allowed';
}

document.querySelectorAll('.field-input').forEach(input => {
    input.addEventListener('input', updateUI);
    input.addEventListener('change', updateUI);
});

updateUI(); // init on load
</script>

<?php include('../includes/footer.php'); ?>