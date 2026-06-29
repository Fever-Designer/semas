<?php
declare(strict_types=1);
/**
 * Background email queue runner.
 * Launched non-blocking via Mailer::dispatch(); do NOT call from a web request.
 *
 *   php scripts/process-email-queue.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

Mailer::processQueue();
exit(0);
