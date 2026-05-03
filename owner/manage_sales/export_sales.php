<?php
/* ============================================================
   owner/export_sales.php — Export Sales Data
   - format=excel  → downloads a proper .xlsx-compatible file
   - format=pdf    → downloads a clean HTML-to-PDF print page
   - Works with filters passed from view_sales.php / sales_report.php
   ============================================================ */

session_start();
include('../../includes/db.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header("Location: ../../portal/login.php");
    exit();
}

// --- PARAMS ---
$allowed_methods = ['all', 'Cash', 'GCash', 'Bank Transfer'];
$filter_method   = (isset($_GET['method']) && in_array($_GET['method'], $allowed_methods))
                   ? $_GET['method'] : 'all';
$date_from = !empty($_GET['from']) ? date('Y-m-d', strtotime($_GET['from'])) : date('Y-m-01');
$date_to   = !empty($_GET['to'])   ? date('Y-m-d', strtotime($_GET['to']))   : date('Y-m-d');
$format    = (isset($_GET['format']) && $_GET['format'] === 'pdf') ? 'pdf' : 'excel';
$is_report = isset($_GET['report']); // coming from sales_report page

// Build WHERE
$where_parts = ["DATE(s.date_sold) BETWEEN '$date_from' AND '$date_to'"];
if ($filter_method !== 'all') {
    $safe_method   = $conn->real_escape_string($filter_method);
    $where_parts[] = "s.payment_method = '$safe_method'";
}
$where = 'WHERE ' . implode(' AND ', $where_parts);

