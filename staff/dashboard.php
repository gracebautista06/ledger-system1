<?php
$page_title = 'Dashboard';

include('../includes/db.php');

include('../includes/header.php');
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header('Location: ../portal/login.php'); exit();
}

include('../includes/log_activity.php');

$username = $_SESSION['username'];
$staff_id = (int) $_SESSION['user_id'];

date_default_timezone_set('Asia/Manila');
$hour     = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

// ── STAT: Today's harvest ──────────────────────────────────────
$stmt = $conn->prepare("SELECT COALESCE(SUM(total_eggs),0) AS logged_today FROM harvests WHERE staff_id=? AND DATE(date_logged)=CURDATE()");
$stmt->bind_param('i', $staff_id); $stmt->execute();
$logged_today = (int)$stmt->get_result()->fetch_assoc()['logged_today'];
$stmt->close();

// ── STAT: Pending edit requests ────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM edit_requests WHERE staff_id=? AND status='Pending'");
$stmt->bind_param('i', $staff_id); $stmt->execute();
$my_pending = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// ── STAT: Reviewed requests (Approved/Rejected) with owner notes ──
$stmt = $conn->prepare("
    SELECT request_type, record_type, status, owner_note, reviewed_at
    FROM edit_requests
    WHERE staff_id = ? AND status IN ('Approved','Rejected') AND reviewed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY reviewed_at DESC
    LIMIT 5
");
$stmt->bind_param('i', $staff_id); $stmt->execute();
$reviewed_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── STAT: Today's sales ────────────────────────────────────────
$stmt = $conn->prepare("SELECT COALESCE(SUM(quantity_sold),0) AS sold_today, COALESCE(SUM(total_amount),0) AS revenue_today FROM sales WHERE staff_id=? AND DATE(date_sold)=CURDATE()");
$stmt->bind_param('i', $staff_id); $stmt->execute();
$sales_today   = $stmt->get_result()->fetch_assoc();
$stmt->close();

$trays_today   = (int)$sales_today['sold_today'];
$revenue_today = (float)$sales_today['revenue_today'];
$goal          = 500;
$progress_pct  = min(100, $goal > 0 ? round(($logged_today / $goal) * 100) : 0);

// ── CHART: Personal harvest last 7 days ───────────────────────
$chart_labels = []; $chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_eggs),0) AS total FROM harvests WHERE staff_id=? AND DATE(date_logged)=?");
    $stmt->bind_param('is', $staff_id, $d);
    $stmt->execute();
    $chart_labels[] = date('D', strtotime($d));
    $chart_data[]   = (int)$stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
}
$js_labels = json_encode($chart_labels);
$js_data   = json_encode($chart_data);

