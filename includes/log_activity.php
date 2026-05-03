<?php
/*  includes/log_activity.php — Activity Logger
    Include AFTER db.php, then call log_activity() anywhere.

    log_activity($conn, $user_id, $user_role, $action_type, $description)

    Standard action_type values:
      Login / Logout
      Harvest Added / Harvest Edited / Harvest Deleted
      Sale Added / Sale Deleted
      Health Added / Health Edited / Health Deleted
      Edit Request Sent / Edit Request Approved / Edit Request Rejected
      User Created / User Deleted / Password Reset
      Notification Sent / Notification Acknowledged / Notification Completed
      Price Updated / Batch Added / Batch Retired / Batch Deleted
*/

function log_activity($conn, $user_id, $user_role, $action_type, $description = '') {
    if (!$conn || !$user_id || !$user_role || !$action_type) return false;
    $stmt = $conn->prepare(
        "INSERT INTO activity_logs (user_id, user_role, action_type, description) VALUES (?,?,?,?)"
    );
    if (!$stmt) return false;
    $stmt->bind_param("isss", $user_id, $user_role, $action_type, $description);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// Shortcut: log from active session — call after auth check
function log_session_activity($conn, $action_type, $description = '') {
    if (!isset($_SESSION['user_id'], $_SESSION['role'])) return false;
    return log_activity($conn, $_SESSION['user_id'], $_SESSION['role'], $action_type, $description);
}
?>