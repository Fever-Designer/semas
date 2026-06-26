<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Not authenticated.']);
    exit;
}

$userId = Auth::id();
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');

switch ($action) {
    case 'list':
        $items = NotificationCenter::recent($userId, 20);
        echo json_encode([
            'ok' => true,
            'unread_count' => NotificationCenter::unreadCount($userId),
            'items' => $items,
        ]);
        break;

    case 'mark_read':
        csrf_verify();
        NotificationCenter::markRead($userId, (int) $_POST['id']);
        echo json_encode(['ok' => true, 'unread_count' => NotificationCenter::unreadCount($userId)]);
        break;

    case 'mark_unread':
        csrf_verify();
        NotificationCenter::markUnread($userId, (int) $_POST['id']);
        echo json_encode(['ok' => true, 'unread_count' => NotificationCenter::unreadCount($userId)]);
        break;

    case 'mark_all_read':
        csrf_verify();
        NotificationCenter::markAllRead($userId);
        echo json_encode(['ok' => true, 'unread_count' => 0]);
        break;

    case 'delete':
        csrf_verify();
        NotificationCenter::delete($userId, (int) $_POST['id']);
        echo json_encode(['ok' => true, 'unread_count' => NotificationCenter::unreadCount($userId)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
}
