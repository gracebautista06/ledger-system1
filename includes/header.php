<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' — ' : ''; ?>Egg Ledger System</title>
    <meta name="description" content="Egg Ledger — Digital farm management for harvest tracking, flock health, and sales.">
    <meta name="robots" content="noindex, nofollow">

    <?php
        $levels = max(0, substr_count($_SERVER['PHP_SELF'], '/') - 2);
        $root   = str_repeat('../', $levels);
        if (session_status() === PHP_SESSION_NONE) session_start();

        // Update last_seen timestamp for logged-in users (used for online/offline status in users.php)
        if (isset($_SESSION['user_id']) && isset($conn)) {
            include_once $root . 'includes/update_last_seen.php';
        }
    ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <!-- IMPROVEMENT: Load both Playfair Display (headings) + DM Sans (body) -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $root; ?>assets/css/style.css">
</head>
<body>

<header>
    <nav class="navbar">
        <a href="<?php echo $root; ?>index.php" class="logo">Egg Ledger</a>

        <ul class="nav-links">
            <li><a href="<?php echo $root; ?>index.php">Home</a></li>

            <?php if (isset($_SESSION['username'])): ?>
                <li>
                    <span class="nav-user-info">
                        👤 <?php echo htmlspecialchars($_SESSION['username']); ?>
                        <span class="badge <?php echo strtolower($_SESSION['role']) === 'owner' ? 'badge-owner' : 'badge-staff'; ?>"
                              style="font-size:0.62rem;">
                            <?php echo htmlspecialchars($_SESSION['role']); ?>
                        </span>
                    </span>
                </li>
                <li>
                    <?php $dashboard_link = ($_SESSION['role'] === 'Owner')
                        ? $root . 'owner/dashboard.php'
                        : $root . 'staff/dashboard.php'; ?>
                    <a href="<?php echo $dashboard_link; ?>">Dashboard</a>
                </li>
                <li>
                    <a href="<?php echo $root; ?>portal/logout.php" class="nav-logout">Logout 🚪</a>
                </li>
            <?php else: ?>
                <li><a href="<?php echo $root; ?>portal/register.php">Register</a></li>
                <li><a href="<?php echo $root; ?>portal/login.php">Login</a></li>
            <?php endif; ?>
        </ul>
    </nav>
</header>

<main role="main">
    <div class="container">