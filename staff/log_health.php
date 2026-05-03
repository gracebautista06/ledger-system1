<?php
/*  staff/log_health.php — Flock Health Report  */
$page_title = 'Flock Health Report';

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
$batch_stmt->close();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $staff_id        = (int) $_SESSION['user_id'];
    $batch_id        = (int) ($_POST['batch_id'] ?? 0);
    $mortality_count = max(0, (int) ($_POST['mortality_count'] ?? 0));
    $symptoms        = trim($_POST['symptoms'] ?? '');

    $allowed_levels = ['Healthy', 'Warning', 'Critical'];
    $status_level   = in_array($_POST['status_level'] ?? '', $allowed_levels) ? $_POST['status_level'] : 'Healthy';

    $check = $conn->prepare("SELECT batch_id FROM batches WHERE batch_id=? AND status='Active' LIMIT 1");
    $check->bind_param("i", $batch_id);
    $check->execute();
    $check->store_result();
    $valid_batch = $check->num_rows > 0;
    $check->close();

    if (!$valid_batch) {
        $message = "<div class='alert error'>⚠️ Please select a valid active batch.</div>";
    } else {
        $ins = $conn->prepare("INSERT INTO flock_health (staff_id, batch_id, status_level, mortality_count, symptoms) VALUES (?,?,?,?,?)");
        $ins->bind_param("iisis", $staff_id, $batch_id, $status_level, $mortality_count, $symptoms);
        if ($ins->execute()) {
            $report_id = $conn->insert_id;
            $ins->close();
            // Log activity
            log_activity($conn, $staff_id, 'Staff', 'Health Added',
                "Batch #{$batch_id} — Status: {$status_level}, Mortality: {$mortality_count} (Report #{$report_id})");
            header("Location: view_logs.php?health_saved=1"); exit();
        } else {
            $message = "<div class='alert error'>Database error. Please try again.</div>";
        }
        $ins->close();
    }
}
?>

<div class="card" style="max-width:600px; margin:2rem auto; border-top:5px solid var(--terra-lt);">
    <h2 style="color:var(--gold); font-family:'Playfair Display',serif;">🐔 Flock Health Report</h2>
    <p style="color:var(--text-muted); margin-bottom:2rem;">Report bird deaths, illness signs, or general observations.</p>

    <?php echo $message; ?>

    <form method="POST" id="healthForm">
        <div class="form-group">
            <label>Select Flock Batch</label>
            <select name="batch_id" class="form-input" required>
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

        <div class="form-group">
            <label>Overall Health Status</label>
            <select name="status_level" class="form-input" required>
                <option value="Healthy">🟢 Healthy — Normal behavior, no issues</option>
                <option value="Warning">🟡 Warning — Minor concerns observed</option>
                <option value="Critical">🔴 Critical — High mortality or disease signs</option>
            </select>
        </div>

        <div class="form-group">
            <label>Mortality Count (Birds Found Dead Today)</label>
            <input type="number" name="mortality_count" class="form-input" value="0" min="0" required>
        </div>

        <div class="form-group">
            <label>Observations / Symptoms</label>
            <textarea name="symptoms" class="form-input" rows="4"
                      placeholder="e.g., Birds sluggish, reduced feed intake — or — All birds active and eating normally."></textarea>
        </div>

        <button type="submit" class="btn-farm btn-orange btn-full" style="padding:16px;">
            Submit Health Report 🐔
        </button>
        <a href="dashboard.php" id="backBtn" class="back-link" style="display:block; text-align:center; margin-top:1rem;">
            ← Back to Dashboard
        </a>
    </form>
</div>

<script>
let isDirty = false;
const healthForm = document.getElementById('healthForm');
healthForm.addEventListener('input', () => isDirty = true);
healthForm.addEventListener('submit', () => isDirty = false);
window.addEventListener('beforeunload', e => { if (isDirty) { e.preventDefault(); e.returnValue = ''; } });
document.getElementById('backBtn').addEventListener('click', e => {
    if (isDirty && !confirm("Discard unsaved health report?")) e.preventDefault();
});
</script>

<?php include('../includes/footer.php'); ?>