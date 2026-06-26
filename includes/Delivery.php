<?php
declare(strict_types=1);

/**
 * Delivery
 * ---------
 * Fans an announcement/message out across all three channels (in-app
 * notification, email, SMS) to whatever audience AudienceResolver returns.
 * Centralizing this means a targeting bug can only exist in one place
 * (AudienceResolver), not be re-introduced per call site.
 */
final class Delivery
{
    /** @return int number of recipients reached */
    public static function announce(array $announcement, bool $sendSms = false): int
    {
        $recipients = AudienceResolver::resolve(
            $announcement['target_audience'],
            $announcement['department_id'] ?? null,
            $announcement['faculty_id'] ?? null,
            $announcement['event_id'] ?? null
        );

        foreach ($recipients as $user) {
            NotificationCenter::notify(
                (int) $user['user_id'],
                $announcement['title'],
                $announcement['message'],
                'Announcement',
                $announcement['announcement_id'] ?? null
            );
            Mailer::sendAnnouncementNotification($user, $announcement);
            if ($sendSms && !empty($user['sms_opt_in']) && !empty($user['phone_number'])) {
                Sms::send($user['phone_number'], $announcement['title'] . ': ' . mb_substr($announcement['message'], 0, 100), (int) $user['user_id']);
            }
        }
        return count($recipients);
    }
}
