<?php
session_start();
include('../../includes/db.php');

if (isset($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];

    $conn->query("
        UPDATE users 
        SET is_online = 0, last_seen = NOW() 
        WHERE user_id = $user_id
    ");
}