<?php
declare(strict_types=1);

final class GpsService
{
    /** Great-circle distance between two lat/lng points, in meters. */
    public static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0; // meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /** Checks a submitted GPS reading against the configured campus center
     *  and radius (or an event-specific override if the event has its own
     *  lat/lng). Returns the distance and a pass/fail flag — never throws,
     *  so the caller can still log a denied attempt with full details. */
    public static function withinCampus(float $lat, float $lng, ?float $centerLat = null, ?float $centerLng = null, ?int $radiusM = null): array
    {
        $db = Database::connection();
        $centerLat = $centerLat ?? (float) self::setting($db, 'campus_latitude', DEFAULT_CAMPUS_LAT);
        $centerLng = $centerLng ?? (float) self::setting($db, 'campus_longitude', DEFAULT_CAMPUS_LNG);
        $radiusM   = $radiusM ?? (int) self::setting($db, 'campus_radius_meters', DEFAULT_CAMPUS_RADIUS_M);

        $distance = self::distanceMeters($lat, $lng, $centerLat, $centerLng);

        return [
            'distance_meters' => round($distance, 2),
            'radius_meters'   => $radiusM,
            'passed'          => $distance <= $radiusM,
        ];
    }

    private static function setting(PDO $db, string $key, $default)
    {
        $stmt = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = :k');
        $stmt->execute(['k' => $key]);
        $v = $stmt->fetchColumn();
        return $v !== false ? $v : $default;
    }
}
