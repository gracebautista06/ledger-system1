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

                <?php
                // ── Notification bell ──────────────────────────────────
                if (isset($conn) && isset($_SESSION['user_id'])) {
                    $bell_uid  = (int)$_SESSION['user_id'];
                    $bell_role = $_SESSION['role'] ?? '';

                    if ($bell_role === 'Owner') {
                        // Owner sees unread notifications only where the linked request is still Pending
                        $bell_q = $conn->prepare("
                            SELECT COUNT(*) AS cnt
                            FROM staff_notifications sn
                            WHERE sn.status = 'unread'
                              AND sn.notif_type IN ('edit_request','delete_request','progress_update','sale_request')
                              AND (
                                  -- for edit/delete requests, only count if still pending
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
                        $bell_href = $root . 'owner/staff_notifications.php';

                    } elseif ($bell_role === 'Staff') {
                        // Staff sees unread owner responses (request_outcome)
                        $bell_q = $conn->prepare("
                            SELECT COUNT(*) AS cnt
                            FROM staff_notifications
                            WHERE staff_id   = ?
                              AND status     = 'unread'
                              AND notif_type = 'request_outcome'
                        ");
                        $bell_q->bind_param('i', $bell_uid);
                        $bell_q->execute();
                        $bell_unread = (int)$bell_q->get_result()->fetch_assoc()['cnt'];
                        $bell_q->close();
                        $bell_href = $root . 'staff/my_notifications.php';

                    } else {
                        $bell_unread = 0;
                        $bell_href   = '#';
                    }
                ?>
                <li>
                    <a href="<?php echo $bell_href; ?>" class="nav-bell" title="Notifications"
                       style="position:relative; display:inline-flex; align-items:center; padding:4px 6px; text-decoration:none;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                             style="vertical-align:middle;">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                        <?php if ($bell_unread > 0): ?>
                            <span style="
                                position:absolute; top:-4px; right:-4px;
                                background:var(--danger, #c23a3a);
                                color:#fff;
                                font-size:0.6rem;
                                font-weight:700;
                                min-width:16px; height:16px;
                                border-radius:999px;
                                display:flex; align-items:center; justify-content:center;
                                padding:0 3px;
                                line-height:1;
                                pointer-events:none;">
                                <?php echo $bell_unread > 99 ? '99+' : $bell_unread; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php } // end if isset($conn) ?>

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