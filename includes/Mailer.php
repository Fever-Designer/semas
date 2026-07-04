<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Mailer
 * -------
 * Thin wrapper around PHPMailer. Every send is logged to email_logs
 * regardless of success/failure, satisfying the "verification status
 * tracking" / audit requirement. Requires `composer require phpmailer/phpmailer`
 * and real SMTP credentials in .env / see .env.example.
 */
final class Mailer
{
    private static bool $shutdownRegistered = false;

    private static function client(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = MAIL_PORT;
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        return $mail;
    }

    /** Renders templates/emails/{template}.php with $vars in scope and returns the HTML string. */
    private static function render(string $template, array $vars): string
    {
        $file = __DIR__ . '/../templates/emails/' . $template . '.php';
        if (!file_exists($file)) {
            throw new RuntimeException("Email template not found: $template");
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    public static function send(string $toEmail, string $subject, string $template, array $vars, ?int $userId = null): bool
    {
        $db = Database::connection();
        $logStmt = $db->prepare(
            'INSERT INTO email_logs (user_id, to_email, subject, template_name, status, created_at)
             VALUES (:uid, :to, :subj, :tpl, :status, NOW())'
        );

        try {
            $html = self::render($template, $vars + ['app_name' => APP_NAME]);
            $mail = self::client();
            $mail->addAddress($toEmail);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = strip_tags($html);
            $mail->send();

            $logStmt->execute(['uid' => $userId, 'to' => $toEmail, 'subj' => $subject, 'tpl' => $template, 'status' => 'Sent']);
            return true;
        } catch (PHPMailerException|Throwable $e) {
            $logStmt->execute(['uid' => $userId, 'to' => $toEmail, 'subj' => $subject, 'tpl' => $template, 'status' => 'Failed']);
            $db->prepare('UPDATE email_logs SET error_message = :err WHERE email_log_id = LAST_INSERT_ID()')
               ->execute(['err' => $e->getMessage()]);
            error_log('[Mailer] send failed: ' . $e->getMessage());
            return false;
        }
    }

    // --- Async queue helpers ---

    /** Insert an email into the send queue; the background worker delivers it. */
    public static function enqueue(string $toEmail, string $subject, string $template, array $vars, ?int $userId = null): void
    {
        $db = Database::connection();
        self::ensureEmailQueueTable($db);

        try {
            $db->prepare(
                'INSERT INTO email_queue (to_email, user_id, subject, template_name, vars_json)
                 VALUES (:email, :uid, :subj, :tpl, :vars)'
            )->execute([
                'email' => $toEmail,
                'uid'   => $userId,
                'subj'  => $subject,
                'tpl'   => $template,
                'vars'  => json_encode($vars),
            ]);
        } catch (PDOException $e) {
            $errorCode = $e->errorInfo[1] ?? null;
            if ($errorCode === 1146 || stripos($e->getMessage(), 'email_queue') !== false) {
                // If the queue table is missing, fallback to direct send so email delivery does not fail catastrophically.
                self::send($toEmail, $subject, $template, $vars, $userId);
                return;
            }
            throw $e;
        }
    }

    private static function ensureEmailQueueTable(PDO $db): void
    {
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS email_queue (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    to_email       VARCHAR(255)  NOT NULL,
    user_id        INT           NULL,
    subject        VARCHAR(500)  NOT NULL,
    template_name  VARCHAR(100)  NOT NULL,
    vars_json      MEDIUMTEXT    NOT NULL,
    status         ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    attempts       TINYINT       NOT NULL DEFAULT 0,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at   DATETIME      NULL,
    INDEX idx_status_attempts (status, attempts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
        );
    }

    public static function processQueue(int $limit = 200): void
    {
        $db = Database::connection();
        $rows = $db->prepare(
            'SELECT * FROM email_queue WHERE status = :status AND attempts < :max_att ORDER BY id ASC LIMIT :row_limit'
        );
        $rows->bindValue('status', 'pending', PDO::PARAM_STR);
        $rows->bindValue('max_att', 5, PDO::PARAM_INT);
        $rows->bindValue('row_limit', $limit, PDO::PARAM_INT);
        $rows->execute();

        foreach ($rows->fetchAll() as $row) {
            $db->prepare(
                'UPDATE email_queue SET status = :status, attempts = attempts + 1 WHERE id = :id AND status = :pending'
            )->execute(['status' => 'processing', 'id' => $row['id'], 'pending' => 'pending']);

            $vars = (array) json_decode((string) $row['vars_json'], true);
            $userId = $row['user_id'] !== null ? (int) $row['user_id'] : null;

            $ok = self::send(
                (string) $row['to_email'],
                (string) $row['subject'],
                (string) $row['template_name'],
                $vars,
                $userId
            );

            $db->prepare(
                'UPDATE email_queue SET status = :s, processed_at = NOW() WHERE id = :id'
            )->execute(['s' => $ok ? 'sent' : 'failed', 'id' => $row['id']]);
        }
    }

    /** Schedule queue processing after the response is sent (register_shutdown_function). */
    public static function dispatch(): void
    {
        if (self::$shutdownRegistered) return;
        self::$shutdownRegistered = true;
        register_shutdown_function(function () {
            // Flush output to client first if possible (Apache + mod_php)
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                self::processQueue();
            } catch (Throwable $e) {
                error_log('[Mailer] dispatch/processQueue failed: ' . $e->getMessage());
            }
        });
    }

    // --- Enqueue convenience wrappers (bulk sends / response returns before delivery) ---

    public static function enqueueAnnouncementNotification(array $user, array $announcement): void
    {
        self::enqueue(
            $user['email'],
            '[' . ($announcement['category'] ?? 'Announcement') . '] ' . $announcement['title'],
            'announcement_notification',
            ['full_name' => $user['full_name'], 'announcement' => $announcement],
            isset($user['user_id']) ? (int) $user['user_id'] : null
        );
    }

    public static function enqueueSemesterCalendar(array $user, array $calendar): void
    {
        $subject = 'Semester Calendar: ' . $calendar['semester_name'] . ' / Starts ' . date('d M Y', strtotime($calendar['start_date']));
        $userId  = isset($user['user_id']) ? (int) $user['user_id'] : null;
        self::enqueue($user['email'], $subject, 'semester_calendar', [
            'full_name'     => $user['full_name'],
            'academic_year' => $calendar['academic_year'],
            'intake'        => $calendar['intake'],
            'semester_name' => $calendar['semester_name'],
            'start_date'    => $calendar['start_date'],
            'end_date'      => $calendar['end_date'],
            'notes'         => $calendar['notes'] ?? '',
            'login_url'     => APP_URL . '/auth/login.php',
        ], $userId);

        $phone = $user['phone_number'] ?? $user['phone'] ?? '';
        if ($phone) {
            $wa = "Hi {$user['full_name']}, {$calendar['semester_name']} ({$calendar['academic_year']}) starts on " . date('d M Y', strtotime($calendar['start_date'])) . " and ends on " . date('d M Y', strtotime($calendar['end_date'])) . ". Log in to SEMAS to register for your modules. / SEMAS";
            WhatsApp::send($phone, $wa, $userId);
        }
    }

    public static function enqueueCatExamSchedule(array $user, array $schedule): void
    {
        $dayOfWeek = date('l', strtotime($schedule['scheduled_date']));
        $typeWord  = $schedule['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $userId    = isset($user['user_id']) ? (int) $user['user_id'] : null;
        self::enqueue($user['email'], 'Your ' . $typeWord . ' is on ' . $dayOfWeek . ' / ' . $schedule['module_title'], 'cat_exam_schedule', [
            'full_name'        => $user['full_name'],
            'exam_type'        => $schedule['exam_type'],
            'module_title'     => $schedule['module_title'],
            'scheduled_date'   => $schedule['scheduled_date'],
            'start_time'       => $schedule['start_time'],
            'end_time'         => $schedule['end_time'],
            'room'             => $schedule['room'],
            'invigilator_name' => $schedule['invigilator_name'],
            'day_of_week'      => $dayOfWeek,
        ], $userId);

        $phone = $user['phone_number'] ?? $user['phone'] ?? '';
        if ($phone) {
            $smsDate = date('d M Y', strtotime($schedule['scheduled_date']));
            $smsTime = date('h:i A', strtotime($schedule['start_time'])) . '-' . date('h:i A', strtotime($schedule['end_time']));
            $sms = "Hi {$user['full_name']}, your $typeWord for {$schedule['module_title']} is on $dayOfWeek, $smsDate at $smsTime. Room: {$schedule['room']}. Invigilator: {$schedule['invigilator_name']}. / SEMAS";
            Sms::send($phone, mb_substr($sms, 0, 160), $userId);
            WhatsApp::send($phone, $sms, $userId);
        }
    }

    public static function enqueueEligibilityDecision(array $user, array $schedule, string $finalDecision): void
    {
        $dayOfWeek = date('l', strtotime($schedule['scheduled_date']));
        $typeWord  = $schedule['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $userId    = isset($user['user_id']) ? (int) $user['user_id'] : null;
        $subject   = $finalDecision === 'Allowed'
            ? 'Your ' . $typeWord . ' eligibility is approved / Entry Card ready'
            : 'Your ' . $typeWord . ' eligibility decision has been made';

        self::enqueue($user['email'], $subject, 'cat_exam_eligibility_decision', [
            'full_name'        => $user['full_name'],
            'exam_type'        => $schedule['exam_type'],
            'module_title'     => $schedule['module_title'],
            'scheduled_date'   => $schedule['scheduled_date'],
            'start_time'       => $schedule['start_time'],
            'end_time'         => $schedule['end_time'],
            'room'             => $schedule['room'],
            'invigilator_name' => $schedule['invigilator_name'],
            'day_of_week'      => $dayOfWeek,
            'final_decision'   => $finalDecision,
        ], $userId);

        $phone = $user['phone_number'] ?? $user['phone'] ?? '';
        if ($phone) {
            $smsDate = date('d M Y', strtotime($schedule['scheduled_date']));
            if ($finalDecision === 'Allowed') {
                $sms = "Hi {$user['full_name']}, your $typeWord eligibility for {$schedule['module_title']} is APPROVED. Log in to SEMAS to print your Entry Card. Good luck! / SEMAS";
            } else {
                $sms = "Hi {$user['full_name']}, your $typeWord eligibility for {$schedule['module_title']} is NOT ALLOWED. Please visit your HoD office before $typeWord day ($smsDate). / SEMAS";
            }
            Sms::send($phone, mb_substr($sms, 0, 160), $userId);
            WhatsApp::send($phone, $sms, $userId);
        }
    }

    public static function enqueueInvigilatorAssigned(array $invigilator, array $schedule): void
    {
        $dayOfWeek = date('l', strtotime($schedule['scheduled_date']));
        $typeWord  = $schedule['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        self::enqueue($invigilator['email'], 'Invigilator Assignment: ' . $typeWord . ' / ' . $schedule['module_title'] . ' on ' . $dayOfWeek, 'invigilator_assigned', [
            'full_name'      => $invigilator['full_name'],
            'exam_type'      => $schedule['exam_type'],
            'module_title'   => $schedule['module_title'],
            'scheduled_date' => $schedule['scheduled_date'],
            'start_time'     => $schedule['start_time'],
            'end_time'       => $schedule['end_time'],
            'room'           => $schedule['room'],
            'day_of_week'    => $dayOfWeek,
        ], isset($invigilator['user_id']) ? (int) $invigilator['user_id'] : null);
    }

    public static function enqueueAssignmentNotification(array $student, array $assignment, array $module, string $lecturerName): void
    {
        $deadlineFormatted = date('d M Y, h:i A', strtotime($assignment['deadline']));
        $attachmentUrl = !empty($assignment['attachment_path'])
            ? rtrim(APP_URL, '/') . '/' . ltrim($assignment['attachment_path'], '/')
            : '';
        $userId = isset($student['user_id']) ? (int) $student['user_id'] : null;
        self::enqueue(
            $student['email'],
            'New Assignment: ' . $assignment['title'] . ' / ' . $module['module_title'],
            'assignment_notification',
            [
                'full_name'          => $student['full_name'],
                'assignment_title'   => $assignment['title'],
                'module_title'       => $module['module_title'],
                'lecturer_name'      => $lecturerName,
                'session_type'       => $module['session_type'] ?? 'N/A',
                'deadline_formatted' => $deadlineFormatted,
                'instructions'       => $assignment['instructions'] ?? '',
                'attachment_url'     => $attachmentUrl,
            ],
            $userId
        );

        $phone = $student['phone_number'] ?? $student['phone'] ?? '';
        if ($phone) {
            $sms = "Hi {$student['full_name']}, new assignment \"{$assignment['title']}\" for {$module['module_title']} is due $deadlineFormatted. Log in to SEMAS for details. / SEMAS";
            Sms::send($phone, mb_substr($sms, 0, 160), $userId);
            WhatsApp::send($phone, $sms, $userId);
        }
    }

    // --- Convenience wrappers for every email type listed in the spec ---

    public static function sendVerification(array $user, string $verifyUrl): bool
    {
        return self::send($user['email'], 'Verify your SEMAS account', 'verification', [
            'full_name' => $user['full_name'], 'verify_url' => $verifyUrl,
        ], (int) $user['user_id']);
    }

    public static function sendPasswordResetLink(array $user, string $resetUrl): bool
    {
        return self::send($user['email'], 'Reset your SEMAS password', 'password_reset_link', [
            'full_name' => $user['full_name'], 'reset_url' => $resetUrl,
        ], (int) $user['user_id']);
    }

    public static function sendOtp(array $user, string $code, string $purposeLabel): bool
    {
        return self::send($user['email'], "Your SEMAS verification code", 'otp', [
            'full_name' => $user['full_name'], 'code' => $code, 'purpose_label' => $purposeLabel,
        ], (int) $user['user_id']);
    }

    public static function sendRegistrationConfirmation(array $user): bool
    {
        return self::send($user['email'], 'Welcome to SEMAS', 'registration_confirmation', [
            'full_name' => $user['full_name'],
        ], (int) $user['user_id']);
    }

    public static function sendEventRegistrationConfirmation(array $user, array $event): bool
    {
        return self::send($user['email'], 'Event registration confirmed: ' . $event['title'], 'event_registration_confirmation', [
            'full_name' => $user['full_name'], 'event' => $event,
        ], (int) $user['user_id']);
    }

    public static function sendAttendanceConfirmation(array $user, array $event, string $checkinTime): bool
    {
        return self::send($user['email'], 'Attendance confirmed: ' . $event['title'], 'attendance_confirmation', [
            'full_name' => $user['full_name'], 'event' => $event, 'checkin_time' => $checkinTime,
        ], (int) $user['user_id']);
    }

    public static function sendAnnouncementNotification(array $user, array $announcement): bool
    {
        return self::send($user['email'], '[' . $announcement['category'] . '] ' . $announcement['title'], 'announcement_notification', [
            'full_name' => $user['full_name'], 'announcement' => $announcement,
        ], (int) $user['user_id']);
    }

    public static function sendPasswordChangedNotice(array $user): bool
    {
        return self::send($user['email'], 'Your SEMAS password was changed', 'password_changed', [
            'full_name' => $user['full_name'], 'changed_at' => date('Y-m-d H:i'),
        ], (int) $user['user_id']);
    }

    public static function sendAccountActivated(array $user): bool
    {
        return self::send($user['email'], 'Your SEMAS account is now active', 'account_activated', [
            'full_name' => $user['full_name'],
        ], (int) $user['user_id']);
    }

    public static function sendAccountDeactivated(array $user): bool
    {
        return self::send($user['email'], 'Your SEMAS account has been deactivated', 'account_deactivated', [
            'full_name' => $user['full_name'],
        ], (int) $user['user_id']);
    }

    public static function sendStaffAccountCreated(array $user, string $roleLabel, string $tempPassword, string $createdByName): bool
    {
        return self::send($user['email'], 'Your SEMAS ' . $roleLabel . ' account has been created', 'staff_account_created', [
            'full_name' => $user['full_name'], 'role_label' => $roleLabel,
            'temp_password' => $tempPassword, 'created_by_name' => $createdByName,
        ], (int) $user['user_id']);
    }

    /**
     * Notify a lecturer they have been assigned as invigilator for a CAT/Exam.
     * $schedule must contain: exam_type, module_title, scheduled_date, start_time, end_time, room
     */
    public static function sendInvigilatorAssigned(array $invigilator, array $schedule): bool
    {
        $dayOfWeek = date('l', strtotime($schedule['scheduled_date']));
        $typeWord  = $schedule['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $subject   = 'Invigilator Assignment: ' . $typeWord . ' / ' . $schedule['module_title'] . ' on ' . $dayOfWeek;
        return self::send($invigilator['email'], $subject, 'invigilator_assigned', [
            'full_name'      => $invigilator['full_name'],
            'exam_type'      => $schedule['exam_type'],
            'module_title'   => $schedule['module_title'],
            'scheduled_date' => $schedule['scheduled_date'],
            'start_time'     => $schedule['start_time'],
            'end_time'       => $schedule['end_time'],
            'room'           => $schedule['room'],
            'day_of_week'    => $dayOfWeek,
        ], (int) $invigilator['user_id']);
    }

    /**
     * Notify a student of their upcoming CAT or Exam schedule.
     * $schedule must contain: exam_type, module_title, scheduled_date, start_time, end_time, room, invigilator_name
     */
    public static function sendCatExamSchedule(array $user, array $schedule): bool
    {
        $dayOfWeek  = date('l', strtotime($schedule['scheduled_date']));
        $typeWord   = $schedule['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $subject    = 'Your ' . $typeWord . ' is on ' . $dayOfWeek . ' / ' . $schedule['module_title'];
        return self::send($user['email'], $subject, 'cat_exam_schedule', [
            'full_name'        => $user['full_name'],
            'exam_type'        => $schedule['exam_type'],
            'module_title'     => $schedule['module_title'],
            'scheduled_date'   => $schedule['scheduled_date'],
            'start_time'       => $schedule['start_time'],
            'end_time'         => $schedule['end_time'],
            'room'             => $schedule['room'],
            'invigilator_name' => $schedule['invigilator_name'],
            'day_of_week'      => $dayOfWeek,
        ], (int) $user['user_id']);
    }

    /**
     * Notify a student of a published semester calendar.
     * $calendar must contain: academic_year, intake, semester_name, start_date, end_date, notes
     */
    public static function sendSemesterCalendar(array $user, array $calendar): bool
    {
        $subject = 'Semester Calendar: ' . $calendar['semester_name'] . ' / Starts ' . date('d M Y', strtotime($calendar['start_date']));
        return self::send($user['email'], $subject, 'semester_calendar', [
            'full_name'     => $user['full_name'],
            'academic_year' => $calendar['academic_year'],
            'intake'        => $calendar['intake'],
            'semester_name' => $calendar['semester_name'],
            'start_date'    => $calendar['start_date'],
            'end_date'      => $calendar['end_date'],
            'notes'         => $calendar['notes'] ?? '',
            'login_url'     => APP_URL . '/auth/login.php',
        ], (int) $user['user_id']);
    }
}
