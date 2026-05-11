<?php
/*
 * owner/manage_inventory/inventory_history.php — Inventory Archive
 *
 * Permanent record of all retired batches:
 * - Total eggs harvested vs. sold per batch
 * - Estimated revenue generated per batch
 * - Days active (lifespan)
 * - Final status when closed
 */

$page_title = 'Inventory History';

session_start();
include('../../includes/db.php');
include('../../includes/header.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header("Location: ../../portal/login.php");
    exit();
}

// ── FILTERS ───────────────────────────────────────────────────────
$filter_status = in_array($_GET['status'] ?? '', ['all', 'Retired', 'sold_out'])
                 ? ($_GET['status'] ?? 'all') : 'all';
$filter_breed  = trim($_GET['breed'] ?? '');
$date_from     = !empty($_GET['from']) ? date('Y-m-d', strtotime($_GET['from'])) : date('Y-m-d', strtotime('-12 months'));
$date_to       = !empty($_GET['to'])   ? date('Y-m-d', strtotime($_GET['to']))   : date('Y-m-d');

// ── PAGINATION ────────────────────────────────────────────────────
$per_page     = 20;
$current_page = max(1, intval($_GET['page'] ?? 1));
$offset       = ($current_page - 1) * $per_page;

// ── BREEDS FOR FILTER ─────────────────────────────────────────────
$breeds_q = $conn->query("SELECT DISTINCT breed FROM batches ORDER BY breed ASC");
$breeds   = [];
if ($breeds_q) while ($r = $breeds_q->fetch_assoc()) $breeds[] = $r['breed'];

// ── BUILD WHERE CLAUSE ────────────────────────────────────────────
$where_parts = ["b.status = 'Retired'"];
if ($filter_breed !== '') {
    $safe_breed    = $conn->real_escape_string($filter_breed);
    $where_parts[] = "b.breed = '$safe_breed'";
}
$where = 'WHERE ' . implode(' AND ', $where_parts);

