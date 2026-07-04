<?php
declare(strict_types=1);

/**
 * Announcement
 * -------------
 * Single source of truth for CREATING an announcement and for rendering
 * the "Sent by / Role / Date / Time" block required on every announcement
 * page. Every role-specific send page (admin/events.php, hod/announcements.php,
 * dean/announcements.php) MUST create rows through self::create() instead of
 * writing its own INSERT, so:
 *   1. The sender's full name/role/scope are permanently snapshotted at
 *      send-time (renaming or deactivating the sender later never changes
 *      what historical announcements display).
 *   2. Delivery (in-app + email + optional SMS) and the audit log entry
 *      always happen, in the same order, for every announcement in the
 *      system / not re-implemented (and potentially forgotten) per page.
 */
final class Announcement
{
    /**
     * @param array $data Expected keys: title, category, priority, message,
     *                     target_audience, department_id (nullable),
     *                     faculty_id (nullable), event_id (nullable),
     *                     status ('Draft'|'Published', default Published).
     * @param array $sender Full row from users (Auth::user()).
     * @param string $senderRole Auth::role() at send time.
     * @param string|null $scopeLabel Human-readable scope, e.g. "Department of Information Technology".
     * @return array{announcement_id:int, recipients:int}
     */
    public static function create(array $data, array $sender, string $senderRole, ?string $scopeLabel): array
    {
        $db = Database::connection();
        $status = $data['status'] ?? 'Published';

        $db->prepare(
            'INSERT INTO announcements
                (event_id, title, category, priority, target_audience, department_id, faculty_id,
                 message, status, posted_by, sender_name, sender_role, sender_scope)
             VALUES
                (:event_id, :title, :category, :priority, :audience, :dept, :faculty,
                 :message, :status, :uid, :sname, :srole, :sscope)'
        )->execute([
            'event_id' => $data['event_id'] ?: null,
            'title'    => trim($data['title']),
            'category' => $data['category'],
            'priority' => $data['priority'],
            'audience' => $data['target_audience'],
            'dept'     => $data['department_id'] ?: null,
            'faculty'  => $data['faculty_id'] ?: null,
            'message'  => trim($data['message']),
            'status'   => $status,
            'uid'      => (int) $sender['user_id'],
            'sname'    => $sender['full_name'],
            'srole'    => $senderRole,
            'sscope'   => $scopeLabel,
        ]);
        $announcementId = (int) $db->lastInsertId();

        $reached = 0;
        if ($status === 'Published') {
            $stmt = $db->prepare('SELECT * FROM announcements WHERE announcement_id = :id');
            $stmt->execute(['id' => $announcementId]);
            $row = $stmt->fetch();
            $row['event_id'] = $data['event_id'] ?: null; // for AudienceResolver's Event Participants branch

            // HOD/Dean/Lecturer role-scoped sends pass a pre-resolved recipient list
            // (so a Dean can never accidentally reach another faculty's
            // students even if target_audience looks generic); Principal
            // sends fall back to Delivery::announce's full AudienceResolver.
            if (isset($data['recipients']) && is_array($data['recipients'])) {
                $recipients = $data['recipients'];
                self::deliverTo($recipients, $row);
            } else {
                $recipients = Delivery::announce($row);
            }
            $reached = count($recipients);
            self::persistRecipients($announcementId, $recipients);

            $db->prepare('UPDATE announcements SET recipients_count = :c, sms_sent = 1 WHERE announcement_id = :id')
               ->execute(['c' => $reached, 'id' => $announcementId]);
        }

        AuditLog::record((int) $sender['user_id'], 'CREATE_ANNOUNCEMENT', 'announcements', $announcementId,
            "audience={$data['target_audience']}; status=$status; role=$senderRole");

        return ['announcement_id' => $announcementId, 'recipients' => $reached];
    }

    /** Deliver to an explicit, pre-scoped recipient list (used by HOD/Dean/Lecturer send pages). */
    private static function deliverTo(array $recipients, array $announcement): void
    {
        $uniName    = Settings::get('university_name', 'University of Kigali');
        $senderName = $announcement['sender_name'] ?? 'SEMAS';

        foreach ($recipients as $user) {
            NotificationCenter::notify((int) $user['user_id'], $announcement['title'], $announcement['message'], 'Announcement', $announcement['announcement_id']);
            Mailer::enqueueAnnouncementNotification($user, $announcement);

            if (!empty($user['phone_number'])) {
                $waText = WhatsApp::formatAnnouncement(
                    $announcement['title'],
                    $announcement['message'],
                    $senderName,
                    $uniName
                );
                WhatsApp::send($user['phone_number'], $waText, (int) $user['user_id']);

                Sms::send($user['phone_number'], $announcement['title'] . ': ' . mb_substr($announcement['message'], 0, 100), (int) $user['user_id']);
            }
        }
        Mailer::dispatch();
    }

    /** Permanently erases announcements older than 1 week from the Board (and, via
     *  ON DELETE CASCADE, their announcement_recipients rows). Called lazily at the
     *  top of the pages that list announcements, the same way Module::autoCompleteExpired()
     *  sweeps expired modules / no real cron job required. */
    public static function purgeExpired(): void
    {
        Database::connection()->exec(
            "DELETE FROM announcements WHERE posted_at < NOW() - INTERVAL 7 DAY"
        );
    }

