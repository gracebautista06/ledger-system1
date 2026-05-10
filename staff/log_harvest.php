<?php
$page_title = 'Log Harvest';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header("Location: ../portal/login.php"); exit();
}

$message = "";

$batch_stmt = $conn->prepare("SELECT batch_id, breed FROM batches WHERE status='Active' ORDER BY batch_id ASC");
$batch_stmt->execute();
$batch_query = $batch_stmt->get_result();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $staff_id = (int) $_SESSION['user_id'];
    $batch_id = (int) ($_POST['batch_id'] ?? 0);
    $pw  = max(0, (int) ($_POST['size_pw'] ?? 0));
    $s   = max(0, (int) ($_POST['size_s']  ?? 0));
    $m   = max(0, (int) ($_POST['size_m']  ?? 0));
    $l   = max(0, (int) ($_POST['size_l']  ?? 0));
    $xl  = max(0, (int) ($_POST['size_xl'] ?? 0));
    $j   = max(0, (int) ($_POST['size_j']  ?? 0));
    $notes = trim($_POST['notes'] ?? '');

    // FIX #11: cap each size to a sane maximum to catch fat-finger entries
    $max_per_size = 9999;
    $pw = min($pw, $max_per_size);
    $s  = min($s,  $max_per_size);
    $m  = min($m,  $max_per_size);
    $l  = min($l,  $max_per_size);
    $xl = min($xl, $max_per_size);
    $j  = min($j,  $max_per_size);

    $calculated_total = $pw + $s + $m + $l + $xl + $j;

    $check = $conn->prepare("SELECT batch_id FROM batches WHERE batch_id=? AND status='Active' LIMIT 1");
    $check->bind_param("i", $batch_id);
    $check->execute();
    $check->store_result();
    $valid_batch = $check->num_rows > 0;
    $check->close();

    if (!$valid_batch) {
        // FIX #9: use Tabler icon instead of leading space character
        $message = "<div class='alert error'><i class='ti ti-alert-circle' style='margin-right:6px;vertical-align:-2px;'></i>Invalid batch selected. Please choose an active batch.</div>";
    } elseif ($calculated_total === 0) {
        $message = "<div class='alert error'><i class='ti ti-alert-circle' style='margin-right:6px;vertical-align:-2px;'></i>Please enter at least one egg count before submitting.</div>";
    } else {
        $ins = $conn->prepare("INSERT INTO harvests (staff_id, batch_id, total_eggs, size_pw, size_s, size_m, size_l, size_xl, size_j, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $ins->bind_param("iiiiiiiiss", $staff_id, $batch_id, $calculated_total, $pw, $s, $m, $l, $xl, $j, $notes);
        // FIX #4: clean single close() path — no duplicate close risk
        if ($ins->execute()) {
            $harvest_id = $conn->insert_id;
            $ins->close();
            log_activity($conn, $staff_id, 'Staff', 'Harvest Added',
                "Logged {$calculated_total} eggs for Batch #{$batch_id} (Harvest #{$harvest_id})");
            header("Location: view_logs.php?harvest_saved=1");
            exit();
        }
        $ins->close();
        $message = "<div class='alert error'><i class='ti ti-alert-circle' style='margin-right:6px;vertical-align:-2px;'></i>Database error. Please try again.</div>";
    }
}
?>

