<?php
declare(strict_types=1);

/**
 * WhatsApp
 * ---------
 * Sends WhatsApp messages via Vonage Messages API v1 using cURL.
 * No SDK required / plain JSON POST with HTTP Basic Auth.
 *
 * Vonage Messages API v1 endpoint:
 *   POST https://api.nexmo.com/v1/messages
 *   Authorization: Basic base64(VONAGE_API_KEY:VONAGE_API_SECRET)
 *   Content-Type: application/json
 *
 * The FROM number must be a WhatsApp Business number registered with Vonage.
 * Set VONAGE_WHATSAPP_FROM in .env (e.g. 250786408274).
 * Every send attempt is logged to whatsapp_logs regardless of outcome.
 */
final class WhatsApp
{
    /**
     * Sends a plain-text WhatsApp message to $toPhone (E.164 without '+', e.g. 250786408274).
     * Returns true on success.
     */
    public static function send(string $toPhone, string $message, ?int $userId = null): bool
    {
        $result = self::sendViaVonage($toPhone, $message);

        Database::connection()->prepare(
            'INSERT INTO whatsapp_logs (user_id, to_phone, message, status, error_message, sent_at, created_at)
             VALUES (:uid, :to, :msg, :status, :err, :sent_at, NOW())'
        )->execute([
            'uid'     => $userId,
            'to'      => $toPhone,
            'msg'     => mb_substr($message, 0, 4096),
            'status'  => $result['ok'] ? 'Sent' : 'Failed',
            'err'     => $result['error'],
            'sent_at' => $result['ok'] ? date('Y-m-d H:i:s') : null,
        ]);

        return $result['ok'];
    }

    /**
     * Formats an announcement for WhatsApp.
     * WhatsApp supports *bold*, _italic_, and plain text.
     */
    public static function formatAnnouncement(string $title, string $body, string $senderName, string $universityName): string
    {
        $text  = "📢 *" . $title . "*\n\n";
        $text .= $body . "\n\n";
        $text .= "/ _" . $senderName . " · " . $universityName . "_\n";
        $text .= "_Sent via SEMAS_";
        return $text;
    }

    private static function sendViaVonage(string $toPhone, string $message): array
    {
        if (VONAGE_API_KEY === '' || VONAGE_API_SECRET === '' || VONAGE_WHATSAPP_FROM === '') {
            return ['ok' => false, 'error' => 'Vonage WhatsApp credentials not configured (VONAGE_API_KEY, VONAGE_API_SECRET, VONAGE_WHATSAPP_FROM).'];
        }

        // Strip leading '+' / Vonage Messages API expects plain E.164 digits
        $to = ltrim($toPhone, '+');

        $payload = json_encode([
            'channel'      => 'whatsapp',
            'message_type' => 'text',
            'to'           => $to,
            'from'         => ltrim(VONAGE_WHATSAPP_FROM, '+'),
            'text'         => $message,
        ]);

        $ch = curl_init('https://api.nexmo.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_USERPWD        => VONAGE_API_KEY . ':' . VONAGE_API_SECRET,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $err      = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'error' => "cURL error: $err"];
        }

        // Messages API v1 returns 202 Accepted on success
        if ($httpCode === 202) {
            return ['ok' => true, 'error' => null];
        }

        $data     = json_decode((string) $response, true);
        $errTitle = $data['title'] ?? ('Vonage WhatsApp error HTTP ' . $httpCode);
        $errDetail = $data['detail'] ?? (string) $response;
        return ['ok' => false, 'error' => $errTitle . ': ' . $errDetail];
    }
}
