<?php
// includes/sidebar_owner.php
$current_page = basename($_SERVER['PHP_SELF']);

function sidebar_link($href, $label, $icon_class, $current_page, $match_file) {
    $is_active = ($current_page === $match_file) ? 'active' : '';
    echo "<a href=\"$href\" class=\"sidebar-link $is_active\">
            <i class=\"$icon_class sidebar-icon\"></i>
            <span class=\"sidebar-label\">$label</span>
          </a>";
}
?>

<aside class="sidebar" id="sidebar">

    <!-- Profile Section -->
    <div class="sidebar-profile">
        <div class="sidebar-avatar">
            <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
        </div>
        <div class="sidebar-user-info">
            <div class="sidebar-username"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
            <div class="sidebar-role">Owner</div>
        </div>
    </div>

    <hr class="sidebar-divider">

    <nav class="sidebar-nav">

        <div class="sidebar-section-label">Main</div>
        <?php sidebar_link('/ledger-system1/owner/dashboard.php', 'Dashboard', 'fa-solid fa-gauge', $current_page, 'dashboard.php'); ?>

        <div class="sidebar-section-label">Flock</div>
        <?php sidebar_link('/ledger-system1/owner/manage_flocks/batches.php',       'Active Batches', 'fa-solid fa-egg',               $current_page, 'batches.php'); ?>
        <?php sidebar_link('/ledger-system1/owner/manage_flocks/flock_history.php', 'Flock History',  'fa-solid fa-clock-rotate-left', $current_page, 'flock_history.php'); ?>

        <div class="sidebar-section-label">Inventory</div>
        <?php sidebar_link('/ledger-system1/owner/manage_inventory/inventory.php',         'Active Stock',      'fa-solid fa-boxes-stacked',  $current_page, 'inventory.php'); ?>
        <?php sidebar_link('/ledger-system1/owner/manage_inventory/inventory_history.php', 'Inventory History', 'fa-solid fa-rectangle-list', $current_page, 'inventory_history.php'); ?>

        <div class="sidebar-section-label">Sales</div>
        <?php sidebar_link('/ledger-system1/owner/manage_sales/view_sales.php',    "Today's Sales", 'fa-solid fa-cart-shopping', $current_page, 'view_sales.php'); ?>
        <?php sidebar_link('/ledger-system1/owner/manage_sales/prices.php',        'Egg Pricing',   'fa-solid fa-tag',           $current_page, 'prices.php'); ?>
        <?php sidebar_link('/ledger-system1/owner/manage_sales/sales_history.php', 'Sales History', 'fa-solid fa-chart-line',    $current_page, 'sales_history.php'); ?>

        <div class="sidebar-section-label">Reports</div>
        <?php sidebar_link('/ledger-system1/owner/reports.php', 'Generate Report', 'fa-solid fa-file-chart-column', $current_page, 'reports.php'); ?>

        <div class="sidebar-section-label">Management</div>
        <?php sidebar_link('/ledger-system1/owner/manage_users/users.php',               'Staff Management', 'fa-solid fa-users',    $current_page, 'users.php'); ?>
        <?php sidebar_link('/ledger-system1/owner/manage_inventory/data_management.php', 'Data Management',  'fa-solid fa-database', $current_page, 'data_management.php'); ?>
        <?php sidebar_link('/ledger-system1/owner/manage_users/activity_log.php',                     'Activity Log',     'fa-solid fa-timeline', $current_page, 'activity_log.php'); ?>
        <?php sidebar_link('/ledger-system1/owner/staff_notifications.php',              'Staff Requests',   'fa-solid fa-bell',     $current_page, 'staff_notifications.php'); ?>

        <div class="sidebar-section-label">Account</div>
        <?php sidebar_link('/ledger-system1/owner/profile.php',  'My Profile', 'fa-solid fa-circle-user',        $current_page, 'profile.php'); ?>
        <?php sidebar_link('/ledger-system1/portal/logout.php',  'Logout',     'fa-solid fa-right-from-bracket', $current_page, 'logout.php'); ?>

    </nav>
</aside>