// ── SUMMARY STATS ─────────────────────────────────────────────────
$summary_q = $conn->query("
    SELECT
        COUNT(b.batch_id)                        AS total_batches,
        COALESCE(SUM(h_agg.total_harvested), 0)  AS grand_harvested,
        COALESCE(SUM(s_agg.total_sold_eggs), 0)  AS grand_sold
    FROM batches b
    LEFT JOIN (
        SELECT batch_id, SUM(total_eggs) AS total_harvested
        FROM harvests GROUP BY batch_id
    ) h_agg ON h_agg.batch_id = b.batch_id
    LEFT JOIN (
        SELECT b2.batch_id, COALESCE(SUM(s.quantity_sold * 30), 0) AS total_sold_eggs
        FROM batches b2
        LEFT JOIN sales s ON DATE(s.date_sold) >= COALESCE(b2.arrival_date, b2.date_acquired, '2000-01-01')
        GROUP BY b2.batch_id
    ) s_agg ON s_agg.batch_id = b.batch_id
    $where
");
$summary = $summary_q
    ? $summary_q->fetch_assoc()
    : ['total_batches' => 0, 'grand_harvested' => 0, 'grand_sold' => 0];

$rev_q = $conn->query("
    SELECT COALESCE(SUM(s.total_amount), 0) AS total_revenue
    FROM sales s
    JOIN batches b ON DATE(s.date_sold) >= COALESCE(b.arrival_date, b.date_acquired, '2000-01-01')
    $where
");
$total_revenue = $rev_q ? (float)$rev_q->fetch_assoc()['total_revenue'] : 0;

// ── PAGINATION COUNT ──────────────────────────────────────────────
$count_q    = $conn->query("SELECT COUNT(*) AS c FROM batches b $where");
$total_rows = $count_q ? (int)$count_q->fetch_assoc()['c'] : 0;
$total_pages = max(1, ceil($total_rows / $per_page));

// ── MAIN QUERY ────────────────────────────────────────────────────
$history_q = $conn->query("
    SELECT
        b.batch_id,
        b.breed,
        b.status,
        COALESCE(b.arrival_date, b.date_acquired)  AS arrival_date,
        b.expected_replacement,
        b.notes,
        COALESCE(h_agg.total_harvested,  0) AS total_harvested,
        COALESCE(h_agg.harvest_sessions, 0) AS harvest_sessions,
        h_agg.first_harvest,
        h_agg.last_harvest,
        COALESCE(s_agg.total_sold_eggs, 0)  AS total_sold_eggs,
        COALESCE(s_agg.total_revenue,   0)  AS total_revenue,
        COALESCE(s_agg.sale_count,      0)  AS sale_count,
        COALESCE(b.initial_count, b.quantity, 0) AS bird_count
    FROM batches b
    LEFT JOIN (
        SELECT batch_id, SUM(total_eggs) AS total_harvested, COUNT(*) AS harvest_sessions,
               MIN(date_logged) AS first_harvest, MAX(date_logged) AS last_harvest
        FROM harvests GROUP BY batch_id
    ) h_agg ON h_agg.batch_id = b.batch_id
    LEFT JOIN (
        SELECT b2.batch_id,
               COALESCE(SUM(s.quantity_sold * 30), 0) AS total_sold_eggs,
               COALESCE(SUM(s.total_amount), 0)       AS total_revenue,
               COUNT(s.sale_id)                        AS sale_count
        FROM batches b2
        LEFT JOIN sales s ON DATE(s.date_sold) >= COALESCE(b2.arrival_date, b2.date_acquired, '2000-01-01')
        GROUP BY b2.batch_id
    ) s_agg ON s_agg.batch_id = b.batch_id
    $where
    ORDER BY b.batch_id DESC
    LIMIT $per_page OFFSET $offset
");

function page_url_inv($page, $params) {
    $params['page'] = $page;
    return '?' . http_build_query($params);
}
$filter_params = ['status' => $filter_status, 'breed' => $filter_breed, 'from' => $date_from, 'to' => $date_to];
?>

<div class="page-container">

    <div class="page-header">
        <div>
            <h2>Inventory History</h2>
            <p>Archive of all retired batches — full production and revenue records.</p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <a href="inventory.php" class="btn-farm btn-dark btn-sm">Active Inventory</a>
            <a href="../dashboard.php" class="back-link" style="margin:0;">&larr; Dashboard</a>
        </div>
    </div>

    <!-- FILTER PANEL -->
    <div class="card filter-panel">
        <form method="GET" class="filter-form">
            <div class="form-group" style="margin:0; min-width:160px; flex:1;">
                <label>Breed</label>
                <select name="breed" class="form-input">
                    <option value="">All Breeds</option>
                    <?php foreach ($breeds as $b): ?>
                        <option value="<?php echo htmlspecialchars($b); ?>"
                            <?php echo $filter_breed === $b ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-farm btn-sm">🔍</button>
                <a href="inventory_history.php" class="btn-farm btn-dark btn-sm">Reset</a>
            </div>
        </form>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="stat-grid">
        <div class="stat-card" style="border-top:4px solid var(--gold);">
            <div class="stat-label">Archived Batches</div>
            <div class="stat-value"><?php echo number_format((int)$summary['total_batches']); ?></div>
            <div class="stat-sub">retired / closed</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--terra-lt);">
            <div class="stat-label">Eggs Produced</div>
            <div class="stat-value"><?php echo number_format((int)$summary['grand_harvested']); ?></div>
            <div class="stat-sub"><?php echo number_format(floor((int)$summary['grand_harvested'] / 30)); ?> trays</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--info);">
            <div class="stat-label">Eggs Sold</div>
            <div class="stat-value"><?php echo number_format((int)$summary['grand_sold']); ?></div>
            <div class="stat-sub"><?php echo number_format(floor((int)$summary['grand_sold'] / 30)); ?> trays</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--success);">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value">&#8369;<?php echo number_format($total_revenue, 2); ?></div>
            <div class="stat-sub">from archived batches</div>
        </div>
    </div>

    <!-- HISTORY TABLE -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div class="card__header">
            <h3>
                Archived Records
                <span class="card__header-count">
                    <?php echo number_format($total_rows); ?> batch<?php echo $total_rows !== 1 ? 'es' : ''; ?>
                </span>
            </h3>
        </div>

        <div class="table-wrapper" style="border:none; border-radius:0;">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Breed</th>
                        <th>Birds</th>
                        <th>Status</th>
                        <th>Acquired</th>
                        <th>Active Period</th>
                        <th class="col-center">Produced</th>
                        <th class="col-center">Sold</th>
                        <th class="col-center">Revenue</th>
                        <th class="col-center">Efficiency</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($history_q && $history_q->num_rows > 0):
                    while ($row = $history_q->fetch_assoc()):
                        $arrival      = $row['arrival_date'];
                        $last_harvest = $row['last_harvest'];
                        $harvested    = (int)$row['total_harvested'];
                        $sold_eggs    = (int)$row['total_sold_eggs'];
                        $remaining    = max(0, $harvested - $sold_eggs);
                        $efficiency   = $harvested > 0 ? round(($sold_eggs / $harvested) * 100, 1) : 0;
                        $eff_color    = $efficiency >= 90 ? 'var(--success)'
                                      : ($efficiency >= 70 ? 'var(--gold)' : 'var(--danger)');

                        $lifespan_days = null;
                        if ($arrival && $last_harvest) {
                            $lifespan_days = (int)ceil((strtotime($last_harvest) - strtotime($arrival)) / 86400);
                        }
                ?>
                <tr>
                    <td class="text-muted text-sm font-bold">#<?php echo $row['batch_id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($row['breed']); ?></strong></td>
                    <td class="text-secondary text-sm"><?php echo number_format((int)$row['bird_count']); ?></td>
                    <td><span class="badge badge-rejected"><?php echo htmlspecialchars($row['status']); ?></span></td>
                    <td class="text-muted text-sm">
                        <?php echo $arrival ? date('M d, Y', strtotime($arrival)) : '—'; ?>
                    </td>
                    <td class="text-sm text-muted">
                        <?php if ($arrival && $last_harvest): ?>
                            <div><?php echo date('M d, Y', strtotime($arrival)); ?></div>
                            <div class="text-xs" style="margin-top:1px;">
                                &rarr; <?php echo date('M d, Y', strtotime($last_harvest)); ?>
                            </div>
                            <?php if ($lifespan_days !== null): ?>
                            <div style="margin-top:3px;">
                                <span class="text-xs font-bold" style="color:var(--info);">
                                    <?php echo $lifespan_days; ?>d active
                                </span>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span>—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-center">
                        <strong><?php echo number_format($harvested); ?></strong>
                        <div class="text-xs text-muted"><?php echo number_format(floor($harvested / 30)); ?> trays</div>
                        <?php if ($row['harvest_sessions'] > 0): ?>
                        <div class="text-xs text-muted"><?php echo $row['harvest_sessions']; ?> sessions</div>
                        <?php endif; ?>
                    </td>
                    <td class="col-center">
                        <strong style="color:var(--success);"><?php echo number_format($sold_eggs); ?></strong>
                        <div class="text-xs text-muted"><?php echo number_format(floor($sold_eggs / 30)); ?> trays</div>
                        <?php if ($remaining > 0): ?>
                        <div class="text-xs" style="color:var(--warning); margin-top:2px;">
                            <?php echo number_format($remaining); ?> unsold
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="col-center font-bold" style="color:var(--success);">
                        &#8369;<?php echo number_format((float)$row['total_revenue'], 2); ?>
                        <div class="text-xs text-muted font-normal">
                            <?php echo $row['sale_count']; ?> sale<?php echo $row['sale_count'] != 1 ? 's' : ''; ?>
                        </div>
                    </td>
                    <td class="col-center">
                        <div class="font-bold text-sm" style="color:<?php echo $eff_color; ?>;">
                            <?php echo $efficiency; ?>%
                        </div>
                        <div class="mini-bar">
                            <div class="mini-bar__fill" style="width:<?php echo min(100, $efficiency); ?>%; background:<?php echo $eff_color; ?>;"></div>
                        </div>
                        <div class="text-xs text-muted" style="margin-top:2px;">sold/harvested</div>
                    </td>
                    <td class="text-muted text-sm" style="max-width:140px;">
                        <?php echo htmlspecialchars($row['notes'] ?: '—'); ?>
                    </td>
                </tr>
                <?php endwhile;
                else: ?>
                <tr><td colspan="11">
                    <div class="empty-state">
                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8"/>
                        </svg>
                        <p>No retired batches in the archive yet.</p>
                        <small>
                            When a batch is marked as Retired in
                            <a href="../../manage_flocks/batches.php" class="link-gold">Manage Flocks</a>,
                            it will appear here with its full production history.
                        </small>
                    </div>
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($current_page > 1): ?>
                <a href="<?php echo page_url_inv($current_page - 1, $filter_params); ?>"
                   class="btn-farm btn-dark btn-sm">&larr; Prev</a>
            <?php endif; ?>
            <?php
            $start = max(1, $current_page - 2);
            $end   = min($total_pages, $current_page + 2);
            for ($p = $start; $p <= $end; $p++): ?>
                <a href="<?php echo page_url_inv($p, $filter_params); ?>"
                   class="btn-farm btn-sm <?php echo $p === $current_page ? '' : 'btn-dark'; ?>">
                    <?php echo $p; ?>
                </a>
            <?php endfor; ?>
            <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo page_url_inv($current_page + 1, $filter_params); ?>"
                   class="btn-farm btn-dark btn-sm">Next &rarr;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- INFO NOTE -->
    <div class="info-note">
        <strong>Data flow:</strong>
        Active batches live in <strong>inventory.php</strong>.
        Once a batch is <strong>Retired</strong> in Manage Flocks,
        it moves here permanently with its full production and sales record — your permanent audit trail.
    </div>

    <a href="../dashboard.php" class="back-link">&larr; Back to Dashboard</a>
