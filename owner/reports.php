<?php
$page_title = 'Reports';

session_start();
include('../includes/db.php');
include('../includes/header.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header('Location: ../portal/login.php');
    exit();
}

// ── PARAMETERS ───────────────────────────────────────────────────────────────
$allowed_types  = ['harvest', 'sales', 'flock'];
$report_type    = in_array($_GET['type'] ?? '', $allowed_types, true) ? $_GET['type'] : null;
$date_from      = date('Y-m-d', strtotime(!empty($_GET['from']) ? $_GET['from'] : date('Y-m-01')));
$date_to        = date('Y-m-d', strtotime(!empty($_GET['to'])   ? $_GET['to']   : date('Y-m-d')));

// Per-type filters
$filter_batch   = (int)($_GET['batch_id'] ?? 0);
$filter_method  = $_GET['method']  ?? 'all';
$filter_status  = $_GET['status']  ?? 'all';

$allowed_methods = ['all', 'Cash', 'GCash', 'Bank Transfer'];
if (!in_array($filter_method, $allowed_methods, true)) $filter_method = 'all';
$allowed_statuses = ['all', 'Healthy', 'Mild', 'Moderate', 'Critical'];
if (!in_array($filter_status, $allowed_statuses, true)) $filter_status = 'all';

// ── FETCH BATCHES FOR DROPDOWN ────────────────────────────────────────────────
$batches_q = $conn->query("SELECT batch_id, breed, coop_number FROM batches ORDER BY status='Active' DESC, batch_id DESC");
$batches   = [];
if ($batches_q) while ($r = $batches_q->fetch_assoc()) $batches[] = $r;

// ── REPORT DATA ───────────────────────────────────────────────────────────────
$report_data  = [];
$report_stats = [];
$generated    = false;

