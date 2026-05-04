<?php
$page_title = 'Dashboard';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');
include('../includes/notifications.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Staff') {
    header('Location: ../portal/login.php'); exit();
}

$username = $_SESSION['username'];
$staff_id = (int) $_SESSION['user_id'];

$hour = (int) date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$stmt = $conn->prepare("SELECT COALESCE(SUM(total_eggs),0) AS logged_today FROM harvests WHERE staff_id=? AND DATE(date_logged)=CURDATE()");
$stmt->bind_param('i', $staff_id);
$stmt->execute();
$logged_today = (int) $stmt->get_result()->fetch_assoc()['logged_today'];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM edit_requests WHERE staff_id=? AND status='Pending'");
$stmt->bind_param('i', $staff_id);
$stmt->execute();
$my_pending = (int) $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(quantity_sold),0) AS sold_today, COALESCE(SUM(total_amount),0) AS revenue_today FROM sales WHERE staff_id=? AND DATE(date_sold)=CURDATE()");
$stmt->bind_param('i', $staff_id);
$stmt->execute();
$sales_today   = $stmt->get_result()->fetch_assoc();
$stmt->close();

$trays_today   = (int) $sales_today['sold_today'];
$revenue_today = (float) $sales_today['revenue_today'];
$goal          = 500;
$progress_pct  = min(100, $goal > 0 ? round(($logged_today / $goal) * 100) : 0);

function stat_card(string $label, string $value, string $sub, string $accent): void { ?>
    <div class="stat-card" style="border-left:3px solid <?= $accent ?>;">
        <span class="stat-label"><?= htmlspecialchars($label) ?></span>
        <span class="stat-value"><?= htmlspecialchars($value) ?></span>
        <span class="stat-sub"><?= htmlspecialchars($sub) ?></span>
    </div>
<?php }

function action_card(string $title, string $href, string $btn_label, string $accent): void { ?>
    <a href="<?= htmlspecialchars($href) ?>" class="action-card" style="--accent:<?= $accent ?>;">
        <div class="action-card__body">
            <h3 class="action-card__title"><?= htmlspecialchars($title) ?></h3>
        </div>
        <span class="action-card__btn"><?= htmlspecialchars($btn_label) ?></span>
    </a>
<?php }
?>

<style>
    .db-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 2rem;
    }

    .db-header__greeting {
        color: var(--gold);
        font-family: 'Playfair Display', serif;
        font-size: 1.75rem;
        font-weight: 600;
        margin: 0 0 4px;
    }

    .db-header__sub {
        color: var(--text-muted);
        font-size: 0.875rem;
        margin: 0;
    }

    .alert--warning {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 12px 16px;
        border: 1px solid var(--warning);
        border-radius: 6px;
        color: var(--warning);
        font-size: 0.875rem;
        margin-bottom: 1.5rem;
    }

    .alert--warning a {
        color: var(--warning);
        font-weight: 600;
        margin-left: auto;
        text-decoration: none;
        white-space: nowrap;
    }

    .stats-grid {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        margin-bottom: 2rem;
    }

    .stat-card {
        flex: 1;
        min-width: 160px;
        background: var(--card-bg, #1e1e1e);
        border-radius: 8px;
        padding: 1.25rem 1.5rem;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .stat-label {
        font-size: 0.75rem;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
    }

    .stat-value {
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--text-primary, #fff);
        line-height: 1.2;
    }

    .stat-sub {
        font-size: 0.8rem;
        color: var(--text-muted);
    }

    .progress-bar {
        margin-top: 10px;
        background: var(--bg-plank, #2a2a2a);
        border-radius: 4px;
        height: 4px;
        overflow: hidden;
    }

    .progress-bar__fill {
        height: 100%;
        border-radius: 4px;
        background: var(--terra-lt);
        transition: width 0.5s ease;
    }

    .actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
    }

    .action-card {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        background: var(--card-bg, #1e1e1e);
        border-radius: 8px;
        border-top: 3px solid var(--accent);
        padding: 1.5rem;
        text-decoration: none;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .action-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.25);
    }

    .action-card__title {
        font-family: 'Playfair Display', serif;
        font-size: 1rem;
        color: var(--gold);
        margin: 0 0 6px;
    }

    .action-card__btn {
        display: inline-block;
        padding: 8px 16px;
        border-radius: 5px;
        font-size: 0.8rem;
        font-weight: 600;
        text-align: center;
        background: var(--accent);
        color: #fff;
        letter-spacing: 0.02em;
    }

    .db-footer {
        margin-top: 2rem;
        text-align: right;
    }

    .db-footer a {
        color: var(--text-muted);
        font-size: 0.8rem;
        text-decoration: none;
    }

    .db-footer a:hover {
        color: var(--text-primary, #fff);
    }
</style>

<div class="db-header">
    <div>
        <h1 class="db-header__greeting"><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($username) ?></h1>
        <p class="db-header__sub"><?= date('l, F j, Y') ?></p>
    </div>
    <div>
        <?php render_notification_bell($conn, 'Staff'); ?>
    </div>
</div>

<?php render_notification_panel($conn, 'Staff'); ?>

<?php if ($my_pending > 0): ?>
<div class="alert--warning">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?= $my_pending ?> pending edit <?= $my_pending === 1 ? 'request' : 'requests' ?> awaiting review.
    <a href="view_logs.php">View Logs</a>
</div>
<?php endif; ?>

<div class="stats-grid">
    <?php stat_card('Daily Target', number_format($goal) . ' eggs', 'Production goal', 'var(--gold-dim)'); ?>

    <div class="stat-card" style="border-left:3px solid var(--terra-lt);">
        <span class="stat-label">Logged Today</span>
        <span class="stat-value"><?= number_format($logged_today) ?></span>
        <span class="stat-sub"><?= $logged_today >= $goal ? 'Target reached' : number_format(max(0, $goal - $logged_today)) . ' remaining' ?></span>
        <div class="progress-bar">
            <div class="progress-bar__fill" style="width:<?= $progress_pct ?>%;"></div>
        </div>
    </div>

    <?php
    $sales_sub = $trays_today > 0 ? 'Revenue: ₱' . number_format($revenue_today, 2) : 'No sales recorded';
    stat_card("Today's Sales", number_format($trays_today) . ' trays', $sales_sub, 'var(--success)');
    ?>
</div>

<div class="actions-grid">
    <?php
    action_card('Harvest',     'log_harvest.php', 'Record Harvest', 'var(--gold)');
    action_card('Sales',       'log_sale.php',    'New Sale',       'var(--success)');
    action_card('Flock',       'log_health.php',  'Update Status',  'var(--terra-lt)');
    action_card('Log History', 'view_logs.php',   'View Logs',      'var(--info)');
    ?>
</div>

<div class="db-footer">
    <a href="../portal/logout.php">Sign out</a>
</div>

<?php include('../includes/footer.php'); ?>