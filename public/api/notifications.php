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
        try {
            RoleNotificationService::generateFor($userId, (string) Auth::role());
        } catch (Throwable $e) {
            // Generated role alerts are supplementary; one unavailable source
            // must not prevent the user's existing notifications from loading.
            error_log('Role notification generation failed: ' . $e->getMessage());
        }
        try {
            $items = NotificationCenter::recent($userId, 20);
            echo json_encode([
                'ok' => true,
                'unread_count' => NotificationCenter::unreadCount($userId),
                'items' => $items,
            ]);
        } catch (Throwable $e) {
            error_log('Notification list failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Notifications are temporarily unavailable.']);
        }
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

    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
}
