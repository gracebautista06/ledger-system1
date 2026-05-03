<?php
$page_title = 'Sales History';

session_start();
include('../../includes/db.php');
include('../../includes/header.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header('Location: ../../portal/login.php');
    exit();
}

$allowed_methods = ['all', 'Cash', 'GCash', 'Bank Transfer'];
$filter_method   = in_array($_GET['method'] ?? '', $allowed_methods, true) ? $_GET['method'] : 'all';
$date_from       = date('Y-m-d', strtotime(!empty($_GET['from']) ? $_GET['from'] : date('Y-m-01')));
$date_to         = date('Y-m-d', strtotime(!empty($_GET['to'])   ? $_GET['to']   : date('Y-m-d')));

$conditions = ["DATE(s.date_sold) BETWEEN ? AND ?"];
$bind_types = 'ss';
$bind_vals  = [$date_from, $date_to];

if ($filter_method !== 'all') {
    $conditions[] = 's.payment_method = ?';
    $bind_types  .= 's';
    $bind_vals[]  = $filter_method;
}

$where = 'WHERE ' . implode(' AND ', $conditions);

function bind_query(mysqli $conn, string $sql, string $types, array $vals): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if ($types) {
        $stmt->bind_param($types, ...$vals);
    }
    $stmt->execute();
    return $stmt;
}

