<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Coordinator']);
// Coordinator uses the shared HoD eligibility page — Weekend filter applied automatically via $isCoordinator
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ' . APP_URL . '/hod/eligibility.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
