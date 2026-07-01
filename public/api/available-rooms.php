<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'rooms' => []]);
    exit;
}

$sessionType  = trim($_GET['session_type'] ?? '');
$excludeModId = (int) ($_GET['module_id'] ?? 0);
$db = Database::connection();

if (!$sessionType || $sessionType === 'Weekend') {
    $rooms = $db->query('SELECT room_id, room_name FROM rooms ORDER BY room_name')->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'rooms' => $rooms]);
    exit;
}

$stmt = $db->prepare(
    "SELECT r.room_id, r.room_name FROM rooms r
     WHERE r.room_id NOT IN (
         SELECT m.room_id FROM modules m
         WHERE m.session_type = :st AND m.status = 'Ongoing'
           AND m.room_id IS NOT NULL AND m.module_id != :mid
     ) ORDER BY r.room_name"
);
$stmt->execute(['st' => $sessionType, 'mid' => $excludeModId]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'rooms' => $rooms]);
