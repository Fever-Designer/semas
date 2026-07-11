<?php
declare(strict_types=1);

final class Otp
{
    /** Generates a new 6-digit OTP for a user, stores its hash, and returns the
     *  PLAINTEXT code so the caller can send it by email/SMS. The plaintext is
     *  never stored / only password_hash() of it is. */
    public static function generate(int $userId, string $purpose, string $channel = 'email'): string
    {
        $code = (string) random_int(100000, 999999); // 6 digits, OTP_LENGTH
        $hash = password_hash($code, PASSWORD_BCRYPT);
        $expiryMinutes = (int) self::setting('otp_expiry_minutes', OTP_DEFAULT_EXPIRY_MINUTES);

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO otp_codes (user_id, purpose, code_hash, channel, max_attempts, expires_at)
             VALUES (:uid, :purpose, :hash, :channel, :max_attempts, DATE_ADD(NOW(), INTERVAL :mins MINUTE))'
        )->execute([
            'uid'          => $userId,
            'purpose'      => $purpose,
            'hash'         => $hash,
            'channel'      => $channel,
            'max_attempts' => OTP_MAX_ATTEMPTS,
            'mins'         => $expiryMinutes,
        ]);

        return $code;
    }

    /** Verifies a submitted OTP against the most recent unconsumed code for
     *  this user/purpose. Increments the attempt counter on failure and
     *  enforces the attempt limit. */
    public static function verify(int $userId, string $purpose, string $submittedCode): array
    {
        $db = Database::connection();
        $submittedCode = preg_replace('/[^0-9]/', '', $submittedCode) ?? '';
        if (!preg_match('/^[0-9]{6}$/', $submittedCode)) {
            return ['ok' => false, 'error' => 'Enter the complete 6-digit code.'];
        }
        $stmt = $db->prepare(
            'SELECT * FROM otp_codes
             WHERE user_id = :uid AND purpose = :purpose AND consumed_at IS NULL
             ORDER BY otp_id DESC'
        );
        $stmt->execute(['uid' => $userId, 'purpose' => $purpose]);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            return ['ok' => false, 'error' => 'No pending OTP found. Please request a new code.'];
        }

        $now = new DateTimeImmutable('now');
        $hasUnexpired = false;
        $hasAttemptsRemaining = false;
        foreach ($rows as $row) {
            if (new DateTimeImmutable((string) $row['expires_at']) < $now) continue;
            $hasUnexpired = true;
            if ((int) $row['attempts'] >= (int) $row['max_attempts']) continue;
            $hasAttemptsRemaining = true;
            if (password_verify($submittedCode, (string) $row['code_hash'])) {
                $db->prepare(
                    'UPDATE otp_codes SET consumed_at = NOW()
                     WHERE user_id = :uid AND purpose = :purpose AND consumed_at IS NULL'
                )->execute(['uid' => $userId, 'purpose' => $purpose]);
                return ['ok' => true, 'error' => null];
            }
        }

        if (!$hasUnexpired) {
            return ['ok' => false, 'error' => 'This code has expired. Please request a new one.'];
        }
        if (!$hasAttemptsRemaining) {
            return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
        }

        foreach ($rows as $row) {
            if (new DateTimeImmutable((string) $row['expires_at']) >= $now
                && (int) $row['attempts'] < (int) $row['max_attempts']) {
                $db->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE otp_id = :id')
                   ->execute(['id' => $row['otp_id']]);
                break;
            }
        }
        return ['ok' => false, 'error' => 'Incorrect code. Check the latest password-reset email and try again.'];
    }

    private static function setting(string $key, $default)
    {
        $stmt = Database::connection()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :k');
        $stmt->execute(['k' => $key]);
        $v = $stmt->fetchColumn();
        return $v !== false ? $v : $default;
    }
}
