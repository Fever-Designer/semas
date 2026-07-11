<?php
declare(strict_types=1);

/**
 * SlipVerification
 * ------------------
 * Builds a minimal, self-contained "data:" HTML page that gets embedded
 * directly into a slip's QR code, so scanning it with an ordinary phone
 * camera works with zero network connectivity — no server fetch needed.
 * A "Verify online" link is included for anyone who does have connectivity
 * and wants to re-check the slip live against the SEMAS database.
 *
 * Deliberately plain-text-first (no table, no borders/boxes, no <title>/
 * charset meta, ASCII punctuation only) rather than a full styled replica of
 * verify-slip.php: a QR that reliably scans with a generic phone camera tops
 * out around Version 15-20 (~400-850 bytes). The full styled page design
 * needs ~2.8KB, which forces Version 40 (177x177 modules) — too dense to
 * scan reliably, especially off a screen. Keeping only the essential fields
 * and trimming every spare byte keeps the QR small and actually usable.
 */
final class SlipVerification
{
    /**
     * Build a scanner-friendly offline record. Generic camera and QR apps show
     * plain text without needing a browser, data-URI support, or connectivity.
     * The signed URL remains available for a live authenticity check when online.
     *
     * @param array<int,array{0:string,1:string}> $rows Ordered [label, value] pairs.
     */
    public static function offlineText(
        string $title,
        array $rows,
        string $statusLabel,
        string $verifyUrl,
        string $uniName
    ): string {
        $lines = ['SEMAS - ' . $title];
        foreach ($rows as [$label, $value]) {
            $lines[] = $label . ': ' . $value;
        }
        $lines[] = 'Status: ' . $statusLabel;
        $lines[] = 'Institution: ' . $uniName;
        $lines[] = 'Online verification: ' . $verifyUrl;
        return implode("\n", $lines);
    }

    /** @param array<int,array{0:string,1:string}> $rows Ordered [label, value] pairs — keep to 4-5 essential fields. */
    public static function offlineDataUri(
        string $title,
        string $subtitle,
        array $rows,
        string $statusLabel,
        bool $statusOk,
        string $verifyUrl,
        string $uniName
    ): string {
        // Field values come from user-entered data (names, module titles, up to
        // 150 chars in the DB) with no guaranteed max length; truncate defensively
        // so a single long value can't push the page past the QR's byte budget.
        $truncate = function (string $value, int $max = 28): string {
            return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '.' : $value;
        };

        $title    = $truncate($title, 28);
        $subtitle = $truncate($subtitle, 32);
        $uniName  = $truncate($uniName, 28);

        $rowsHtml = '';
        foreach ($rows as [$label, $value]) {
            $rowsHtml .= htmlspecialchars($label) . ': ' . htmlspecialchars($truncate($value)) . '<br>';
        }

        // No colors/bold/divs beyond one lightweight body style: every styled
        // element costs bytes that are better spent leaving room for the
        // (fixed-size, unavoidable) verify link. Statuses read fine as plain
        // text ("VERIFIED - Present") without needing color emphasis.
        $html = '<body style="font-family:Arial;font-size:14px;padding:10px">'
            . 'SEMAS - ' . htmlspecialchars($title) . '<br>'
            . htmlspecialchars($subtitle) . '<br><br>'
            . $rowsHtml . '<br>'
            . htmlspecialchars($statusLabel) . '<br><br>'
            . htmlspecialchars($uniName) . '<br>'
            . '<a href="' . htmlspecialchars($verifyUrl) . '">Verify</a>';

        return 'data:text/html;base64,' . base64_encode($html);
    }
}
