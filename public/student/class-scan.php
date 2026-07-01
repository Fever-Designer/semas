<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireRole(['Student']);

$qs = $_SERVER['QUERY_STRING'] ?? '';
redirect('/student/attendance.php' . ($qs !== '' ? '?' . $qs : ''));
