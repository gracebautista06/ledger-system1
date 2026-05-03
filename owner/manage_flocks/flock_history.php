<?php
/* ============================================================
   owner/manage_flocks/flock_history.php
   — Retired Flock Batch Archive

   LAYER:  🟡 HISTORY / ARCHIVE (Layer 3)
   PURPOSE: Permanent record of all flock batches that have
            been retired, replaced, or sold off.

   WHAT THIS SHOWS:
   - Every batch ever added, grouped by status (Retired / Active)
   - Lifespan: date acquired → last known harvest date
   - Total eggs they produced over their lifetime
   - How many birds were in the batch
   - Expected vs actual replacement date
   - Notes and any important flags

   DATA SOURCE:
   - batches table (all statuses)
   - harvests table (production summary per batch)

   FLOW:
   batches.php (manage/add flocks)  ← Active management
        ↓ (batch marked Retired)
   flock_history.php  ← YOU ARE HERE (permanent archive)
   ============================================================ */

$page_title = 'Flock History';

session_start();
include('../../includes/db.php');
include('../../includes/header.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header("Location: ../../portal/login.php");
    exit();
}

// ── FILTERS ──────────────────────────────────────────────────────
$allowed_statuses = ['all', 'Retired', 'Active'];
$filter_status    = in_array($_GET['status'] ?? '', $allowed_statuses) ? $_GET['status'] : 'all';
$filter_breed     = trim($_GET['breed'] ?? '');
$search           = trim($_GET['q'] ?? '');

// ── PAGINATION ────────────────────────────────────────────────────
$per_page     = 25;
$current_page = max(1, intval($_GET['page'] ?? 1));
$offset       = ($current_page - 1) * $per_page;

// ── FETCH BREEDS FOR DROPDOWN ─────────────────────────────────────
$breeds_q = $conn->query("SELECT DISTINCT breed FROM batches ORDER BY breed ASC");
$breeds   = [];
if ($breeds_q) while ($r = $breeds_q->fetch_assoc()) $breeds[] = $r['breed'];

// ── BUILD WHERE ───────────────────────────────────────────────────
// Flock history shows ALL batches — both Retired (archive) and Active (context)
// Default: show Retired only so it feels like a proper archive
$conditions = [];
if ($filter_status !== 'all') {
    $safe_status   = $conn->real_escape_string($filter_status);
    $conditions[]  = "b.status = '$safe_status'";
} else {
    // Default: only Retired unless user explicitly picks "All" or "Active"
    $conditions[] = "b.status IN ('Retired', 'Active')";
}
if ($filter_breed !== '') {
    $safe_breed  = $conn->real_escape_string($filter_breed);
    $conditions[] = "b.breed = '$safe_breed'";
}
if ($search !== '') {
    $safe_search  = $conn->real_escape_string($search);
    $conditions[] = "(b.breed LIKE '%$safe_search%' OR b.notes LIKE '%$safe_search%')";
}
$where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

