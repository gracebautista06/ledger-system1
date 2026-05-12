<?php
$page_title = 'Owner Dashboard';

session_start();
include('../includes/db.php');

// ✅ Role check BEFORE header
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header("Location: ../portal/login.php"); exit();
}

include('../includes/header.php');
include('../includes/notifications.php');


// ── STATS ─────────────────────────────────────────────────────
$req_query     = $conn->query("SELECT COUNT(*) AS total FROM edit_requests WHERE status='Pending'");
$pending_count = (int)$req_query->fetch_assoc()['total'];

$today_query  = $conn->query("SELECT COALESCE(SUM(total_eggs),0) AS today_eggs FROM harvests WHERE DATE(date_logged)=CURDATE()");
$today_eggs   = (int)$today_query->fetch_assoc()['today_eggs'];

$yest_query   = $conn->query("SELECT COALESCE(SUM(total_eggs),0) AS yest FROM harvests WHERE DATE(date_logged)=DATE_SUB(CURDATE(),INTERVAL 1 DAY)");
$yest_eggs    = (int)$yest_query->fetch_assoc()['yest'];
$harvest_diff = $today_eggs - $yest_eggs;

$staff_query  = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='Staff'");
$staff_count  = (int)$staff_query->fetch_assoc()['total'];

$rev_query    = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS month_rev FROM sales WHERE MONTH(date_sold)=MONTH(NOW()) AND YEAR(date_sold)=YEAR(NOW())");
$month_rev    = (float)$rev_query->fetch_assoc()['month_rev'];

$prev_rev_q   = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS prev_rev FROM sales WHERE MONTH(date_sold)=MONTH(DATE_SUB(NOW(),INTERVAL 1 MONTH)) AND YEAR(date_sold)=YEAR(DATE_SUB(NOW(),INTERVAL 1 MONTH))");
$prev_rev     = (float)$prev_rev_q->fetch_assoc()['prev_rev'];
$rev_pct      = $prev_rev > 0 ? round((($month_rev - $prev_rev) / $prev_rev) * 100, 1) : null;

// ── CHARTS ────────────────────────────────────────────────────
$chart_labels = []; $chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $q = $conn->query("SELECT COALESCE(SUM(total_eggs),0) AS total FROM harvests WHERE DATE(date_logged)='$d'");
    $chart_labels[] = date('D', strtotime($d));
    $chart_data[]   = (int)$q->fetch_assoc()['total'];
}

$rev_labels = []; $rev_data = [];
for ($i = 5; $i >= 0; $i--) {
    $m_start = date('Y-m-01', strtotime("-$i months"));
    $m_end   = date('Y-m-t',  strtotime("-$i months"));
    $rev_q   = $conn->query("SELECT COALESCE(SUM(total_amount),0) AS rev FROM sales WHERE DATE(date_sold) BETWEEN '$m_start' AND '$m_end'");
    $rev_labels[] = date('M', strtotime($m_start));
    $rev_data[]   = round((float)$rev_q->fetch_assoc()['rev'], 2);
}

