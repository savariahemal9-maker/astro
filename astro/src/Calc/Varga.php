<?php
namespace App\Calc;

/** Parashari divisional charts. Input sidereal longitude, output sign index 0..11. */
final class Varga {
    public const SUPPORTED = [1,2,3,4,7,9,10,12,16,20,24,27,30,40,45,60];

    public static function sign(float $lon, int $d): int {
        $lon = Zodiac::norm($lon);
        $s = Zodiac::signOf($lon);
        $deg = $lon - 30 * $s;
        $odd = $s % 2 === 0;            // Aries(0) is an odd sign
        $mod = $s % 3;                  // 0 movable, 1 fixed, 2 dual
        $part = fn(int $n) => min($n - 1, (int) floor($deg * $n / 30));
        switch ($d) {
            case 1:  return $s;
            case 2:  return ($deg < 15) === $odd ? 4 : 3;                                  // Leo / Cancer
            case 3:  return ($s + 4 * $part(3)) % 12;
            case 4:  return ($s + 3 * $part(4)) % 12;
            case 7:  return (($odd ? $s : $s + 6) + $part(7)) % 12;
            case 9:  return ([$s, $s + 8, $s + 4][$mod] + $part(9)) % 12;
            case 10: return (($odd ? $s : $s + 8) + $part(10)) % 12;
            case 12: return ($s + $part(12)) % 12;
            case 16: return ([0, 4, 8][$mod] + $part(16)) % 12;
            case 20: return ([0, 8, 4][$mod] + $part(20)) % 12;
            case 24: return (($odd ? 4 : 3) + $part(24)) % 12;
            case 27: return ([0, 3, 6, 9][$s % 4] + $part(27)) % 12;
            case 30: return self::trimshamsha($deg, $odd);
            case 40: return (($odd ? 0 : 6) + $part(40)) % 12;
            case 45: return ([0, 4, 8][$mod] + $part(45)) % 12;
            case 60: return ($s + $part(60)) % 12;
        }
        throw new CalcException("Unsupported varga D$d");
    }

    private static function trimshamsha(float $deg, bool $odd): int {
        // odd: Mars 0-5 Aries, Saturn 5-10 Aquarius, Jupiter 10-18 Sagittarius, Mercury 18-25 Gemini, Venus 25-30 Libra
        $bounds = $odd ? [[5, 0], [10, 10], [18, 8], [25, 2], [30, 6]]
                       : [[5, 1], [12, 5], [20, 11], [25, 9], [30, 7]]; // even: reverse order of lords
        foreach ($bounds as [$lim, $sign]) if ($deg < $lim) return $sign;
        return $bounds[4][1];
    }

    /** Degrees from lon to the nearest boundary of the division containing it (for time-sensitivity). */
    public static function boundaryDistance(float $lon, int $d): array {
        $lon = Zodiac::norm($lon);
        if ($d === 30) {
            $s = Zodiac::signOf($lon); $deg = $lon - 30 * $s;
            $edges = array_merge([0], $s % 2 === 0 ? [5, 10, 18, 25] : [5, 12, 20, 25], [30]);
        } else {
            $w = 30 / $d; $deg = fmod($lon, $w); $edges = [0, $w];
            if ($d === 2) { $deg = fmod($lon, 15); $edges = [0, 15]; }
        }
        $lo = 0; $hi = 30;
        foreach ($edges as $e) { if ($e <= $deg) $lo = $e; if ($e > $deg) { $hi = $e; break; } }
        return ['before' => $deg - $lo, 'after' => $hi - $deg];
    }
}
