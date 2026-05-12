<?php
/*
 * owner/inventory.php — Real-time Egg Inventory
 *
 * Coop tab filtering: ?coop=N filters all queries to that batch.
 * Falls back gracefully if coop_number column not yet added.
 */

$page_title = 'Egg Inventory';

session_start();
include('../../includes/db.php');
include('../../includes/header.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header("Location: ../../portal/login.php");
    exit();
}

$message = '';

// ── COOP FILTER ───────────────────────────────────────────────────
$coop_id      = isset($_GET['coop']) ? intval($_GET['coop']) : 0;
$is_all       = ($coop_id === 0);
$batch_filter = $is_all ? "" : "AND b.batch_id = $coop_id";
$hv_filter    = $is_all ? "" : "AND h.batch_id = $coop_id";

// ── FETCH ACTIVE BATCHES FOR TABS ─────────────────────────────────
$tab_q = $conn->query("
    SELECT batch_id, breed,
           COALESCE(arrival_date, date_acquired) AS arrival_date,
           coop_number, coop_label, status
    FROM batches
    WHERE status = 'Active'
    ORDER BY coop_number ASC, batch_id ASC
");
$tab_batches    = [];
$selected_batch = null;
if ($tab_q) {
    while ($tb = $tab_q->fetch_assoc()) {
        $tab_batches[] = $tb;
        if (!$is_all && $tb['batch_id'] == $coop_id) {
            $selected_batch = $tb;
        }
    }
}

// Fall back to All if selected coop doesn't exist or isn't active
if (!$is_all && !$selected_batch) {
    $coop_id      = 0;
    $is_all       = true;
    $batch_filter = "";
    $hv_filter    = "";
}

// ── HARVEST TOTALS PER SIZE ───────────────────────────────────────
$h_q = $conn->query("
    SELECT
        COALESCE(SUM(h.size_pw),0)    AS pw,
        COALESCE(SUM(h.size_s),0)     AS s,
        COALESCE(SUM(h.size_m),0)     AS m,
        COALESCE(SUM(h.size_l),0)     AS l,
        COALESCE(SUM(h.size_xl),0)    AS xl,
        COALESCE(SUM(h.size_j),0)     AS j,
        COALESCE(SUM(h.total_eggs),0) AS total
    FROM harvests h
    JOIN batches b ON h.batch_id = b.batch_id
    WHERE 1=1 $batch_filter
");
$h               = $h_q->fetch_assoc();
$total_harvested = (int)$h['total'];

// ── TOTAL SOLD ────────────────────────────────────────────────────
$_inv_has_batch_col = $conn->query("SHOW COLUMNS FROM sales LIKE 'batch_id'")->num_rows > 0;

if ($is_all) {
    $s_q = $conn->query("
        SELECT
            COALESCE(SUM(qty_pw*30),0) AS pw,
            COALESCE(SUM(qty_s*30),0)  AS s,
            COALESCE(SUM(qty_m*30),0)  AS m,
            COALESCE(SUM(qty_l*30),0)  AS l,
            COALESCE(SUM(qty_xl*30),0) AS xl,
            COALESCE(SUM(qty_j*30),0)  AS j
        FROM sales
    ");
} elseif ($_inv_has_batch_col) {
    $s_q = $conn->query("
        SELECT
            COALESCE(SUM(qty_pw*30),0) AS pw,
            COALESCE(SUM(qty_s*30),0)  AS s,
            COALESCE(SUM(qty_m*30),0)  AS m,
            COALESCE(SUM(qty_l*30),0)  AS l,
            COALESCE(SUM(qty_xl*30),0) AS xl,
            COALESCE(SUM(qty_j*30),0)  AS j
        FROM sales
        WHERE batch_id = $coop_id
    ");
} else {
    $arrival_esc = $conn->real_escape_string($selected_batch['arrival_date'] ?? '2000-01-01');
    $s_q = $conn->query("
        SELECT
            COALESCE(SUM(qty_pw*30),0) AS pw,
            COALESCE(SUM(qty_s*30),0)  AS s,
            COALESCE(SUM(qty_m*30),0)  AS m,
            COALESCE(SUM(qty_l*30),0)  AS l,
            COALESCE(SUM(qty_xl*30),0) AS xl,
            COALESCE(SUM(qty_j*30),0)  AS j
        FROM sales
        WHERE DATE(date_sold) >= '$arrival_esc'
    ");
}
$sold_by_size = $s_q->fetch_assoc();
$total_sold   = array_sum($sold_by_size);

// ── SIZE METADATA ─────────────────────────────────────────────────
$sizes_meta = [
    'pw' => ['label' => 'Peewee',      'code' => 'PW', 'color' => '#adb5bd'],
    's'  => ['label' => 'Small',       'code' => 'S',  'color' => '#74c0fc'],
    'm'  => ['label' => 'Medium',      'code' => 'M',  'color' => '#51cf66'],
    'l'  => ['label' => 'Large',       'code' => 'L',  'color' => '#fcc419'],
    'xl' => ['label' => 'Extra Large', 'code' => 'XL', 'color' => '#ff922b'],
    'j'  => ['label' => 'Jumbo',       'code' => 'J',  'color' => '#f03e3e'],
];

$stock           = [];
$total_remaining = 0;
foreach ($sizes_meta as $key => $meta) {
    $harvested   = (int)$h[$key];
    $sold        = (int)$sold_by_size[$key];
    $remaining   = max(0, $harvested - $sold);
    $total_remaining += $remaining;
    $stock[$key] = [
        'label'      => $meta['label'],
        'code'       => $meta['code'],
        'color'      => $meta['color'],
        'harvested'  => $harvested,
        'sold'       => $sold,
        'remaining'  => $remaining,
        'trays_harv' => (int)floor($harvested / 30),
        'trays_rem'  => (int)floor($remaining / 30),
    ];
}

// ── PRICES ────────────────────────────────────────────────────────
$prices = [];
$pq = $conn->query("SELECT breed, size_code, price_per_tray, price_per_piece FROM breed_prices");
if ($pq) {
    while ($p = $pq->fetch_assoc()) {
        $prices[$p['breed']][$p['size_code']] = $p;
    }
}

// Accumulator: estimated value per size across all batches (built in the batch loop below)
$est_value_by_size = ['PW' => 0, 'S' => 0, 'M' => 0, 'L' => 0, 'XL' => 0, 'J' => 0];

// ── TODAY / WEEK HARVEST ──────────────────────────────────────────
$today_q = $conn->query("
    SELECT COALESCE(SUM(h.total_eggs),0) AS v
    FROM harvests h
    JOIN batches b ON h.batch_id = b.batch_id
    WHERE DATE(h.date_logged) = CURDATE() $batch_filter
");
$today = (int)$today_q->fetch_assoc()['v'];

$week_q = $conn->query("
    SELECT COALESCE(SUM(h.total_eggs),0) AS v
    FROM harvests h
    JOIN batches b ON h.batch_id = b.batch_id
    WHERE h.date_logged >= DATE_SUB(NOW(), INTERVAL 7 DAY) $batch_filter
");
$this_week = (int)$week_q->fetch_assoc()['v'];

// ── TODAY'S HARVEST DETAIL PER SIZE (per-coop view only) ─────────
$today_detail = null;
if (!$is_all) {
    $td_q = $conn->query("
        SELECT
            COALESCE(SUM(h.size_pw),0)    AS pw,
            COALESCE(SUM(h.size_s),0)     AS s,
            COALESCE(SUM(h.size_m),0)     AS m,
            COALESCE(SUM(h.size_l),0)     AS l,
            COALESCE(SUM(h.size_xl),0)    AS xl,
            COALESCE(SUM(h.size_j),0)     AS j,
            COALESCE(SUM(h.total_eggs),0) AS total
        FROM harvests h
        WHERE h.batch_id = $coop_id
          AND DATE(h.date_logged) = CURDATE()
    ");
    $today_detail = $td_q ? $td_q->fetch_assoc() : null;
}

// ── PER-BATCH DATA ────────────────────────────────────────────────
$batch_q = $conn->query("
    SELECT
        b.batch_id, b.breed, b.arrival_date, b.status,
        b.coop_number, b.coop_label,
        COALESCE(SUM(h.total_eggs),0)  AS eggs_harvested,
        COALESCE(SUM(h.size_pw),0)     AS bpw,
        COALESCE(SUM(h.size_s),0)      AS bs,
        COALESCE(SUM(h.size_m),0)      AS bm,
        COALESCE(SUM(h.size_l),0)      AS bl,
        COALESCE(SUM(h.size_xl),0)     AS bxl,
        COALESCE(SUM(h.size_j),0)      AS bj,
        (SELECT u.username FROM harvests h2
         JOIN users u ON h2.staff_id = u.user_id
         WHERE h2.batch_id = b.batch_id
         ORDER BY h2.date_logged DESC LIMIT 1) AS last_harvester,
        (SELECT h2.date_logged FROM harvests h2
         WHERE h2.batch_id = b.batch_id
         ORDER BY h2.date_logged DESC LIMIT 1) AS last_harvest_date
    FROM batches b
    LEFT JOIN harvests h ON h.batch_id = b.batch_id
    WHERE b.status = 'Active' $batch_filter
    GROUP BY b.batch_id
    ORDER BY b.arrival_date ASC, b.batch_id ASC
");

$batches_list    = [];
$oldest_batch_id = null;

// Check once if sales has a batch_id column
$sales_has_batch_col = $conn->query("SHOW COLUMNS FROM sales LIKE 'batch_id'")->num_rows > 0;

if ($batch_q) {
    while ($row = $batch_q->fetch_assoc()) {
        $bid        = (int)$row['batch_id'];
        $harv_total = (int)$row['eggs_harvested'];

        if ($sales_has_batch_col) {
            // Exact: sales linked directly to this batch
            $sold_q2    = $conn->query("
                SELECT
                    COALESCE(SUM(qty_pw),0)  AS spw,
                    COALESCE(SUM(qty_s),0)   AS ss,
                    COALESCE(SUM(qty_m),0)   AS sm,
                    COALESCE(SUM(qty_l),0)   AS sl,
                    COALESCE(SUM(qty_xl),0)  AS sxl,
                    COALESCE(SUM(qty_j),0)   AS sj,
                    COALESCE(SUM(quantity_sold),0) AS total_trays
                FROM sales WHERE batch_id=$bid
            ");
            $sold_row   = $sold_q2 ? $sold_q2->fetch_assoc() : [];
            $batch_sold = (int)($sold_row['total_trays'] ?? 0) * 30;
            $sold_per_size = [
                'pw'  => (int)($sold_row['spw']  ?? 0) * 30,
                's'   => (int)($sold_row['ss']   ?? 0) * 30,
                'm'   => (int)($sold_row['sm']   ?? 0) * 30,
                'l'   => (int)($sold_row['sl']   ?? 0) * 30,
                'xl'  => (int)($sold_row['sxl']  ?? 0) * 30,
                'j'   => (int)($sold_row['sj']   ?? 0) * 30,
            ];
        } else {
            // Fallback: all sales since arrival date, proportionally split if multiple batches
            $arrival     = $row['arrival_date'] ?? '2000-01-01';
            $arr_esc     = $conn->real_escape_string($arrival);
            $sold_q2     = $conn->query("
                SELECT
                    COALESCE(SUM(qty_pw),0)  AS spw,
                    COALESCE(SUM(qty_s),0)   AS ss,
                    COALESCE(SUM(qty_m),0)   AS sm,
                    COALESCE(SUM(qty_l),0)   AS sl,
                    COALESCE(SUM(qty_xl),0)  AS sxl,
                    COALESCE(SUM(qty_j),0)   AS sj,
                    COALESCE(SUM(quantity_sold),0) AS total_trays
                FROM sales WHERE DATE(date_sold) >= '$arr_esc'
            ");
            $sold_row    = $sold_q2 ? $sold_q2->fetch_assoc() : [];
            $batch_sold  = (int)($sold_row['total_trays'] ?? 0) * 30;
            $sold_per_size = [
                'pw'  => (int)($sold_row['spw']  ?? 0) * 30,
                's'   => (int)($sold_row['ss']   ?? 0) * 30,
                'm'   => (int)($sold_row['sm']   ?? 0) * 30,
                'l'   => (int)($sold_row['sl']   ?? 0) * 30,
                'xl'  => (int)($sold_row['sxl']  ?? 0) * 30,
                'j'   => (int)($sold_row['sj']   ?? 0) * 30,
            ];
        }

        $remaining_eggs = max(0, $harv_total - $batch_sold);

        $size_remaining = [];
        $sz_map = ['pw' => 'bpw', 's' => 'bs', 'm' => 'bm', 'l' => 'bl', 'xl' => 'bxl', 'j' => 'bj'];
        foreach ($sz_map as $sz => $col) {
            $h_sz  = (int)$row[$col];
            $s_sz  = $sold_per_size[$sz] ?? 0;
            $r_sz  = max(0, $h_sz - $s_sz);
            $code  = $sizes_meta[$sz]['code'];
            $trays = (int)floor($r_sz / 30);

            // Use this batch's breed price for this size
            $breed_price = isset($prices[$row['breed']][$code])
                           ? (float)$prices[$row['breed']][$code]['price_per_tray']
                           : 0;
            $size_est_val = $trays * $breed_price;

            // Add to the all-coops accumulator
            $est_value_by_size[$code] += $size_est_val;

            $size_remaining[$sz] = [
                'eggs'    => $r_sz,
                'trays'   => $trays,
                'code'    => $code,
                'est_val' => $size_est_val,  // per-coop est value for this size
            ];
        }

        $row['remaining_eggs']  = $remaining_eggs;
        $row['remaining_trays'] = (int)floor($remaining_eggs / 30);
        $row['size_rem']        = $size_remaining;
        $batches_list[]         = $row;
    }
    foreach ($batches_list as $bl) {
        if ($bl['remaining_trays'] > 0) { $oldest_batch_id = $bl['batch_id']; break; }
    }
}

// ── OLD STOCK (>7 days since last harvest) ────────────────────────
$old_stock_batches = array_filter($batches_list, function($bl) {
    $days = $bl['last_harvest_date']
            ? (int)floor((time() - strtotime($bl['last_harvest_date'])) / 86400) : 0;
    return $bl['remaining_trays'] > 0 && $days > 7;
});

// Auto-notify removed: notifications are only created manually via the Sell First button.

?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="page-container">

    <div class="page-header">
        <div>
            <h2>Egg Inventory</h2>
            <p>Stock on hand &middot; Per-size counts</p>
        </div>
        <span class="timestamp"><?php echo date('M d, Y — g:i A'); ?></span>

       
    </div>

    <?php echo $message; ?>

    <!-- COOP TABS -->
    <div class="coop-tabs-wrapper">
        <div class="coop-tabs">
            <a href="inventory.php" class="coop-tab <?php echo $is_all ? 'active' : ''; ?>">
                All Coops
            </a>
            <?php foreach ($tab_batches as $tb):
                $is_active   = (!$is_all && $tb['batch_id'] == $coop_id);
                $coop_label  = $tb['coop_label'] ?: ('Coop ' . $tb['coop_number']);
                $coop_display = $tb['coop_number'] ? $coop_label : ('Batch #' . $tb['batch_id']);
            ?>
            <a href="?coop=<?php echo $tb['batch_id']; ?>"
               class="coop-tab <?php echo $is_active ? 'active' : ''; ?>">
                <span class="coop-dot"></span>
                <?php echo htmlspecialchars($coop_display); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (!$is_all && $selected_batch): ?>
        <div class="coop-info-strip">
            <div class="coop-info-item">
                <span class="coop-info-label">Breed</span>
                <span class="coop-info-value"><?php echo htmlspecialchars($selected_batch['breed']); ?></span>
            </div>
            <div class="coop-info-item">
                <span class="coop-info-label">Batch</span>
                <span class="coop-info-value">#<?php echo $selected_batch['batch_id']; ?></span>
            </div>
            <?php if ($selected_batch['arrival_date']): ?>
            <div class="coop-info-item">
                <span class="coop-info-label">Arrived</span>
                <span class="coop-info-value"><?php echo date('M d, Y', strtotime($selected_batch['arrival_date'])); ?></span>
            </div>
            <?php
            $selected_bl = null;
            foreach ($batches_list as $bl) {
                if ($bl['batch_id'] == $selected_batch['batch_id']) { $selected_bl = $bl; break; }
            }
            $days_since = ($selected_bl && $selected_bl['last_harvest_date'])
                ? (int)floor((time() - strtotime($selected_bl['last_harvest_date'])) / 86400)
                : null;
            if ($days_since !== null && $days_since > 7 && !empty($batches_list)): ?>
            <div class="coop-info-item">
                <span class="badge badge-critical"><?php echo $days_since; ?> days on hand</span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- STAT CARDS -->
    <div class="stat-grid">
        <div class="stat-card" style="border-top:3px solid var(--gold);">
            <div class="stat-label">Today's Harvest</div>
            <div class="stat-value"><?php echo number_format($today); ?></div>
            <div class="stat-sub">eggs collected<?php echo !$is_all ? ' (this coop)' : ''; ?></div>
        </div>
        <div class="stat-card" style="border-top:3px solid var(--terra-lt);">
            <div class="stat-label">This Week</div>
            <div class="stat-value"><?php echo number_format($this_week); ?></div>
            <div class="stat-sub">last 7 days<?php echo !$is_all ? ' (this coop)' : ''; ?></div>
        </div>
        <div class="stat-card" style="border-top:3px solid var(--success);">
            <div class="stat-label">Total on Hand</div>
            <div class="stat-value"><?php echo number_format($total_remaining); ?></div>
            <div class="stat-sub"><?php echo number_format(floor($total_remaining / 30)); ?> trays remaining</div>
        </div>
        <div class="stat-card" style="border-top:3px solid var(--danger);">
            <div class="stat-label">Total Sold</div>
            <div class="stat-value"><?php echo number_format($total_sold); ?></div>
            <div class="stat-sub"><?php echo number_format(floor($total_sold / 30)); ?> trays sold</div>
        </div>
    </div>

    <?php if ($is_all): ?>
    <!-- ALL COOPS: Harvest by Size (lifetime cumulative totals) -->
    <div class="card card--table">
        <div class="card__header">
            <div>
                <h3>Harvest by Size</h3>
                <p class="card__subtext">Lifetime total eggs collected per size — all batches combined.</p>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>Size</th>
                        <th class="col-center">Eggs</th>
                        <th class="col-center">Trays</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stock as $key => $info):
                        $share = $total_harvested > 0
                            ? round(($info['harvested'] / $total_harvested) * 100, 1) : 0;
                    ?>
                    <tr>
                        <td>
                            <span class="size-dot" style="background:<?php echo $info['color']; ?>;"></span>
                            <strong><?php echo $info['label']; ?></strong>
                            <span class="text-muted"><?php echo $info['code']; ?></span>
                        </td>
                        <td class="col-center font-medium"><?php echo number_format($info['harvested']); ?></td>
                        <td class="col-center"><?php echo number_format($info['trays_harv']); ?></td>
                        <td>
                            <div class="progress-row">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width:<?php echo $share; ?>%; background:<?php echo $info['color']; ?>;"></div>
                                </div>
                                <span class="progress-label"><?php echo $share; ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>Total</strong></td>
                        <td class="col-center"><strong><?php echo number_format($total_harvested); ?></strong></td>
                        <td class="col-center"><?php echo number_format(floor($total_harvested / 30)); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php else: ?>
    <!-- PER-COOP: Today's Harvest for this coop only -->
    <div class="card card--table">
        <div class="card__header">
            <div>
                <h3>Today's Harvest
                    <span class="card__header-note">
                        <?php echo htmlspecialchars($selected_batch['breed']); ?>
                        <?php if ($selected_batch['coop_number']): ?>
                            &mdash; Coop <?php echo $selected_batch['coop_number']; ?>
                        <?php endif; ?>
                    </span>
                </h3>
                <p class="card__subtext">
                    Eggs collected today, <?php echo date('M d, Y'); ?>.
                    <?php if (empty($today_detail) || (int)$today_detail['total'] === 0): ?>
                        <strong style="color:var(--text-muted);">No harvest logged yet today.</strong>
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>Size</th>
                        <th class="col-center">Eggs Today</th>
                        <th class="col-center">Trays Today</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $today_total = $today_detail ? (int)$today_detail['total'] : 0;
                    foreach ($sizes_meta as $key => $meta):
                        $eggs_today  = $today_detail ? (int)$today_detail[$key] : 0;
                        $trays_today = (int)floor($eggs_today / 30);
                        $share_today = $today_total > 0
                            ? round(($eggs_today / $today_total) * 100, 1) : 0;
                    ?>
                    <tr <?php echo $eggs_today === 0 ? 'class="row-dimmed"' : ''; ?>>
                        <td>
                            <span class="size-dot" style="background:<?php echo $meta['color']; ?>;"></span>
                            <strong><?php echo $meta['label']; ?></strong>
                            <span class="text-muted"><?php echo $meta['code']; ?></span>
                        </td>
                        <td class="col-center font-medium"><?php echo number_format($eggs_today); ?></td>
                        <td class="col-center"><?php echo number_format($trays_today); ?></td>
                        <td>
                            <div class="progress-row">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width:<?php echo $share_today; ?>%; background:<?php echo $meta['color']; ?>;"></div>
                                </div>
                                <span class="progress-label"><?php echo $share_today; ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>Total</strong></td>
                        <td class="col-center"><strong><?php echo number_format($today_total); ?></strong></td>
                        <td class="col-center"><?php echo number_format(floor($today_total / 30)); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- CURRENT STOCK TABLE -->
    <div class="card card--table">
        <div class="card__header">
            <div>
                <h3>Current Stock
                    <?php if (!$is_all): ?>
                    <span class="card__header-note">filtered to selected coop</span>
                    <?php endif; ?>
                </h3>
                <p class="card__subtext">Remaining = harvested minus estimated sold. 1 tray = 30 eggs.</p>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>Size</th>
                        <th class="col-center">Sold</th>
                        <th class="col-center">Remaining</th>
                        <th class="col-center">Trays</th>
                        <th class="col-center">Est. Value</th>
                        <th>Share</th>
                        <th class="col-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stock as $key => $info):
                        $est_value  = $est_value_by_size[$info['code']] ?? 0;
                        $share      = $total_remaining > 0 ? round(($info['remaining'] / $total_remaining) * 100, 1) : 0;
                    ?>
                    <tr <?php echo $info['remaining'] === 0 ? 'class="row-dimmed"' : ''; ?>>
                        <td>
                            <span class="size-dot size-dot--glow" style="background:<?php echo $info['color']; ?>; box-shadow:0 0 6px <?php echo $info['color']; ?>66;"></span>
                            <strong><?php echo $info['label']; ?></strong>
                            <span class="text-muted"><?php echo $info['code']; ?></span>
                        </td>
                        <td class="col-center text-danger text-sm">&minus;<?php echo number_format($info['sold']); ?></td>
                        <td class="col-center font-bold"><?php echo number_format($info['remaining']); ?></td>
                        <td class="col-center">
                            <?php if ($info['trays_rem'] > 0): ?>
                                <strong style="color:var(--gold); font-size:1.05rem;"><?php echo number_format($info['trays_rem']); ?></strong>
                            <?php else: ?>
                                <span class="badge badge-approved">Sold Out</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-center text-success text-sm">
                            <?php echo ($est_value > 0)
                                ? '&#8369;' . number_format($est_value, 2)
                                : '<span class="text-muted">—</span>'; ?>
                        </td>
                        <td>
                            <div class="progress-row">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width:<?php echo $share; ?>%; background:<?php echo $info['color']; ?>;"></div>
                                </div>
                                <span class="progress-label"><?php echo $share; ?>%</span>
                            </div>
                        </td>
                        <td class="col-center">
                            <?php if ($info['trays_rem'] == 0): ?>
                                <span class="badge badge-approved">Out</span>
                            <?php elseif ($info['trays_rem'] < 10): ?>
                                <span class="badge badge-warning">Low</span>
                            <?php else: ?>
                                <span class="badge badge-healthy">Good</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>Total</strong></td>
                        <td class="col-center text-danger">&minus;<?php echo number_format($total_sold); ?></td>
                        <td class="col-center font-bold" style="color:var(--gold);"><?php echo number_format($total_remaining); ?></td>
                        <td class="col-center font-bold" style="color:var(--gold);"><?php echo number_format(floor($total_remaining / 30)); ?></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- OLD STOCK / SELL-FIRST -->
    <?php if (!empty($old_stock_batches)): ?>
    <div class="card card--table card--danger-accent">
        <div class="card__header">
            <div>
                <h3>Old Stock</h3>
                <p class="card__subtext">Batches with remaining trays where the last harvest was more than 7 days ago.</p>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Breed</th>
                        <th>Age</th>
                        <th>Last Harvested</th>
                        <?php foreach ($sizes_meta as $meta): ?>
                        <th class="col-center"><?php echo $meta['code']; ?></th>
                        <?php endforeach; ?>
                        <th class="col-center">Total</th>
                        <th class="col-center">Est. Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($old_stock_batches as $bl):
                        $days_old  = $bl['last_harvest_date']
                                     ? (int)floor((time() - strtotime($bl['last_harvest_date'])) / 86400) : '?';
                        $age_color = is_int($days_old) && $days_old > 30 ? 'var(--danger)' : 'var(--warning)';
                        $est_val   = 0;
                        foreach ($bl['size_rem'] as $sz => $info) {
                            $est_val += $info['est_val'];
                        }
                    ?>
                    <tr class="row-alert">
                        <td>
                            <strong>#<?php echo $bl['batch_id']; ?></strong>
                            <?php if ($bl['batch_id'] === $oldest_batch_id): ?>
                            <span class="badge badge-critical badge--xs">Oldest</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-secondary"><?php echo htmlspecialchars($bl['breed']); ?></td>
                        <td>
                            <strong style="color:<?php echo $age_color; ?>;"><?php echo $days_old; ?> days</strong>
                        </td>
                        <td class="text-sm">
                            <?php if ($bl['last_harvester']): ?>
                                <strong><?php echo htmlspecialchars($bl['last_harvester']); ?></strong>
                                <span class="text-muted" style="margin-left:4px;">
                                    <?php echo date('M d', strtotime($bl['last_harvest_date'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <?php $sz_order = ['pw', 's', 'm', 'l', 'xl', 'j'];
                        foreach ($sz_order as $sz): ?>
                        <td class="col-center">
                            <?php $info = $bl['size_rem'][$sz]; ?>
                            <?php if ($info['trays'] > 0): ?>
                                <strong style="color:var(--gold);"><?php echo $info['trays']; ?></strong>
                            <?php else: ?>
                                <span class="text-muted text-xs">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="col-center">
                            <strong style="color:var(--terra-lt);"><?php echo number_format($bl['remaining_trays']); ?></strong>
                        </td>
                        <td class="col-center text-success text-sm">
                            <?php echo $est_val > 0 ? '&#8369;' . number_format($est_val, 2) : '<span class="text-muted">—</span>'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ALL ACTIVE BATCHES — All view only -->
    <?php if ($is_all): ?>
    <div class="card card--table">
        <div class="card__header">
            <h3>Active Batches</h3>
            <p class="card__subtext">Sorted oldest first.</p>
        </div>
        <?php if (!empty($batches_list)): ?>
        <div class="table-wrapper">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Breed</th>
                        <th>Coop</th>
                        <th>Last Harvested By</th>
                        <th class="col-center">Harvested</th>
                        <th class="col-center">Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batches_list as $bl):
                        $is_old    = ($bl['batch_id'] === $oldest_batch_id && $bl['remaining_trays'] > 0);
                        $coop_disp = $bl['coop_number'] ? 'Coop ' . $bl['coop_number'] : '—';
                        if (!empty($bl['coop_label'])) $coop_disp = $bl['coop_label'];
                    ?>
                    <tr <?php echo $is_old ? 'class="row-alert"' : ''; ?>>
                        <td>
                            <strong>#<?php echo $bl['batch_id']; ?></strong>
                            <?php if ($is_old): ?>
                            <span class="badge badge-critical badge--xs">Old Stock</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-secondary"><?php echo htmlspecialchars($bl['breed']); ?></td>
                        <td>
                            <a href="?coop=<?php echo $bl['batch_id']; ?>" class="link-gold text-sm">
                                <?php echo htmlspecialchars($coop_disp); ?>
                            </a>
                        </td>
                        <td class="text-sm">
                            <?php if ($bl['last_harvester']): ?>
                                <strong><?php echo htmlspecialchars($bl['last_harvester']); ?></strong>
                                <span class="text-muted" style="margin-left:4px;">
                                    <?php echo date('M d', strtotime($bl['last_harvest_date'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">No harvests yet</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-center text-muted">
                            <?php echo number_format((int)floor($bl['eggs_harvested'] / 30)); ?> trays
                        </td>
                        <td class="col-center">
                            <?php if ($bl['remaining_trays'] > 0): ?>
                                <strong style="color:var(--gold);"><?php echo number_format($bl['remaining_trays']); ?></strong>
                                <span class="text-muted text-xs"> trays</span>
                            <?php else: ?>
                                <span class="badge badge-approved">Sold Out</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M20 7H4a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/>
                <path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/>
            </svg>
            <p>No active batches found.</p>
            <small>Add batches in <a href="../manage_batches.php" class="link-gold">Manage Batches</a> first.</small>
        </div>
        <?php endif; ?>
    </div>

    <!-- HARVEST DISTRIBUTION CHARTS -->
    <div class="charts-grid">
        <div class="card card--padded" style="text-align:center;">
            <h3 class="chart-title">Harvest Distribution</h3>
            <p class="card__subtext">Share of total harvested eggs per size.</p>
            <canvas id="harvestDist" style="max-height:240px;"></canvas>
        </div>
        <div class="card card--padded" style="text-align:center;">
            <h3 class="chart-title">Stock Remaining</h3>
            <p class="card__subtext">Share of unsold stock per size.</p>
            <canvas id="stockDist" style="max-height:240px;"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <a href="../dashboard.php" class="back-link">&larr; Back to Dashboard</a>
</div>


<style>
.page-container        { max-width:1080px; margin:2rem auto; }
.stat-grid             { display:flex; gap:16px; margin-bottom:2rem; flex-wrap:wrap; }
.card--table           { padding:0; overflow:hidden; margin-bottom:24px; }
.card--padded          { padding:1.4rem 1.6rem; }
.card--danger-accent   { border-top:3px solid var(--danger); }
.card__header          { padding:1.2rem 1.6rem; border-bottom:1px solid var(--border-subtle); }
.card__header h3       { margin:0; }
.card__header-note     { font-size:0.72rem; font-weight:400; color:var(--text-muted); margin-left:8px; }
.card__subtext         { font-size:0.78rem; color:var(--text-muted); margin-top:4px; margin-bottom:0; }
.chart-title           { font-size:0.92rem; margin-bottom:0.5rem; }
.charts-grid           { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px; }
.timestamp             { font-size:0.82rem; color:var(--text-muted); }

/* Coop tabs */
.coop-tabs-wrapper     { margin-bottom:1.5rem; }
.coop-tabs             { display:flex; flex-wrap:wrap; gap:8px; align-items:center;
                         border-bottom:2px solid var(--border-subtle); padding-bottom:0; }
.coop-tab              { display:inline-flex; align-items:center; gap:7px;
                         padding:9px 18px; border-radius:var(--radius) var(--radius) 0 0;
                         border:1px solid var(--border-mid); border-bottom:2px solid var(--border-mid);
                         background:var(--bg-wood); color:var(--text-secondary);
                         font-weight:500; font-size:0.85rem; text-decoration:none;
                         margin-bottom:-2px; transition:color 0.15s, border-color 0.15s; }
.coop-tab.active       { border-color:var(--gold); border-bottom-color:var(--bg-soil);
                         background:var(--bg-soil); color:var(--gold); font-weight:700; }
.coop-dot              { width:8px; height:8px; border-radius:50%; background:var(--success); flex-shrink:0; }
.coop-info-strip       { background:var(--bg-wood); border:1px solid var(--border-mid); border-top:none;
                         border-radius:0 0 var(--radius) var(--radius);
                         padding:10px 18px; display:flex; gap:24px; flex-wrap:wrap; align-items:center; }
.coop-info-item        { display:flex; flex-direction:column; gap:2px; }
.coop-info-label       { font-size:0.65rem; text-transform:uppercase; letter-spacing:0.6px;
                         color:var(--text-muted); font-weight:700; }
.coop-info-value       { font-size:0.9rem; font-weight:700; color:var(--text-primary); }

/* Table helpers */
.col-center  { text-align:center; }
.text-sm     { font-size:0.85rem; }
.text-xs     { font-size:0.72rem; }
.text-muted  { color:var(--text-muted); }
.text-secondary { color:var(--text-secondary); }
.text-danger { color:var(--danger); }
.text-success { color:var(--success); }
.font-medium { font-weight:600; }
.font-bold   { font-weight:700; }
.link-gold   { color:var(--gold); text-decoration:none; font-weight:600; }
.row-dimmed  { opacity:0.4; }
.row-alert   { background:rgba(194,58,58,0.04); }
.badge--xs   { font-size:0.55rem; margin-left:4px; vertical-align:middle; }

/* Size dot */
.size-dot      { display:inline-block; width:11px; height:11px; border-radius:50%;
                 margin-right:8px; vertical-align:middle; }

/* Progress bar */
.progress-row   { display:flex; align-items:center; gap:8px; min-width:130px; }
.progress-bar   { flex:1; background:var(--bg-plank); height:7px; border-radius:4px; }
.progress-fill  { height:7px; border-radius:4px; }
.progress-label { font-size:0.75rem; color:var(--text-muted); min-width:36px; }

/* Modal */
.modal-overlay  { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:999; }
.modal          { display:none; position:fixed; top:50%; left:50%;
                  transform:translate(-50%,-50%); z-index:1000;
                  width:min(540px,95vw); background:var(--bg-soil);
                  border:1px solid var(--border-mid); border-top:4px solid var(--danger);
                  border-radius:var(--radius-lg); padding:1.8rem;
                  box-shadow:var(--shadow-raised); }
.modal__title   { color:var(--gold); font-family:'Playfair Display',serif; margin-bottom:0.3rem; }
.modal__subtitle { font-size:0.83rem; color:var(--text-muted); margin-bottom:1.4rem; line-height:1.6; }
.modal__batch-info  { background:var(--bg-wood); border-radius:var(--radius);
                      padding:13px 16px; border-left:4px solid var(--danger); margin-bottom:1.2rem; }
.modal__batch-info-label { font-size:0.65rem; font-weight:700; color:var(--text-muted);
                           text-transform:uppercase; letter-spacing:0.6px; margin-bottom:4px; }
.modal__batch-name  { font-size:1rem; font-weight:700; color:var(--text-primary); }
.modal__batch-stock { font-size:0.8rem; color:var(--text-muted); margin-top:3px; }
.modal__batch-sizes { font-size:0.82rem; color:var(--gold); margin-top:4px; }
.modal__actions     { display:flex; gap:10px; margin-top:0.5rem; }
.form-hint          { font-size:0.75rem; color:var(--text-muted); display:block; margin-top:4px; }
.form-label-optional { font-weight:400; color:var(--text-muted); }

/* Empty state */
.empty-icon  { width:40px; height:40px; color:var(--text-muted); margin:0 auto 12px; display:block; }
</style>

<script>
<?php if ($is_all): ?>
const chartColors = <?php echo json_encode(array_map(fn($s) => $s['color'], array_values($sizes_meta))); ?>;
const sizeLabels  = <?php echo json_encode(array_map(fn($s) => $s['label'], array_values($sizes_meta))); ?>;
const harvestData = <?php echo json_encode(array_map(fn($s) => $s['harvested'], array_values($stock))); ?>;
const remainData  = <?php echo json_encode(array_map(fn($s) => $s['remaining'], array_values($stock))); ?>;

const sharedOpts = {
    responsive: true,
    plugins: {
        legend: { position: 'bottom', labels: { color: '#B8A88A', font: { size: 11 }, padding: 12 } },
        tooltip: { backgroundColor: '#2E2720', titleColor: '#F2EAD8', bodyColor: '#B8A88A' }
    },
    cutout: '55%'
};

new Chart(document.getElementById('harvestDist').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: sizeLabels,
        datasets: [{ data: harvestData, backgroundColor: chartColors, borderWidth: 3, borderColor: '#231E18' }]
    },
    options: sharedOpts
});
new Chart(document.getElementById('stockDist').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: sizeLabels,
        datasets: [{ data: remainData, backgroundColor: chartColors, borderWidth: 3, borderColor: '#231E18' }]
    },
    options: sharedOpts
});
<?php endif; ?>
</script>

<?php include('../../includes/footer.php'); ?>