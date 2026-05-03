<?php
/**
 * owner/staff_notifications.php
 * Owner view of all staff-submitted notifications:
 * edit requests, delete requests, progress updates.
 */

$page_title = 'Notifications';

include('../includes/db.php');
include('../includes/header.php');
include('../includes/log_activity.php');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Owner') {
    header('Location: ../portal/login.php');
    exit();
}

$owner_id = (int)$_SESSION['user_id'];
$flash    = '';

// ── Actions ───────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['notif_action'] ?? '';

    if ($action === 'mark_read') {
        $nid = (int)($_POST['notif_id'] ?? 0);
        if ($nid > 0) {
            $stmt = $conn->prepare("
                UPDATE staff_notifications
                SET    status = 'read', read_at = NOW()
                WHERE  notif_id = ? AND status = 'unread'
            ");
            $stmt->bind_param('i', $nid);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, $owner_id, 'Owner', 'Notification Read',
                "Marked notification #{$nid} as read");
            $flash = "<div class='alert success'>Notification marked as read.</div>";
        }

    } elseif ($action === 'mark_all_read') {
        $conn->query("UPDATE staff_notifications SET status = 'read', read_at = NOW() WHERE status = 'unread'");
        log_activity($conn, $owner_id, 'Owner', 'Notifications Read', 'Marked all notifications as read');
        $flash = "<div class='alert success'>All notifications marked as read.</div>";
    }
}

// ── Data ──────────────────────────────────────────────────────

$result = $conn->query("
    SELECT sn.*, u.username AS staff_name
    FROM   staff_notifications sn
    JOIN   users u ON sn.staff_id = u.user_id
    ORDER  BY FIELD(sn.status, 'unread', 'read'), sn.created_at DESC
    LIMIT  100
");

$notifications = [];
$unread_count  = 0;

if ($result) {
    while ($row = $result->fetch_assoc()) {
        if ($row['status'] === 'unread') $unread_count++;
        $notifications[] = $row;
    }
}

// ── Type config ───────────────────────────────────────────────

$type_config = [
    'edit_request'    => [
        'label'        => 'Edit Request',
        'badge_class'  => 'type-badge--edit',
        'icon_class'   => 'fa-pen-to-square',
        'review_href'  => 'review_edit_requests.php',
        'review_label' => 'Review Edits',
    ],
    'delete_request'  => [
        'label'        => 'Delete Request',
        'badge_class'  => 'type-badge--delete',
        'icon_class'   => 'fa-trash-can',
        'review_href'  => 'review_delete_requests.php',
        'review_label' => 'Review Deletions',
    ],
    'progress_update' => [
        'label'        => 'Progress Update',
        'badge_class'  => 'type-badge--progress',
        'icon_class'   => 'fa-chart-bar',
        'review_href'  => 'view_sales.php',
        'review_label' => 'View Sales',
    ],
    'sale_request'    => [
        'label'        => 'Sale Request',
        'badge_class'  => 'type-badge--sale',
        'icon_class'   => 'fa-file-invoice-dollar',
        'review_href'  => 'review_edit_requests.php',
        'review_label' => 'Review Requests',
    ],
];

$default_config = [
    'label'        => 'Notification',
    'badge_class'  => 'type-badge--default',
    'icon_class'   => 'fa-bell',
    'review_href'  => 'dashboard.php',
    'review_label' => 'Dashboard',
];
?>

<link rel="stylesheet" href="<?= $root ?>owner/staff_notifications.css">

<div class="notif-page">

    <!-- Header -->
    <div class="notif-header">
        <div>
            <h2 class="notif-header__title">
                Notifications
                <?php if ($unread_count > 0): ?>
                    <span class="unread-pill"><?= $unread_count ?> new</span>
                <?php endif; ?>
            </h2>
            <p class="notif-header__sub">
                Requests and updates submitted by staff.
            </p>
        </div>

        <div class="notif-header__actions">
            <?php if ($unread_count > 0): ?>
            <form method="POST">
                <input type="hidden" name="notif_action" value="mark_all_read">
                <button type="submit" class="btn-farm btn-outline btn-sm">
                    <i class="fa-solid fa-check-double"></i> Mark all read
                </button>
            </form>
            <?php endif; ?>
            <a href="dashboard.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
        </div>
    </div>

    <?= $flash ?>

    <?php if (empty($notifications)): ?>

    <div class="card">
        <div class="notif-empty">
            <i class="fa-regular fa-bell notif-empty__icon"></i>
            <p class="notif-empty__title">No notifications yet.</p>
            <p class="notif-empty__sub">Edit and deletion requests from staff will appear here.</p>
        </div>
    </div>

    <?php else: foreach ($notifications as $n):
        $is_unread = $n['status'] === 'unread';
        $config    = $type_config[$n['notif_type']] ?? $default_config;
    ?>

    <div class="notif-card <?= $is_unread ? 'notif-card--unread' : 'notif-card--read' ?>">

        <!-- Card header -->
        <div class="notif-card__header">
            <div class="notif-card__meta">

                <?php if ($is_unread): ?>
                    <span class="status-badge status-badge--new">
                        <i class="fa-solid fa-circle" style="font-size:.45rem;"></i> New
                    </span>
                <?php else: ?>
                    <span class="status-badge status-badge--seen">
                        <i class="fa-regular fa-circle-check" style="font-size:.7rem;"></i> Seen
                    </span>
                <?php endif; ?>

                <span class="type-badge <?= $config['badge_class'] ?>">
                    <i class="fa-solid <?= $config['icon_class'] ?>" style="font-size:.65rem;"></i>
                    <?= $config['label'] ?>
                </span>

                <span class="notif-card__author">
                    <?= htmlspecialchars($n['staff_name']) ?>
                </span>

                <?php if ($n['record_type'] && $n['record_id']): ?>
                    <span class="notif-card__ref">
                        &mdash; <?= htmlspecialchars($n['record_type']) ?> #<?= (int)$n['record_id'] ?>
                    </span>
                <?php endif; ?>

                <?php if ($n['batch_id']): ?>
                    <span class="notif-card__ref">
                        &middot; Batch #<?= (int)$n['batch_id'] ?>
                    </span>
                <?php endif; ?>

            </div>

            <time class="notif-card__time" datetime="<?= htmlspecialchars($n['created_at']) ?>">
                <?= date('M j, Y · g:i A', strtotime($n['created_at'])) ?>
            </time>
        </div>

        <!-- Message -->
        <div class="notif-card__message">
            <?= htmlspecialchars($n['message']) ?>
        </div>

        <!-- Actions -->
        <div class="notif-card__footer">

            <a href="<?= htmlspecialchars($config['review_href']) ?>"
               class="btn-farm btn-orange btn-sm">
                <?= htmlspecialchars($config['review_label']) ?>
                <i class="fa-solid fa-arrow-right" style="font-size:.7rem;"></i>
            </a>

            <?php if ($is_unread): ?>
            <form method="POST">
                <input type="hidden" name="notif_action" value="mark_read">
                <input type="hidden" name="notif_id" value="<?= (int)$n['notif_id'] ?>">
                <button type="submit" class="btn-farm btn-outline btn-sm">
                    <i class="fa-solid fa-check"></i> Mark as read
                </button>
            </form>
            <?php endif; ?>

            <?php if ($n['read_at']): ?>
                <span class="notif-card__seen-at">
                    Read <?= date('M j · g:i A', strtotime($n['read_at'])) ?>
                </span>
            <?php endif; ?>

        </div>

    </div>

    <?php endforeach; endif; ?>

</div>

<?php include('../includes/footer.php'); ?>