<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'rooms' => [], 'message' => 'Authentication required.']);
    exit;
}

$db = Database::connection();
Semester::enforceAcademicWrite($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array(Auth::role(), ['HOD', 'Coordinator'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Only the Head Of Department or Coordinator can add rooms.']);
        exit;
    }

    csrf_verify();
    $roomName = preg_replace('/\s+/', ' ', trim((string) ($_POST['room_name'] ?? '')));
    if ($roomName === '' || mb_strlen($roomName) < 2 || mb_strlen($roomName) > 100) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Room name must contain between 2 and 100 characters.']);
        exit;
    }

    $existing = $db->prepare('SELECT room_id, room_name FROM rooms WHERE LOWER(room_name) = LOWER(:name) LIMIT 1');
    $existing->execute(['name' => $roomName]);
    $room = $existing->fetch(PDO::FETCH_ASSOC);
    if ($room) {
        echo json_encode([
            'ok' => true,
            'room' => $room,
            'created' => false,
            'message' => 'That room already exists and has been selected.',
        ]);
        exit;
    }

    try {
        $db->prepare('INSERT INTO rooms (room_name) VALUES (:name)')->execute(['name' => $roomName]);
        $room = ['room_id' => (int) $db->lastInsertId(), 'room_name' => $roomName];
        AuditLog::record(Auth::id(), 'CREATE_ROOM', 'rooms', (int) $room['room_id'], 'room_name=' . $roomName);
        echo json_encode([
            'ok' => true,
            'room' => $room,
            'created' => true,
            'message' => 'Room added successfully.',
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) {
            $existing->execute(['name' => $roomName]);
            echo json_encode([
                'ok' => true,
                'room' => $existing->fetch(PDO::FETCH_ASSOC),
                'created' => false,
                'message' => 'That room already exists and has been selected.',
            ]);
            exit;
        }
        throw $e;
    }
    exit;
}

$sessionType  = trim($_GET['session_type'] ?? '');
$excludeModId = (int) ($_GET['module_id'] ?? 0);

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
