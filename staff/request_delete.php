<?php
$page_title = 'Request Deletion';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');
include('../includes/notify_owner.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header("Location: ../portal/login.php"); exit();
}

$staff_id = (int) $_SESSION['user_id'];

$allowed_types = ['Harvest', 'Health', 'Sale'];
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
} else { // Health
    $res_stmt = $conn->prepare("SELECT fh.*, b.breed, b.batch_id FROM flock_health fh JOIN batches b ON fh.batch_id=b.batch_id WHERE fh.report_id=? AND fh.staff_id=? LIMIT 1");
    $res_stmt->bind_param("ii", $id, $staff_id);
}
$res_stmt->execute();
$res = $res_stmt->get_result();
if ($res->num_rows === 0) { header("Location: view_logs.php"); exit(); }
$original = $res->fetch_assoc();
$res_stmt->close();

// ── Pre-defined deletion reasons per record type ─────────────────────────────
$preset_reasons = [
    'Harvest' => [
        'duplicate'   => ['Duplicate entry',   'Duplicate entry — this harvest was logged twice.'],
        'wrong_batch' => ['Wrong batch',        'Wrong batch selected — this belongs to a different batch.'],
        'wrong_date'  => ['Wrong date',         'Wrong date — this was logged on the wrong day.'],
        'test_entry'  => ['Accidental submit',  'Test entry — this was accidentally submitted.'],
        'other'       => ['Other reason…',      ''],
    ],
    'Sale' => [
        'duplicate'      => ['Duplicate entry',  'Duplicate entry — this sale was recorded twice.'],
        'cancelled'      => ['Sale cancelled',   'Cancelled transaction — the sale did not push through.'],
        'wrong_customer' => ['Wrong customer',   'Wrong customer — this was logged under the wrong buyer.'],
        'test_entry'     => ['Accidental submit','Test entry — this was accidentally submitted.'],
        'other'          => ['Other reason…',   ''],
    ],
    'Health' => [
        'duplicate'   => ['Duplicate entry',   'Duplicate entry — this report was submitted twice.'],
        'wrong_batch' => ['Wrong batch',        'Wrong batch selected — this belongs to a different batch.'],
        'wrong_date'  => ['Wrong date',         'Wrong date — this was reported on the wrong day.'],
        'test_entry'  => ['Accidental submit',  'Test entry — this was accidentally submitted.'],
        'other'       => ['Other reason…',      ''],
    ],
];
$reasons_for_type = $preset_reasons[$type];

// ── Duplicate-pending check ───────────────────────────────────────────────────
$dup_stmt = $conn->prepare("SELECT request_id FROM edit_requests WHERE staff_id=? AND record_type=? AND record_id=? AND request_type='Delete' AND status='Pending' LIMIT 1");
$dup_stmt->bind_param("isi", $staff_id, $type, $id);
$dup_stmt->execute();
$dup_stmt->store_result();
$already_pending = $dup_stmt->num_rows > 0;
$dup_stmt->close();

