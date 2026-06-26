<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Administrator']);
header('Content-Type: application/json');

$eventId = (int) ($_GET['event_id'] ?? 0);
$db = Database::connection();
$stmt = $db->prepare('SELECT * FROM events WHERE event_id = :id');
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

if (!$event) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

// Rotation window matches events.qr_rotation_seconds (0 = a long-lived token instead).
$ttl = (int) $event['qr_rotation_seconds'] > 0 ? (int) $event['qr_rotation_seconds'] + 5 : 21600;
$token = QrService::buildPayload($eventId, $event['qr_secret'], $ttl);
$scanUrl = APP_URL . '/student/scan.php?event_id=' . $eventId . '&t=' . urlencode($token);

echo json_encode(['ok' => true, 'scan_url' => $scanUrl, 'rotation_seconds' => (int) $event['qr_rotation_seconds']]);