// ── TOP CUSTOMERS ─────────────────────────────────────────────
$top_cust = $conn->query("
    SELECT customer_name, COUNT(*) AS txn_count,
           SUM(total_amount) AS total_spent, SUM(quantity_sold) AS trays
    FROM sales
    WHERE MONTH(date_sold)=MONTH(NOW()) AND YEAR(date_sold)=YEAR(NOW())
    GROUP BY customer_name ORDER BY total_spent DESC LIMIT 5
");

// ── FLOCK HEALTH ──────────────────────────────────────────────
$health_q = $conn->query("
    SELECT fh.batch_id, b.breed, fh.status_level, fh.mortality_count, fh.date_reported
    FROM flock_health fh
    JOIN batches b ON fh.batch_id = b.batch_id
    WHERE b.status='Active'
      AND fh.report_id=(SELECT MAX(fh2.report_id) FROM flock_health fh2 WHERE fh2.batch_id=fh.batch_id)
    ORDER BY FIELD(fh.status_level,'Critical','Warning','Healthy')
    LIMIT 4
");

// ── NEXT BATCH REPLACEMENT ────────────────────────────────────
$next_batch = $conn->query("SELECT batch_id,breed,expected_replacement FROM batches WHERE status='Active' AND expected_replacement IS NOT NULL ORDER BY expected_replacement ASC LIMIT 1");
$nb        = $next_batch ? $next_batch->fetch_assoc() : null;
$days_left = $nb ? (int)ceil((strtotime($nb['expected_replacement']) - time()) / 86400) : null;

$js_labels   = json_encode($chart_labels);
$js_data     = json_encode($chart_data);
$js_rev_lbl  = json_encode($rev_labels);
$js_rev_data = json_encode($rev_data);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- ── PAGE HEADER ────────────────────────────────────────── -->
<div class="page-header">
    <div>
        <h2><i class="fa-solid fa-gauge" style="color:var(--gold); margin-right:8px;"></i>Management Dashboard</h2>
        <p>Welcome back, <strong style="color:var(--gold);"><?php echo htmlspecialchars($_SESSION['username']); ?></strong> — here's your farm today.</p>
    </div>

</div>

<!-- ── STAT CARDS ─────────────────────────────────────────── -->
<div style="display:flex; gap:16px; margin-bottom:2rem; flex-wrap:wrap;">

    <div class="stat-card" style="border-top:3px solid var(--success);">
        <div class="stat-label">Today's Harvest</div>
        <div class="stat-value"><?php echo number_format($today_eggs); ?></div>
        <div class="stat-sub">
            <?php if ($harvest_diff > 0): ?>
                <span style="color:var(--success);">▲ <?php echo number_format($harvest_diff); ?></span> vs yesterday
            <?php elseif ($harvest_diff < 0): ?>
                <span style="color:var(--danger);">▼ <?php echo number_format(abs($harvest_diff)); ?></span> vs yesterday
            <?php else: ?>
                Same as yesterday
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-card" style="border-top:3px solid var(--gold);">
        <div class="stat-label">This Month's Revenue</div>
        <div class="stat-value" style="font-size:1.5rem;">₱<?php echo number_format($month_rev, 0); ?></div>
        <div class="stat-sub">
            <?php if ($rev_pct !== null): ?>
                <span style="color:<?php echo $rev_pct >= 0 ? 'var(--success)' : 'var(--danger)'; ?>;">
                    <?php echo $rev_pct >= 0 ? '▲' : '▼'; ?> <?php echo abs($rev_pct); ?>%
                </span> vs last month
            <?php else: ?>
                <?php echo date('F Y'); ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="stat-card" style="border-top:3px solid var(--terra-lt);">
        <div class="stat-label">Active Staff</div>
        <div class="stat-value"><?php echo $staff_count; ?></div>
        <div class="stat-sub">registered farm workers</div>
    </div>

    <a href="staff_notifications.php" style="text-decoration:none; flex:1; min-width:180px;">
        <div class="stat-card" style="border-top:3px solid var(--warning); cursor:pointer; transition:border-color 0.2s;"
             onmouseover="this.style.borderColor='var(--danger)'"
             onmouseout="this.style.borderColor='var(--warning)'">
            <div class="stat-label">Pending Requests</div>
            <div class="stat-value" style="color:<?php echo $pending_count > 0 ? 'var(--danger)' : 'var(--text-primary)'; ?>;">
                <?php echo $pending_count; ?>
            </div>
            <div class="stat-sub" style="color:<?php echo $pending_count > 0 ? 'var(--warning)' : 'var(--text-muted)'; ?>;">
                <?php echo $pending_count > 0 ? 'tap to review ↗' : 'all caught up'; ?>
            </div>
        </div>
    </a>

</div>

<!-- ── CHARTS ROW ─────────────────────────────────────────── -->
<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
    <div class="card" style="padding:1.4rem 1.6rem;">
        <h3 style="margin-bottom:1.2rem; font-size:0.92rem;"> Harvest — Last 7 Days</h3>
        <canvas id="harvestChart" style="max-height:220px;"></canvas>
    </div>
    <div class="card" style="padding:1.4rem 1.6rem;">
        <h3 style="margin-bottom:1.2rem; font-size:0.92rem;"> Revenue — Last 6 Months</h3>
        <canvas id="revenueChart" style="max-height:220px;"></canvas>
    </div>
</div>

<!-- ── ROW 2: CUSTOMERS + HEALTH + ACTIONS ────────────────── -->
<div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-bottom:20px;">

    <!-- Top Customers -->
    <div class="card" style="padding:1.4rem 1.6rem;">
        <h3 style="font-size:0.92rem; margin-bottom:1.2rem;"> Top Customers This Month</h3>
        <?php if ($top_cust && $top_cust->num_rows > 0):
            $rank = 1;
            while ($c = $top_cust->fetch_assoc()):
                $medal = match($rank) { 1=>'🥇', 2=>'🥈', 3=>'🥉', default=>"#{$rank}" };
        ?>
        <div style="display:flex; justify-content:space-between; align-items:center;
                    padding:9px 0; border-bottom:1px solid var(--border-subtle);">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:1.1rem; min-width:24px;"><?php echo $medal; ?></span>
                <div>
                    <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary);">
                        <?php echo htmlspecialchars($c['customer_name']); ?>
                    </div>
                    <div style="font-size:0.72rem; color:var(--text-muted);">
                        <?php echo $c['txn_count']; ?> orders · <?php echo number_format($c['trays']); ?> trays
                    </div>
                </div>
            </div>
            <span style="font-weight:700; font-size:0.85rem; color:var(--success);">
                ₱<?php echo number_format((float)$c['total_spent'], 0); ?>
            </span>
        </div>
        <?php $rank++; endwhile;
        else: ?>
        <div class="empty-state" style="padding:1.5rem 0;">
            <span class="empty-icon"></span><p>No sales this month yet.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Flock Health -->
    <div class="card" style="padding:1.4rem 1.6rem;">
        <h3 style="font-size:0.92rem; margin-bottom:1.2rem;"> Active Flock Health</h3>
        <?php if ($health_q && $health_q->num_rows > 0):
            while ($row = $health_q->fetch_assoc()):
                $bc = match($row['status_level']) { 'Healthy'=>'badge-healthy','Warning'=>'badge-warning','Critical'=>'badge-critical', default=>'badge-pending' };
        ?>
        <div style="display:flex; justify-content:space-between; align-items:center;
                    padding:9px 0; border-bottom:1px solid var(--border-subtle);">
            <div>
                <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary);">
                    Batch #<?php echo $row['batch_id']; ?> — <?php echo htmlspecialchars($row['breed']); ?>
                </div>
                <div style="font-size:0.72rem; color:var(--text-muted);">
                    Mortality: <?php echo $row['mortality_count']; ?> · <?php echo date('M d', strtotime($row['date_reported'])); ?>
                </div>
            </div>
            <span class="badge <?php echo $bc; ?>"><?php echo $row['status_level']; ?></span>
        </div>
        <?php endwhile;
        else: ?>
        <div class="empty-state" style="padding:1.5rem 0;">
            <span class="empty-icon"></span><p>No health reports yet.</p>
        </div>
        <?php endif; ?>

        <?php if ($nb && $days_left !== null): ?>
        <div style="margin-top:14px; background:var(--bg-wood);
                    border-left:3px solid <?php echo $days_left <= 30 ? 'var(--danger)' : 'var(--terra-lt)'; ?>;
                    padding:10px 12px; border-radius:0 6px 6px 0;">
            <div style="font-size:0.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; margin-bottom:4px;">
                Next Replacement
            </div>
            <div style="font-size:0.84rem; color:var(--text-primary);">
                Batch #<?php echo $nb['batch_id']; ?> — <?php echo htmlspecialchars($nb['breed']); ?>
            </div>
            <div style="font-size:0.75rem; color:<?php echo $days_left <= 30 ? 'var(--danger)' : 'var(--text-muted)'; ?>; margin-top:3px;">
                <?php echo date('M d, Y', strtotime($nb['expected_replacement'])); ?>
                (<?php echo $days_left > 0 ? "in $days_left days" : 'Overdue!'; ?>)
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- ── CHART SCRIPTS ─────────────────────────────────────── -->
<script>
const cd = { gc:'rgba(232,168,56,0.06)', tc:'#7A6E5E', bg:'#2E2720', tx:'#F2EAD8' };

