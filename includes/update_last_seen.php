<?php
/* ============================================================
   includes/update_last_seen.php

   WHAT THIS DOES:
   Updates the last_seen timestamp in the users table every time
   a logged-in user loads any page. This is how the Owner can see
   if staff is currently online or when they were last active.

   HOW TO USE:
   Add this ONE LINE in includes/header.php right after session_start():

       include_once $root . 'includes/update_last_seen.php';

   Or if $conn and $_SESSION are already available, just include it directly.

   ONLINE THRESHOLD:
   A user is considered "Online" if last_seen is within the last 5 minutes.
   Anything older = "Last seen X ago" (mins / hours / days).
   ============================================================ */

// Only update if there is an active logged-in session
if (
    isset($_SESSION['user_id']) &&
    isset($_SESSION['role']) &&
    isset($conn) &&
    $conn instanceof mysqli
) {
    $uid = (int) $_SESSION['user_id'];

    // Update last_seen to NOW() — runs on every page load, lightweight query
    $conn->query("UPDATE users SET last_seen = NOW() WHERE user_id = $uid LIMIT 1");
}