$message = "";

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && !$already_pending) {
    $reason_key   = trim($_POST['reason_key']   ?? '');
    $reason_extra = trim($_POST['reason_extra'] ?? '');
    $is_other     = ($reason_key === 'other');

    if (!array_key_exists($reason_key, $reasons_for_type)) {
        $message = "<div class='alert error'><i class='ti ti-alert-circle' style='margin-right:6px;vertical-align:-2px;'></i>Invalid reason. Please try again.</div>";
    } elseif ($is_other && empty($reason_extra)) {
        $message = "<div class='alert error'><i class='ti ti-alert-circle' style='margin-right:6px;vertical-align:-2px;'></i>Please explain your reason before sending.</div>";
    } else {
        $full_reason = $reasons_for_type[$reason_key][1];
        // FIX #4: reason_extra is trimmed and length-capped before use
        $reason_extra = mb_substr($reason_extra, 0, 500);
        $final_reason = $is_other
            ? "Other: $reason_extra"
            : $full_reason . ($reason_extra ? " Additional notes: $reason_extra" : '');

        $ins = $conn->prepare("
            INSERT INTO edit_requests
            (staff_id, record_type, record_id, request_type, reason, status, created_at)
            VALUES (?,?,?,'Delete',?,'Pending',NOW())
        ");
        $ins->bind_param("isis", $staff_id, $type, $id, $final_reason);

        if ($ins->execute()) {
            $ins->close();

            $batch_id   = (int)($original['batch_id'] ?? 0) ?: null;
            // FIX #5: use 'username' key which is what login sets in session
            $staff_name = $_SESSION['username'] ?? 'A staff member';
            $record_label = $type === 'Sale'
                ? "Sale to " . ($original['customer_name'] ?? "#$id")
                : "$type (Batch: {$original['breed']})";
            $notif_msg = "$staff_name requested deletion of $type record #$id"
                       . " ($record_label). Reason: $final_reason";

            notify_owner($conn, $staff_id, 'delete_request', $notif_msg, $batch_id, $type, $id);
            log_session_activity($conn, 'Delete Request Sent', "Requested deletion of $type #$id — reason: $final_reason");
            header("Location: view_logs.php?delete_sent=1"); exit();
        } else {
            $message = "<div class='alert error'><i class='ti ti-alert-circle' style='margin-right:6px;vertical-align:-2px;'></i>Database error. Please try again.</div>";
            $ins->close();
        }
    }
}

// Which key was posted (used to re-open "Other" box on validation error)
$posted_key = $_POST['reason_key'] ?? '';
?>

<div class="card" style="max-width:520px; margin:2rem auto; border-top:5px solid var(--danger);">
    <h3 style="color:var(--danger); font-family:'Playfair Display',serif; margin-bottom:0.3rem;">Request Record Deletion</h3>
    <p style="color:var(--text-muted); font-size:0.9rem; margin-bottom:1.5rem;">
        Tap the reason this record should be deleted — your request goes straight to the Owner for review.
    </p>

    <!-- Original record summary -->
    <div style="background:var(--bg-wood); border-radius:var(--radius); padding:14px 16px; border-left:4px solid var(--danger); margin-bottom:1.5rem;">
        <p style="font-size:0.75rem; font-weight:700; color:var(--text-muted); margin-bottom:8px; text-transform:uppercase; letter-spacing:0.6px;">
            <!-- FIX #3: $type is whitelisted, $id is int-cast — both safe, kept consistent -->
            Record to Delete — <?php echo htmlspecialchars($type); ?> #<?php echo $id; ?>
        </p>
        <?php if ($type === 'Harvest'): ?>
            <p style="margin:0; font-size:0.9rem; color:var(--text-primary);">
                Batch: <strong><?php echo htmlspecialchars($original['breed']); ?></strong> &nbsp;|&nbsp;
                Total: <strong style="color:var(--gold);"><?php echo number_format($original['total_eggs']); ?> eggs</strong><br>
                <!-- FIX #6: removed non-existent egg_size column reference entirely -->
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
                Status: <strong><?php echo htmlspecialchars($original['status_level']); ?></strong> &nbsp;|&nbsp;
                Mortality: <strong style="color:var(--gold);"><?php echo (int)$original['mortality_count']; ?></strong><br>
                <small style="color:var(--text-muted);">Reported: <?php echo date('M d, Y g:i A', strtotime($original['date_reported'])); ?></small>
            </p>
        <?php endif; ?>
    </div>

    <?php if ($already_pending): ?>
        <div class="alert warning">You already have a pending deletion request for this record. Wait for the Owner to review it.</div>
        <a href="view_logs.php" class="btn-farm btn-outline btn-full" style="text-align:center; margin-top:1rem;">← Back to My Logs</a>

    <?php else: ?>
        <?php echo $message; ?>

        <p style="font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">
            Why should this record be deleted?
        </p>

        <?php foreach ($reasons_for_type as $key => [$label, $full]): ?>
            <?php if ($key === 'other'): ?>

                <!-- "Other" — inline expandable mini-form -->
                <div id="other_section" style="margin-bottom:8px;">
                    <button type="button"
                            id="other_toggle_btn"
                            onclick="openOtherForm()"
                            style="
                                display:<?php echo ($posted_key === 'other') ? 'none' : 'flex'; ?>;
                                width:100%; align-items:center; gap:10px;
                                background:var(--bg-wood); border:1.5px solid var(--border-mid);
                                border-radius:var(--radius); padding:11px 14px;
                                cursor:pointer; font-size:0.88rem; color:var(--text-muted);
                                text-align:left; transition:border-color 0.15s;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                             stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0; opacity:0.6;">
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12" y2="16"/>
                        </svg>
                        <?php echo htmlspecialchars($label); ?>
                    </button>

                    <!-- Inline "Other" mini-form -->
                    <form method="POST" id="other_form"
                          style="display:<?php echo ($posted_key === 'other') ? 'block' : 'none'; ?>;
                                 background:var(--bg-wood); border:1.5px solid var(--danger);
                                 border-radius:var(--radius); padding:14px;">
                        <input type="hidden" name="reason_key" value="other">
                        <label style="font-size:0.82rem; color:var(--text-muted); display:block; margin-bottom:6px;">
                            Explain your reason <span style="color:var(--danger);">*</span>
                        </label>
                        <!-- FIX #8: added maxlength="500" to cap reason length -->
                        <textarea name="reason_extra" class="form-input" rows="3"
                                  maxlength="500"
                                  placeholder="Describe why this record needs to be deleted."
                                  style="margin-bottom:6px;"><?php echo htmlspecialchars($_POST['reason_extra'] ?? '', ENT_QUOTES); ?></textarea>
                        <!-- FIX #8: character counter so staff knows the limit -->
                        <div id="reason_extra_counter" style="font-size:0.72rem; color:var(--text-muted); text-align:right; margin-bottom:10px;">
                            <span id="reason_extra_len">0</span> / 500
                        </div>
                        <div style="display:flex; gap:8px;">
                            <button type="submit" id="other_submit_btn" class="btn-farm btn-danger" style="flex:1; padding:10px;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                                     stroke-linecap="round" stroke-linejoin="round"
                                     style="vertical-align:middle; margin-right:5px;">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                    <path d="M10 11v6"/><path d="M14 11v6"/>
                                    <path d="M9 6V4h6v2"/>
                                </svg>Send Request
                            </button>
                            <button type="button" onclick="closeOtherForm()"
                                    class="btn-farm btn-outline" style="padding:10px 14px;">Cancel</button>
                        </div>
                    </form>
                </div>

            <?php else: ?>

                <!-- Every other reason = single submit button, submits immediately -->
                <form method="POST" style="margin-bottom:8px;" class="reason-form">
                    <input type="hidden" name="reason_key" value="<?php echo htmlspecialchars($key, ENT_QUOTES); ?>">
                    <button type="submit" style="
                        display:flex; width:100%; align-items:center; gap:10px;
                        background:var(--bg-wood); border:1.5px solid var(--border-mid);
                        border-radius:var(--radius); padding:11px 14px;
                        cursor:pointer; font-size:0.88rem; color:var(--text-primary);
                        text-align:left; transition:border-color 0.15s, background 0.15s;"
                        onmouseover="this.style.borderColor='var(--danger)'; this.style.background='rgba(180,30,30,0.05)';"
                        onmouseout="this.style.borderColor='var(--border-mid)'; this.style.background='var(--bg-wood)';">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                             stroke-linecap="round" stroke-linejoin="round"
                             style="flex-shrink:0; color:var(--danger); opacity:0.7;">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                            <path d="M10 11v6"/><path d="M14 11v6"/>
                            <path d="M9 6V4h6v2"/>
                        </svg>
                        <?php echo htmlspecialchars($label); ?>
                    </button>
                </form>

            <?php endif; ?>
        <?php endforeach; ?>

        <a href="view_logs.php" class="back-link" style="display:block; text-align:center; margin-top:1.2rem;">← Cancel and Go Back</a>
    <?php endif; ?>
</div>

<script>
function openOtherForm() {
    document.getElementById('other_toggle_btn').style.display = 'none';
    document.getElementById('other_form').style.display = 'block';
    document.querySelector('#other_form textarea').focus();
}
function closeOtherForm() {
    document.getElementById('other_toggle_btn').style.display = 'flex';
    document.getElementById('other_form').style.display = 'none';
}

// FIX #8: live character counter for Other textarea
const reasonExtraTA  = document.querySelector('#other_form textarea[name="reason_extra"]');
const reasonExtraLen = document.getElementById('reason_extra_len');
if (reasonExtraTA && reasonExtraLen) {
    reasonExtraTA.addEventListener('input', function () {
        reasonExtraLen.textContent = this.value.length;
    });
    // Init on load (for repopulated value after validation error)
    reasonExtraLen.textContent = reasonExtraTA.value.length;
}

// FIX #7: disable preset reason buttons on submit to prevent double-posting
document.querySelectorAll('.reason-form').forEach(function (f) {
    f.addEventListener('submit', function () {
        this.querySelectorAll('button[type=submit]').forEach(function (b) {
            b.disabled = true;
            b.style.opacity = '0.6';
            b.style.cursor  = 'not-allowed';
        });
    });
});

// FIX #7: disable Other submit button on submit
const otherForm = document.getElementById('other_form');
if (otherForm) {
    otherForm.addEventListener('submit', function () {
        const btn = document.getElementById('other_submit_btn');
        if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; btn.style.cursor = 'not-allowed'; }
    });
}
</script>

<?php include('../includes/footer.php'); ?>