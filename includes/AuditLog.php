<?php
declare(strict_types=1);

final class AuditLog
{
    public static function record(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address)
             VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip)'
        );
        $stmt->execute([
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'details'     => $details,
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }
}
