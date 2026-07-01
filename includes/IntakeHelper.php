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

    // Also include any intake codes already in use or published through the
    // semester calendar, so new cohorts show up immediately everywhere intakes
    // are selected/filtered.
    try {
        $used = Database::connection()
            ->query(
                "SELECT DISTINCT intake FROM users WHERE intake IS NOT NULL AND intake != ''
                 UNION
                 SELECT DISTINCT intake FROM semester_calendars WHERE intake IS NOT NULL AND intake != ''"
            )
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($used as $code) {
            if (isValidIntakeCode((string) $code) && !in_array($code, $intakes, true)) {
                $intakes[] = $code;
            }
        }
    } catch (Throwable $e) {
        // DB not ready yet — fall back to the date-computed list only.
    }

    return $intakes;
}

/**
 * Detect intake code (e.g. 'MAY26') from a UoK registration number.
 *
 * Format: [YY][0][NNN]...
 *   YY  = 2-digit year (any year — not a fixed lookup table, so a new
 *         year prefix is recognised automatically the moment it appears
 *         in a reg number, with no code change needed)
 *   0   = literal separator digit (3rd character)
 *   NNN = digits 4–6 (sequence range):
 *         100–499 → JAN
 *         500–899 → MAY
 *         900–999 → SEPT
 */
function detectIntakeCode(string $regNumber): ?string
{
    if (strlen($regNumber) < 6) return null;
    if (substr($regNumber, 2, 1) !== '0') return null;

    $yy = substr($regNumber, 0, 2);
    if (!ctype_digit($yy)) return null;

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
