<?php
/**
 * includes/sidebar.php
 * Drop-in sidebar for every Owner page.
 *
 * USAGE (at the top of any owner page, AFTER session_start + auth check):
 *
 *   $levels  = max(0, substr_count($_SERVER['PHP_SELF'], '/') - 2);
 *   $root    = str_repeat('../', $levels);
 *   $current = basename($_SERVER['PHP_SELF']); // used to mark active nav-item
 *   include $root . 'includes/sidebar.php';
 *
 * The file outputs the opening <div class="app-shell"> and <aside class="sidebar">
 * then leaves the cursor right before <div class="main-content">, which the
 * calling page opens itself so it can inject its own topbar title.
 *
 * The calling page must close:
 *   </div><!-- /.main-content -->
 *   </div><!-- /.app-shell -->
 */

// Helper: is the current page "active" for this href?
function _nav_active(string $href, string $current): string {
    return (basename($href) === $current) ? ' active' : '';
}
?>
<div class="app-shell">

    <aside class="sidebar">

        <a href="<?= $root ?>owner/dashboard.php" class="sidebar-logo">
            <span class="sidebar-logo__icon">🥚</span>
            <span class="sidebar-logo__text">Egg Ledger</span>
        </a>

        <div class="sidebar-user">
            <div class="sidebar-user__avatar">
                <i class="fa-solid fa-user"></i>
            </div>
            <div>
                <div class="sidebar-user__name"><?= htmlspecialchars($_SESSION['username']) ?></div>
                <div class="sidebar-user__role"><?= htmlspecialchars($_SESSION['role']) ?></div>
            </div>
        </div>

        <nav class="sidebar-nav">

            <div class="nav-group">
                <span class="nav-group__label">Overview</span>
                <a href="<?= $root ?>owner/dashboard.php"
                   class="nav-item<?= _nav_active('dashboard.php', $current ?? '') ?>">
                    <i class="fa-solid fa-chart-pie"></i> Dashboard
                </a>
            </div>

            <div class="nav-group">
                <span class="nav-group__label">Inventory</span>
                <a href="<?= $root ?>owner/manage_inventory/inventory.php"
                   class="nav-item<?= _nav_active('inventory.php', $current ?? '') ?>">
                    <i class="fa-solid fa-boxes-stacked"></i> Active Stock
                </a>
                <a href="<?= $root ?>owner/manage_inventory/inventory_history.php"
                   class="nav-item<?= _nav_active('inventory_history.php', $current ?? '') ?>">
                    <i class="fa-solid fa-clock-rotate-left"></i> History
                </a>
            </div>

            <div class="nav-group">
                <span class="nav-group__label">Flocks</span>
                <a href="<?= $root ?>owner/manage_flocks/batches.php"
                   class="nav-item<?= _nav_active('batches.php', $current ?? '') ?>">
                    <i class="fa-solid fa-layer-group"></i> Batches
                </a>
                <a href="<?= $root ?>owner/manage_flocks/prices.php"
                   class="nav-item<?= _nav_active('prices.php', $current ?? '') ?>">
                    <i class="fa-solid fa-tag"></i> Pricing
                </a>
                <a href="<?= $root ?>owner/manage_flocks/flock_history.php"
                   class="nav-item<?= _nav_active('flock_history.php', $current ?? '') ?>">
                    <i class="fa-solid fa-clock-rotate-left"></i> History
                </a>
            </div>

            <div class="nav-group">
                <span class="nav-group__label">Sales</span>
                <a href="<?= $root ?>owner/manage_sales/view_sales.php"
                   class="nav-item<?= _nav_active('view_sales.php', $current ?? '') ?>">
                    <i class="fa-solid fa-receipt"></i> Today's Sales
                </a>
                <a href="<?= $root ?>owner/manage_sales/sales_history.php"
                   class="nav-item<?= _nav_active('sales_history.php', $current ?? '') ?>">
                    <i class="fa-solid fa-clock-rotate-left"></i> History
                </a>
            </div>

            <div class="nav-group">
                <span class="nav-group__label">Users</span>
                <a href="<?= $root ?>owner/manage_users/users.php"
                   class="nav-item<?= _nav_active('users.php', $current ?? '') ?>">
                    <i class="fa-solid fa-users"></i> Staff
                </a>
                <a href="<?= $root ?>owner/manage_users/user_activity_log.php"
                   class="nav-item<?= _nav_active('user_activity_log.php', $current ?? '') ?>">
                    <i class="fa-solid fa-list-check"></i> Activity Log
                </a>
            </div>

        </nav>

        <div class="sidebar-footer">
            <a href="<?= $root ?>portal/logout.php">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
            </a>
        </div>

    </aside>