<?php
session_start();
include('../../includes/db.php');

if (isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];

    // Update last_seen every ping
    $conn->query("
        UPDATE users 
        SET last_seen = NOW(), is_online = 1 
        WHERE user_id = $user_id
    ");
}

echo json_encode(['status' => 'ok']);