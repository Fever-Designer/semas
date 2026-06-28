<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
// The Analytics Dashboard was merged into the main Dashboard (public/dashboard.php),
// which is now the single unified entry point for every role. This file is kept
// only so old links/bookmarks to /analytics/dashboard.php keep working.
redirect('/dashboard.php');
