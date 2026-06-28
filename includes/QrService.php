<?php
declare(strict_types=1);

/**
 * QrService
 * ----------
 * Each event gets its own random secret (events.qr_secret, generated at
 * event creation — see EventController::create()). The QR code encodes a
 * JSON payload {event_id, exp} that is:
 *   1. Encrypted with AES-256-CBC using a key derived from the event secret
 *      (so the payload is not human-readable if the QR is photographed).
 *   2. HMAC-SHA256 signed with the same secret, so the server can detect
 *      any tampering (anti-forgery) before trusting event_id/exp.
 *   3. Time-limited via an "exp" (expiry) claim, independent of the
 *      events.qr_expires_at column, which is used to gate whether a NEW
 *      QR may still be displayed/regenerated for that event.
 *
 * The string actually rendered into the QR image is:
 *      base64url(iv) . '.' . base64url(ciphertext) . '.' . base64url(hmac)
 */
final class QrService
{
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32)); // 64 hex chars
    }

    public static function buildPayload(int $eventId, string $eventSecret, int $ttlSeconds = 21600): string
    {
        $payload = json_encode([
            'event_id' => $eventId,
            'exp'      => time() + $ttlSeconds,
            'nonce'    => bin2hex(random_bytes(8)),
        ]);

        $key = hash('sha256', $eventSecret, true); // 32-byte key for AES-256
        $iv  = random_bytes(16);
        $cipher = openssl_encrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac('sha256', $iv . $cipher, $eventSecret, true);

        return self::b64url($iv) . '.' . self::b64url($cipher) . '.' . self::b64url($hmac);
    }

    /** Verifies and decodes a scanned QR string against the event's stored
     *  secret. Returns ['ok' => bool, 'data' => array|null, 'error' => string|null]. */
    public static function verifyPayload(string $token, string $eventSecret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return ['ok' => false, 'data' => null, 'error' => 'Malformed QR code.'];
        }
        [$ivB64, $cipherB64, $hmacB64] = $parts;
        $iv = self::unb64url($ivB64);
        $cipher = self::unb64url($cipherB64);
        $hmac = self::unb64url($hmacB64);
        if ($iv === false || $cipher === false || $hmac === false) {
            return ['ok' => false, 'data' => null, 'error' => 'Malformed QR code.'];
        }

        $expectedHmac = hash_hmac('sha256', $iv . $cipher, $eventSecret, true);
        if (!hash_equals($expectedHmac, $hmac)) {
            return ['ok' => false, 'data' => null, 'error' => 'QR signature invalid — possible tampering.'];
        }

        $key = hash('sha256', $eventSecret, true);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            return ['ok' => false, 'data' => null, 'error' => 'Unable to decrypt QR payload.'];
        }

        $data = json_decode($plain, true);
        if (!is_array($data) || !isset($data['event_id'], $data['exp'])) {
            return ['ok' => false, 'data' => null, 'error' => 'Malformed QR payload.'];
        }
        if ((int) $data['exp'] < time()) {
            return ['ok' => false, 'data' => null, 'error' => 'This QR code has expired.'];
        }

        return ['ok' => true, 'data' => $data, 'error' => null];
    }

    /** Same scheme as buildPayload()/verifyPayload() above, but for class_sessions (Class Attendance)
     *  instead of events — kept as separate methods so the payload's 'session_id' vs 'event_id' key
     *  can never be confused/mixed up by a caller. */
    public static function buildSessionPayload(int $sessionId, string $sessionSecret, int $ttlSeconds = 3600): string
    {
        $payload = json_encode([
            'session_id' => $sessionId,
            'exp'        => time() + $ttlSeconds,
            'nonce'      => bin2hex(random_bytes(8)),
        ]);

        $key = hash('sha256', $sessionSecret, true);
        $iv  = random_bytes(16);
        $cipher = openssl_encrypt($payload, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac('sha256', $iv . $cipher, $sessionSecret, true);

        return self::b64url($iv) . '.' . self::b64url($cipher) . '.' . self::b64url($hmac);
    }

    public static function verifySessionPayload(string $token, string $sessionSecret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return ['ok' => false, 'data' => null, 'error' => 'Malformed QR code.'];
        }
        [$ivB64, $cipherB64, $hmacB64] = $parts;
        $iv = self::unb64url($ivB64);
        $cipher = self::unb64url($cipherB64);
        $hmac = self::unb64url($hmacB64);
        if ($iv === false || $cipher === false || $hmac === false) {
            return ['ok' => false, 'data' => null, 'error' => 'Malformed QR code.'];
        }

        $expectedHmac = hash_hmac('sha256', $iv . $cipher, $sessionSecret, true);
        if (!hash_equals($expectedHmac, $hmac)) {
            return ['ok' => false, 'data' => null, 'error' => 'QR signature invalid — possible tampering.'];
        }

        $key = hash('sha256', $sessionSecret, true);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            return ['ok' => false, 'data' => null, 'error' => 'Unable to decrypt QR payload.'];
        }

        $data = json_decode($plain, true);
        if (!is_array($data) || !isset($data['session_id'], $data['exp'])) {
            return ['ok' => false, 'data' => null, 'error' => 'Malformed QR payload.'];
        }
        if ((int) $data['exp'] < time()) {
            return ['ok' => false, 'data' => null, 'error' => 'This QR code has expired.'];
        }

        return ['ok' => true, 'data' => $data, 'error' => null];
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** @return string|false */
    private static function unb64url(string $b64)
    {
        $b64 = strtr($b64, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($b64, true);
    }
}
