<?php
declare(strict_types=1);

use chillerlan\QRCode\{QRCode, QROptions};
use chillerlan\QRCode\Common\{EccLevel, Version};
use chillerlan\QRCode\Output\QRGdImagePNG;

/**
 * SimpleQr
 * ---------
 * Thin wrapper around chillerlan/php-qrcode. Kept as its own class (rather than
 * calling the library directly at each call site) so every QR code in SEMAS
 * goes through one place if the underlying library or defaults ever change.
 *
 * The previous hand-rolled QR encoder here only supported up to QR Version 5
 * (106 usable bytes at error-correction level L). Every attendance scan URL
 * (module_id + a 43-byte token, wrapped in the app's base URL) is well over
 * that, which silently corrupted the generated code and made it unscannable.
 * chillerlan/php-qrcode auto-selects a version large enough for the payload
 * (up to Version 40), so this can no longer happen.
 */
final class SimpleQr
{
    public static function pngDataUri(string $text, int $scale = 5, int $margin = 3): string
    {
        $options = new QROptions([
            'version'         => Version::AUTO,
            'eccLevel'        => EccLevel::L,
            'outputInterface' => QRGdImagePNG::class,
            'scale'           => $scale,
            'quietzoneSize'   => $margin,
            'imageTransparent' => false,
        ]);

        return (new QRCode($options))->render($text);
    }
}