<div class="card" style="max-width:650px; margin:2rem auto; border-top:5px solid var(--gold);">

    <h2 style="color:var(--gold); font-family:'Playfair Display',serif;">Daily Harvest Log</h2>
    <p style="color:var(--text-muted); margin-bottom:2rem;">Log counts per egg size.</p>

    <?php echo $message; ?>

    <form method="POST" id="harvestForm">
        <div class="form-group">
            <label for="batch_id">Select Flock Batch</label>
            <select name="batch_id" id="batch_id" class="form-input" required>
                <option value="" disabled selected>-- Select Active Batch --</option>
                <?php
                if ($batch_query && $batch_query->num_rows > 0) {
                    while ($b = $batch_query->fetch_assoc()) {
                        // FIX #6: escape breed (varchar) to prevent XSS
                        echo "<option value='" . (int)$b['batch_id'] . "'>"
                           . "Batch #" . (int)$b['batch_id']
                           . " (" . htmlspecialchars($b['breed'], ENT_QUOTES, 'UTF-8') . ")"
                           . "</option>";
                    }
                } else {
                    echo "<option disabled>No active batches</option>";
                }
                ?>
            </select>
        </div>

        <!-- Size Breakdown -->
        <div style="background:var(--bg-wood); padding:18px; border-radius:var(--radius); border:1px solid var(--border-mid); margin-bottom:18px;">
            <p style="font-weight:700; margin-bottom:14px; color:var(--gold-muted); font-size:0.78rem; text-transform:uppercase; letter-spacing:1px;">
                Egg Size Breakdown
            </p>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                <?php
                $sizes = ['size_pw'=>'Peewee (PW)','size_s'=>'Small (S)','size_m'=>'Medium (M)','size_l'=>'Large (L)','size_xl'=>'XLarge (XL)','size_j'=>'Jumbo (J)'];
                foreach ($sizes as $name => $label): ?>
                <div class="form-group" style="margin-bottom:0;">
                    <label><?php echo $label; ?></label>
                    <!-- FIX #10: blank placeholder so staff types rather than clears;
                         FIX #11: max="9999" to cap fat-finger input -->
                    <input type="number" name="<?php echo $name; ?>"
                           class="form-input egg-count"
                           value="" placeholder="0"
                           min="0" max="9999" required>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Live Total -->
        <div class="form-group" style="background:var(--bg-plank); padding:16px; border-radius:var(--radius); text-align:center; margin-bottom:1.5rem; border:1px solid var(--border-mid);">
            <label style="color:var(--text-muted); display:block; margin-bottom:6px; font-size:0.75rem; text-transform:uppercase; letter-spacing:0.8px;">
                Total Eggs Harvested
            </label>
            <div id="total_display" style="font-size:2.8rem; font-weight:800; color:var(--gold); font-family:'Playfair Display',serif; line-height:1;">0</div>
        </div>

        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea name="notes" id="notes" class="form-input" rows="2" placeholder="Cracked eggs, observations, issues…"></textarea>
        </div>

        <button type="submit" id="submitBtn" class="btn-farm btn-full" style="padding:16px; font-size:1rem;">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;"><polyline points="20 6 9 17 4 12"/></svg><span id="submitLabel">Submit Harvest</span>
        </button>

        <a href="dashboard.php" id="backBtn" class="back-link" style="display:block; text-align:center; margin-top:1rem;">
            ← Back to Dashboard
        </a>
    </form>
</div>

<script>
const sizeInputs   = document.querySelectorAll('.egg-count');
const totalDisplay = document.getElementById('total_display');
const harvestForm  = document.getElementById('harvestForm');
let isDirty = false;

function calculateTotal() {
    let total = 0;
    sizeInputs.forEach(i => total += Math.max(0, parseInt(i.value) || 0));
    totalDisplay.textContent = total.toLocaleString();
}

harvestForm.addEventListener('input', () => isDirty = true);

// FIX #12: disable submit button on submit to prevent double-submission
harvestForm.addEventListener('submit', function () {
    isDirty = false;
    const btn   = document.getElementById('submitBtn');
    const label = document.getElementById('submitLabel');
    btn.disabled      = true;
    btn.style.opacity = '0.6';
    if (label) label.textContent = 'Submitting…';
});

window.addEventListener('beforeunload', e => { if (isDirty) { e.preventDefault(); e.returnValue = ''; } });
document.getElementById('backBtn').addEventListener('click', e => {
    if (isDirty && !confirm("You have unsaved data. Leave anyway?")) e.preventDefault();
});
sizeInputs.forEach(i => i.addEventListener('input', calculateTotal));
calculateTotal();
</script>

<?php include('../includes/footer.php'); ?>