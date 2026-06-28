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
 * and real SMTP credentials in .env — see .env.example.
 */
final class Mailer
{
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
     * Notify a student of their upcoming CAT or Exam schedule.
     * $schedule must contain: exam_type, module_title, scheduled_date, start_time, end_time, room, invigilator_name
     */
    public static function sendCatExamSchedule(array $user, array $schedule): bool
    {
        $dayOfWeek  = date('l', strtotime($schedule['scheduled_date']));
        $typeWord   = $schedule['exam_type'] === 'Exam' ? 'Exam' : 'CAT';
        $subject    = 'Your ' . $typeWord . ' is on ' . $dayOfWeek . ' — ' . $schedule['module_title'];
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
}
