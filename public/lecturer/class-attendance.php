<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
Auth::requireTeachingAccess();

// Preserve old bookmarks while keeping live-session.php as the single
// lecturer attendance implementation.
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
header('Location: ' . APP_URL . '/lecturer/live-session.php' . ($query !== '' ? '?' . $query : ''));
exit;
