<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = Database::connection();

$newPassword = password_hash("admin123", PASSWORD_BCRYPT);

$stmt = $db->prepare("
    UPDATE users 
    SET password_hash = ?
    WHERE email = 'admin@semas.com'
");

$stmt->execute([$newPassword]);

echo "Admin password updated successfully!";