    /** Gives a newly-created/imported student visibility of still-live (published, not
     *  yet expired) announcements whose audience they now match / posted before they had
     *  an account and so never got an announcement_recipients row through the normal send
     *  flow. Backfills BOTH the Announcement Board listing and a bell notification for each,
     *  so the student is actively alerted rather than having to think to check the Board. */
    public static function backfillForNewStudent(int $userId, ?int $departmentId, ?string $sessionType, ?int $yearOfStudy): void
    {
        $db = Database::connection();

        $facultyId = null;
        if ($departmentId) {
            $f = $db->prepare('SELECT faculty_id FROM departments WHERE department_id = :id');
            $f->execute(['id' => $departmentId]);
            $facultyId = $f->fetchColumn() ?: null;
        }

        $stmt = $db->prepare(
            "SELECT announcement_id, title, message FROM announcements
             WHERE status = 'Published' AND posted_at >= NOW() - INTERVAL 7 DAY
               AND (
                    target_audience = 'All Students'
                 OR target_audience = 'University Community'
                 OR (target_audience = 'First Year Students'  AND :year1 = 1)
                 OR (target_audience = 'Final Year Students'  AND :year2 >= 4)
                 OR (target_audience = 'Specific Department'  AND department_id = :dept)
                 OR (target_audience = 'Specific Faculty'     AND faculty_id = :faculty)
                 OR (target_audience = 'Day Students'         AND :session1 = 'Day')
                 OR (target_audience = 'Evening Students'     AND :session2 = 'Evening')
                 OR (target_audience = 'Weekend Students'     AND :session3 = 'Weekend')
               )
             ORDER BY posted_at ASC"
        );
        $stmt->execute([
            'year1' => $yearOfStudy, 'year2' => $yearOfStudy,
            'dept' => $departmentId, 'faculty' => $facultyId,
            'session1' => $sessionType, 'session2' => $sessionType, 'session3' => $sessionType,
        ]);
        $announcements = $stmt->fetchAll();
        if (!$announcements) {
            return;
        }

        $ins = $db->prepare('INSERT IGNORE INTO announcement_recipients (announcement_id, user_id) VALUES (:aid, :uid)');
        foreach ($announcements as $a) {
            $ins->execute(['aid' => (int) $a['announcement_id'], 'uid' => $userId]);
            if ($ins->rowCount() > 0) {
                NotificationCenter::notify($userId, $a['title'], $a['message'], 'Announcement', (int) $a['announcement_id']);
            }
        }
    }

    /** Records exactly who an announcement was sent to, so the Announcement Board can be scoped per-viewer. */
    private static function persistRecipients(int $announcementId, array $recipients): void
    {
        if (!$recipients) {
            return;
        }
        $db = Database::connection();
        $stmt = $db->prepare('INSERT IGNORE INTO announcement_recipients (announcement_id, user_id) VALUES (:aid, :uid)');
        foreach ($recipients as $user) {
            $stmt->execute(['aid' => $announcementId, 'uid' => (int) $user['user_id']]);
        }
    }

    /** Renders the exact "Sent by / Role / Date / Time" block specified for every announcement page. */
    public static function renderBlock(array $a): string
    {
        $date = date('d F Y', strtotime($a['posted_at']));
        $time = date('h:i A', strtotime($a['posted_at']));
        $html  = '<div class="announcement-sentby">';
        $html .= '<div class="text-center text-muted small mb-2">University of Kigali</div>';
        $html .= '<h6 class="display-font text-center mb-3">' . ($a['priority'] === 'Urgent' ? 'Important Announcement' : e($a['title'])) . '</h6>';
        if ($a['priority'] === 'Urgent') {
            $html .= '<p class="fw-semibold text-center mb-3">' . e($a['title']) . '</p>';
        }
        $html .= '<p style="white-space:pre-wrap;">' . e($a['message']) . '</p>';
        $html .= '<hr>';
        $html .= '<div class="row small">';
        $html .= '<div class="col-6"><span class="text-muted">Sent by:</span><br><strong>' . e($a['sender_name'] ?? '') . '</strong></div>';
        $html .= '<div class="col-6"><span class="text-muted">Role:</span><br><strong>' . e($a['sender_role'] ?? '') . '</strong>' . ($a['sender_scope'] ? '<br><span class="text-muted">' . e($a['sender_scope']) . '</span>' : '') . '</div>';
        $html .= '<div class="col-6 mt-2"><span class="text-muted">Date:</span><br>' . e($date) . '</div>';
        $html .= '<div class="col-6 mt-2"><span class="text-muted">Time:</span><br>' . e($time) . '</div>';
        $html .= '</div></div>';
        return $html;
    }

    /** Short string for use in flash messages / confirmation toasts. */
    public static function senderSignature(array $sender, string $role, ?string $scope): string
    {
        $line = $sender['full_name'] . "\n" . $role;
        if ($scope) {
            $line .= "\n" . $scope;
        }
        $line .= "\n" . date('d F Y / h:i A');
        return $line;
    }
}
