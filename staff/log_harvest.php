<?php
/*  staff/log_harvest.php — Daily Harvest Logger  */
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

    $calculated_total = $pw + $s + $m + $l + $xl + $j;

    $check = $conn->prepare("SELECT batch_id FROM batches WHERE batch_id=? AND status='Active' LIMIT 1");
    $check->bind_param("i", $batch_id);
    $check->execute();
    $check->store_result();
    $valid_batch = $check->num_rows > 0;
    $check->close();

    if (!$valid_batch) {
        $message = "<div class='alert error'>⚠️ Invalid batch selected. Please choose an active batch.</div>";
    } elseif ($calculated_total === 0) {
        $message = "<div class='alert error'>⚠️ Please enter at least one egg count before submitting.</div>";
    } else {
        $ins = $conn->prepare("INSERT INTO harvests (staff_id, batch_id, total_eggs, size_pw, size_s, size_m, size_l, size_xl, size_j, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $ins->bind_param("iiiiiiiiss", $staff_id, $batch_id, $calculated_total, $pw, $s, $m, $l, $xl, $j, $notes);
        if ($ins->execute()) {
            $harvest_id = $conn->insert_id;
            $ins->close();
            // Log activity
            log_activity($conn, $staff_id, 'Staff', 'Harvest Added',
                "Logged {$calculated_total} eggs for Batch #{$batch_id} (Harvest #{$harvest_id})");
            header("Location: view_logs.php?harvest_saved=1"); exit();
        } else {
            $message = "<div class='alert error'>Database error. Please try again.</div>";
        }
        $ins->close();
    }
}
?>

<div class="card" style="max-width:650px; margin:2rem auto; border-top:5px solid var(--gold);">

    <h2 style="color:var(--gold); font-family:'Playfair Display',serif;">🧺 Daily Harvest Log</h2>
    <p style="color:var(--text-muted); margin-bottom:2rem;">Log counts per egg size. Total is calculated automatically.</p>

    <?php echo $message; ?>

    <form method="POST" id="harvestForm">
        <div class="form-group">
            <label for="batch_id">Select Flock Batch</label>
            <select name="batch_id" id="batch_id" class="form-input" required>
                <option value="" disabled selected>-- Select Active Batch --</option>
                <?php
                if ($batch_query && $batch_query->num_rows > 0) {
                    while ($b = $batch_query->fetch_assoc()) {
                        echo "<option value='{$b['batch_id']}'>Batch #{$b['batch_id']} ({$b['breed']})</option>";
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
                    <input type="number" name="<?php echo $name; ?>" class="form-input egg-count" value="0" min="0" required>
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

        <button type="submit" class="btn-farm btn-full" style="padding:16px; font-size:1rem;">
            Submit Harvest 🧺
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
harvestForm.addEventListener('submit', () => isDirty = false);
window.addEventListener('beforeunload', e => { if (isDirty) { e.preventDefault(); e.returnValue = ''; } });
document.getElementById('backBtn').addEventListener('click', e => {
    if (isDirty && !confirm("You have unsaved data. Leave anyway?")) e.preventDefault();
});
sizeInputs.forEach(i => i.addEventListener('input', calculateTotal));
calculateTotal();
</script>

<?php include('../includes/footer.php'); ?>