// ── SUMMARY STATS ─────────────────────────────────────────────────
$stats_q = $conn->query("
    SELECT
        COUNT(b.batch_id)                       AS total_batches,
        SUM(CASE WHEN b.status='Retired' THEN 1 ELSE 0 END) AS retired_count,
        SUM(CASE WHEN b.status='Active'  THEN 1 ELSE 0 END) AS active_count,
        COALESCE(SUM(COALESCE(b.initial_count, b.quantity, 0)), 0) AS total_birds
    FROM batches b
");
$stats = $stats_q ? $stats_q->fetch_assoc() : ['total_batches'=>0,'retired_count'=>0,'active_count'=>0,'total_birds'=>0];

// Total lifetime eggs from all batches (all time)
$lifetime_q = $conn->query("SELECT COALESCE(SUM(total_eggs), 0) AS grand_total FROM harvests");
$lifetime_eggs = $lifetime_q ? (int)$lifetime_q->fetch_assoc()['grand_total'] : 0;

// ── COUNT FOR PAGINATION ──────────────────────────────────────────
$count_q    = $conn->query("SELECT COUNT(*) AS c FROM batches b $where");
$total_rows = $count_q ? (int)$count_q->fetch_assoc()['c'] : 0;
$total_pages = max(1, ceil($total_rows / $per_page));

// ── MAIN QUERY ───────────────────────────────────────────────────
$flock_q = $conn->query("
    SELECT
        b.batch_id,
        b.breed,
        b.status,
        COALESCE(b.arrival_date, b.date_acquired)  AS arrival_date,
        b.expected_replacement,
        b.notes,
        COALESCE(b.initial_count, b.quantity, 0)   AS bird_count,

        COALESCE(h_agg.total_eggs,       0)  AS lifetime_eggs,
        COALESCE(h_agg.session_count,    0)  AS session_count,
        h_agg.first_harvest_date             AS first_harvest,
        h_agg.last_harvest_date              AS last_harvest,
        COALESCE(h_agg.size_pw,          0)  AS size_pw,
        COALESCE(h_agg.size_s,           0)  AS size_s,
        COALESCE(h_agg.size_m,           0)  AS size_m,
        COALESCE(h_agg.size_l,           0)  AS size_l,
        COALESCE(h_agg.size_xl,          0)  AS size_xl,
        COALESCE(h_agg.size_j,           0)  AS size_j

    FROM batches b

    LEFT JOIN (
        SELECT
            batch_id,
            SUM(total_eggs)   AS total_eggs,
            COUNT(*)          AS session_count,
            MIN(date_logged)  AS first_harvest_date,
            MAX(date_logged)  AS last_harvest_date,
            SUM(size_pw)      AS size_pw,
            SUM(size_s)       AS size_s,
            SUM(size_m)       AS size_m,
            SUM(size_l)       AS size_l,
            SUM(size_xl)      AS size_xl,
            SUM(size_j)       AS size_j
        FROM harvests
        GROUP BY batch_id
    ) h_agg ON h_agg.batch_id = b.batch_id

    $where
    ORDER BY FIELD(b.status,'Retired','Active'), b.batch_id DESC
    LIMIT $per_page OFFSET $offset
");

function flock_page_url($p, $params) {
    $params['page'] = $p;
    return '?' . http_build_query($params);
}
$filter_params = ['status'=>$filter_status, 'breed'=>$filter_breed, 'q'=>$search];

$size_labels = ['size_pw'=>'PW', 'size_s'=>'S', 'size_m'=>'M', 'size_l'=>'L', 'size_xl'=>'XL', 'size_j'=>'J'];
?>

<div style="max-width:1080px; margin:2rem auto;">

    <div class="page-header">
        <div>
            <h2>   Flock History</h2>
            <p>Lifetime archive of all flock batches — production records &amp; lifecycle data.</p>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <a href="batches.php"        class="btn-farm btn-dark btn-sm"> Active Batches</a>
            <a href="../dashboard.php"   class="back-link" style="margin:0;">← Dashboard</a>
        </div>
    </div>

    <!-- ── SUMMARY CARDS ────────────────────────────────────────── -->
    <div style="display:flex; gap:16px; margin-bottom:1.8rem; flex-wrap:wrap;">
        <div class="stat-card" style="border-top:4px solid var(--gold);">
            <div class="stat-label">Total Batches (All Time)</div>
            <div class="stat-value"><?php echo number_format((int)$stats['total_batches']); ?></div>
            <div class="stat-sub">
                <?php echo number_format((int)$stats['active_count']); ?> active ·
                <?php echo number_format((int)$stats['retired_count']); ?> retired
            </div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--terra-lt);">
            <div class="stat-label">Total Birds (All Batches)</div>
            <div class="stat-value"><?php echo number_format((int)$stats['total_birds']); ?></div>
            <div class="stat-sub">across all recorded batches</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--success);">
            <div class="stat-label">Lifetime Eggs Produced</div>
            <div class="stat-value"><?php echo number_format($lifetime_eggs); ?></div>
            <div class="stat-sub"><?php echo number_format(floor($lifetime_eggs/30)); ?> trays total (all time)</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--danger);">
            <div class="stat-label">Retired Batches</div>
            <div class="stat-value"><?php echo number_format((int)$stats['retired_count']); ?></div>
            <div class="stat-sub">completed production cycles</div>
        </div>
    </div>

    <!-- ── FILTER PANEL ─────────────────────────────────────────── -->
    <div class="card" style="padding:1.2rem 1.6rem; margin-bottom:1.5rem;">
        <form method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">

            <div class="form-group" style="margin:0; min-width:140px;">
                <label>Status</label>
                <select name="status" class="form-input">
                    <option value="all"     <?php echo $filter_status==='all'     ? 'selected' : ''; ?>>All</option>
                    <option value="Retired" <?php echo $filter_status==='Retired' ? 'selected' : ''; ?>>🗃️ Retired</option>
                    <option value="Active"  <?php echo $filter_status==='Active'  ? 'selected' : ''; ?>>✅ Active</option>
                </select>
            </div>
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
            <div class="form-group" style="margin:0; flex:2; min-width:180px;">
                <label>Search</label>
                <input type="text" name="q" class="form-input" placeholder="Search breed or notes…"
                       value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div style="padding-bottom:1px; display:flex; gap:8px;">
                <button type="submit" class="btn-farm btn-sm">🔍 Filter</button>
                <a href="flock_history.php" class="btn-farm btn-dark btn-sm">✕ Reset</a>
            </div>
        </form>
    </div>

    <!-- ── FLOCK HISTORY TABLE ───────────────────────────────────── -->
    <div class="card" style="padding:0; overflow:hidden;">
        <div style="padding:1.2rem 1.6rem; border-bottom:1px solid var(--border-subtle);
                    display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
            <h3 style="margin:0;">
                📋 Batch Records
                <span style="font-size:0.78rem; font-weight:500; color:var(--text-muted); margin-left:8px;">
                    <?php echo number_format($total_rows); ?> batch<?php echo $total_rows !== 1 ? 'es' : ''; ?> found
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
                        <th>Replacement Due</th>
                        <th>Active Period</th>
                        <th>Sessions</th>
                        <th>Lifetime Eggs</th>
                        <th>Size Breakdown</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($flock_q && $flock_q->num_rows > 0):
                    while ($row = $flock_q->fetch_assoc()):
                        $arrival      = $row['arrival_date'];
                        $repl_date    = $row['expected_replacement'];
                        $first_h      = $row['first_harvest'];
                        $last_h       = $row['last_harvest'];
                        $eggs         = (int)$row['lifetime_eggs'];
                        $is_retired   = $row['status'] === 'Retired';

                        // Lifespan
                        $lifespan_days = null;
                        if ($arrival) {
                            $end_ref = ($last_h && $is_retired) ? $last_h : ($is_retired ? $arrival : null);
                            if ($end_ref) {
                                $lifespan_days = (int)ceil((strtotime($end_ref) - strtotime($arrival)) / 86400);
                            }
                        }

                        // Size breakdown string
                        $size_parts = [];
                        foreach ($size_labels as $col => $code) {
                            $val = (int)$row[$col];
                            if ($val > 0) {
                                $size_parts[] = "<span style='color:var(--text-primary);font-weight:700;'>$code</span>:"
                                              . number_format(floor($val/30)) . "T";
                            }
                        }
                ?>
                <tr style="<?php echo $is_retired ? 'opacity:0.88;' : ''; ?>">
                    <td style="color:var(--text-muted); font-size:0.8rem; font-weight:700;">
                        #<?php echo $row['batch_id']; ?>
                    </td>
                    <td>
                        <strong style="color:var(--text-primary);">
                            <?php echo htmlspecialchars($row['breed']); ?>
                        </strong>
                    </td>
                    <td style="color:var(--text-secondary); font-size:0.85rem;">
                        <?php echo number_format((int)$row['bird_count']); ?>
                    </td>
                    <td>
                        <span class="badge <?php echo $is_retired ? 'badge-rejected' : 'badge-healthy'; ?>">
                            <?php echo htmlspecialchars($row['status']); ?>
                        </span>
                    </td>
                    <td style="font-size:0.8rem; color:var(--text-muted); white-space:nowrap;">
                        <?php echo $arrival ? date('M d, Y', strtotime($arrival)) : '—'; ?>
                    </td>
                    <td style="font-size:0.8rem; white-space:nowrap;">
                        <?php if ($repl_date): ?>
                            <div style="color:var(--text-secondary);">
                                <?php echo date('M d, Y', strtotime($repl_date)); ?>
                            </div>
                            <?php if ($is_retired): ?>
                            <div style="font-size:0.7rem; color:var(--text-muted); margin-top:2px;">
                                (retired before due)
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.78rem; color:var(--text-muted);">
                        <?php if ($first_h): ?>
                            <div><?php echo date('M d, Y', strtotime($first_h)); ?></div>
                            <?php if ($last_h && $first_h !== $last_h): ?>
                            <div style="color:var(--text-muted); font-size:0.72rem;">
                                → <?php echo date('M d, Y', strtotime($last_h)); ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($lifespan_days !== null): ?>
                            <div style="margin-top:3px;">
                                <span style="font-size:0.7rem; font-weight:700; color:var(--info);">
                                    <?php echo $lifespan_days; ?>d active
                                </span>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">No harvests logged</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center; color:var(--text-muted); font-size:0.85rem;">
                        <?php echo $row['session_count'] > 0 ? number_format((int)$row['session_count']) : '—'; ?>
                    </td>
                    <td style="text-align:center;">
                        <?php if ($eggs > 0): ?>
                            <strong style="color:var(--gold);">
                                <?php echo number_format($eggs); ?>
                            </strong>
                            <div style="font-size:0.72rem; color:var(--text-muted);">
                                <?php echo number_format(floor($eggs/30)); ?> trays
                            </div>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-family:monospace; font-size:0.72rem; color:var(--text-muted); white-space:nowrap; line-height:1.9;">
                        <?php echo !empty($size_parts)
                            ? implode(' &nbsp;', $size_parts)
                            : '<span style="color:var(--text-muted)">—</span>'; ?>
                    </td>
                    <td style="font-size:0.8rem; color:var(--text-muted); max-width:140px;">
                        <?php echo htmlspecialchars($row['notes'] ?: '—'); ?>
                    </td>
                </tr>
                <?php endwhile;
                else: ?>
                <tr><td colspan="11">
                    <div class="empty-state">
                        <span class="empty-icon">🐔</span>
                        <p>No flock records found.</p>
                        <small>
                            Retire a batch in
                            <a href="batches.php" style="color:var(--gold);">Manage Batches</a>
                            and it will appear here as a permanent record.
                        </small>
                    </div>
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
        <div style="display:flex; justify-content:center; gap:6px; padding:1.2rem; flex-wrap:wrap;">
            <?php if ($current_page > 1): ?>
                <a href="<?php echo flock_page_url($current_page-1, $filter_params); ?>"
                   class="btn-farm btn-dark btn-sm">← Prev</a>
            <?php endif; ?>
            <?php
            $start = max(1, $current_page - 2);
            $end   = min($total_pages, $current_page + 2);
            for ($p = $start; $p <= $end; $p++): ?>
                <a href="<?php echo flock_page_url($p, $filter_params); ?>"
                   class="btn-farm btn-sm <?php echo $p === $current_page ? '' : 'btn-dark'; ?>">
                    <?php echo $p; ?>
                </a>
            <?php endfor; ?>
            <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo flock_page_url($current_page+1, $filter_params); ?>"
                   class="btn-farm btn-dark btn-sm">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- INFO NOTE -->
    <div style="background:var(--info-bg); padding:12px 16px; border-radius:var(--radius-sm);
                font-size:0.82rem; color:#6AABDE; border-left:4px solid var(--info);
                margin-top:1.4rem;">
        💡 <strong>How data flows:</strong>
        Batches are managed in <strong>batches.php</strong> (Active layer).
        Once a batch is <strong>Retired</strong>, it stays visible here forever
        with its full production record — harvest sessions, egg counts, size breakdowns.
        This is your permanent flock performance log.
    </div>

    <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
</div>

<?php include('../../includes/footer.php'); ?>