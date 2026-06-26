<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
redirect(Auth::check() ? '/dashboard.php' : '/auth/login.php');