if ($report_type && isset($_GET['from'])) {
    $generated = true;

    if ($report_type === 'harvest') {
        // ── HARVEST REPORT ────────────────────────────────────────────────────
        $conds  = ["DATE(h.date_logged) BETWEEN ? AND ?"];
        $types  = 'ss';
        $vals   = [$date_from, $date_to];

        if ($filter_batch > 0) {
            $conds[] = 'h.batch_id = ?';
            $types  .= 'i';
            $vals[]  = $filter_batch;
        }
        $where = 'WHERE ' . implode(' AND ', $conds);

        $s = $conn->prepare("
            SELECT
                COALESCE(SUM(h.total_eggs), 0)   AS total_eggs,
                COALESCE(SUM(h.size_pw), 0)      AS total_pw,
                COALESCE(SUM(h.size_s),  0)      AS total_s,
                COALESCE(SUM(h.size_m),  0)      AS total_m,
                COALESCE(SUM(h.size_l),  0)      AS total_l,
                COALESCE(SUM(h.size_xl), 0)      AS total_xl,
                COALESCE(SUM(h.size_j),  0)      AS total_j,
                COUNT(*)                         AS sessions,
                COALESCE(AVG(h.total_eggs), 0)   AS avg_per_session
            FROM harvests h $where");
        $s->bind_param($types, ...$vals);
        $s->execute();
        $report_stats = $s->get_result()->fetch_assoc();
        $s->close();

        $s = $conn->prepare("
            SELECT h.harvest_id, h.date_logged, h.total_eggs,
                   h.size_pw, h.size_s, h.size_m, h.size_l, h.size_xl, h.size_j,
                   b.breed, b.coop_number, u.username AS staff_name
            FROM harvests h
            JOIN batches b ON h.batch_id = b.batch_id
            LEFT JOIN users u ON h.staff_id = u.user_id
            $where
            ORDER BY h.date_logged DESC");
        $s->bind_param($types, ...$vals);
        $s->execute();
        $report_data = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();

    } elseif ($report_type === 'sales') {
        // ── SALES REPORT ──────────────────────────────────────────────────────
        $conds  = ["DATE(s.date_sold) BETWEEN ? AND ?"];
        $types  = 'ss';
        $vals   = [$date_from, $date_to];

        if ($filter_method !== 'all') {
            $conds[] = 's.payment_method = ?';
            $types  .= 's';
            $vals[]  = $filter_method;
        }
        $where = 'WHERE ' . implode(' AND ', $conds);

        $s = $conn->prepare("
            SELECT
                COUNT(*)                         AS transactions,
                COALESCE(SUM(s.total_amount), 0) AS total_revenue,
                COALESCE(AVG(s.total_amount), 0) AS avg_sale,
                COALESCE(SUM(s.quantity_sold), 0) AS total_trays,
                COALESCE(SUM(COALESCE(s.qty_pw,0)),0)  AS total_pw,
                COALESCE(SUM(COALESCE(s.qty_s,0)), 0)  AS total_s,
                COALESCE(SUM(COALESCE(s.qty_m,0)), 0)  AS total_m,
                COALESCE(SUM(COALESCE(s.qty_l,0)), 0)  AS total_l,
                COALESCE(SUM(COALESCE(s.qty_xl,0)),0)  AS total_xl,
                COALESCE(SUM(COALESCE(s.qty_j,0)), 0)  AS total_j
            FROM sales s $where");
        $s->bind_param($types, ...$vals);
        $s->execute();
        $report_stats = $s->get_result()->fetch_assoc();
        $s->close();

        $s = $conn->prepare("
            SELECT s.sale_id, s.date_sold, s.customer_name, s.quantity_sold,
                   s.unit_price, s.total_amount, s.payment_method, s.notes,
                   COALESCE(s.qty_pw,0) AS qty_pw, COALESCE(s.qty_s,0) AS qty_s,
                   COALESCE(s.qty_m,0)  AS qty_m,  COALESCE(s.qty_l,0) AS qty_l,
                   COALESCE(s.qty_xl,0) AS qty_xl, COALESCE(s.qty_j,0) AS qty_j,
                   u.username AS staff_name
            FROM sales s
            LEFT JOIN users u ON s.staff_id = u.user_id
            $where
            ORDER BY s.date_sold DESC");
        $s->bind_param($types, ...$vals);
        $s->execute();
        $report_data = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();

    } elseif ($report_type === 'flock') {
        // ── FLOCK HEALTH REPORT ───────────────────────────────────────────────
        $conds  = ["DATE(fh.date_reported) BETWEEN ? AND ?"];
        $types  = 'ss';
        $vals   = [$date_from, $date_to];

        if ($filter_batch > 0) {
            $conds[] = 'fh.batch_id = ?';
            $types  .= 'i';
            $vals[]  = $filter_batch;
        }
        if ($filter_status !== 'all') {
            $conds[] = 'fh.status_level = ?';
            $types  .= 's';
            $vals[]  = $filter_status;
        }
        $where = 'WHERE ' . implode(' AND ', $conds);

        $s = $conn->prepare("
            SELECT
                COUNT(*)                              AS total_reports,
                COALESCE(SUM(fh.mortality_count), 0)  AS total_mortality,
                COALESCE(AVG(fh.mortality_count), 0)  AS avg_mortality,
                SUM(CASE WHEN fh.status_level='Critical' THEN 1 ELSE 0 END) AS critical_count,
                SUM(CASE WHEN fh.status_level='Moderate' THEN 1 ELSE 0 END) AS moderate_count,
                SUM(CASE WHEN fh.status_level='Mild'     THEN 1 ELSE 0 END) AS mild_count,
                SUM(CASE WHEN fh.status_level='Healthy'  THEN 1 ELSE 0 END) AS healthy_count
            FROM flock_health fh $where");
        $s->bind_param($types, ...$vals);
        $s->execute();
        $report_stats = $s->get_result()->fetch_assoc();
        $s->close();

        $s = $conn->prepare("
            SELECT fh.report_id, fh.date_reported, fh.mortality_count,
                   fh.status_level, b.notes,
                   b.breed, b.coop_number, u.username AS staff_name
            FROM flock_health fh
            JOIN batches b ON fh.batch_id = b.batch_id
            LEFT JOIN users u ON fh.staff_id = u.user_id
            $where
            ORDER BY fh.date_reported DESC");
        $s->bind_param($types, ...$vals);
        $s->execute();
        $report_data = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();
    }
}

// ── EXPORT URL BUILDER ────────────────────────────────────────────────────────
function export_url(string $format): string {
    $p = $_GET;
    $p['format'] = $format;
    return 'export_report.php?' . http_build_query($p);
}

$type_labels = [
    'harvest' => 'Harvest Report',
    'sales'   => 'Sales Report',
    'flock'   => 'Flock Health Report',
];
$type_icons = [
    'harvest' => '',
    'sales'   => '',
    'flock'   => '',
];
?>

<style>
/* ── Report page layout ─────────────────────────────────── */
.rpt-wrap        { max-width:1120px; margin:2rem auto; }

/* Type selector cards */
.type-grid       { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-bottom:2rem; }
.type-card       { display:flex; flex-direction:column; align-items:center; justify-content:center;
                   gap:8px; padding:1.6rem 1rem; border-radius:var(--radius);
                   border:2px solid var(--border-mid); background:var(--bg-card);
                   cursor:pointer; text-decoration:none; transition:all 0.18s;
                   color:var(--text-primary); }
.type-card:hover { border-color:var(--gold); background:rgba(212,175,55,.06); }
.type-card.active{ border-color:var(--gold); background:rgba(212,175,55,.1);
                   box-shadow:0 0 0 3px rgba(212,175,55,.18); }
.type-card__icon { font-size:2rem; line-height:1; }
.type-card__label{ font-weight:700; font-size:0.9rem; letter-spacing:0.3px; }
.type-card__sub  { font-size:0.74rem; color:var(--text-muted); }

/* Filter bar */
.filter-bar      { background:var(--bg-card); border:1px solid var(--border-subtle);
                   border-radius:var(--radius); padding:1.2rem 1.6rem; margin-bottom:1.6rem;
                   display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.filter-bar .form-group { margin:0; flex:1; min-width:130px; }

/* Stat summary row */
.stat-row        { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:1.6rem; }
.stat-row .stat-card { flex:1; min-width:140px; }

/* Status badge colours */
.badge-healthy  { background:rgba(74,171,74,.15);  color:#4aab4a; }
.badge-mild     { background:rgba(212,175,55,.15); color:var(--gold); }
.badge-moderate { background:rgba(255,140,0,.15);  color:#ff8c00; }
.badge-critical { background:rgba(194,58,58,.15);  color:var(--danger); }

/* Breakdown chips */
.size-chips      { display:flex; gap:5px; flex-wrap:wrap; }
.size-chip       { font-size:0.7rem; font-family:monospace; padding:1px 6px;
                   border-radius:3px; background:var(--bg-wood);
                   color:var(--text-secondary); white-space:nowrap; }
.size-chip strong{ color:var(--text-primary); }

/* Print/export bar */
.export-bar      { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

/* Empty state */
.rpt-empty       { text-align:center; padding:3rem 1rem; color:var(--text-muted); }
.rpt-empty__icon { font-size:2.8rem; display:block; margin-bottom:12px; }

@media print {
    .no-print { display:none !important; }
    .rpt-wrap { margin:0; max-width:100%; }
    body      { background:#fff !important; }
}
</style>

<div class="rpt-wrap">

    <!-- ── PAGE HEADER ──────────────────────────────────────────────── -->
    <div class="page-header no-print">
        <div>
            <h2> Reports</h2>
            <p style="color:var(--text-muted); font-size:0.9rem; margin:0;">
                Generate and export Harvest, Sales, and Flock Health reports.
            </p>
        </div>
       
    </div>

    <!-- ── STEP 1: SELECT REPORT TYPE ──────────────────────────────── -->
    <div class="no-print">
        <p style="font-size:0.75rem; font-weight:700; color:var(--text-muted);
                  text-transform:uppercase; letter-spacing:0.6px; margin-bottom:10px;">
            Step 1 — Select Report Type
        </p>
        <div class="type-grid">
            <?php foreach (['harvest','sales','flock'] as $t): ?>
            <a href="?type=<?php echo $t; ?>&from=<?php echo urlencode($date_from); ?>&to=<?php echo urlencode($date_to); ?>"
               class="type-card <?php echo $report_type === $t ? 'active' : ''; ?>">
                <span class="type-card__icon"><?php echo $type_icons[$t]; ?></span>
                <span class="type-card__label"><?php echo $type_labels[$t]; ?></span>
                <span class="type-card__sub">
                    <?php echo $t === 'harvest' ? 'Egg production by size & batch'
                             : ($t === 'sales' ? 'Revenue, trays & payment methods'
                             : 'Mortality, status & health trends'); ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($report_type): ?>

    <!-- ── STEP 2: FILTERS ──────────────────────────────────────────── -->
    <div class="no-print">
        <p style="font-size:0.75rem; font-weight:700; color:var(--text-muted);
                  text-transform:uppercase; letter-spacing:0.6px; margin-bottom:10px;">
            Step 2 — Set Date Range &amp; Filters
        </p>
        <form method="GET" class="filter-bar">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($report_type); ?>">

            <div class="form-group">
                <label>From</label>
                <input type="date" name="from" class="form-input" value="<?php echo $date_from; ?>">
            </div>
            <div class="form-group">
                <label>To</label>
                <input type="date" name="to" class="form-input" value="<?php echo $date_to; ?>">
            </div>

            <?php if ($report_type === 'harvest' || $report_type === 'flock'): ?>
            <div class="form-group">
                <label>Batch</label>
                <select name="batch_id" class="form-input">
                    <option value="0">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?php echo $b['batch_id']; ?>"
                            <?php echo $filter_batch === (int)$b['batch_id'] ? 'selected' : ''; ?>>
                            #<?php echo $b['batch_id']; ?> — <?php echo htmlspecialchars($b['breed']); ?>
                            (Coop <?php echo $b['coop_number']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($report_type === 'sales'): ?>
            <div class="form-group">
                <label>Payment Method</label>
                <select name="method" class="form-input">
                    <?php foreach (['all'=>'All Methods','Cash'=>'Cash','GCash'=>'GCash','Bank Transfer'=>'Bank Transfer'] as $v => $lbl): ?>
                        <option value="<?php echo $v; ?>" <?php echo $filter_method === $v ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($lbl); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($report_type === 'flock'): ?>
            <div class="form-group">
                <label>Status Level</label>
                <select name="status" class="form-input">
                    <?php foreach (['all'=>'All Statuses','Healthy'=>'Healthy','Mild'=>'Mild','Moderate'=>'Moderate','Critical'=>'Critical'] as $v => $lbl): ?>
                        <option value="<?php echo $v; ?>" <?php echo $filter_status === $v ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($lbl); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div style="display:flex; gap:6px; padding-bottom:1px;">
                <button type="submit" class="btn-farm btn-sm">Generate Report</button>
                <a href="?type=<?php echo htmlspecialchars($report_type); ?>" class="btn-farm btn-dark btn-sm">Reset</a>
            </div>
        </form>
    </div>

    <?php if ($generated): ?>

    <!-- ── REPORT HEADER (print-visible) ───────────────────────────── -->
    <div style="display:flex; justify-content:space-between; align-items:center;
                flex-wrap:wrap; gap:10px; margin-bottom:1.2rem;">
        <div>
            <h3 style="margin:0; font-family:'Playfair Display',serif; color:var(--gold);">
                <?php echo $type_icons[$report_type]; ?>
                <?php echo $type_labels[$report_type]; ?>
            </h3>
            <p style="margin:4px 0 0; font-size:0.82rem; color:var(--text-muted);">
                <?php echo date('M d, Y', strtotime($date_from)); ?> &ndash;
                <?php echo date('M d, Y', strtotime($date_to)); ?>
                <?php if ($filter_batch > 0):
                    $bn = array_filter($batches, fn($b) => (int)$b['batch_id'] === $filter_batch);
                    $bn = reset($bn);
                    if ($bn): ?> &nbsp;·&nbsp; Batch #<?php echo $filter_batch; ?> (<?php echo htmlspecialchars($bn['breed']); ?>)<?php endif;
                endif; ?>
                <?php if ($filter_method !== 'all'): ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($filter_method); ?><?php endif; ?>
                <?php if ($filter_status !== 'all'): ?> &nbsp;·&nbsp; <?php echo htmlspecialchars($filter_status); ?><?php endif; ?>
            </p>
        </div>
        <div class="export-bar no-print">
            <a href="<?php echo export_url('excel'); ?>" class="btn-farm btn-green btn-sm">⬇ Export Excel</a>
            <a href="<?php echo export_url('pdf');   ?>" class="btn-farm btn-danger btn-sm">⬇ Export PDF</a>
            <button onclick="window.print()" class="btn-farm btn-dark btn-sm">🖨 Print</button>
        </div>
    </div>

    <!-- ── STATS SUMMARY ────────────────────────────────────────────── -->
    <?php if ($report_type === 'harvest' && $report_stats): ?>
    <div class="stat-row">
        <div class="stat-card" style="border-top:4px solid var(--gold);">
            <div class="stat-label">Total Eggs</div>
            <div class="stat-value"><?php echo number_format((int)$report_stats['total_eggs']); ?></div>
            <div class="stat-sub"><?php echo number_format(floor((int)$report_stats['total_eggs']/30)); ?> trays</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--success);">
            <div class="stat-label">Sessions</div>
            <div class="stat-value"><?php echo number_format((int)$report_stats['sessions']); ?></div>
            <div class="stat-sub">harvest logs</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--terra-lt);">
            <div class="stat-label">Avg per Session</div>
            <div class="stat-value"><?php echo number_format((float)$report_stats['avg_per_session'], 0); ?></div>
            <div class="stat-sub">eggs average</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--info);">
            <div class="stat-label">Size Breakdown</div>
            <div class="stat-value" style="font-size:0.9rem; line-height:1.5;">
                <?php
                $sizes = ['PW'=>'total_pw','S'=>'total_s','M'=>'total_m','L'=>'total_l','XL'=>'total_xl','J'=>'total_j'];
                $parts = [];
                foreach ($sizes as $lbl => $col) {
                    if ((int)$report_stats[$col] > 0)
                        $parts[] = "<span style='color:var(--text-muted);font-size:0.72rem;'>$lbl</span> " . number_format((int)$report_stats[$col]);
                }
                echo implode(' &nbsp;', $parts) ?: '—';
                ?>
            </div>
            <div class="stat-sub">eggs by size</div>
        </div>
    </div>

    <?php elseif ($report_type === 'sales' && $report_stats): ?>
    <div class="stat-row">
        <div class="stat-card" style="border-top:4px solid var(--success);">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value">&#8369;<?php echo number_format((float)$report_stats['total_revenue'], 2); ?></div>
            <div class="stat-sub">period total</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--gold);">
            <div class="stat-label">Transactions</div>
            <div class="stat-value"><?php echo number_format((int)$report_stats['transactions']); ?></div>
            <div class="stat-sub">sales recorded</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--terra-lt);">
            <div class="stat-label">Trays Sold</div>
            <div class="stat-value"><?php echo number_format((int)$report_stats['total_trays']); ?></div>
            <div class="stat-sub"><?php echo number_format((int)$report_stats['total_trays'] * 30); ?> eggs</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--info);">
            <div class="stat-label">Avg Per Sale</div>
            <div class="stat-value">&#8369;<?php echo number_format((float)$report_stats['avg_sale'], 2); ?></div>
            <div class="stat-sub">average transaction</div>
        </div>
    </div>

    <?php elseif ($report_type === 'flock' && $report_stats): ?>
    <div class="stat-row">
        <div class="stat-card" style="border-top:4px solid var(--danger);">
            <div class="stat-label">Total Mortality</div>
            <div class="stat-value"><?php echo number_format((int)$report_stats['total_mortality']); ?></div>
            <div class="stat-sub">birds lost</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--gold);">
            <div class="stat-label">Reports Filed</div>
            <div class="stat-value"><?php echo number_format((int)$report_stats['total_reports']); ?></div>
            <div class="stat-sub">health logs</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--terra-lt);">
            <div class="stat-label">Avg Mortality</div>
            <div class="stat-value"><?php echo number_format((float)$report_stats['avg_mortality'], 1); ?></div>
            <div class="stat-sub">per report</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--info);">
            <div class="stat-label">Status Breakdown</div>
            <div class="stat-value" style="font-size:0.82rem; line-height:1.6;">
                <span style="color:var(--danger);">Critical: <?php echo (int)$report_stats['critical_count']; ?></span> &nbsp;
                <span style="color:#ff8c00;">Moderate: <?php echo (int)$report_stats['moderate_count']; ?></span><br>
                <span style="color:var(--gold);">Mild: <?php echo (int)$report_stats['mild_count']; ?></span> &nbsp;
                <span style="color:var(--success);">Healthy: <?php echo (int)$report_stats['healthy_count']; ?></span>
            </div>
            <div class="stat-sub">across period</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── DATA TABLE ───────────────────────────────────────────────── -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="table-wrapper" style="border:none; border-radius:0;">

        <?php if ($report_type === 'harvest'): ?>
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date &amp; Time</th>
                        <th>Batch / Breed</th>
                        <th>Coop</th>
                        <th>Staff</th>
                        <th style="text-align:center;">Total Eggs</th>
                        <th>Size Breakdown</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($report_data)): foreach ($report_data as $row): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-size:0.78rem;">#<?php echo $row['harvest_id']; ?></td>
                        <td style="font-size:0.8rem; white-space:nowrap;">
                            <?php echo date('M d, Y', strtotime($row['date_logged'])); ?><br>
                            <span style="color:var(--text-muted); font-size:0.72rem;"><?php echo date('g:i A', strtotime($row['date_logged'])); ?></span>
                        </td>
                        <td><strong><?php echo htmlspecialchars($row['breed']); ?></strong></td>
                        <td style="font-size:0.82rem;">
                            <span class="badge badge-healthy badge-sm">Coop <?php echo $row['coop_number']; ?></span>
                        </td>
                        <td style="font-size:0.84rem;"><?php echo htmlspecialchars($row['staff_name'] ?? '—'); ?></td>
                        <td style="text-align:center; font-weight:700; color:var(--gold);">
                            <?php echo number_format((int)$row['total_eggs']); ?>
                        </td>
                        <td>
                            <div class="size-chips">
                                <?php
                                $sz = ['PW'=>'size_pw','S'=>'size_s','M'=>'size_m','L'=>'size_l','XL'=>'size_xl','J'=>'size_j'];
                                foreach ($sz as $lbl => $col):
                                    if ((int)($row[$col] ?? 0) > 0): ?>
                                    <span class="size-chip"><strong><?php echo $lbl; ?></strong> <?php echo number_format((int)$row[$col]); ?></span>
                                <?php endif; endforeach; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="7"><div class="rpt-empty">
                        <span class="rpt-empty__icon"></span>
                        <p>No harvest records found for this period.</p>
                    </div></td></tr>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($report_data)): ?>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right; font-size:0.8rem;">Period Total</td>
                        <td style="font-weight:700; color:var(--gold); text-align:center;">
                            <?php echo number_format((int)$report_stats['total_eggs']); ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>

        <?php elseif ($report_type === 'sales'): ?>
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Staff</th>
                        <th style="text-align:center;">Trays</th>
                        <th>Size Breakdown</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($report_data)): foreach ($report_data as $row): ?>
                    <tr>
                        <td style="color:var(--text-muted); font-size:0.78rem;">#<?php echo $row['sale_id']; ?></td>
                        <td style="font-size:0.8rem; white-space:nowrap;">
                            <?php echo date('M d, Y', strtotime($row['date_sold'])); ?><br>
                            <span style="color:var(--text-muted); font-size:0.72rem;"><?php echo date('g:i A', strtotime($row['date_sold'])); ?></span>
                        </td>
                        <td><strong><?php echo htmlspecialchars($row['customer_name']); ?></strong></td>
                        <td style="font-size:0.84rem;"><?php echo htmlspecialchars($row['staff_name'] ?? '—'); ?></td>
                        <td style="text-align:center; font-weight:700;"><?php echo number_format((int)$row['quantity_sold']); ?></td>
                        <td>
                            <div class="size-chips">
                                <?php
                                $sz = ['PW'=>'qty_pw','S'=>'qty_s','M'=>'qty_m','L'=>'qty_l','XL'=>'qty_xl','J'=>'qty_j'];
                                foreach ($sz as $lbl => $col):
                                    if ((int)($row[$col] ?? 0) > 0): ?>
                                    <span class="size-chip"><strong><?php echo $lbl; ?></strong> <?php echo number_format((int)$row[$col]); ?></span>
                                <?php endif; endforeach; ?>
                            </div>
                        </td>
                        <td>&#8369;<?php echo number_format((float)$row['unit_price'], 2); ?></td>
                        <td style="font-weight:700; color:var(--success);">
                            &#8369;<?php echo number_format((float)$row['total_amount'], 2); ?>
                        </td>
                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($row['payment_method']); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="9"><div class="rpt-empty">
                        <span class="rpt-empty__icon"></span>
                        <p>No sales records found for this period.</p>
                    </div></td></tr>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($report_data)): ?>
                <tfoot>
                    <tr>
                        <td colspan="7" style="text-align:right; font-size:0.8rem;">Period Total</td>
                        <td style="font-weight:700; color:var(--gold);">
                            &#8369;<?php echo number_format((float)$report_stats['total_revenue'], 2); ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>

        <?php elseif ($report_type === 'flock'): ?>
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date Reported</th>
                        <th>Batch / Breed</th>
                        <th>Coop</th>
                        <th>Staff</th>
                        <th style="text-align:center;">Mortality</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($report_data)): foreach ($report_data as $row):
                    $status_badge = match($row['status_level']) {
                        'Critical' => 'badge-critical',
                        'Moderate' => 'badge-moderate',
                        'Mild'     => 'badge-mild',
                        default    => 'badge-healthy',
                    };
                ?>
                    <tr>
                        <td style="color:var(--text-muted); font-size:0.78rem;">#<?php echo $row['report_id']; ?></td>
                        <td style="font-size:0.8rem; white-space:nowrap;">
                            <?php echo date('M d, Y', strtotime($row['date_reported'])); ?><br>
                            <span style="color:var(--text-muted); font-size:0.72rem;"><?php echo date('g:i A', strtotime($row['date_reported'])); ?></span>
                        </td>
                        <td><strong><?php echo htmlspecialchars($row['breed']); ?></strong></td>
                        <td>
                            <span class="badge badge-healthy badge-sm">Coop <?php echo $row['coop_number']; ?></span>
                        </td>
                        <td style="font-size:0.84rem;"><?php echo htmlspecialchars($row['staff_name'] ?? '—'); ?></td>
                        <td style="text-align:center; font-weight:700;
                                   color:<?php echo (int)$row['mortality_count'] > 0 ? 'var(--danger)' : 'var(--success)'; ?>;">
                            <?php echo number_format((int)$row['mortality_count']); ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $status_badge; ?>" style="font-size:0.72rem;">
                                <?php echo htmlspecialchars($row['status_level']); ?>
                            </span>
                        </td>
                        <td style="font-size:0.8rem; color:var(--text-muted); max-width:160px;">
                            <?php echo htmlspecialchars($row['notes'] ?: '—'); ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="8"><div class="rpt-empty">
                        <span class="rpt-empty__icon"></span>
                        <p>No flock health records found for this period.</p>
                    </div></td></tr>
                <?php endif; ?>
                </tbody>
                <?php if (!empty($report_data)): ?>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right; font-size:0.8rem;">Period Total Mortality</td>
                        <td style="font-weight:700; color:var(--danger); text-align:center;">
                            <?php echo number_format((int)$report_stats['total_mortality']); ?>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        <?php endif; ?>

        </div>
    </div>

    <?php else: /* $generated but type chosen — show prompt */ ?>
    <div class="card">
        <div class="rpt-empty">
            <span class="rpt-empty__icon"><?php echo $type_icons[$report_type]; ?></span>
            <p style="font-weight:700; color:var(--text-primary);">
                <?php echo $type_labels[$report_type]; ?> ready
            </p>
            <small>Set your date range above and click <strong>Generate Report</strong>.</small>
        </div>
    </div>
    <?php endif; ?>

    <?php else: /* No type selected */ ?>
    <div class="card">
        <div class="rpt-empty">
            <span class="rpt-empty__icon"></span>
            <p style="font-weight:700; color:var(--text-primary);">Select a report type above to get started.</p>
            <small style="color:var(--text-muted);">Choose Harvest, Sales, or Flock Health.</small>
        </div>
    </div>
    <?php endif; ?>

    <a href="dashboard.php" class="back-link no-print">← Back to Dashboard</a>
</div>

<?php include('../includes/footer.php'); ?>