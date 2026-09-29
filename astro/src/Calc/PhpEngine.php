<?php
namespace App\Calc;

/**
 * Pure-PHP engine for shared hosting. Evaluates Chebyshev fits of Swiss Ephemeris output
 * (data/ephem/*.bin, made by tools/gen_ephem.py, verified by tests/verify_php_engine.php).
 * Returns the same structure as the compiled astro_calc engine. Lahiri ayanamsa, 1800–2399.
 */
final class PhpEngine {
    public const VERSION = '2.0.0-php';
    private const BODIES = ['Sun' => 'sun', 'Moon' => 'moon', 'Mars' => 'mars', 'Mercury' => 'mercury',
                            'Jupiter' => 'jupiter', 'Venus' => 'venus', 'Saturn' => 'saturn'];
    private array $meta; private array $fh = [];

    public function __construct(private string $dir, private string $node = 'mean', private string $rise = 'conventional') {
        $m = @file_get_contents($dir . '/meta.json');
        if ($m === false) throw new CalcException('Ephemeris data missing (data/ephem). Upload it.');
        $this->meta = json_decode($m, true);
    }

    // ---------- Chebyshev evaluation ----------
    /** @return array{0:float,1:float} value, derivative per day */
    private function cheb(string $name, float $jd): array {
        if ($jd < $this->meta['jd0'] || $jd >= $this->meta['jd1']) throw new CalcException('Date outside supported range (1800–2399)');
        $s = $this->meta['series'][$name];
        $k = (int) floor(($jd - $this->meta['jd0']) / $s['seg']);
        $f = $this->fh[$name] ??= fopen($this->dir . "/$name.bin", 'rb');
        if (!$f || fseek($f, $k * $s['n'] * 8) !== 0) throw new CalcException("Ephemeris file $name unreadable");
        $raw = fread($f, $s['n'] * 8);
        if (strlen($raw) !== $s['n'] * 8) throw new CalcException("Ephemeris file $name truncated");
        $c = array_values(unpack('e*', $raw));
        $x = 2 * (($jd - $this->meta['jd0']) - $k * $s['seg']) / $s['seg'] - 1;
        // Clenshaw for value and derivative
        $n = $s['n']; $t0 = 1; $t1 = $x; $u0 = 0; $u1 = 1; $v = $c[0] + $c[1] * $x; $d = $c[1];
        for ($i = 2; $i < $n; $i++) {
            $t2 = 2 * $x * $t1 - $t0; $u2 = 2 * $t1 + 2 * $x * $u1 - $u0;
            $v += $c[$i] * $t2; $d += $c[$i] * $u2;
            [$t0, $t1, $u0, $u1] = [$t1, $t2, $u1, $u2];
        }
        if ($s['angle']) $v = fmod(fmod($v, 360) + 360, 360);
        return [$v, $d * 2 / $s['seg']];
    }
    private function v(string $n, float $jd): float { return $this->cheb($n, $jd)[0]; }
    private function aeff(float $jd): float { return $this->v('aeff', $jd); }
    private static function n360(float $x): float { $x = fmod($x, 360); return $x < 0 ? $x + 360 : $x; }

    public function sid(string $body, float $jd): float { return self::n360($this->v(self::BODIES[$body], $jd) - $this->aeff($jd)); }

    // ---------- sidereal time, ascendant ----------
    private function gast(float $jd): float {
        $d = $jd - 2451545.0; $t = $d / 36525;
        $gmst = 280.46061837 + 360.98564736629 * $d + 0.000387933 * $t * $t - $t * $t * $t / 38710000;
        return self::n360($gmst + $this->v('dpsi', $jd) * cos(deg2rad($this->v('eps', $jd))) + $this->v('stcorr', $jd));
    }
    private function ascTrop(float $jd, float $lat, float $lon): array {
        $ramc = deg2rad(self::n360($this->gast($jd) + $lon)); $e = deg2rad($this->v('eps', $jd)); $p = deg2rad($lat);
        $asc = self::n360(rad2deg(atan2(cos($ramc), -(sin($ramc) * cos($e) + tan($p) * sin($e)))));
        $mc = self::n360(rad2deg(atan2(sin($ramc), cos($ramc) * cos($e))));
        return [$asc, $mc];
    }

    public function info(): array {
        return ['engine' => $this->engineInfo(), 'ayanamsa_deg' => $this->aeff(2451545.0) - $this->v('dpsi', 2451545.0), 'ok' => true];
    }
    private function engineInfo(): array {
        return ['name' => 'php_cheb', 'version' => self::VERSION, 'swisseph' => $this->meta['swisseph'], 'ayanamsa' => 'lahiri', 'node' => $this->node];
    }

    public function chart(float $jd, float $lat, float $lon): array {
        $a = $this->aeff($jd); $planets = [];
        foreach (self::BODIES as $name => $file) {
            [$t, $sp] = $this->cheb($file, $jd);
            $planets[] = ['name' => $name, 'lon' => self::n360($t - $a), 'lat' => $name === 'Moon' ? $this->v('moonlat', $jd) : 0.0,
                          'speed' => $sp, 'retro' => $sp < 0, 'tropical_lon' => $t];
        }
        [$r, $rs] = $this->cheb($this->node === 'true' ? 'truenode' : 'meannode', $jd);
        $planets[] = ['name' => 'Rahu', 'lon' => self::n360($r - $a), 'lat' => 0, 'speed' => $rs, 'retro' => true, 'tropical_lon' => $r];
        $planets[] = ['name' => 'Ketu', 'lon' => self::n360($r + 180 - $a), 'lat' => 0, 'speed' => $rs, 'retro' => true, 'tropical_lon' => self::n360($r + 180)];
        [$asc, $mc] = $this->ascTrop($jd, $lat, $lon);
        [$asc2] = $this->ascTrop($jd + 1 / 1440, $lat, $lon);
        $rate = $asc2 - $asc; if ($rate < -180) $rate += 360; if ($rate > 180) $rate -= 360;
        return ['engine' => $this->engineInfo(), 'ayanamsa_deg' => $a - $this->v('dpsi', $jd), 'jd_ut' => $jd, 'planets' => $planets,
                'ascendant' => ['lon' => self::n360($asc - $a), 'deg_per_min' => $rate], 'mc' => self::n360($mc - $a)];
    }

