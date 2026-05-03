<?php
/* ============================================================
   owner/export_history.php — Export Activity Log as CSV
   NEW FILE: Added as a companion to view_history.php
   ============================================================ */

session_start();
include('../../includes/db.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header("Location: ../../portal/login.php");
    exit();
}

$allowed_filters = ['all', 'Staff', 'Owner'];
$filter = (isset($_GET['role']) && in_array($_GET['role'], $allowed_filters))
          ? $_GET['role'] : 'all';

$where = ($filter !== 'all')
    ? "WHERE al.user_role = '" . $conn->real_escape_string($filter) . "'"
    : "";

$logs = $conn->query("
    SELECT al.timestamp, u.username, al.user_role, al.action_type, al.description
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    $where
    ORDER BY al.timestamp DESC
    LIMIT 5000
");

$filename = 'activity_log_' . date('Y-m-d') . ($filter !== 'all' ? '_' . strtolower($filter) : '') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

$out = fopen('php://output', 'w');
fputcsv($out, ['Timestamp', 'Username', 'Role', 'Action', 'Description']);

if ($logs) {
    while ($row = $logs->fetch_assoc()) {
        fputcsv($out, [
            date('Y-m-d H:i:s', strtotime($row['timestamp'])),
            $row['username'] ?? 'Deleted User',
            $row['user_role'],
            $row['action_type'],
            $row['description'],
        ]);
    }
}
fclose($out);
exit();
?>