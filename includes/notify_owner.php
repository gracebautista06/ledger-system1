<?php
/* includes/notify_owner.php
   Reusable function to send Staff → Owner notifications.
   Call this after any staff action that the owner needs to see.
*/

function notify_owner(
    mysqli $conn,
    int    $staff_id,
    string $notif_type,   // 'edit_request' | 'delete_request' | 'progress_update'
    string $message,
    ?int   $batch_id    = null,
    ?string $record_type = null,
    ?int   $record_id   = null
): bool {
    $stmt = $conn->prepare("
        INSERT INTO staff_notifications
            (staff_id, batch_id, record_type, record_id, notif_type, message, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, 'unread', NOW())
    ");
    $stmt->bind_param(
        "iisiss",
        $staff_id,
        $batch_id,
        $record_type,
        $record_id,
        $notif_type,
        $message
    );
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}
?>