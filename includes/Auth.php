<?php
declare(strict_types=1);

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
            session_start();
        }
    }

    public static function attempt(string $email, string $password): array
    {
        // Returns ['ok' => bool, 'user' => array|null, 'error' => string|null]
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT u.*, r.role_name FROM users u
             JOIN roles r ON r.role_id = u.role_id
             WHERE u.email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['ok' => false, 'user' => null, 'error' => 'Invalid email or password.'];
        }
        if ($user['status'] === 'Pending') {
            return ['ok' => false, 'user' => null, 'error' => 'Please verify your email before logging in.'];
        }
        if ($user['status'] === 'Deactivated') {
            return ['ok' => false, 'user' => null, 'error' => 'Your account has been deactivated. Contact the administrator.'];
        }
        return ['ok' => true, 'user' => $user, 'error' => null];
    }

    public static function login(array $user): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['user_id'];
        $_SESSION['role']      = $user['role_name'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email']     = $user['email'];

        $db = Database::connection();
        $db->prepare('UPDATE users SET last_login_at = NOW() WHERE user_id = :id')
           ->execute(['id' => $user['user_id']]);

        AuditLog::record((int) $user['user_id'], 'LOGIN');
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
}
