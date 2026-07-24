<?php
declare(strict_types=1);

final class Auth
{
    public static function start(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            session_set_cookie_params([
                'httponly' => true,
                'secure' => $secure,
                'samesite' => 'Lax',
                'path' => '/',
            ]);
            session_start();
        }
        if (!empty($_SESSION['user_id'])) {
            $now = time();
            $createdAt = (int) ($_SESSION['created_at'] ?? $now);
            $lastActivity = (int) ($_SESSION['last_activity'] ?? $now);
            if (($now - $lastActivity) > SESSION_IDLE_TIMEOUT_SECONDS
                || ($now - $createdAt) > SESSION_ABSOLUTE_TIMEOUT_SECONDS) {
                $_SESSION = [];
                session_destroy();
                return;
            }
            $_SESSION['created_at'] = $createdAt;
            $_SESSION['last_activity'] = $now;
        }
    }

    public static function attempt(string $email, string $password): array
    {
        // Returns ['ok' => bool, 'user' => array|null, 'error' => string|null]
        // Accepts email OR registration number as the first credential.
        $db = Database::connection();
        $identifierHash = hash('sha256', mb_strtolower(trim($email), 'UTF-8'));
        $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
        $db->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
        $limit = $db->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE identifier_hash = :identifier_hash
               AND ip_address = :ip_address
               AND attempted_at >= NOW() - INTERVAL 15 MINUTE'
        );
        $limit->execute(['identifier_hash' => $identifierHash, 'ip_address' => $ipAddress]);
        if ((int) $limit->fetchColumn() >= 5) {
            return [
                'ok' => false,
                'user' => null,
                'error' => 'Too many failed login attempts. Wait 15 minutes before trying again.',
            ];
        }
        $stmt = $db->prepare(
            'SELECT u.*, r.role_name FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE (u.email = :email OR u.reg_number = :reg) LIMIT 1'
        );
        $stmt->execute(['email' => $email, 'reg' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $db->prepare(
                'INSERT INTO login_attempts (identifier_hash, ip_address) VALUES (:identifier_hash, :ip_address)'
            )->execute(['identifier_hash' => $identifierHash, 'ip_address' => $ipAddress]);
            return ['ok' => false, 'user' => null, 'error' => 'Invalid email or password.'];
        }
        if ($user['status'] === 'Pending') {
            return ['ok' => false, 'user' => null, 'error' => 'Please verify your email before logging in.'];
        }
        if ($user['status'] === 'Deactivated') {
            return ['ok' => false, 'user' => null, 'error' => 'Your account has been deactivated. Contact the Principal.'];
        }
        $db->prepare(
            'DELETE FROM login_attempts WHERE identifier_hash = :identifier_hash AND ip_address = :ip_address'
        )->execute(['identifier_hash' => $identifierHash, 'ip_address' => $ipAddress]);
        return ['ok' => true, 'user' => $user, 'error' => null];
    }

    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id']              = (int) $user['user_id'];
        $_SESSION['role']                 = $user['role_name'];
        $_SESSION['full_name']            = $user['full_name'];
        $_SESSION['email']                = $user['email'];
        $_SESSION['must_change_password'] = (bool) ($user['must_change_password'] ?? false);
        $_SESSION['created_at']            = time();
        $_SESSION['last_activity']         = time();
        $_SESSION['last_auth_at']          = time();

        $db = Database::connection();
        $db->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = :id')
           ->execute(['id' => $user['user_id']]);

        AuditLog::record((int) $user['user_id'], 'LOGIN');
    }

    /** Returns true if the logged-in user must change their password before accessing anything.
     *  Reads the DB fresh rather than the session-cached flag set at login / the session value
     *  can go stale (e.g. a request that updates the DB but doesn't finish syncing the session),
     *  and a stale "true" here against an up-to-date "false" in change-password.php's own DB
     *  check produces an infinite redirect loop between this page and /dashboard.php. */
    public static function mustChangePassword(): bool
    {
        self::start();
        if (!self::check()) {
            return false;
        }
        $stmt = Database::connection()->prepare('SELECT must_change_password FROM users WHERE user_id = :id');
        $stmt->execute(['id' => self::id()]);
        return (bool) $stmt->fetchColumn();
    }

    /** Call at the top of any protected page to enforce the first-login password change. */
    public static function enforceMustChangePassword(): void
    {
        if (self::check() && self::mustChangePassword()) {
            // Avoid infinite redirect loop on the change-password page itself
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            if (!str_ends_with($script, 'change-password.php') && !str_ends_with($script, 'logout.php')) {
                header('Location: ' . APP_URL . '/auth/change-password.php');
                exit;
            }
        }
    }

    public static function logout(): void
    {
        self::start();
        AuditLog::record(self::id(), 'LOGOUT');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        self::start();
        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        self::start();
        return $_SESSION['user_id'] ?? null;
    }

    public static function role(): ?string
    {
        self::start();
        return $_SESSION['role'] ?? null;
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE user_id = :id');
        $stmt->execute(['id' => self::id()]);
        $u = $stmt->fetch();
        return $u ?: null;
    }

    /** Call at the top of any protected page. Redirects to login if not authenticated,
     *  or to a 403 page if authenticated but role is not permitted. */
    public static function requireRole(array $allowedRoles): void
    {
        self::start();
        if (!self::check()) {
            header('Location: ' . APP_URL . '/auth/login.php');
            exit;
        }
        if (!in_array(self::role(), $allowedRoles, true)) {
            http_response_code(403);
            echo '<h1>403 Forbidden</h1><p>Your role does not have access to this page.</p>';
            exit;
        }
    }

    /**
     * True for actual Lecturers, and for HOD/Coordinator staff who have been
     * assigned to teach at least one ongoing module. Used to expose the
     * "My Teaching" pages/nav to staff who teach, and hide it again once
     * they have no ongoing module assigned to them.
     */
    public static function canAccessTeaching(): bool
    {
        if (!self::check()) {
            return false;
        }
        $role = self::role();
        if ($role === 'Lecturer') {
            return true;
        }
        if (!in_array($role, ['HOD', 'Coordinator'], true)) {
            return false;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT 1 FROM lecturers l
             LEFT JOIN modules m ON m.lecturer_id = l.lecturer_id AND m.status = 'Ongoing'
             LEFT JOIN cat_exam_schedules cs ON cs.invigilator_id = l.lecturer_id
             WHERE l.user_id = :uid
               AND (m.module_id IS NOT NULL OR cs.schedule_id IS NOT NULL)
             LIMIT 1"
        );
        $stmt->execute(['uid' => self::id()]);
        return (bool) $stmt->fetchColumn();
    }

    /** Returns true when the sidebar should show the teaching navigation. */
    public static function canAccessTeachingMenu(): bool
    {
        if (!self::check()) {
            return false;
        }
        $role = self::role();
        if ($role === 'Lecturer') {
            return true;
        }
        if (!in_array($role, ['HOD', 'Coordinator'], true)) {
            return false;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            "SELECT 1 FROM lecturers l
             JOIN modules m ON m.lecturer_id = l.lecturer_id AND m.status = 'Ongoing'
             WHERE l.user_id = :uid
             LIMIT 1"
        );
        $stmt->execute(['uid' => self::id()]);
        return (bool) $stmt->fetchColumn();
    }

    /** Call at the top of "My Teaching" pages instead of requireRole(['Lecturer']). */
    public static function requireTeachingAccess(): void
    {
        self::start();
        if (!self::check()) {
            header('Location: ' . APP_URL . '/auth/login.php');
            exit;
        }
        if (!self::canAccessTeaching()) {
            http_response_code(403);
            echo '<h1>403 Forbidden</h1><p>Your role does not have access to this page.</p>';
            exit;
        }
    }
}
