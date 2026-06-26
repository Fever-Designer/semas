<?php
declare(strict_types=1);

/**
 * Sms
 * ----
 * Sends real SMS via Africa's Talking or Twilio's HTTP APIs using cURL
 * (no SDK dependency required). Select the provider with SMS_PROVIDER in
 * .env. Every send attempt is logged to sms_logs regardless of outcome.
 */
final class Sms
{
    public static function send(string $toPhone, string $message, ?int $userId = null): bool
    {
        $provider = SMS_PROVIDER;
        $result = $provider === 'twilio'
            ? self::sendViaTwilio($toPhone, $message)
            : self::sendViaAfricasTalking($toPhone, $message);

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO sms_logs (user_id, to_phone, message, provider, status, error_message, sent_at, created_at)
             VALUES (:uid, :to, :msg, :provider, :status, :err, :sent_at, NOW())'
        )->execute([
            'uid'      => $userId,
            'to'       => $toPhone,
            'msg'      => $message,
            'provider' => $provider,
            'status'   => $result['ok'] ? 'Sent' : 'Failed',
            'err'      => $result['error'],
            'sent_at'  => $result['ok'] ? date('Y-m-d H:i:s') : null,
        ]);

        return $result['ok'];
    }

    private static function sendViaAfricasTalking(string $toPhone, string $message): array
    {
        if (AT_USERNAME === '' || AT_API_KEY === '') {
            return ['ok' => false, 'error' => 'Africa\'s Talking credentials not configured.'];
        }
        $isSandbox = AT_USERNAME === 'sandbox';
        $url = $isSandbox
            ? 'https://api.sandbox.africastalking.com/version1/messaging'
            : 'https://api.africastalking.com/version1/messaging';

        $postFields = http_build_query([
            'username' => AT_USERNAME,
            'to'       => $toPhone,
            'message'  => $message,
            'from'     => AT_SHORTCODE,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_HTTPHEADER     => [
                'apiKey: ' . AT_API_KEY,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'error' => "cURL error: $err"];
        }
        $data = json_decode((string) $response, true);
        $status = $data['SMSMessageData']['Recipients'][0]['status'] ?? null;
        if ($status === 'Success') {
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => 'Africa\'s Talking response: ' . (string) $response];
    }

    private static function sendViaTwilio(string $toPhone, string $message): array
    {
        if (TWILIO_SID === '' || TWILIO_TOKEN === '' || TWILIO_FROM_NUMBER === '') {
            return ['ok' => false, 'error' => 'Twilio credentials not configured.'];
        }
        $url = "https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_SID . "/Messages.json";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'From' => TWILIO_FROM_NUMBER,
                'To'   => $toPhone,
                'Body' => $message,
            ]),
            CURLOPT_USERPWD => TWILIO_SID . ':' . TWILIO_TOKEN,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'error' => "cURL error: $err"];
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => "Twilio HTTP $httpCode: $response"];
    }
}
