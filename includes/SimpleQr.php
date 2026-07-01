<?php
declare(strict_types=1);

final class SimpleQr
{
    private const L_TABLE = [
        1 => ['data' => 19,  'ecc' => 7],
        2 => ['data' => 34,  'ecc' => 10],
        3 => ['data' => 55,  'ecc' => 15],
        4 => ['data' => 80,  'ecc' => 20],
        5 => ['data' => 108, 'ecc' => 26],
    ];

    public static function pngDataUri(string $text, int $scale = 5, int $margin = 3): string
    {
        $matrix = self::matrix($text);
        $size = count($matrix);
        $imgSize = ($size + ($margin * 2)) * $scale;
        $img = imagecreatetruecolor($imgSize, $imgSize);
        $white = imagecolorallocate($img, 255, 255, 255);
        $dark = imagecolorallocate($img, 30, 42, 82);
        imagefill($img, 0, 0, $white);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix[$y][$x]) {
                    imagefilledrectangle(
                        $img,
                        ($x + $margin) * $scale,
                        ($y + $margin) * $scale,
                        ($x + $margin + 1) * $scale - 1,
                        ($y + $margin + 1) * $scale - 1,
                        $dark
                    );
                }
            }
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    private static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text));
        $version = 5;
        foreach (self::L_TABLE as $v => $cfg) {
            if (count($bytes) <= $cfg['data'] - 2) {
                $version = $v;
                break;
            }
        }
        $cfg = self::L_TABLE[$version];
        $data = self::dataCodewords($bytes, $cfg['data']);
        $ecc = self::ecc($data, $cfg['ecc']);
        $bits = [];
        foreach (array_merge($data, $ecc) as $cw) {
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = (($cw >> $i) & 1) === 1;
            }
        }

        $size = 17 + 4 * $version;
        $m = array_fill(0, $size, array_fill(0, $size, false));
        $f = array_fill(0, $size, array_fill(0, $size, false));
        self::patterns($m, $f, $version);
        self::dataBits($m, $f, $bits);
        self::format($m, $f, $version);
        return $m;
    }

    private static function dataCodewords(array $bytes, int $dataCount): array
    {
        $bits = [false, true, false, false]; // byte mode
        $len = count($bytes);
        for ($i = 7; $i >= 0; $i--) $bits[] = (($len >> $i) & 1) === 1;
        foreach ($bytes as $b) {
            for ($i = 7; $i >= 0; $i--) $bits[] = (($b >> $i) & 1) === 1;
        }
        $capacity = $dataCount * 8;
        for ($i = 0; $i < 4 && count($bits) < $capacity; $i++) $bits[] = false;
        while (count($bits) % 8 !== 0) $bits[] = false;
        $out = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $v = 0;
            for ($j = 0; $j < 8; $j++) $v = ($v << 1) | ($bits[$i + $j] ? 1 : 0);
            $out[] = $v;
        }
        for ($pad = 0; count($out) < $dataCount; $pad++) {
            $out[] = ($pad % 2 === 0) ? 0xec : 0x11;
        }
        return $out;
    }

    private static function patterns(array &$m, array &$f, int $version): void
    {
        $size = count($m);
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as $p) self::finder($m, $f, $p[0], $p[1]);
        for ($i = 8; $i < $size - 8; $i++) {
            self::set($m, $f, 6, $i, $i % 2 === 0);
            self::set($m, $f, $i, 6, $i % 2 === 0);
        }
        $apos = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30]][$version];
        foreach ($apos as $y) foreach ($apos as $x) {
            if (($x < 9 && $y < 9) || ($x > $size - 10 && $y < 9) || ($x < 9 && $y > $size - 10)) continue;
            self::align($m, $f, $x, $y);
        }
        self::set($m, $f, 8, 4 * $version + 9, true);
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) { $f[8][$i] = true; $f[$i][8] = true; }
        }
        for ($i = 0; $i < 8; $i++) {
            $f[8][$size - 1 - $i] = true;
            $f[$size - 1 - $i][8] = true;
        }
    }

    private static function finder(array &$m, array &$f, int $x, int $y): void
    {
        for ($dy = -1; $dy <= 7; $dy++) for ($dx = -1; $dx <= 7; $dx++) {
            $xx = $x + $dx; $yy = $y + $dy;
            if ($yy < 0 || $yy >= count($m) || $xx < 0 || $xx >= count($m)) continue;
            $on = ($dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6 && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4)));
            self::set($m, $f, $xx, $yy, $on);
        }
    }

    private static function align(array &$m, array &$f, int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) {
            $on = max(abs($dx), abs($dy)) !== 1;
            self::set($m, $f, $x + $dx, $y + $dy, $on);
        }
    }

    private static function dataBits(array &$m, array $f, array $bits): void
    {
        $size = count($m); $i = 0; $up = true;
        for ($x = $size - 1; $x >= 1; $x -= 2) {
            if ($x === 6) $x--;
            for ($j = 0; $j < $size; $j++) {
                $y = $up ? $size - 1 - $j : $j;
                for ($dx = 0; $dx < 2; $dx++) {
                    $xx = $x - $dx;
                    if ($f[$y][$xx]) continue;
                    $bit = $bits[$i++] ?? false;
                    if ((($xx + $y) % 2) === 0) $bit = !$bit;
                    $m[$y][$xx] = $bit;
                }
            }
            $up = !$up;
        }
    }

    private static function format(array &$m, array &$f, int $version): void
    {
        $bits = self::formatBits(1, 0); // L, mask 0
        $size = count($m);
        for ($i = 0; $i <= 5; $i++) self::set($m, $f, 8, $i, (($bits >> $i) & 1) === 1);
        self::set($m, $f, 8, 7, (($bits >> 6) & 1) === 1);
        self::set($m, $f, 8, 8, (($bits >> 7) & 1) === 1);
        self::set($m, $f, 7, 8, (($bits >> 8) & 1) === 1);
        for ($i = 9; $i < 15; $i++) self::set($m, $f, 14 - $i, 8, (($bits >> $i) & 1) === 1);
        for ($i = 0; $i < 8; $i++) self::set($m, $f, $size - 1 - $i, 8, (($bits >> $i) & 1) === 1);
        for ($i = 8; $i < 15; $i++) self::set($m, $f, 8, $size - 15 + $i, (($bits >> $i) & 1) === 1);
    }

    private static function formatBits(int $ecc, int $mask): int
    {
        $data = ($ecc << 3) | $mask;
        $v = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($v >> $i) & 1) $v ^= 0x537 << ($i - 10);
        }
        return (($data << 10) | $v) ^ 0x5412;
    }

    private static function ecc(array $data, int $ecCount): array
    {
        [$exp, $log] = self::gf();
        $gen = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $coef) {
                $next[$j] ^= self::mul($coef, 1, $exp, $log);
                $next[$j + 1] ^= self::mul($coef, $exp[$i], $exp, $log);
            }
            $gen = $next;
        }
        $res = array_fill(0, $ecCount, 0);
        foreach ($data as $d) {
            $factor = $d ^ $res[0];
            array_shift($res);
            $res[] = 0;
            for ($i = 0; $i < $ecCount; $i++) {
                $res[$i] ^= self::mul($gen[$i + 1], $factor, $exp, $log);
            }
        }
        return $res;
    }

    private static function gf(): array
    {
        static $cache = null;
        if ($cache) return $cache;
        $exp = array_fill(0, 512, 0); $log = array_fill(0, 256, 0); $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x; $log[$x] = $i; $x <<= 1;
            if ($x & 0x100) $x ^= 0x11d;
        }
        for ($i = 255; $i < 512; $i++) $exp[$i] = $exp[$i - 255];
        return $cache = [$exp, $log];
    }

    private static function mul(int $a, int $b, array $exp, array $log): int
    {
        return ($a === 0 || $b === 0) ? 0 : $exp[$log[$a] + $log[$b]];
    }

    private static function set(array &$m, array &$f, int $x, int $y, bool $v): void
    {
        $m[$y][$x] = $v; $f[$y][$x] = true;
    }
}
