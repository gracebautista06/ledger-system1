<?php

ob_start();
// ── Must run before ANY HTML output ───────────────────────
$levels = max(0, substr_count($_SERVER['PHP_SELF'], '/') - 2);
$root   = str_repeat('../', $levels);
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_SESSION['user_id']) && isset($conn)) {
    include_once $root . 'includes/update_last_seen.php';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' — ' : ''; ?>Egg Ledger System</title>
    <meta name="description" content="Egg Ledger — Digital farm management for harvest tracking, flock health, and sales.">
    <meta name="robots" content="noindex, nofollow">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $root; ?>assets/css/style.css">
</head>
<body>

<?php
// ── Decide which sidebar and topbar to show ────────────────
$is_logged_in   = isset($_SESSION['role']);
$is_owner       = $is_logged_in && $_SESSION['role'] === 'Owner';
$is_staff       = $is_logged_in && $_SESSION['role'] === 'Staff';
$show_sidebar   = $is_owner || $is_staff;

// ── Notification count for bell (Owner only) ───────────────
$bell_unread = 0;
if ($is_owner && isset($conn) && isset($_SESSION['user_id'])) {
    $bell_q = $conn->prepare("
        SELECT COUNT(*) AS cnt
        FROM staff_notifications sn
        WHERE sn.status = 'unread'
          AND sn.notif_type IN ('edit_request','delete_request','progress_update','sale_request')
          AND (
              sn.notif_type NOT IN ('edit_request','delete_request')
              OR EXISTS (
                  SELECT 1 FROM edit_requests er
                  WHERE er.record_type  = sn.record_type
                    AND er.record_id    = sn.record_id
                    AND er.request_type IN ('Edit','Delete')
                    AND er.status       = 'Pending'
              )
          )
    ");
    $bell_q->execute();
    $bell_unread = (int)$bell_q->get_result()->fetch_assoc()['cnt'];
    $bell_q->close();
}
?>

<?php if ($show_sidebar): ?>
<!-- ── LAYOUT WRAPPER (Sidebar + Main) ──────────────────── -->
<div class="layout-wrapper">

    <!-- SIDEBAR -->
    <?php
        if ($is_owner) include_once $root . 'includes/sidebar_owner.php';
        if ($is_staff) include_once $root . 'includes/sidebar_staff.php';
    ?>

    <!-- SIDEBAR OVERLAY (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- MAIN CONTENT -->
    <div class="main-content">

        <!-- TOP BAR -->
        <header class="topbar">
            <div class="topbar-left">
                <!-- Mobile toggle -->
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <span class="topbar-title">
                    <?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Dashboard'; ?>
                </span>
            </div>
            <div class="topbar-right">
                <?php if ($is_owner): ?>
                <a href="<?php echo $root; ?>owner/staff_notifications.php"
                   class="topbar-bell" title="Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <?php if ($bell_unread > 0): ?>
                        <span class="topbar-bell-badge">
                            <?php echo $bell_unread > 99 ? '99+' : $bell_unread; ?>
                        </span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <span class="topbar-date">
                    <?php
                        date_default_timezone_set('Asia/Manila');
                        echo date('l, M j, Y — g:i A');
                    ?>
                </span>
            </div>
        </header>

        <!-- PAGE CONTENT -->
        <main role="main">
            <div class="container">

<?php else: ?>
<!-- ── PUBLIC PAGES (login, register, index) ────────────── -->
<header>
    <nav class="navbar">
        <a href="<?php echo $root; ?>index.php" class="logo">Egg Ledger</a>
        <ul class="nav-links">
            <li><a href="<?php echo $root; ?>index.php">Home</a></li>
            <li><a href="<?php echo $root; ?>portal/register.php">Register</a></li>
            <li><a href="<?php echo $root; ?>portal/login.php">Login</a></li>
        </ul>
    </nav>
</header>
<main role="main">
    <div class="container">
<?php endif; ?> 