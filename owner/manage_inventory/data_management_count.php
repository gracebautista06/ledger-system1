<?php
/*
 * owner/data_management_count.php — AJAX count endpoint
 * Called by data_management.php JS before showing deletion modal.
 * Returns JSON { count: N }
 */

session_start();
include('../../includes/db.php');

header('Content-Type: application/json');

// Owner only
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    echo json_encode(['count' => 0]);
    exit();
}

$type = $_GET['type'] ?? '';
$mode = $_GET['mode'] ?? 'range';
$from = $_GET['from'] ?? '';
$to   = $_GET['to']   ?? '';

$count = 0;

if ($type === 'sales') {
    if ($mode === 'all') {
        $q = $conn->query("SELECT COUNT(*) AS c FROM sales");
    } else {
        $from_esc = $conn->real_escape_string($from);
        $to_esc   = $conn->real_escape_string($to);
        $q = $conn->query("SELECT COUNT(*) AS c FROM sales WHERE DATE(date_sold) BETWEEN '$from_esc' AND '$to_esc'");
    }
    $count = $q ? (int)$q->fetch_assoc()['c'] : 0;

} elseif ($type === 'harvests') {
    if ($mode === 'all') {
        $q = $conn->query("SELECT COUNT(*) AS c FROM harvests");
    } else {
        $from_esc = $conn->real_escape_string($from);
        $to_esc   = $conn->real_escape_string($to);
        $q = $conn->query("SELECT COUNT(*) AS c FROM harvests WHERE DATE(date_logged) BETWEEN '$from_esc' AND '$to_esc'");
    }
    $count = $q ? (int)$q->fetch_assoc()['c'] : 0;
}

echo json_encode(['count' => $count]);