// Fetch data
$sales_q = $conn->query("
    SELECT s.sale_id, s.date_sold, u.username AS staff_name,
           s.customer_name, s.quantity_sold, s.unit_price,
           s.total_amount, s.payment_method, s.notes
    FROM sales s
    LEFT JOIN users u ON s.staff_id = u.user_id
    $where
    ORDER BY s.date_sold DESC
");

// Summary
$stats_q = $conn->query("
    SELECT
        COUNT(*)                    AS total_sales,
        COALESCE(SUM(total_amount), 0) AS total_revenue,
        COALESCE(SUM(quantity_sold), 0) AS total_trays
    FROM sales s $where
");
$stats = $stats_q ? $stats_q->fetch_assoc() : ['total_sales'=>0,'total_revenue'=>0,'total_trays'=>0];

$filename_base = 'sales_' . $date_from . '_to_' . $date_to;

/* ===========================================================
   EXCEL EXPORT — outputs a tab-separated .xls file
   (Excel opens these natively; no library required)
   =========================================================== */
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.xls"');
    header('Cache-Control: max-age=0');

    // BOM for Excel UTF-8
    echo "\xEF\xBB\xBF";

    // Title block
    echo "Egg Ledger System — Sales Export\t\t\t\t\t\t\t\t\n";
    echo "Period:\t" . date('M d, Y', strtotime($date_from)) . " to " . date('M d, Y', strtotime($date_to)) . "\t\t\t\t\t\t\t\n";
    echo "Payment Filter:\t" . ($filter_method === 'all' ? 'All Methods' : $filter_method) . "\t\t\t\t\t\t\t\n";
    echo "Generated:\t" . date('M d, Y g:i A') . "\t\t\t\t\t\t\t\n";
    echo "\n";

    // Summary block
    echo "SUMMARY\t\t\t\t\t\t\t\t\n";
    echo "Total Transactions:\t" . number_format((int)$stats['total_sales']) . "\t\t\t\t\t\t\t\n";
    echo "Total Trays Sold:\t" . number_format((int)$stats['total_trays']) . "\t\t\t\t\t\t\t\n";
    echo "Total Revenue:\t" . number_format((float)$stats['total_revenue'], 2) . "\t\t\t\t\t\t\t\n";
    echo "\n";

    // Column headers
    $headers = ['Sale ID', 'Date', 'Time', 'Staff', 'Customer', 'Trays', 'Unit Price (₱)', 'Total (₱)', 'Payment', 'Notes'];
    echo implode("\t", $headers) . "\n";

    // Data rows
    if ($sales_q && $sales_q->num_rows > 0) {
        while ($row = $sales_q->fetch_assoc()) {
            $cols = [
                '#' . $row['sale_id'],
                date('Y-m-d', strtotime($row['date_sold'])),
                date('g:i A',  strtotime($row['date_sold'])),
                $row['staff_name']    ?? '—',
                $row['customer_name'],
                $row['quantity_sold'],
                number_format((float)$row['unit_price'],   2),
                number_format((float)$row['total_amount'], 2),
                $row['payment_method'],
                $row['notes'] ?? '',
            ];
            // Escape tabs inside cell values
            echo implode("\t", array_map(fn($c) => str_replace("\t", ' ', $c), $cols)) . "\n";
        }
    }

    // Footer totals
    echo "\n";
    echo "TOTAL\t\t\t\t\t" . number_format((int)$stats['total_trays']) . "\t\t" . number_format((float)$stats['total_revenue'], 2) . "\t\t\n";
    exit();
}

/* ===========================================================
   PDF EXPORT — full-page print-optimised HTML
   User clicks the page and uses Ctrl+P / browser print-to-PDF
   We trigger the print dialog automatically via JS.
   =========================================================== */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Report — <?php echo $date_from; ?> to <?php echo $date_to; ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #1a1a1a; background:#fff; }

        .print-header { padding: 20px 28px 14px; border-bottom: 2px solid #c4850a; display:flex; justify-content:space-between; align-items:flex-start; }
        .print-header h1 { font-size: 18px; color: #c4850a; font-weight: 800; }
        .print-header .meta { font-size: 10px; color: #666; line-height: 1.7; text-align:right; }

        .summary-row { display: flex; gap: 0; border-bottom: 1px solid #e0d0b0; }
        .summary-box { flex:1; padding: 12px 20px; border-right: 1px solid #e0d0b0; }
        .summary-box:last-child { border-right:none; }
        .summary-box .s-label { font-size: 9px; text-transform:uppercase; letter-spacing:0.6px; color: #888; font-weight:700; }
        .summary-box .s-value { font-size: 16px; font-weight: 800; color: #1a1a1a; margin-top:3px; }

        table { width:100%; border-collapse:collapse; margin: 0; }
        thead tr { background: #f5ead0; }
        thead th { padding: 8px 10px; text-align:left; font-size:9px; text-transform:uppercase; letter-spacing:0.7px; color:#6b4c1a; font-weight:700; border-bottom:2px solid #c4850a; white-space:nowrap; }
        tbody tr { border-bottom: 1px solid #f0e8d8; }
        tbody tr:nth-child(even) { background: #fdf9f0; }
        tbody td { padding: 7px 10px; vertical-align:middle; }
        tfoot tr { background: #f5ead0; border-top: 2px solid #c4850a; }
        tfoot td { padding: 8px 10px; font-weight:700; font-size:10px; color:#6b4c1a; }

        .table-wrap { padding: 0 0 16px; }
        .amount { font-weight:700; color:#2a7a40; }
        .total-amount { font-weight:800; font-size:12px; color:#c4850a; }

        .print-footer { text-align:center; padding: 14px; border-top: 1px solid #e0d0b0; font-size:9px; color:#aaa; }
        .no-print { position:fixed; bottom:24px; right:24px; display:flex; gap:10px; }
        .no-print button {
            padding: 10px 20px; background: #c4850a; color:#fff; border:none;
            border-radius:6px; font-size:13px; font-weight:700; cursor:pointer;
        }
        .no-print button.close-btn { background:#555; }

        @media print {
            .no-print { display: none !important; }
            body { font-size: 10px; }
            @page { margin: 1.5cm; size: A4 landscape; }
        }
    </style>
</head>
<body>

<!-- Print Controls (hidden on print) -->
<div class="no-print">
    <button onclick="window.print()">🖨️ Print / Save PDF</button>
    <button class="close-btn" onclick="window.location.href='sales_history.php'">✕ Close</button>
</div>

<!-- Header -->
<div class="print-header">
    <div>
        <h1>🥚 Egg Ledger System</h1>
        <div style="font-size:13px; font-weight:600; color:#555; margin-top:4px;">Sales Report</div>
    </div>
    <div class="meta">
        Period: <strong><?php echo date('M d, Y', strtotime($date_from)); ?> – <?php echo date('M d, Y', strtotime($date_to)); ?></strong><br>
        Payment: <?php echo htmlspecialchars($filter_method === 'all' ? 'All Methods' : $filter_method); ?><br>
        Generated: <?php echo date('M d, Y g:i A'); ?><br>
        Exported by: <?php echo htmlspecialchars($_SESSION['username']); ?> (Owner)
    </div>
</div>

<!-- Summary -->
<div class="summary-row">
    <div class="summary-box">
        <div class="s-label">Total Transactions</div>
        <div class="s-value"><?php echo number_format((int)$stats['total_sales']); ?></div>
    </div>
    <div class="summary-box">
        <div class="s-label">Trays Sold</div>
        <div class="s-value"><?php echo number_format((int)$stats['total_trays']); ?></div>
    </div>
    <div class="summary-box">
        <div class="s-label">Total Revenue</div>
        <div class="s-value">₱<?php echo number_format((float)$stats['total_revenue'], 2); ?></div>
    </div>
    <div class="summary-box">
        <div class="s-label">Avg. per Sale</div>
        <div class="s-value">
            ₱<?php echo (int)$stats['total_sales'] > 0
                ? number_format((float)$stats['total_revenue'] / (int)$stats['total_sales'], 2)
                : '0.00'; ?>
        </div>
    </div>
</div>

<!-- Table -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Staff</th>
                <th>Customer</th>
                <th>Trays</th>
                <th>Unit Price</th>
                <th>Total Amount</th>
                <th>Payment</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Re-run query since pointer is exhausted
            $sales_q2 = $conn->query("
                SELECT s.sale_id, s.date_sold, u.username AS staff_name,
                       s.customer_name, s.quantity_sold, s.unit_price,
                       s.total_amount, s.payment_method, s.notes
                FROM sales s
                LEFT JOIN users u ON s.staff_id = u.user_id
                $where
                ORDER BY s.date_sold DESC
            ");
            if ($sales_q2 && $sales_q2->num_rows > 0):
                while ($row = $sales_q2->fetch_assoc()): ?>
            <tr>
                <td style="color:#aaa;"><?php echo $row['sale_id']; ?></td>
                <td style="white-space:nowrap;">
                    <?php echo date('M d, Y', strtotime($row['date_sold'])); ?><br>
                    <span style="color:#aaa; font-size:9px;"><?php echo date('g:i A', strtotime($row['date_sold'])); ?></span>
                </td>
                <td><?php echo htmlspecialchars($row['staff_name'] ?? '—'); ?></td>
                <td><strong><?php echo htmlspecialchars($row['customer_name']); ?></strong></td>
                <td style="text-align:center; font-weight:700;"><?php echo number_format($row['quantity_sold']); ?></td>
                <td>₱<?php echo number_format((float)$row['unit_price'], 2); ?></td>
                <td class="amount">₱<?php echo number_format((float)$row['total_amount'], 2); ?></td>
                <td><?php echo htmlspecialchars($row['payment_method']); ?></td>
                <td style="color:#888;"><?php echo htmlspecialchars($row['notes'] ?: '—'); ?></td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="9" style="text-align:center; padding:30px; color:#aaa;">No sales records found for this period.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align:right;">TOTAL</td>
                <td style="text-align:center;"><?php echo number_format((int)$stats['total_trays']); ?></td>
                <td></td>
                <td class="total-amount">₱<?php echo number_format((float)$stats['total_revenue'], 2); ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
</div>

<div class="print-footer">
    Egg Ledger System &mdash; Generated on <?php echo date('F j, Y \a\t g:i A'); ?> &mdash; Confidential Farm Records
</div>

<script>
    // Auto-open print dialog after page loads
    window.addEventListener('load', function () {
        setTimeout(() => window.print(), 600);
    });
</script>
</body>
</html>