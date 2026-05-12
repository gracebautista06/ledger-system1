<?php
/*
 * owner/view_sales.php — Today's Sales
 *
 * End-of-day snapshot: revenue, trays, transactions, average.
 * vs-yesterday comparison, payment method breakdown, per-staff summary.
 * Links to sales_report.php for full history and exports.
 */

$page_title = "Today's Sales";

session_start();
include('../../includes/db.php');
include('../../includes/header.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header("Location: ../../portal/login.php");
    exit();
}

$today     = date('Y-m-d');
$today_fmt = date('l, F j, Y');

// ── TODAY'S SUMMARY ───────────────────────────────────────────────
$stats_q = $conn->query("
    SELECT
        COUNT(*)                           AS total_transactions,
        COALESCE(SUM(quantity_sold),    0) AS total_trays,
        COALESCE(SUM(quantity_sold*30), 0) AS total_eggs,
        COALESCE(SUM(total_amount),     0) AS total_revenue,
        COALESCE(AVG(total_amount),     0) AS avg_per_sale,
        COALESCE(MAX(total_amount),     0) AS biggest_sale
    FROM sales
    WHERE DATE(date_sold) = '$today'
");
$stats    = $stats_q ? $stats_q->fetch_assoc() : [];
$today_rev = (float)($stats['total_revenue']      ?? 0);
$total_tx  = (int)  ($stats['total_transactions'] ?? 0);

// ── YESTERDAY COMPARISON ──────────────────────────────────────────
$yest_q   = $conn->query("
    SELECT COALESCE(SUM(total_amount), 0) AS rev
    FROM sales
    WHERE DATE(date_sold) = DATE_SUB('$today', INTERVAL 1 DAY)
");
$yest_rev = $yest_q ? (float)$yest_q->fetch_assoc()['rev'] : 0;
$rev_diff = $today_rev - $yest_rev;
$rev_pct  = $yest_rev > 0 ? round(($rev_diff / $yest_rev) * 100, 1) : null;

// ── PAYMENT METHOD BREAKDOWN ──────────────────────────────────────
$payment_q = $conn->query("
    SELECT payment_method, COUNT(*) AS cnt,
           SUM(total_amount) AS total, SUM(quantity_sold) AS trays
    FROM sales
    WHERE DATE(date_sold) = '$today'
    GROUP BY payment_method
    ORDER BY total DESC
");
$payment_rows = [];
if ($payment_q) while ($r = $payment_q->fetch_assoc()) $payment_rows[] = $r;

// ── PER-STAFF SUMMARY ─────────────────────────────────────────────
$staff_q = $conn->query("
    SELECT u.username,
           COUNT(s.sale_id)     AS transactions,
           SUM(s.quantity_sold) AS trays,
           SUM(s.total_amount)  AS revenue
    FROM sales s
    JOIN users u ON s.staff_id = u.user_id
    WHERE DATE(s.date_sold) = '$today'
    GROUP BY s.staff_id, u.username
    ORDER BY revenue DESC
");
$staff_rows = [];
if ($staff_q) while ($r = $staff_q->fetch_assoc()) $staff_rows[] = $r;

// ── TODAY'S TRANSACTIONS ──────────────────────────────────────────
$sales_q = $conn->query("
    SELECT s.*, u.username AS staff_name
    FROM sales s
    LEFT JOIN users u ON s.staff_id = u.user_id
    WHERE DATE(s.date_sold) = '$today'
    ORDER BY s.date_sold DESC
");

// ── PAYMENT METHOD CONFIG ─────────────────────────────────────────
$payment_config = [
    'Cash'          => ['color' => 'var(--success)', 'icon' => 'payments'],
    'GCash'         => ['color' => 'var(--info)',    'icon' => 'smartphone'],
    'Bank Transfer' => ['color' => 'var(--gold)',    'icon' => 'account_balance'],
];
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">

<div class="page-container">

    <div class="page-header">
        <div>
            <h2>Today's Sales</h2>
            
        </div>
        
    </div>

    <!-- SNAPSHOT CARDS -->
    <div class="stat-grid">

        <div class="stat-card stat-card--wide" style="border-top:4px solid var(--success);">
            <div class="stat-label">Revenue</div>
            <div class="stat-value stat-value--lg" style="color:var(--success);">
                &#8369;<?php echo number_format($today_rev, 2); ?>
            </div>
            <div class="stat-sub" style="margin-top:8px;">
                <?php if ($rev_pct !== null): ?>
                    <span class="delta <?php echo $rev_diff >= 0 ? 'delta--up' : 'delta--down'; ?>">
                        <?php echo $rev_diff >= 0 ? '&#9650;' : '&#9660;'; ?>
                        <?php echo abs($rev_pct); ?>%
                    </span>
                    vs yesterday &nbsp;(&#8369;<?php echo number_format($yest_rev, 2); ?>)
                <?php elseif ($yest_rev == 0 && $today_rev > 0): ?>
                    <span class="text-muted">No sales yesterday to compare</span>
                <?php else: ?>
                    <span class="text-muted">No sales recorded today yet</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="stat-card" style="border-top:4px solid var(--gold);">
            <div class="stat-label">Transactions</div>
            <div class="stat-value"><?php echo number_format($total_tx); ?></div>
            <div class="stat-sub">sales today</div>
        </div>

        <div class="stat-card" style="border-top:4px solid var(--terra-lt);">
            <div class="stat-label">Trays Sold</div>
            <div class="stat-value"><?php echo number_format((int)($stats['total_trays'] ?? 0)); ?></div>
            <div class="stat-sub"><?php echo number_format((int)($stats['total_eggs'] ?? 0)); ?> eggs</div>
        </div>

        <div class="stat-card" style="border-top:4px solid var(--info);">
            <div class="stat-label">Avg. per Sale</div>
            <div class="stat-value">&#8369;<?php echo number_format((float)($stats['avg_per_sale'] ?? 0), 2); ?></div>
            <div class="stat-sub">
                Biggest: &#8369;<?php echo number_format((float)($stats['biggest_sale'] ?? 0), 2); ?>
            </div>
        </div>

    </div>

    <?php if ($total_tx === 0): ?>

    <!-- EMPTY STATE -->
    <div class="card empty-card">
        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
            <rect x="2" y="5" width="20" height="14" rx="2"/>
            <path d="M2 10h20"/>
            <path d="M6 15h4M14 15h4"/>
        </svg>
        <h3 class="empty-title">No sales recorded today.</h3>
      
    </div>

    <?php else: ?>

    <!-- BREAKDOWN: PAYMENT + STAFF -->
    <div class="breakdown-grid">

        <!-- Payment Method -->
        <div class="card card--padded">
            <h3 class="section-title">By Payment Method</h3>
            <?php foreach ($payment_rows as $pm):
                $pct    = $today_rev > 0 ? round(((float)$pm['total'] / $today_rev) * 100, 1) : 0;
                $cfg    = $payment_config[$pm['payment_method']] ?? ['color' => 'var(--text-muted)', 'icon' => 'credit_card'];
                $color  = $cfg['color'];
                $icon   = $cfg['icon'];
            ?>
            <div class="payment-row">
                <div class="payment-row__header">
                    <span class="payment-row__method">
                        <span class="material-symbols-outlined payment-icon" style="color:<?php echo $color; ?>;">
                            <?php echo $icon; ?>
                        </span>
                        <?php echo htmlspecialchars($pm['payment_method']); ?>
                    </span>
                    <span class="payment-row__meta">
                        <?php echo $pm['cnt']; ?> sale<?php echo $pm['cnt'] > 1 ? 's' : ''; ?>
                        &nbsp;&middot;&nbsp;
                        <strong style="color:var(--success);">&#8369;<?php echo number_format((float)$pm['total'], 2); ?></strong>
                    </span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $color; ?>;"></div>
                </div>
                <div class="payment-row__footnote">
                    <?php echo $pct; ?>% of revenue
                    &nbsp;&middot;&nbsp;
                    <?php echo number_format((int)$pm['trays']); ?> tray<?php echo $pm['trays'] > 1 ? 's' : ''; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Per-Staff Summary -->
        <div class="card card--padded">
            <h3 class="section-title">Sales by Staff</h3>
            <div class="table-wrapper" style="border:none;">
                <table class="table-farm" style="font-size:0.84rem;">
                    <thead>
                        <tr>
                            <th>Staff</th>
                            <th class="col-center">Sales</th>
                            <th class="col-center">Trays</th>
                            <th class="col-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($staff_rows as $sr): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($sr['username']); ?></strong></td>
                            <td class="col-center text-muted"><?php echo $sr['transactions']; ?></td>
                            <td class="col-center text-muted"><?php echo number_format((int)$sr['trays']); ?></td>
                            <td class="col-right font-bold text-success">
                                &#8369;<?php echo number_format((float)$sr['revenue'], 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="col-right text-muted text-sm">Day total</td>
                            <td class="col-right" style="color:var(--gold);">
                                &#8369;<?php echo number_format($today_rev, 2); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div>

    <!-- TRANSACTION LIST -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card__header">
            <h3>
                Transactions
                <span class="card__header-count">
                    <?php echo $total_tx; ?> record<?php echo $total_tx !== 1 ? 's' : ''; ?>
                </span>
            </h3>
        </div>
        <div class="table-wrapper" style="border:none; border-radius:0;">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Time</th>
                        <th>Staff</th>
                        <th>Customer</th>
                        <th class="col-center">Trays</th>
                        <th class="col-right">Unit Price</th>
                        <th class="col-right">Total</th>
                        <th>Payment</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $sales_q->fetch_assoc()):
                        $pm_cfg  = $payment_config[$row['payment_method']] ?? ['color' => 'var(--text-muted)', 'icon' => 'credit_card'];
                        $pm_icon = $pm_cfg['icon'];
                        $pm_color = $pm_cfg['color'];
                    ?>
                    <tr>
                        <td class="text-muted text-xs">#<?php echo $row['sale_id']; ?></td>
                        <td class="text-muted text-sm" style="white-space:nowrap;">
                            <?php echo date('g:i A', strtotime($row['date_sold'])); ?>
                        </td>
                        <td class="text-secondary text-sm">
                            <?php echo htmlspecialchars($row['staff_name'] ?? '—'); ?>
                        </td>
                        <td><strong><?php echo htmlspecialchars($row['customer_name']); ?></strong></td>
                        <td class="col-center font-bold"><?php echo number_format($row['quantity_sold']); ?></td>
                        <td class="col-right text-muted text-sm">
                            &#8369;<?php echo number_format((float)$row['unit_price'], 2); ?>
                        </td>
                        <td class="col-right font-bold text-success">
                            &#8369;<?php echo number_format((float)$row['total_amount'], 2); ?>
                        </td>
                        <td style="white-space:nowrap;">
                            <span class="payment-method-cell">
                                <span class="material-symbols-outlined payment-icon-sm" style="color:<?php echo $pm_color; ?>;">
                                    <?php echo $pm_icon; ?>
                                </span>
                                <span class="text-sm"><?php echo htmlspecialchars($row['payment_method']); ?></span>
                            </span>
                        </td>
                        <td class="text-muted text-xs" style="max-width:150px;">
                            <?php echo htmlspecialchars($row['notes'] ?: '—'); ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="col-right text-muted text-sm">Day total</td>
                        <td class="col-center font-bold" style="color:var(--gold);">
                            <?php echo number_format((int)$stats['total_trays']); ?> trays
                        </td>
                        <td></td>
                        <td class="col-right font-bold" style="color:var(--gold); font-size:1rem;">
                            &#8369;<?php echo number_format($today_rev, 2); ?>
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php endif; ?>


</div>

<style>
.page-container    { max-width:1080px; margin:2rem auto; }

/* Stat grid */
.stat-grid         { display:flex; gap:16px; margin-bottom:2rem; flex-wrap:wrap; }
.stat-card--wide   { flex:2; min-width:200px; }
.stat-value--lg    { font-size:2.3rem; }

/* Delta indicator */
.delta             { font-weight:700; }
.delta--up         { color:var(--success); }
.delta--down       { color:var(--danger); }

/* Breakdown grid */
.breakdown-grid    { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px; }
.card--padded      { padding:1.4rem 1.6rem; }
.section-title     { margin-bottom:1.2rem; font-size:0.92rem; }

/* Payment rows */
.payment-row           { margin-bottom:1.1rem; }
.payment-row__header   { display:flex; justify-content:space-between;
                         font-size:0.84rem; margin-bottom:5px; }
.payment-row__method   { font-weight:600; color:var(--text-primary);
                         display:flex; align-items:center; gap:6px; }
.payment-row__meta     { color:var(--text-muted); }
.payment-row__footnote { font-size:0.7rem; color:var(--text-muted); margin-top:3px; }
.payment-icon          { font-size:16px; vertical-align:middle; }

/* Payment icon in table */
.payment-method-cell   { display:inline-flex; align-items:center; gap:5px; }
.payment-icon-sm       { font-size:15px; }

/* Progress bar */
.progress-bar  { background:var(--bg-wood); border-radius:4px; height:8px; }
.progress-fill { height:8px; border-radius:4px; }

/* Card header */
.card__header        { padding:1.2rem 1.6rem; border-bottom:1px solid var(--border-subtle); }
.card__header h3     { margin:0; }
.card__header-count  { font-size:0.78rem; font-weight:500; color:var(--text-muted); margin-left:8px; }

/* Table helpers */
.col-center    { text-align:center; }
.col-right     { text-align:right; }
.text-muted    { color:var(--text-muted); }
.text-secondary { color:var(--text-secondary); }
.text-success  { color:var(--success); }
.text-sm       { font-size:0.84rem; }
.text-xs       { font-size:0.75rem; }
.font-bold     { font-weight:700; }
.link-gold     { color:var(--gold); text-decoration:none; font-weight:600; }

/* Empty state */
.empty-card    { text-align:center; padding:4rem 2rem; }
.empty-icon    { width:48px; height:48px; color:var(--text-muted); opacity:0.35;
                 margin:0 auto 1rem; display:block; }
.empty-title   { color:var(--text-secondary); margin-bottom:0.5rem; }
.empty-sub     { color:var(--text-muted); font-size:0.9rem; }

/* Footer nav */
.page-footer-nav { margin-top:1.5rem; display:flex; justify-content:space-between;
                   align-items:center; flex-wrap:wrap; gap:10px; }
</style>
