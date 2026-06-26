<?php
declare(strict_types=1);
/**
 * cron/send_reminders.php
 * --------------------------
 * Run this every 5–10 minutes via cron (Linux) or Task Scheduler (Windows/XAMPP):
 *   php /path/to/semas/cron/send_reminders.php
 *
 * Sends three reminder stages per event, to every student with a 'Registered'
 * (not 'Cancelled'/'Waitlisted') registration:
 *   - 24h before event start
 *   - 1h before event start
 *   - at event start
 * Each (event, user, stage) combination is recorded in event_reminders_sent
 * so re-running this script never double-sends.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = Database::connection();
$now = new DateTime();

$stages = [
    '24h'   => ['window_minutes' => 60, 'offset_minutes' => 24 * 60],
    '1h'    => ['window_minutes' => 15, 'offset_minutes' => 60],
    'start' => ['window_minutes' => 10, 'offset_minutes' => 0],
];

$events = $db->query(
    "SELECT * FROM events WHERE status IN ('Scheduled','Ongoing') AND event_date >= CURDATE() - INTERVAL 1 DAY"
)->fetchAll();

$sentTotal = 0;

foreach ($events as $event) {
    $eventStart = new DateTime($event['event_date'] . ' ' . $event['start_time']);

    foreach ($stages as $stageName => $cfg) {
        $target = (clone $eventStart)->modify('-' . $cfg['offset_minutes'] . ' minutes');
        $diffMinutes = abs(($now->getTimestamp() - $target->getTimestamp()) / 60);
        if ($diffMinutes > $cfg['window_minutes']) {
            continue; // not in this stage's firing window right now
        }

        $regStmt = $db->prepare(
            "SELECT u.* FROM event_registrations er
             JOIN users u ON u.user_id = er.user_id
             WHERE er.event_id = :eid AND er.status = 'Registered' AND u.status = 'Active'"
        );
        $regStmt->execute(['eid' => $event['event_id']]);
        $registrants = $regStmt->fetchAll();

        foreach ($registrants as $student) {
            $already = $db->prepare(
                'SELECT 1 FROM event_reminders_sent WHERE event_id = :eid AND user_id = :uid AND reminder_type = :stage'
            );
            $already->execute(['eid' => $event['event_id'], 'uid' => $student['user_id'], 'stage' => $stageName]);
            if ($already->fetchColumn()) {
                continue; // already sent — never duplicate
            }

            $label = ['24h' => 'in 24 hours', '1h' => 'in 1 hour', 'start' => 'starting now'][$stageName];
            $title = $event['title'] . ' ' . $label;
            $body = 'Reminder: "' . $event['title'] . '" at ' . $event['venue'] . ' is ' . $label . '.';

            NotificationCenter::notify((int) $student['user_id'], $title, $body, 'Event');
            Mailer::send($student['email'], 'Event Reminder: ' . $event['title'], 'event_reminder', [
                'full_name' => $student['full_name'], 'event' => $event, 'label' => $label,
            ], (int) $student['user_id']);

            $db->prepare(
                'INSERT INTO event_reminders_sent (event_id, user_id, reminder_type) VALUES (:eid, :uid, :stage)'
            )->execute(['eid' => $event['event_id'], 'uid' => $student['user_id'], 'stage' => $stageName]);

            $sentTotal++;
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Event reminders processed. Sent: $sentTotal\n";
