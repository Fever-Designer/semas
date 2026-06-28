<?php
declare(strict_types=1);

/**
 * NotificationGenerator
 * -----------------------
 * Implements the rules supplied by the project owner: fixed category list,
 * fixed priority levels, fixed audience options, strict output schema, and
 * the required email structure (greeting, message, details, closing,
 * signature). This is a deterministic, template-based implementation —
 * the same approach used in the AI-prototype demo in Chapter Four — kept
 * in pure PHP so it runs inside the live application rather than calling
 * an external LLM API. Swap generate() for a real API call later if you
 * want model-generated wording instead of templated wording.
 */
final class NotificationGenerator
{
    public const CATEGORIES = ['Academic', 'Examination', 'Event', 'Registration', 'Scholarship',
        'Sports', 'General Notice', 'Emergency', 'Workshop', 'Career Opportunity'];
    public const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent'];
    public const AUDIENCES = ['All Students', 'First Year Students', 'Final Year Students',
        'Specific Department', 'Specific Faculty', 'Staff', 'Event Participants',
        'University Community', 'Day Students', 'Evening Students', 'Weekend Students'];

    public static function generate(array $input): array
    {
        $category = in_array($input['category'], self::CATEGORIES, true) ? $input['category'] : 'General Notice';
        $priority = in_array($input['priority'], self::PRIORITIES, true) ? $input['priority'] : 'Medium';
        $audience = in_array($input['target_audience'], self::AUDIENCES, true) ? $input['target_audience'] : 'All Students';

        $title = trim($input['title']);
        $content = trim($input['content']);
        $eventDate = $input['date'] ?? null;
        $venue = $input['venue'] ?? null;
        $deadline = $input['deadline'] ?? null;

        $greeting = ($audience === 'Staff') ? 'Dear Colleague,' : 'Dear Student,';

        $detailLines = [];
        if ($eventDate) $detailLines[] = "Date: $eventDate";
        if ($venue) $detailLines[] = "Venue: $venue";
        if ($deadline) $detailLines[] = "Deadline: $deadline";
        $detailsBlock = $detailLines ? implode("\n", $detailLines) . "\n\n" : '';

        $emailBody = "$greeting\n\n$content\n\n{$detailsBlock}"
            . "Please direct any questions to your department office or the SEMAS helpdesk.\n\n"
            . "Best regards,\nUniversity Administration\nUniversity of Kigali\nSEMAS Notification System";

        return [
            'title'           => $title,
            'category'        => $category,
            'priority'        => $priority,
            'target_audience' => $audience,
            'date'            => $eventDate,
            'venue'           => $venue,
            'content'         => $content,
            'email_subject'   => "[" . mb_strtoupper($category) . "] $title",
            'email_body'      => $emailBody,
            'created_at'      => date('Y-m-d H:i:s'),
        ];
    }

    public static function save(array $record, string $prompt, ?int $generatedByUser): int
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO ai_notifications
             (title, category, priority, target_audience, event_date, venue, content,
              email_subject, email_body, generated_by_prompt, generated_by_user, created_at)
             VALUES (:title, :category, :priority, :audience, :date, :venue, :content,
                     :subject, :body, :prompt, :uid, :created_at)'
        );
        $stmt->execute([
            'title'    => $record['title'], 'category' => $record['category'], 'priority' => $record['priority'],
            'audience' => $record['target_audience'], 'date' => $record['date'], 'venue' => $record['venue'],
            'content'  => $record['content'], 'subject' => $record['email_subject'], 'body' => $record['email_body'],
            'prompt'   => $prompt, 'uid' => $generatedByUser, 'created_at' => $record['created_at'],
        ]);
        return (int) $db->lastInsertId();
    }

    /** Publishes a saved AI notification as a real announcement and queues
     *  email/SMS delivery to the matching audience. */
    public static function publish(int $notificationId, array $publishedByUser, string $publishedByRole): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM ai_notifications WHERE notification_id = :id');
        $stmt->execute(['id' => $notificationId]);
        $n = $stmt->fetch();
        if (!$n) {
            throw new RuntimeException('Notification not found.');
        }

        // Route through the single shared Announcement::create() path so an
        // AI-generated announcement gets the exact same sender snapshot and
        // recipient-tracking (for the scoped Announcement Board) as every
        // other send path — never a second, drifted implementation.
        $result = Announcement::create([
            'title' => $n['title'], 'category' => $n['category'], 'priority' => $n['priority'],
            'target_audience' => $n['target_audience'], 'message' => $n['content'], 'status' => 'Published',
        ], $publishedByUser, $publishedByRole, null, false);

        $db->prepare('UPDATE ai_notifications SET published_announcement_id = :aid WHERE notification_id = :id')
           ->execute(['aid' => $result['announcement_id'], 'id' => $notificationId]);
    }
}