    // ---------- rise / set ----------
    private function altitude(string $body, float $jd, float $lat, float $lon): float {
        $e = deg2rad($this->v('eps', $jd));
        $l = deg2rad($this->v($body === 'Sun' ? 'sun' : 'moon', $jd));
        $b = ($body === 'Moon' && $this->rise !== 'hindu') ? deg2rad($this->v('moonlat', $jd)) : 0.0;
        $dec = asin(sin($b) * cos($e) + cos($b) * sin($e) * sin($l));
        $ra = atan2(sin($l) * cos($e) - tan($b) * sin($e), cos($l));
        $h = deg2rad($this->gast($jd) + $lon) - $ra; $p = deg2rad($lat);
        $alt = rad2deg(asin(sin($p) * sin($dec) + cos($p) * cos($dec) * cos($h)));
        return $alt - $this->horizon($body, $jd);
    }
    /** Geocentric altitude of the body's centre at the moment of rise/set. */
    private function horizon(string $body, float $jd): float {
        if ($this->rise === 'hindu') return 0.0;                        // disc centre, no refraction
        $refr = 33.4 / 60;                                              // refraction at horizon, 1013.25 hPa, 15 °C
        if ($body === 'Sun') {
            $m = deg2rad(357.529 + 0.98560028 * ($jd - 2451545.0));
            $r = 1.00014 - 0.01671 * cos($m) - 0.00014 * cos(2 * $m);
            return -$refr - (959.63 / 3600) / $r;
        }
        $dist = $this->v('moondist', $jd) * 149597870.7;               // km
        return rad2deg(asin(6378.137 / $dist)) - $refr - rad2deg(asin(1737.4 / $dist));
    }
    /** First rise (dir=1) or set (dir=-1) after $jd within $span days, or -1. */
    private function event(string $body, float $jd, int $dir, float $lat, float $lon, float $span = 1.2): float {
        $step = 10 / 1440; $t = $jd; $a = $this->altitude($body, $t, $lat, $lon);
        for ($end = $jd + $span; $t < $end; $t += $step) {
            $b = $this->altitude($body, $t + $step, $lat, $lon);
            if (($dir > 0 && $a < 0 && $b >= 0) || ($dir < 0 && $a > 0 && $b <= 0)) {
                $lo = $t; $hi = $t + $step;
                while ($hi - $lo > 0.1 / 86400) { $m = ($lo + $hi) / 2; $am = $this->altitude($body, $m, $lat, $lon); if (($am < 0) === ($dir > 0)) $lo = $m; else $hi = $m; }
                return $hi;
            }
            $a = $b;
        }
        return -1;
    }

    // ---------- panchang ----------
    private function idx(string $what, float $jd): int {
        $s = $this->sid('Sun', $jd); $m = $this->sid('Moon', $jd);
        return match ($what) {
            'tithi' => (int) floor(self::n360($m - $s) / 12), 'karana' => (int) floor(self::n360($m - $s) / 6),
            'nakshatra' => (int) floor($m / (360 / 27)), 'yoga' => (int) floor(self::n360($s + $m) / (360 / 27)),
        };
    }
    private function segments(string $what, float $start, float $until): array {
        $step = 1 / 48; $t = $start; $cur = $this->idx($what, $t); $out = [];
        for ($g = 0; $g < 200; $g++) {
            $t2 = $t + $step; $v2 = $this->idx($what, $t2);
            if ($v2 !== $cur) {
                $lo = $t; $hi = $t2;
                while ($hi - $lo > 0.5 / 86400) { $m = ($lo + $hi) / 2; if ($this->idx($what, $m) === $cur) $lo = $m; else $hi = $m; }
                $out[] = ['index' => $cur, 'end_jd' => $hi];
                if ($hi >= $until) return $out;
                $cur = $this->idx($what, $hi); $t = $hi; continue;
            }
            $t = $t2;
        }
        throw new CalcException('panchang scan did not converge');
    }

    public function panchang(float $jd, float $lat, float $lon): array {
        $sr = $this->event('Sun', $jd, 1, $lat, $lon, 1.0);
        if ($sr < 0) throw new CalcException('No sunrise on this date at this latitude');
        $ss = $this->event('Sun', $sr, -1, $lat, $lon); $sr2 = $this->event('Sun', $sr + 0.01, 1, $lat, $lon);
        $mr = $this->event('Moon', $jd, 1, $lat, $lon, 1.0); $ms = $this->event('Moon', $jd, -1, $lat, $lon, 1.0);
        $out = ['engine' => $this->engineInfo(), 'ayanamsa_deg' => $this->aeff($sr) - $this->v('dpsi', $sr),
                'sunrise_jd' => $sr, 'sunset_jd' => $ss, 'next_sunrise_jd' => $sr2, 'moonrise_jd' => $mr, 'moonset_jd' => $ms,
                'sun_sid_at_sunrise' => $this->sid('Sun', $sr), 'moon_sid_at_sunrise' => $this->sid('Moon', $sr)];
        foreach (['tithi', 'nakshatra', 'yoga', 'karana'] as $w) $out[$w] = $this->segments($w, $sr, $sr2);
        return $out;
    }
}