new Chart(document.getElementById('harvestChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?php echo $js_labels; ?>,
        datasets: [{ label:'Eggs', data:<?php echo $js_data; ?>,
            backgroundColor:'rgba(232,168,56,0.18)', borderColor:'#E8A838',
            borderWidth:2, borderRadius:5, hoverBackgroundColor:'rgba(232,168,56,0.35)' }]
    },
    options: { responsive:true,
        plugins:{ legend:{display:false},
            tooltip:{ backgroundColor:cd.bg,titleColor:cd.tx,bodyColor:'#B8A88A',
                callbacks:{label:c=>` ${c.parsed.y.toLocaleString()} eggs`} } },
        scales:{ y:{beginAtZero:true,grid:{color:cd.gc},ticks:{color:cd.tc,precision:0}},
                 x:{grid:{display:false},ticks:{color:cd.tc}} } }
});

new Chart(document.getElementById('revenueChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?php echo $js_rev_lbl; ?>,
        datasets: [{ label:'Revenue', data:<?php echo $js_rev_data; ?>,
            borderColor:'#4E9B5B', backgroundColor:'rgba(78,155,91,0.08)',
            fill:true, tension:0.4, pointBackgroundColor:'#4E9B5B',
            pointRadius:5, pointHoverRadius:7 }]
    },
    options: { responsive:true,
        plugins:{ legend:{display:false},
            tooltip:{ backgroundColor:cd.bg,titleColor:cd.tx,bodyColor:'#B8A88A',
                callbacks:{label:c=>` ₱${c.parsed.y.toLocaleString()}`} } },
        scales:{ y:{beginAtZero:true,grid:{color:cd.gc},ticks:{color:cd.tc,callback:v=>'₱'+v.toLocaleString()}},
                 x:{grid:{display:false},ticks:{color:cd.tc}} } }
});
</script>

<?php include('../includes/footer.php'); ?>