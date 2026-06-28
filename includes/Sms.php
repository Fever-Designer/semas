<?php
declare(strict_types=1);

/**
 * Sms
 * ----
 * Sends real SMS via Africa's Talking or Vonage (Messages API) using cURL
 * (no SDK dependency required). Select the provider with SMS_PROVIDER in
 * .env. Every send attempt is logged to sms_logs regardless of outcome.
 */
final class Sms
{
    public static function send(string $toPhone, string $message, ?int $userId = null): bool
    {
        $provider = SMS_PROVIDER;
        $result = $provider === 'vonage'
            ? self::sendViaVonage($toPhone, $message)
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

    private static function sendViaVonage(string $toPhone, string $message): array
    {
        if (VONAGE_API_KEY === '' || VONAGE_API_SECRET === '' || VONAGE_FROM === '') {
            return ['ok' => false, 'error' => 'Vonage credentials not configured.'];
        }

        $ch = curl_init('https://rest.nexmo.com/sms/json');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'api_key'    => VONAGE_API_KEY,
                'api_secret' => VONAGE_API_SECRET,
                'from'       => VONAGE_FROM,
                'to'         => $toPhone,
                'text'       => $message,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_TIMEOUT    => 15,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'error' => "cURL error: $err"];
        }

        $data   = json_decode((string) $response, true);
        $status = $data['messages'][0]['status'] ?? null;

        if ($status === '0') {
            return ['ok' => true, 'error' => null];
        }

        $errText = $data['messages'][0]['error-text'] ?? ('Vonage error: ' . (string) $response);
        return ['ok' => false, 'error' => $errText];
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

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'username' => AT_USERNAME,
                'to'       => $toPhone,
                'message'  => $message,
                'from'     => AT_SHORTCODE,
            ]),
            CURLOPT_HTTPHEADER => [
                'apiKey: ' . AT_API_KEY,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            return ['ok' => false, 'error' => "cURL error: $err"];
        }
        $data   = json_decode((string) $response, true);
        $status = $data['SMSMessageData']['Recipients'][0]['status'] ?? null;
        if ($status === 'Success') {
            return ['ok' => true, 'error' => null];
        }
        return ['ok' => false, 'error' => 'Africa\'s Talking response: ' . (string) $response];
    }
}