// ── RECENT ACTIVITY: Last 5 logs ──────────────────────────────
$stmt = $conn->prepare("
    SELECT 'harvest' AS type, date_logged AS log_date, total_eggs AS value, NULL AS extra
    FROM harvests WHERE staff_id=?
    UNION ALL
    SELECT 'sale' AS type, date_sold AS log_date, quantity_sold AS value, total_amount AS extra
    FROM sales WHERE staff_id=?
    UNION ALL
    SELECT 'health' AS type, date_reported AS log_date, NULL AS value, status_level AS extra
    FROM flock_health WHERE staff_id=?
    ORDER BY log_date DESC LIMIT 6
");
$stmt->bind_param('iii', $staff_id, $staff_id, $staff_id);
$stmt->execute();
$recent_logs = $stmt->get_result();
$stmt->close();

// ── FLOCK HEALTH SUMMARY ──────────────────────────────────────
$health_q = $conn->query("
    SELECT b.batch_id, b.breed, fh.status_level, fh.date_reported
    FROM flock_health fh
    JOIN batches b ON fh.batch_id = b.batch_id
    WHERE b.status = 'Active'
      AND fh.report_id = (SELECT MAX(fh2.report_id) FROM flock_health fh2 WHERE fh2.batch_id = fh.batch_id)
    ORDER BY FIELD(fh.status_level,'Critical','Warning','Healthy')
    LIMIT 4
");
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
.db-header { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:2rem; }
.db-header__greeting { color:var(--gold); font-family:'Playfair Display',serif; font-size:1.75rem; font-weight:600; margin:0 0 4px; }
.db-header__sub { color:var(--text-muted); font-size:0.875rem; margin:0; }

.alert--warning { display:flex; align-items:center; gap:10px; padding:12px 16px; border:1px solid var(--warning); border-radius:6px; color:var(--warning); font-size:0.875rem; margin-bottom:1.5rem; }
.alert--warning a { color:var(--warning); font-weight:600; margin-left:auto; text-decoration:none; white-space:nowrap; }

.stats-grid { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:2rem; }
.stat-card { flex:1; min-width:160px; background:var(--card-bg,#1e1e1e); border-radius:8px; padding:1.25rem 1.5rem; display:flex; flex-direction:column; gap:4px; }
.stat-label { font-size:0.75rem; font-weight:500; text-transform:uppercase; letter-spacing:0.06em; color:var(--text-muted); }
.stat-value { font-size:1.6rem; font-weight:700; color:var(--text-primary,#fff); line-height:1.2; }
.stat-sub { font-size:0.8rem; color:var(--text-muted); }
.progress-bar { margin-top:10px; background:var(--bg-plank,#2a2a2a); border-radius:4px; height:4px; overflow:hidden; }
.progress-bar__fill { height:100%; border-radius:4px; background:var(--terra-lt); transition:width 0.5s ease; }

/* ── Bottom 2-col grid ── */
.dash-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:2rem; }
@media(max-width:768px){ .dash-grid { grid-template-columns:1fr; } }

.dash-card { background:var(--card-bg,#1e1e1e); border-radius:8px; padding:1.4rem 1.6rem; }
.dash-card__title { font-size:0.82rem; font-weight:700; text-transform:uppercase; letter-spacing:0.7px; color:var(--text-muted); margin-bottom:1.1rem; display:flex; align-items:center; gap:8px; }
.dash-card__title i { color:var(--gold); font-size:0.88rem; }

/* Activity feed */
.activity-item { display:flex; align-items:flex-start; gap:10px; padding:8px 0; border-bottom:1px solid var(--border-subtle); }
.activity-item:last-child { border-bottom:none; }
.activity-icon { width:30px; height:30px; min-width:30px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.75rem; }
.activity-icon.harvest { background:rgba(232,168,56,0.12); color:var(--gold); }
.activity-icon.sale    { background:rgba(78,155,91,0.12);  color:var(--success); }
.activity-icon.health  { background:rgba(194,100,58,0.12); color:var(--terra-lt); }
.activity-text { flex:1; }
.activity-text strong { font-size:0.83rem; color:var(--text-primary); font-weight:600; }
.activity-text span   { font-size:0.75rem; color:var(--text-muted); display:block; margin-top:1px; }

/* Flock health */
.flock-row { display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid var(--border-subtle); }
.flock-row:last-child { border-bottom:none; }
.flock-name { font-size:0.83rem; font-weight:600; color:var(--text-primary); }
.flock-date { font-size:0.72rem; color:var(--text-muted); margin-top:1px; }

/* Quick links row */
.quick-links { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:2rem; }
.quick-link { display:inline-flex; align-items:center; gap:7px; padding:8px 14px; background:var(--bg-wood); border-radius:6px; text-decoration:none; font-size:0.8rem; font-weight:600; color:var(--text-secondary); border:1px solid var(--border-subtle); transition:background 0.15s, color 0.15s; }
.quick-link:hover { background:var(--bg-plank); color:var(--gold); border-color:var(--gold-dim); }
.quick-link i { font-size:0.82rem; }
</style>

<!-- ── PAGE HEADER ─────────────────────────────────────────── -->
<div class="db-header">
    <div>
        <h1 class="db-header__greeting"><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($username) ?></h1>
      
    </div>
</div>

<!-- ── PENDING ALERT ──────────────────────────────────────── -->
<?php if ($my_pending > 0): ?>
<div class="alert--warning">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <?= $my_pending ?> pending edit <?= $my_pending === 1 ? 'request' : 'requests' ?> awaiting review.
    <a href="view_logs.php">View Logs</a>
</div>
<?php endif; ?>

<!-- ── QUICK LINKS ────────────────────────────────────────── -->
<div class="quick-links">
    <a href="log_harvest.php" class="quick-link"><i class="fa-solid fa-egg"></i> Log Harvest</a>
    <a href="log_sale.php"    class="quick-link"><i class="fa-solid fa-cart-shopping"></i> New Sale</a>
    <a href="log_health.php"  class="quick-link"><i class="fa-solid fa-heart-pulse"></i> Flock Health</a>
    <a href="view_logs.php"   class="quick-link"><i class="fa-solid fa-rectangle-list"></i> My Logs</a>
</div>

<!-- ── STAT CARDS ─────────────────────────────────────────── -->
<div class="stats-grid">

    <div class="stat-card" style="border-left:3px solid var(--gold-dim);">
        <span class="stat-label">Daily Target</span>
        <span class="stat-value"><?= number_format($goal) ?></span>
        <span class="stat-sub">eggs — production goal</span>
    </div>

    <div class="stat-card" style="border-left:3px solid var(--terra-lt);">
        <span class="stat-label">Logged Today</span>
        <span class="stat-value"><?= number_format($logged_today) ?></span>
        <span class="stat-sub"><?= $logged_today >= $goal ? '✔ Target reached' : number_format(max(0,$goal-$logged_today)).' remaining' ?></span>
        <div class="progress-bar">
            <div class="progress-bar__fill" style="width:<?= $progress_pct ?>%;"></div>
        </div>
    </div>

    <div class="stat-card" style="border-left:3px solid var(--success);">
        <span class="stat-label">Today's Sales</span>
        <span class="stat-value"><?= number_format($trays_today) ?> <small style="font-size:1rem;font-weight:500;">trays</small></span>
        <span class="stat-sub"><?= $trays_today > 0 ? '₱'.number_format($revenue_today,2).' revenue' : 'No sales recorded' ?></span>
    </div>

</div>

<!-- ── BOTTOM GRID ────────────────────────────────────────── -->
<div class="dash-grid">

    <!-- Weekly Harvest Chart -->
    <div class="dash-card">
        <div class="dash-card__title">
            <i class="fa-solid fa-chart-column"></i> My Harvest — Last 7 Days
        </div>
        <canvas id="harvestChart" style="max-height:200px;"></canvas>
    </div>

    <!-- Recent Activity Feed -->
    <div class="dash-card">
        <div class="dash-card__title">
            <i class="fa-solid fa-clock-rotate-left"></i> Recent Activity
        </div>
        <?php if ($recent_logs && $recent_logs->num_rows > 0):
            while ($row = $recent_logs->fetch_assoc()):
                $type = $row['type'];
                if ($type === 'harvest') {
                    $icon  = 'fa-solid fa-egg';
                    $label = number_format((int)$row['value']) . ' eggs logged';
                } elseif ($type === 'sale') {
                    $icon  = 'fa-solid fa-cart-shopping';
                    $label = number_format((int)$row['value']) . ' trays sold — ₱' . number_format((float)$row['extra'], 2);
                } else {
                    $icon  = 'fa-solid fa-heart-pulse';
                    $label = 'Flock health: ' . htmlspecialchars($row['extra'] ?? '—');
                }
                $date_label = date('M d, g:i a', strtotime($row['log_date']));
        ?>
        <div class="activity-item">
            <div class="activity-icon <?= $type ?>">
                <i class="<?= $icon ?>"></i>
            </div>
            <div class="activity-text">
                <strong><?= $label ?></strong>
                <span><?= $date_label ?></span>
            </div>
        </div>
        <?php endwhile; else: ?>
        <div style="color:var(--text-muted); font-size:0.83rem; padding:1rem 0;">No recent activity yet.</div>
        <?php endif; ?>
    </div>

    <!-- Flock Health Summary -->
    <div class="dash-card">
        <div class="dash-card__title">
            <i class="fa-solid fa-shield-heart"></i> Active Flock Health
        </div>
        <?php if ($health_q && $health_q->num_rows > 0):
            while ($row = $health_q->fetch_assoc()):
                $bc = match($row['status_level']) {
                    'Healthy'  => 'badge-healthy',
                    'Warning'  => 'badge-warning',
                    'Critical' => 'badge-critical',
                    default    => 'badge-pending'
                };
        ?>
        <div class="flock-row">
            <div>
                <div class="flock-name">Batch #<?= $row['batch_id'] ?> — <?= htmlspecialchars($row['breed']) ?></div>
                <div class="flock-date"><?= date('M d, Y', strtotime($row['date_reported'])) ?></div>
            </div>
            <span class="badge <?= $bc ?>"><?= $row['status_level'] ?></span>
        </div>
        <?php endwhile; else: ?>
        <div style="color:var(--text-muted); font-size:0.83rem; padding:1rem 0;">No health reports on record.</div>
        <?php endif; ?>
    </div>

    <!-- Tips / Reminders card -->
    <div class="dash-card">
        <div class="dash-card__title">
            <i class="fa-solid fa-lightbulb"></i> Daily Reminders
        </div>
        <div style="display:flex; flex-direction:column; gap:10px;">
            <div style="display:flex; gap:10px; align-items:flex-start;">
                <i class="fa-solid fa-check-circle" style="color:var(--success); margin-top:2px;"></i>
                <span style="font-size:0.83rem; color:var(--text-secondary);">Log your harvest <strong>before end of shift</strong> to keep records accurate.</span>
            </div>
            <div style="display:flex; gap:10px; align-items:flex-start;">
                <i class="fa-solid fa-check-circle" style="color:var(--success); margin-top:2px;"></i>
                <span style="font-size:0.83rem; color:var(--text-secondary);">Report any flock health concerns <strong>immediately</strong> to management.</span>
            </div>
            <div style="display:flex; gap:10px; align-items:flex-start;">
                <i class="fa-solid fa-check-circle" style="color:var(--success); margin-top:2px;"></i>
                <span style="font-size:0.83rem; color:var(--text-secondary);">Double-check tray counts before submitting a <strong>sale record</strong>.</span>
            </div>
            <?php if ($my_pending > 0): ?>
            <div style="display:flex; gap:10px; align-items:flex-start;">
                <i class="fa-solid fa-triangle-exclamation" style="color:var(--warning); margin-top:2px;"></i>
                <span style="font-size:0.83rem; color:var(--warning);">You have <strong><?= $my_pending ?> pending edit request<?= $my_pending > 1 ? 's' : '' ?></strong> — check your logs.</span>
            </div>
            <?php endif; ?>

            <?php if (!empty($reviewed_requests)): ?>
            <div style="border-top:1px solid var(--border-subtle); margin-top:10px; padding-top:10px;">
                <div style="font-size:0.72rem; font-weight:700; text-transform:uppercase; letter-spacing:0.7px;
                            color:var(--text-muted); margin-bottom:8px;">
                    <i class="fa-solid fa-envelope-open-text" style="color:var(--gold); margin-right:5px;"></i>
                    Owner Responses (Last 7 Days)
                </div>
                <?php foreach ($reviewed_requests as $rr):
                    $is_approved = $rr['status'] === 'Approved';
                    $icon_color  = $is_approved ? 'var(--success)' : '#e03131';
                    $icon_class  = $is_approved ? 'fa-circle-check' : 'fa-circle-xmark';
                    $badge_bg    = $is_approved ? 'rgba(78,155,91,0.12)' : 'rgba(224,49,49,0.10)';
                    $badge_color = $is_approved ? 'var(--success)' : '#e03131';
                    $label       = ucfirst(strtolower($rr['request_type'] ?? 'Edit')) . ' request';
                    $record      = htmlspecialchars(ucfirst(str_replace('_',' ', $rr['record_type'] ?? '')));
                    $note        = htmlspecialchars($rr['owner_note'] ?? '');
                    $date        = date('M d, g:i a', strtotime($rr['reviewed_at']));
                ?>
                <div style="display:flex; gap:10px; align-items:flex-start; padding:8px 10px;
                            background:<?= $badge_bg ?>; border-radius:6px; margin-bottom:6px;
                            border-left:3px solid <?= $badge_color ?>;">
                    <i class="fa-solid <?= $icon_class ?>" style="color:<?= $icon_color ?>; margin-top:2px; flex-shrink:0;"></i>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:0.8rem; font-weight:600; color:var(--text-primary);">
                            <?= $rr['status'] ?> — <?= $label ?> <span style="color:var(--text-muted); font-weight:400;">(<?= $record ?>)</span>
                        </div>
                        <?php if (!empty($note)): ?>
                        <div style="font-size:0.78rem; color:var(--text-secondary); margin-top:3px; font-style:italic;">
                            "<?= $note ?>"
                        </div>
                        <?php else: ?>
                        <div style="font-size:0.75rem; color:var(--text-muted); margin-top:2px;">No note left by owner.</div>
                        <?php endif; ?>
                        <div style="font-size:0.7rem; color:var(--text-muted); margin-top:3px;"><?= $date ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ── CHART SCRIPT ────────────────────────────────────────── -->
<script>
new Chart(document.getElementById('harvestChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= $js_labels ?>,
        datasets: [{
            label: 'Eggs',
            data: <?= $js_data ?>,
            backgroundColor: 'rgba(232,168,56,0.18)',
            borderColor: '#E8A838',
            borderWidth: 2,
            borderRadius: 5,
            hoverBackgroundColor: 'rgba(232,168,56,0.35)'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#2E2720', titleColor: '#F2EAD8', bodyColor: '#B8A88A',
                callbacks: { label: c => ` ${c.parsed.y.toLocaleString()} eggs` }
            }
        },
        scales: {
            y: { beginAtZero:true, grid:{ color:'rgba(232,168,56,0.06)' }, ticks:{ color:'#7A6E5E', precision:0 } },
            x: { grid:{ display:false }, ticks:{ color:'#7A6E5E' } }
        }
    }
});
</script>