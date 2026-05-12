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

<div class="card" style="max-width:1100px; margin:2rem auto; border-top:5px solid var(--gold);">

    <h2 style="color:var(--gold); font-family:'Playfair Display',serif;">Daily Harvest Log</h2>
    <p style="color:var(--text-muted); margin-bottom:2rem;">Log counts per egg size.</p>

    <?php echo $message; ?>

    <form method="POST" id="harvestForm">
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; min-width:900px;">
                <thead>
                    <tr style="background:var(--bg-wood); border-bottom:2px solid var(--border-mid);">
                        <th style="padding:10px 12px; text-align:left; font-size:0.72rem; font-weight:700;
                                   color:var(--gold-muted); text-transform:uppercase; letter-spacing:0.7px;
                                   white-space:nowrap; min-width:160px;">
                            Flock Batch
                        </th>
                        <?php
                        $sizes = ['size_pw'=>'Peewee (PW)','size_s'=>'Small (S)','size_m'=>'Medium (M)','size_l'=>'Large (L)','size_xl'=>'XLarge (XL)','size_j'=>'Jumbo (J)'];
                        foreach ($sizes as $name => $label): ?>
                        <th style="padding:10px 12px; text-align:center; font-size:0.72rem; font-weight:700;
                                   color:var(--gold-muted); text-transform:uppercase; letter-spacing:0.7px;
                                   white-space:nowrap;">
                            <?php echo $label; ?>
                        </th>
                        <?php endforeach; ?>
                        <th style="padding:10px 12px; text-align:left; font-size:0.72rem; font-weight:700;
                                   color:var(--text-muted); text-transform:uppercase; letter-spacing:0.7px;
                                   min-width:160px;">
                            Notes
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background:var(--bg-plank);">
                        <td style="padding:12px;">
                            <select name="batch_id" id="batch_id" class="form-input" required
                                    style="min-width:140px; font-size:0.88rem;">
                                <option value="" disabled selected>-- Select --</option>
                                <?php
                                if ($batch_query && $batch_query->num_rows > 0) {
                                    while ($b = $batch_query->fetch_assoc()) {
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
                        </td>
                        <?php foreach ($sizes as $name => $label): ?>
                        <td style="padding:12px 8px; text-align:center;">
                            <input type="number" name="<?php echo $name; ?>"
                                   class="form-input egg-count"
                                   value="" placeholder="0"
                                   min="0" max="9999"
                                   style="text-align:center; padding:10px 6px; font-size:1rem;
                                          font-weight:600; min-width:70px;">
                        </td>
                        <?php endforeach; ?>
                        <td style="padding:12px;">
                            <textarea name="notes" id="notes" class="form-input" rows="2"
                                      placeholder="Cracked eggs, observations, issues…"
                                      style="min-width:150px; font-size:0.88rem;"></textarea>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="margin-top:1.4rem;">
            <button type="submit" id="submitBtn" class="btn-farm btn-full" style="padding:16px; font-size:1rem;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;"><polyline points="20 6 9 17 4 12"/></svg><span id="submitLabel">Submit Harvest</span>
            </button>
        </div>

    </form>
</div>

<script>
const sizeInputs   = document.querySelectorAll('.egg-count');
const harvestForm  = document.getElementById('harvestForm');
let isDirty = false;

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
</script>