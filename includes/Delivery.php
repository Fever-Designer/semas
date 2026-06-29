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
    /** @return array the list of recipient user rows actually reached */
    public static function announce(array $announcement, bool $sendSms = false): array
    {
        $recipients = AudienceResolver::resolve(
            $announcement['target_audience'],
            $announcement['department_id'] ?? null,
            $announcement['faculty_id'] ?? null,
            $announcement['event_id'] ?? null
        );

        $uniName    = Settings::get('university_name', 'University of Kigali');
        $senderName = $announcement['sender_name'] ?? 'SEMAS';

        foreach ($recipients as $user) {
            NotificationCenter::notify(
                (int) $user['user_id'],
                $announcement['title'],
                $announcement['message'],
                'Announcement',
                $announcement['announcement_id'] ?? null
            );
            Mailer::enqueueAnnouncementNotification($user, $announcement);

            if (!empty($user['phone_number'])) {
                // WhatsApp — sent to every user who has a phone number
                $waText = WhatsApp::formatAnnouncement(
                    $announcement['title'],
                    $announcement['message'],
                    $senderName,
                    $uniName
                );
                WhatsApp::send($user['phone_number'], $waText, (int) $user['user_id']);

                // SMS — only when the sender checked "Also send via SMS" AND user opted in
                if ($sendSms && !empty($user['sms_opt_in'])) {
                    Sms::send($user['phone_number'], $announcement['title'] . ': ' . mb_substr($announcement['message'], 0, 100), (int) $user['user_id']);
                }
            }
        }
        Mailer::dispatch();
        return $recipients;
    }
}