$stats_stmt = bind_query($conn, "
    SELECT
        COUNT(*)                               AS total_transactions,
        COALESCE(SUM(s.quantity_sold * 30), 0) AS total_eggs_sold,
        COALESCE(SUM(s.total_amount), 0)       AS total_revenue,
        COALESCE(AVG(s.total_amount), 0)       AS avg_sale,
        COALESCE(SUM(s.quantity_sold), 0)      AS total_trays
    FROM sales s $where
", $bind_types, $bind_vals);

$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

$per_page    = 20;
$page        = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($page - 1) * $per_page;

$count_stmt  = bind_query($conn, "SELECT COUNT(*) AS total FROM sales s $where", $bind_types, $bind_vals);
$total_rows  = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$page_types  = $bind_types . 'ii';
$page_vals   = array_merge($bind_vals, [$per_page, $offset]);

$sales_stmt = bind_query($conn, "
    SELECT s.*, u.username AS staff_name,
           COALESCE(s.qty_pw, 0) AS qty_pw, COALESCE(s.qty_s, 0) AS qty_s,
           COALESCE(s.qty_m, 0)  AS qty_m,  COALESCE(s.qty_l, 0) AS qty_l,
           COALESCE(s.qty_xl, 0) AS qty_xl, COALESCE(s.qty_j, 0) AS qty_j
    FROM sales s
    LEFT JOIN users u ON s.staff_id = u.user_id
    $where
    ORDER BY s.date_sold DESC
    LIMIT ? OFFSET ?
", $page_types, $page_vals);

$sales = $sales_stmt->get_result();

function pagination_url(int $p, string $from, string $to, string $method): string
{
    return '?page=' . $p . '&from=' . urlencode($from) . '&to=' . urlencode($to) . '&method=' . urlencode($method);
}
?>

<div style="max-width:1100px; margin:2rem auto;">

    <div class="page-header">
        <div>
            <h2>Sales History</h2>
            <p>Filter by date range and payment method.</p>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <a href="export_sales.php?from=<?php echo urlencode($date_from); ?>&to=<?php echo urlencode($date_to); ?>&method=<?php echo urlencode($filter_method); ?>&format=excel"
               class="btn-farm btn-green btn-sm">Export Excel</a>
            <a href="export_sales.php?from=<?php echo urlencode($date_from); ?>&to=<?php echo urlencode($date_to); ?>&method=<?php echo urlencode($filter_method); ?>&format=pdf"
               class="btn-farm btn-danger btn-sm">Export PDF</a>
             <a href="view_sales.php" class="btn-farm btn-dark btn-sm">Sales Records</a>
            <a href="../dashboard.php" class="back-link" style="margin:0;">← Dashboard</a>
        </div>
    </div>

    <div class="card" style="margin-bottom:1.5rem; padding:1.4rem 1.8rem;">
        <form method="GET" style="display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end;">
            <div class="form-group" style="margin:0; flex:1; min-width:140px;">
                <label>From</label>
                <input type="date" name="from" class="form-input" value="<?php echo $date_from; ?>">
            </div>
            <div class="form-group" style="margin:0; flex:1; min-width:140px;">
                <label>To</label>
                <input type="date" name="to" class="form-input" value="<?php echo $date_to; ?>">
            </div>
            <div class="form-group" style="margin:0; flex:1; min-width:160px;">
                <label>Payment Method</label>
                <select name="method" class="form-input">
                    <?php foreach (['all' => 'All Methods', 'Cash' => 'Cash', 'GCash' => 'GCash', 'Bank Transfer' => 'Bank Transfer'] as $val => $label): ?>
                        <option value="<?php echo $val; ?>" <?php echo $filter_method === $val ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="padding-bottom:1px; display:flex; gap:6px;">
                <button type="submit" class="btn-farm btn-sm">Filter</button>
                <a href="sales_history.php" class="btn-farm btn-dark btn-sm">Reset</a>
            </div>
        </form>
    </div>

    <div style="display:flex; gap:16px; margin-bottom:1.8rem; flex-wrap:wrap;">
        <div class="stat-card" style="border-top:4px solid var(--success);">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value">&#8369;<?php echo number_format((float)$stats['total_revenue'], 2); ?></div>
            <div class="stat-sub"><?php echo date('M d', strtotime($date_from)); ?> &ndash; <?php echo date('M d', strtotime($date_to)); ?></div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--gold);">
            <div class="stat-label">Transactions</div>
            <div class="stat-value"><?php echo number_format((int)$stats['total_transactions']); ?></div>
            <div class="stat-sub">sales recorded</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--terra-lt);">
            <div class="stat-label">Trays Sold</div>
            <div class="stat-value"><?php echo number_format((int)$stats['total_trays']); ?></div>
            <div class="stat-sub"><?php echo number_format((int)$stats['total_eggs_sold']); ?> eggs total</div>
        </div>
        <div class="stat-card" style="border-top:4px solid var(--info);">
            <div class="stat-label">Avg. Per Sale</div>
            <div class="stat-value">&#8369;<?php echo number_format((float)$stats['avg_sale'], 2); ?></div>
            <div class="stat-sub">average transaction</div>
        </div>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
        <div style="padding:1.4rem 1.8rem 1rem; border-bottom:1px solid var(--border-subtle);">
            <h3 style="margin:0;">
                Transaction Log
                <span style="font-size:0.78rem; font-weight:500; color:var(--text-muted); margin-left:10px;">
                    <?php echo number_format($total_rows); ?> record<?php echo $total_rows !== 1 ? 's' : ''; ?>
                </span>
            </h3>
        </div>
        <div class="table-wrapper" style="border:none; border-radius:0;">
            <table class="table-farm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date &amp; Time</th>
                        <th>Staff</th>
                        <th>Customer</th>
                        <th>Trays</th>
                        <th>Size Breakdown</th>
                        <th>Unit Price</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sales && $sales->num_rows > 0):
                        while ($row = $sales->fetch_assoc()):
                            $method_icon = match($row['payment_method']) {
                                'GCash'         => 'GCash',
                                'Bank Transfer' => 'Bank',
                                default         => 'Cash',
                            };
                    ?>
                    <tr>
                        <td style="color:var(--text-muted); font-size:0.78rem;">#<?php echo $row['sale_id']; ?></td>
                        <td style="font-size:0.8rem; color:var(--text-muted); white-space:nowrap;">
                            <?php echo date('M d, Y', strtotime($row['date_sold'])); ?><br>
                            <span style="font-size:0.73rem;"><?php echo date('g:i A', strtotime($row['date_sold'])); ?></span>
                        </td>
                        <td style="font-size:0.84rem;"><?php echo htmlspecialchars($row['staff_name'] ?? '—'); ?></td>
                        <td><strong><?php echo htmlspecialchars($row['customer_name']); ?></strong></td>
                        <td style="text-align:center; font-weight:700;"><?php echo number_format($row['quantity_sold']); ?></td>
                        <td style="font-family:monospace; font-size:0.75rem; color:var(--text-muted); white-space:nowrap; line-height:1.9;">
                            <?php
                            $breakdown = [];
                            $sizes = ['PW' => $row['qty_pw'], 'S' => $row['qty_s'], 'M' => $row['qty_m'],
                                      'L'  => $row['qty_l'],  'XL'=> $row['qty_xl'],'J' => $row['qty_j']];
                            foreach ($sizes as $sz => $qty) {
                                if ($qty > 0) {
                                    $breakdown[] = "<span style='color:var(--text-primary);font-weight:700;'>$sz</span>&times;$qty";
                                }
                            }
                            echo !empty($breakdown) ? implode(' &nbsp;', $breakdown) : '<span style="color:var(--text-muted)">—</span>';
                            ?>
                        </td>
                        <td>&#8369;<?php echo number_format((float)$row['unit_price'], 2); ?></td>
                        <td style="font-weight:700; color:var(--success);">
                            &#8369;<?php echo number_format((float)$row['total_amount'], 2); ?>
                        </td>
                        <td style="font-size:0.82rem;"><?php echo htmlspecialchars($row['payment_method']); ?></td>
                        <td style="font-size:0.8rem; color:var(--text-muted); max-width:160px;">
                            <?php echo htmlspecialchars($row['notes'] ?: '—'); ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="10">
                        <div class="empty-state">
                            <p>No sales found for this period.</p>
                            <small>Try adjusting your date range or filters.</small>
                        </div>
                    </td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($total_rows > 0): ?>
                <tfoot>
                    <tr>
                        <td colspan="7" style="text-align:right; font-size:0.8rem;">Period Total</td>
                        <td style="color:var(--gold);">&#8369;<?php echo number_format((float)$stats['total_revenue'], 2); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
        <div style="display:flex; justify-content:center; gap:6px; padding:1.2rem; flex-wrap:wrap;">
            <?php if ($page > 1): ?>
                <a href="<?php echo pagination_url($page - 1, $date_from, $date_to, $filter_method); ?>"
                   class="btn-farm btn-dark btn-sm">&larr; Prev</a>
            <?php endif; ?>
            <?php for ($p = max(1, $page - 2); $p <= min($total_pages, $page + 2); $p++): ?>
                <a href="<?php echo pagination_url($p, $date_from, $date_to, $filter_method); ?>"
                   class="btn-farm btn-sm <?php echo $p === $page ? '' : 'btn-dark'; ?>">
                    <?php echo $p; ?>
                </a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
                <a href="<?php echo pagination_url($page + 1, $date_from, $date_to, $filter_method); ?>"
                   class="btn-farm btn-dark btn-sm">Next &rarr;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <a href="../dashboard.php" class="back-link">← Back to Dashboard</a>
        
</div>

<?php include('../../includes/footer.php'); ?>