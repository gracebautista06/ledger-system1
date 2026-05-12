<?php
// includes/sidebar_staff.php
$current_page = basename($_SERVER['PHP_SELF']);

function sidebar_link_staff($href, $label, $icon_class, $current_page, $match_file) {
    $is_active = ($current_page === $match_file) ? 'active' : '';
    echo "<a href=\"$href\" class=\"sidebar-link $is_active\">
            <i class=\"$icon_class sidebar-icon\"></i>
            <span class=\"sidebar-label\">$label</span>
          </a>";
}
?>

<aside class="sidebar" id="sidebar">

    <!-- Profile Section -->
    <div class="sidebar-brand">
        <span class="sidebar-brand-icon">🥚</span>
        <span class="sidebar-brand-name">Egg Ledger</span>
    </div>

    <div class="sidebar-profile">
        <div class="sidebar-avatar">
            <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
        </div>
        <div class="sidebar-user-info">
            <div class="sidebar-username"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
            <div class="sidebar-role">Staff</div>
        </div>
    </div>

    <hr class="sidebar-divider">

    <nav class="sidebar-nav">

        <div class="sidebar-section-label">Main</div>
        <?php sidebar_link_staff('../staff/dashboard.php', 'Dashboard', 'fa-solid fa-gauge', $current_page, 'dashboard.php'); ?>

        <div class="sidebar-section-label">Daily Logs</div>
        <?php sidebar_link_staff('../staff/log_harvest.php', 'Log Harvest',     'fa-solid fa-egg',          $current_page, 'log_harvest.php'); ?>
        <?php sidebar_link_staff('../staff/log_sale.php',    'Log Sale',         'fa-solid fa-cart-shopping', $current_page, 'log_sale.php'); ?>
        <?php sidebar_link_staff('../staff/log_health.php',  'Log Flock Health', 'fa-solid fa-heart-pulse',   $current_page, 'log_health.php'); ?>

        <div class="sidebar-section-label">Records</div>
        <?php sidebar_link_staff('../staff/view_logs.php', 'View My Logs', 'fa-solid fa-rectangle-list', $current_page, 'view_logs.php'); ?>

        <div class="sidebar-section-label">Account</div>
        <?php sidebar_link_staff('../staff/profile.php',   'My Profile', 'fa-solid fa-circle-user',        $current_page, 'profile.php'); ?>
        <?php sidebar_link_staff('../portal/logout.php',   'Logout',     'fa-solid fa-right-from-bracket', $current_page, 'logout.php'); ?>

    </nav>
</aside>