</div>

<style>
.page-container    { max-width:1080px; margin:2rem auto; }
.stat-grid         { display:flex; gap:16px; margin-bottom:1.8rem; flex-wrap:wrap; }
.card__header      { padding:1.2rem 1.6rem; border-bottom:1px solid var(--border-subtle); }
.card__header h3   { margin:0; }
.card__header-count { font-size:0.78rem; font-weight:500; color:var(--text-muted); margin-left:8px; }

/* Filter */
.filter-panel  { padding:1.2rem 1.6rem; margin-bottom:1.5rem; }
.filter-form   { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.filter-actions { padding-bottom:1px; display:flex; gap:8px; }

/* Table helpers */
.col-center   { text-align:center; }
.text-sm      { font-size:0.82rem; }
.text-xs      { font-size:0.72rem; }
.text-muted   { color:var(--text-muted); }
.text-secondary { color:var(--text-secondary); }
.font-bold    { font-weight:700; }
.font-normal  { font-weight:400; }
.link-gold    { color:var(--gold); text-decoration:none; }

/* Mini progress bar */
.mini-bar       { background:var(--bg-wood); border-radius:3px; height:5px; margin-top:4px; min-width:60px; }
.mini-bar__fill { height:5px; border-radius:3px; }

/* Pagination */
.pagination { display:flex; justify-content:center; gap:6px; padding:1.2rem; flex-wrap:wrap; }

/* Info note */
.info-note  { background:var(--info-bg); padding:12px 16px; border-radius:var(--radius-sm);
              font-size:0.82rem; color:#6AABDE; border-left:4px solid var(--info);
              margin-top:1.4rem; }

.empty-icon { width:36px; height:36px; color:var(--text-muted); margin:0 auto 10px; display:block; }
</style>

<?php include('../../includes/footer.php'); ?>