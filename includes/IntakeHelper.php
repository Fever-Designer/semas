<?php
declare(strict_types=1);

/**
 * Generate the list of intake codes available up to now.
 * Format: JAN24, MAY24, SEPT24, JAN25 … up to the current month's intake.
 * SEPT26 is NOT shown if we haven't reached September 2026 yet.
 *
 * @param int $startYear  First cohort year (default 2024)
 * @return string[]       e.g. ['JAN24','MAY24','SEPT24','JAN25','MAY25','SEPT25','JAN26','MAY26']
 */
function availableIntakes(int $startYear = 2024): array
{
    $curYear  = (int) date('Y');
    $curMonth = (int) date('n');
    $intakes  = [];

    for ($y = $startYear; $y <= $curYear; $y++) {
        $yy = substr((string) $y, 2); // 2024 → '24', 2026 → '26'
        $intakes[] = 'JAN' . $yy;
        if ($y < $curYear || $curMonth >= 5) {
            $intakes[] = 'MAY' . $yy;
        }
        if ($y < $curYear || $curMonth >= 9) {
            $intakes[] = 'SEPT' . $yy;
        }
    }
    return $intakes;
}

/**
 * Detect intake code (e.g. 'MAY26') from a UoK registration number.
 *
 * Format: [YYY][NNN]...
 *   YYY = year prefix: 230→23, 240→24, 250→25, 260→26
 *   NNN = digits 4–6 (sequence range):
 *         100–499 → JAN
 *         500–899 → MAY
 *         900–999 → SEPT
 */
function detectIntakeCode(string $regNumber): ?string
{
    if (strlen($regNumber) < 6) return null;

    $yearMap = ['260' => '26', '250' => '25', '240' => '24', '230' => '23'];
    $yy = $yearMap[substr($regNumber, 0, 3)] ?? null;
    if (!$yy) return null;

    $seq = (int) substr($regNumber, 3, 3); // digits 4–6 as integer
    if ($seq >= 100 && $seq <= 499) return 'JAN'  . $yy;
    if ($seq >= 500 && $seq <= 899) return 'MAY'  . $yy;
    if ($seq >= 900 && $seq <= 999) return 'SEPT' . $yy;
    return null;
}

/** True if the given string looks like a valid intake code (e.g. 'MAY26'). */
function isValidIntakeCode(string $code): bool
{
    return (bool) preg_match('/^(JAN|MAY|SEPT)\d{2}$/', $code);
}
