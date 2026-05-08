<?php
/**
 * export_report.php
 * Exports Harvest / Sales / Flock Health reports as:
 *   - Excel  (?format=excel) → CSV download (opens natively in Excel/Sheets)
 *   - PDF    (?format=pdf)   → inline PDF via HTML→PDF (no external lib needed)
 *
 * Called from reports.php with the same GET parameters.
 */

session_start();
include('../includes/db.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header('Location: ../portal/login.php');
    exit();
}

// ── PARAMETERS ───────────────────────────────────────────────────────────────
$allowed_types   = ['harvest', 'sales', 'flock'];
$allowed_formats = ['excel', 'pdf'];

$report_type  = in_array($_GET['type']   ?? '', $allowed_types,   true) ? $_GET['type']   : null;
$format       = in_array($_GET['format'] ?? '', $allowed_formats, true) ? $_GET['format'] : 'excel';

if (!$report_type) {
    header('Location: reports.php');
    exit();
}

$date_from     = date('Y-m-d', strtotime(!empty($_GET['from']) ? $_GET['from'] : date('Y-m-01')));
$date_to       = date('Y-m-d', strtotime(!empty($_GET['to'])   ? $_GET['to']   : date('Y-m-d')));
$filter_batch  = (int)($_GET['batch_id'] ?? 0);
$filter_method = $_GET['method'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';

$allowed_methods  = ['all', 'Cash', 'GCash', 'Bank Transfer'];
$allowed_statuses = ['all', 'Healthy', 'Mild', 'Moderate', 'Critical'];
if (!in_array($filter_method, $allowed_methods,  true)) $filter_method = 'all';
if (!in_array($filter_status, $allowed_statuses, true)) $filter_status = 'all';

// ── QUERY ─────────────────────────────────────────────────────────────────────
$rows  = [];
$stats = [];

if ($report_type === 'harvest') {

    $conds = ["DATE(h.date_logged) BETWEEN ? AND ?"];
    $types = 'ss';
    $vals  = [$date_from, $date_to];
    if ($filter_batch > 0) { $conds[] = 'h.batch_id = ?'; $types .= 'i'; $vals[] = $filter_batch; }
    $where = 'WHERE ' . implode(' AND ', $conds);

    $s = $conn->prepare("
        SELECT COALESCE(SUM(h.total_eggs),0) AS total_eggs,
               COALESCE(SUM(h.size_pw),0)   AS total_pw,  COALESCE(SUM(h.size_s),0) AS total_s,
               COALESCE(SUM(h.size_m),0)    AS total_m,   COALESCE(SUM(h.size_l),0) AS total_l,
               COALESCE(SUM(h.size_xl),0)   AS total_xl,  COALESCE(SUM(h.size_j),0) AS total_j,
               COUNT(*)                     AS sessions
        FROM harvests h $where");
    $s->bind_param($types, ...$vals); $s->execute();
    $stats = $s->get_result()->fetch_assoc(); $s->close();

    $s = $conn->prepare("
        SELECT h.harvest_id, h.date_logged, b.breed, b.coop_number,
               h.total_eggs, h.size_pw, h.size_s, h.size_m, h.size_l, h.size_xl, h.size_j,
               u.username AS staff_name
        FROM harvests h
        JOIN batches b ON h.batch_id = b.batch_id
        LEFT JOIN users u ON h.staff_id = u.user_id
        $where ORDER BY h.date_logged DESC");
    $s->bind_param($types, ...$vals); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();

} elseif ($report_type === 'sales') {

    $conds = ["DATE(s.date_sold) BETWEEN ? AND ?"];
    $types = 'ss';
    $vals  = [$date_from, $date_to];
    if ($filter_method !== 'all') { $conds[] = 's.payment_method = ?'; $types .= 's'; $vals[] = $filter_method; }
    $where = 'WHERE ' . implode(' AND ', $conds);

    $s = $conn->prepare("
        SELECT COALESCE(SUM(s.total_amount),0) AS total_revenue,
               COUNT(*) AS transactions,
               COALESCE(AVG(s.total_amount),0) AS avg_sale,
               COALESCE(SUM(s.quantity_sold),0) AS total_trays
        FROM sales s $where");
    $s->bind_param($types, ...$vals); $s->execute();
    $stats = $s->get_result()->fetch_assoc(); $s->close();

    $s = $conn->prepare("
        SELECT s.sale_id, s.date_sold, s.customer_name, s.quantity_sold,
               s.unit_price, s.total_amount, s.payment_method, s.notes,
               COALESCE(s.qty_pw,0) AS qty_pw, COALESCE(s.qty_s,0) AS qty_s,
               COALESCE(s.qty_m,0)  AS qty_m,  COALESCE(s.qty_l,0) AS qty_l,
               COALESCE(s.qty_xl,0) AS qty_xl, COALESCE(s.qty_j,0) AS qty_j,
               u.username AS staff_name
        FROM sales s
        LEFT JOIN users u ON s.staff_id = u.user_id
        $where ORDER BY s.date_sold DESC");
    $s->bind_param($types, ...$vals); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();

} elseif ($report_type === 'flock') {

    $conds = ["DATE(fh.date_reported) BETWEEN ? AND ?"];
    $types = 'ss';
    $vals  = [$date_from, $date_to];
    if ($filter_batch > 0)       { $conds[] = 'fh.batch_id = ?';    $types .= 'i'; $vals[] = $filter_batch; }
    if ($filter_status !== 'all'){ $conds[] = 'fh.status_level = ?'; $types .= 's'; $vals[] = $filter_status; }
    $where = 'WHERE ' . implode(' AND ', $conds);

    $s = $conn->prepare("
        SELECT COUNT(*) AS total_reports,
               COALESCE(SUM(fh.mortality_count),0) AS total_mortality,
               COALESCE(AVG(fh.mortality_count),0) AS avg_mortality,
               SUM(CASE WHEN fh.status_level='Critical' THEN 1 ELSE 0 END) AS critical_count,
               SUM(CASE WHEN fh.status_level='Moderate' THEN 1 ELSE 0 END) AS moderate_count,
               SUM(CASE WHEN fh.status_level='Mild'     THEN 1 ELSE 0 END) AS mild_count,
               SUM(CASE WHEN fh.status_level='Healthy'  THEN 1 ELSE 0 END) AS healthy_count
        FROM flock_health fh $where");
    $s->bind_param($types, ...$vals); $s->execute();
    $stats = $s->get_result()->fetch_assoc(); $s->close();

    $s = $conn->prepare("
        SELECT fh.report_id, fh.date_reported, fh.mortality_count,
               fh.status_level, fh.notes, b.breed, b.coop_number,
               u.username AS staff_name
        FROM flock_health fh
        JOIN batches b ON fh.batch_id = b.batch_id
        LEFT JOIN users u ON fh.staff_id = u.user_id
        $where ORDER BY fh.date_reported DESC");
    $s->bind_param($types, ...$vals); $s->execute();
    $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
}

// ── LABELS ────────────────────────────────────────────────────────────────────
$type_labels = ['harvest' => 'Harvest Report', 'sales' => 'Sales Report', 'flock' => 'Flock Health Report'];
$report_label = $type_labels[$report_type];
$period_label = date('M d Y', strtotime($date_from)) . ' to ' . date('M d Y', strtotime($date_to));
$farm_name    = 'Farm Management System';
$generated_at = date('F d, Y g:i A');

// ══════════════════════════════════════════════════════════════════════════════
//  EXCEL (CSV) EXPORT
// ══════════════════════════════════════════════════════════════════════════════
if ($format === 'excel') {

    $filename = strtolower(str_replace(' ', '_', $report_label)) . '_' . $date_from . '_to_' . $date_to . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    // UTF-8 BOM so Excel opens it correctly
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    // ── Title block ──────────────────────────────────────────────────
    fputcsv($out, [$farm_name]);
    fputcsv($out, [$report_label]);
    fputcsv($out, ['Period: ' . $period_label]);
    fputcsv($out, ['Generated: ' . $generated_at]);
    fputcsv($out, []);

    if ($report_type === 'harvest') {

        // Summary
        fputcsv($out, ['SUMMARY']);
        fputcsv($out, ['Total Eggs', 'Sessions', 'PW', 'S', 'M', 'L', 'XL', 'J']);
        fputcsv($out, [
            $stats['total_eggs'], $stats['sessions'],
            $stats['total_pw'], $stats['total_s'], $stats['total_m'],
            $stats['total_l'], $stats['total_xl'], $stats['total_j'],
        ]);
        fputcsv($out, []);

        // Detail
        fputcsv($out, ['DETAIL']);
        fputcsv($out, ['#', 'Date', 'Time', 'Breed', 'Coop', 'Staff', 'Total Eggs', 'PW', 'S', 'M', 'L', 'XL', 'J']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['harvest_id'],
                date('Y-m-d', strtotime($r['date_logged'])),
                date('g:i A',  strtotime($r['date_logged'])),
                $r['breed'], 'Coop ' . $r['coop_number'],
                $r['staff_name'] ?? '',
                $r['total_eggs'],
                $r['size_pw'], $r['size_s'], $r['size_m'],
                $r['size_l'],  $r['size_xl'], $r['size_j'],
            ]);
        }

    } elseif ($report_type === 'sales') {

        // Summary
        fputcsv($out, ['SUMMARY']);
        fputcsv($out, ['Total Revenue (PHP)', 'Transactions', 'Total Trays', 'Avg Per Sale (PHP)']);
        fputcsv($out, [
            number_format((float)$stats['total_revenue'], 2),
            $stats['transactions'],
            $stats['total_trays'],
            number_format((float)$stats['avg_sale'], 2),
        ]);
        fputcsv($out, []);

        // Detail
        fputcsv($out, ['DETAIL']);
        fputcsv($out, ['#', 'Date', 'Time', 'Customer', 'Staff', 'Trays', 'PW', 'S', 'M', 'L', 'XL', 'J', 'Unit Price', 'Total (PHP)', 'Payment', 'Notes']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['sale_id'],
                date('Y-m-d', strtotime($r['date_sold'])),
                date('g:i A',  strtotime($r['date_sold'])),
                $r['customer_name'],
                $r['staff_name'] ?? '',
                $r['quantity_sold'],
                $r['qty_pw'], $r['qty_s'], $r['qty_m'],
                $r['qty_l'],  $r['qty_xl'], $r['qty_j'],
                number_format((float)$r['unit_price'],   2),
                number_format((float)$r['total_amount'], 2),
                $r['payment_method'],
                $r['notes'] ?? '',
            ]);
        }

    } elseif ($report_type === 'flock') {

        // Summary
        fputcsv($out, ['SUMMARY']);
        fputcsv($out, ['Reports Filed', 'Total Mortality', 'Avg Mortality', 'Critical', 'Moderate', 'Mild', 'Healthy']);
        fputcsv($out, [
            $stats['total_reports'], $stats['total_mortality'],
            number_format((float)$stats['avg_mortality'], 1),
            $stats['critical_count'], $stats['moderate_count'],
            $stats['mild_count'],     $stats['healthy_count'],
        ]);
        fputcsv($out, []);

        // Detail
        fputcsv($out, ['DETAIL']);
        fputcsv($out, ['#', 'Date', 'Time', 'Breed', 'Coop', 'Staff', 'Mortality', 'Status', 'Notes']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['report_id'],
                date('Y-m-d', strtotime($r['date_reported'])),
                date('g:i A',  strtotime($r['date_reported'])),
                $r['breed'], 'Coop ' . $r['coop_number'],
                $r['staff_name'] ?? '',
                $r['mortality_count'],
                $r['status_level'],
                $r['notes'] ?? '',
            ]);
        }
    }

    fclose($out);
    exit();
}

// ══════════════════════════════════════════════════════════════════════════════
//  PDF EXPORT  — rendered as a print-ready HTML page, browser prints to PDF
//  (no external library needed; clean layout with print CSS)
// ══════════════════════════════════════════════════════════════════════════════

// Helper: status badge colour for flock
function status_color(string $s): string {
    return match($s) {
        'Critical' => '#c23a3a',
        'Moderate' => '#e07b00',
        'Mild'     => '#b8960c',
        default    => '#2e7d32',
    };
}

$filter_line = '';
if ($filter_batch  > 0)     $filter_line .= ' | Batch #' . $filter_batch;
if ($filter_method !== 'all') $filter_line .= ' | Payment: ' . htmlspecialchars($filter_method);
if ($filter_status !== 'all') $filter_line .= ' | Status: '  . htmlspecialchars($filter_status);

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($report_label); ?> — <?php echo $period_label; ?></title>
<style>
/* ── Base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 12px;
    color: #1a1a1a;
    background: #f8f6f2;
    padding: 0;
}

/* ── Page wrapper ── */
.page {
    max-width: 960px;
    margin: 0 auto;
    background: #fff;
    box-shadow: 0 2px 20px rgba(0,0,0,.12);
}

/* ── Screen-only controls ── */
.screen-bar {
    background: #2c2416;
    padding: 12px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.screen-bar h1 { color: #d4af37; font-size: 15px; font-weight: 700; }
.screen-bar .btn-group { display: flex; gap: 8px; }
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px; border-radius: 5px; border: none;
    font-size: 12px; font-weight: 600; cursor: pointer;
    text-decoration: none; transition: opacity .15s;
}
.btn:hover { opacity: .85; }
.btn-print  { background: #d4af37; color: #1a1a1a; }
.btn-back   { background: #444; color: #fff; }
.btn-excel  { background: #1d6f42; color: #fff; }

/* ── Report header ── */
.rpt-header {
    background: linear-gradient(135deg, #2c2416 0%, #3d3020 100%);
    color: #fff;
    padding: 28px 32px 22px;
    border-bottom: 4px solid #d4af37;
}
.rpt-header__farm  { font-size: 11px; color: #d4af37; letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 6px; }
.rpt-header__title { font-size: 22px; font-weight: 700; margin-bottom: 6px; }
.rpt-header__meta  { font-size: 11px; color: rgba(255,255,255,.65); line-height: 1.8; }
.rpt-header__meta span { color: rgba(255,255,255,.9); }

/* ── Stats grid ── */
.stats-grid {
    display: grid;
    gap: 0;
    border-bottom: 1px solid #e8e0d0;
}
.stats-grid--harvest { grid-template-columns: repeat(4, 1fr); }
.stats-grid--sales   { grid-template-columns: repeat(4, 1fr); }
.stats-grid--flock   { grid-template-columns: repeat(4, 1fr); }

.stat-box {
    padding: 16px 18px;
    border-right: 1px solid #e8e0d0;
    background: #fdfcf8;
}
.stat-box:last-child { border-right: none; }
.stat-box__label { font-size: 10px; font-weight: 700; color: #8a7a5a; text-transform: uppercase; letter-spacing: .6px; margin-bottom: 5px; }
.stat-box__value { font-size: 20px; font-weight: 700; color: #1a1a1a; line-height: 1; }
.stat-box__sub   { font-size: 10px; color: #8a7a5a; margin-top: 3px; }
.stat-box--accent .stat-box__value { color: #b8960c; }
.stat-box--green  .stat-box__value { color: #2e7d32; }
.stat-box--red    .stat-box__value { color: #c23a3a; }

/* ── Table ── */
.tbl-wrap { padding: 0 0 24px; overflow-x: auto; }
.tbl-section-title {
    font-size: 10px; font-weight: 700; color: #8a7a5a;
    text-transform: uppercase; letter-spacing: .7px;
    padding: 14px 24px 8px;
    border-top: 1px solid #e8e0d0;
}
table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11.5px;
}
thead th {
    background: #f5f0e8;
    color: #5a4a2a;
    font-weight: 700;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .4px;
    padding: 8px 10px;
    text-align: left;
    border-bottom: 2px solid #d4af37;
    white-space: nowrap;
}
thead th.num { text-align: right; }
tbody tr { border-bottom: 1px solid #f0ebe0; }
tbody tr:nth-child(even) { background: #fdfcf8; }
tbody tr:hover { background: #f8f4ec; }
td { padding: 7px 10px; color: #2a2a2a; vertical-align: middle; }
td.num    { text-align: right; font-variant-numeric: tabular-nums; }
td.muted  { color: #8a7a5a; font-size: 10.5px; }
td.strong { font-weight: 700; }
td.money  { text-align: right; font-weight: 700; color: #2e7d32; font-variant-numeric: tabular-nums; }
td.gold   { font-weight: 700; color: #b8960c; }
td.red    { font-weight: 700; color: #c23a3a; }

/* Size chips */
.chips { display: flex; gap: 4px; flex-wrap: wrap; }
.chip  {
    font-size: 10px; padding: 1px 5px; border-radius: 3px;
    background: #f0ebe0; color: #5a4a2a; white-space: nowrap;
    font-family: 'Courier New', monospace;
}
.chip strong { color: #2a2a2a; }

/* Status badge */
.badge {
    display: inline-block; font-size: 10px; font-weight: 700;
    padding: 2px 7px; border-radius: 3px; white-space: nowrap;
}

/* Tfoot */
tfoot td {
    padding: 9px 10px;
    background: #f5f0e8;
    font-weight: 700;
    border-top: 2px solid #d4af37;
    font-size: 11px;
}
tfoot .tfoot-label { text-align: right; color: #5a4a2a; }

/* Footer */
.rpt-footer {
    border-top: 1px solid #e8e0d0;
    padding: 12px 24px;
    font-size: 10px;
    color: #8a7a5a;
    display: flex;
    justify-content: space-between;
    background: #fdfcf8;
}

/* Empty */
.empty-row td { text-align: center; padding: 32px; color: #8a7a5a; font-style: italic; }

/* ── PRINT ── */
@media print {
    body         { background: #fff; font-size: 11px; }
    .screen-bar  { display: none; }
    .page        { max-width: 100%; box-shadow: none; }
    .rpt-header  { background: #2c2416 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .stats-grid  { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    thead th     { background: #f5f0e8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    tbody tr:nth-child(even) { background: #fdfcf8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    tfoot td     { background: #f5f0e8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .tbl-wrap    { padding-bottom: 0; }
    table        { page-break-inside: auto; }
    tr           { page-break-inside: avoid; }
}
</style>
</head>
<body>
<div class="page">

    <!-- Screen-only controls -->
    <div class="screen-bar">
        <h1>📊 <?php echo htmlspecialchars($report_label); ?></h1>
        <div class="btn-group">
            <a href="reports.php?type=<?php echo urlencode($report_type);
                ?>&from=<?php echo urlencode($date_from);
                ?>&to=<?php echo urlencode($date_to);
                ?>" class="btn btn-back">← Back to Reports</a>
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['format'=>'excel']))); ?>"
               class="btn btn-excel">⬇ Download Excel</a>
            <button onclick="window.print()" class="btn btn-print">🖨 Save / Print PDF</button>
        </div>
    </div>

    <!-- Report header -->
    <div class="rpt-header">
        <div class="rpt-header__farm"><?php echo htmlspecialchars($farm_name); ?></div>
        <div class="rpt-header__title"><?php echo htmlspecialchars($report_label); ?></div>
        <div class="rpt-header__meta">
            Period: <span><?php echo $period_label; ?></span>
            <?php if ($filter_line): ?>&nbsp;&nbsp;|<?php echo $filter_line; ?><?php endif; ?><br>
            Generated: <span><?php echo $generated_at; ?></span>
            &nbsp;&nbsp;|&nbsp;&nbsp; Records: <span><?php echo count($rows); ?></span>
        </div>
    </div>

    <!-- ── STATS ──────────────────────────────────────────────────────── -->
    <?php if ($report_type === 'harvest'): ?>
    <div class="stats-grid stats-grid--harvest">
        <div class="stat-box stat-box--accent">
            <div class="stat-box__label">Total Eggs</div>
            <div class="stat-box__value"><?php echo number_format((int)$stats['total_eggs']); ?></div>
            <div class="stat-box__sub"><?php echo number_format(floor((int)$stats['total_eggs']/30)); ?> trays</div>
        </div>
        <div class="stat-box">
            <div class="stat-box__label">Sessions</div>
            <div class="stat-box__value"><?php echo number_format((int)$stats['sessions']); ?></div>
            <div class="stat-box__sub">harvest logs</div>
        </div>
        <div class="stat-box">
            <div class="stat-box__label">Avg / Session</div>
            <div class="stat-box__value"><?php echo number_format((float)($stats['total_eggs'] / max(1,$stats['sessions'])), 0); ?></div>
            <div class="stat-box__sub">eggs average</div>
        </div>
        <div class="stat-box">
            <div class="stat-box__label">Size Mix</div>
            <div class="stat-box__value" style="font-size:13px; line-height:1.4;">
                <?php
                $sz = ['PW'=>'total_pw','S'=>'total_s','M'=>'total_m','L'=>'total_l','XL'=>'total_xl','J'=>'total_j'];
                $parts = [];
                foreach ($sz as $lbl => $col)
                    if ((int)$stats[$col] > 0) $parts[] = "<b>$lbl</b> " . number_format((int)$stats[$col]);
                echo implode(' · ', $parts) ?: '—';
                ?>
            </div>
        </div>
    </div>

    <?php elseif ($report_type === 'sales'): ?>
    <div class="stats-grid stats-grid--sales">
        <div class="stat-box stat-box--green">
            <div class="stat-box__label">Total Revenue</div>
            <div class="stat-box__value">&#8369;<?php echo number_format((float)$stats['total_revenue'], 2); ?></div>
            <div class="stat-box__sub">period total</div>
        </div>
        <div class="stat-box stat-box--accent">
            <div class="stat-box__label">Transactions</div>
            <div class="stat-box__value"><?php echo number_format((int)$stats['transactions']); ?></div>
            <div class="stat-box__sub">sales recorded</div>
        </div>
        <div class="stat-box">
            <div class="stat-box__label">Trays Sold</div>
            <div class="stat-box__value"><?php echo number_format((int)$stats['total_trays']); ?></div>
            <div class="stat-box__sub"><?php echo number_format((int)$stats['total_trays'] * 30); ?> eggs</div>
        </div>
        <div class="stat-box">
            <div class="stat-box__label">Avg Per Sale</div>
            <div class="stat-box__value">&#8369;<?php echo number_format((float)$stats['avg_sale'], 2); ?></div>
            <div class="stat-box__sub">average transaction</div>
        </div>
    </div>

    <?php elseif ($report_type === 'flock'): ?>
    <div class="stats-grid stats-grid--flock">
        <div class="stat-box stat-box--red">
            <div class="stat-box__label">Total Mortality</div>
            <div class="stat-box__value"><?php echo number_format((int)$stats['total_mortality']); ?></div>
            <div class="stat-box__sub">birds lost</div>
        </div>
        <div class="stat-box stat-box--accent">
            <div class="stat-box__label">Reports Filed</div>
            <div class="stat-box__value"><?php echo number_format((int)$stats['total_reports']); ?></div>
            <div class="stat-box__sub">health logs</div>
        </div>
        <div class="stat-box">
            <div class="stat-box__label">Avg Mortality</div>
            <div class="stat-box__value"><?php echo number_format((float)$stats['avg_mortality'], 1); ?></div>
            <div class="stat-box__sub">per report</div>
        </div>
        <div class="stat-box">
            <div class="stat-box__label">Status Breakdown</div>
            <div class="stat-box__value" style="font-size:11px; line-height:1.7;">
                <span style="color:#c23a3a; font-weight:700;">Critical <?php echo (int)$stats['critical_count']; ?></span> &nbsp;
                <span style="color:#e07b00; font-weight:700;">Moderate <?php echo (int)$stats['moderate_count']; ?></span><br>
                <span style="color:#b8960c; font-weight:700;">Mild <?php echo (int)$stats['mild_count']; ?></span> &nbsp;
                <span style="color:#2e7d32; font-weight:700;">Healthy <?php echo (int)$stats['healthy_count']; ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── TABLE ──────────────────────────────────────────────────────── -->
    <div class="tbl-wrap">
        <div class="tbl-section-title">Transaction Detail — <?php echo count($rows); ?> record<?php echo count($rows) !== 1 ? 's' : ''; ?></div>

        <?php if ($report_type === 'harvest'): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Breed</th>
                    <th>Coop</th>
                    <th>Staff</th>
                    <th class="num">Total Eggs</th>
                    <th>PW</th><th>S</th><th>M</th><th>L</th><th>XL</th><th>J</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): foreach ($rows as $r): ?>
                <tr>
                    <td class="muted">#<?php echo $r['harvest_id']; ?></td>
                    <td class="muted"><?php echo date('M d, Y', strtotime($r['date_logged'])); ?><br>
                        <span style="font-size:10px;"><?php echo date('g:i A', strtotime($r['date_logged'])); ?></span></td>
                    <td class="strong"><?php echo htmlspecialchars($r['breed']); ?></td>
                    <td class="muted">Coop <?php echo $r['coop_number']; ?></td>
                    <td><?php echo htmlspecialchars($r['staff_name'] ?? '—'); ?></td>
                    <td class="num gold"><?php echo number_format((int)$r['total_eggs']); ?></td>
                    <td class="num muted"><?php echo number_format((int)$r['size_pw']); ?></td>
                    <td class="num muted"><?php echo number_format((int)$r['size_s']);  ?></td>
                    <td class="num muted"><?php echo number_format((int)$r['size_m']);  ?></td>
                    <td class="num muted"><?php echo number_format((int)$r['size_l']);  ?></td>
                    <td class="num muted"><?php echo number_format((int)$r['size_xl']); ?></td>
                    <td class="num muted"><?php echo number_format((int)$r['size_j']);  ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr class="empty-row"><td colspan="12">No records found for this period.</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr>
                    <td colspan="5" class="tfoot-label">Period Total</td>
                    <td class="num gold"><?php echo number_format((int)$stats['total_eggs']); ?></td>
                    <td class="num"><?php echo number_format((int)$stats['total_pw']); ?></td>
                    <td class="num"><?php echo number_format((int)$stats['total_s']);  ?></td>
                    <td class="num"><?php echo number_format((int)$stats['total_m']);  ?></td>
                    <td class="num"><?php echo number_format((int)$stats['total_l']);  ?></td>
                    <td class="num"><?php echo number_format((int)$stats['total_xl']); ?></td>
                    <td class="num"><?php echo number_format((int)$stats['total_j']);  ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>

        <?php elseif ($report_type === 'sales'): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Staff</th>
                    <th class="num">Trays</th>
                    <th>Sizes</th>
                    <th class="num">Unit &#8369;</th>
                    <th class="num">Total &#8369;</th>
                    <th>Payment</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): foreach ($rows as $r): ?>
                <tr>
                    <td class="muted">#<?php echo $r['sale_id']; ?></td>
                    <td class="muted"><?php echo date('M d, Y', strtotime($r['date_sold'])); ?><br>
                        <span style="font-size:10px;"><?php echo date('g:i A', strtotime($r['date_sold'])); ?></span></td>
                    <td class="strong"><?php echo htmlspecialchars($r['customer_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['staff_name'] ?? '—'); ?></td>
                    <td class="num strong"><?php echo number_format((int)$r['quantity_sold']); ?></td>
                    <td>
                        <div class="chips">
                        <?php $sz = ['PW'=>'qty_pw','S'=>'qty_s','M'=>'qty_m','L'=>'qty_l','XL'=>'qty_xl','J'=>'qty_j'];
                        foreach ($sz as $lbl => $col):
                            if ((int)($r[$col] ?? 0) > 0): ?>
                            <span class="chip"><strong><?php echo $lbl; ?></strong> <?php echo $r[$col]; ?></span>
                        <?php endif; endforeach; ?>
                        </div>
                    </td>
                    <td class="num"><?php echo number_format((float)$r['unit_price'], 2); ?></td>
                    <td class="money">&#8369;<?php echo number_format((float)$r['total_amount'], 2); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($r['payment_method']); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr class="empty-row"><td colspan="9">No records found for this period.</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr>
                    <td colspan="7" class="tfoot-label">Period Total Revenue</td>
                    <td class="money">&#8369;<?php echo number_format((float)$stats['total_revenue'], 2); ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>

        <?php elseif ($report_type === 'flock'): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date Reported</th>
                    <th>Breed</th>
                    <th>Coop</th>
                    <th>Staff</th>
                    <th class="num">Mortality</th>
                    <th>Status</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($rows)): foreach ($rows as $r): ?>
                <tr>
                    <td class="muted">#<?php echo $r['report_id']; ?></td>
                    <td class="muted"><?php echo date('M d, Y', strtotime($r['date_reported'])); ?><br>
                        <span style="font-size:10px;"><?php echo date('g:i A', strtotime($r['date_reported'])); ?></span></td>
                    <td class="strong"><?php echo htmlspecialchars($r['breed']); ?></td>
                    <td class="muted">Coop <?php echo $r['coop_number']; ?></td>
                    <td><?php echo htmlspecialchars($r['staff_name'] ?? '—'); ?></td>
                    <td class="num <?php echo (int)$r['mortality_count'] > 0 ? 'red' : ''; ?>">
                        <?php echo number_format((int)$r['mortality_count']); ?>
                    </td>
                    <td>
                        <span class="badge" style="
                            background:<?php echo status_color($r['status_level']); ?>22;
                            color:<?php echo status_color($r['status_level']); ?>;
                            border:1px solid <?php echo status_color($r['status_level']); ?>44;">
                            <?php echo htmlspecialchars($r['status_level']); ?>
                        </span>
                    </td>
                    <td class="muted" style="max-width:180px; font-size:10.5px;">
                        <?php echo htmlspecialchars($r['notes'] ?: '—'); ?>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr class="empty-row"><td colspan="8">No records found for this period.</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($rows)): ?>
            <tfoot>
                <tr>
                    <td colspan="5" class="tfoot-label">Period Total Mortality</td>
                    <td class="num red"><?php echo number_format((int)$stats['total_mortality']); ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="rpt-footer">
        <span><?php echo htmlspecialchars($farm_name); ?> &mdash; <?php echo htmlspecialchars($report_label); ?></span>
        <span>Generated <?php echo $generated_at; ?></span>
    </div>

</div>

<script>
// Auto-trigger print dialog when opened as PDF export
window.addEventListener('load', function () {
    if (window.location.search.includes('format=pdf')) {
        setTimeout(() => window.print(), 400);
    }
});
</script>
</body>
</html>
<?php
// End of export_report.php