<?php
include('../../includes/db.php');

$result = $conn->query("
    SELECT user_id, last_seen, is_online 
    FROM users 
    WHERE role = 'Staff'
");

$data = [];

while ($row = $result->fetch_assoc()) {
    $data[$row['user_id']] = [
        'last_seen' => $row['last_seen'],
        'is_online' => (int)$row['is_online']
    ];
}

header('Content-Type: application/json');
echo json_encode($data);