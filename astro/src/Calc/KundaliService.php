<?php
namespace App\Calc;

/**
 * Builds kundali data strictly from engine output + deterministic rules.
 * Output contains calculated facts only; interpretations live in App\Interp.
 */
final class KundaliService {
    public const DATA_VERSION = 2; // bump when the kundali structure changes (invalidates cache)
    public const SENSITIVITY_VARGAS = [1, 2, 3, 4, 7, 9, 10, 12, 60];

    public function __construct(private Ephemeris $eph = new Ephemeris()) {}

    /** $birth = TimeResolver::resolve() result */
    public function compute(array $birth, float $lat, float $lon): array {
        $e = $this->eph->chart($birth['jd_ut'], $lat, $lon);
        $lagnaLon = $e['ascendant']['lon'];
        $lagnaSign = Zodiac::signOf($lagnaLon);

        $planets = [];
        foreach ($e['planets'] as $p) {
            $s = Zodiac::signOf($p['lon']); $n = Zodiac::nakshatra($p['lon']);
            $planets[] = [
                'name' => $p['name'], 'lon' => round($p['lon'], 6), 'sign' => $s, 'sign_name' => Zodiac::SIGNS[$s],
                'deg_in_sign' => round($p['lon'] - 30 * $s, 6), 'dms' => Zodiac::dms($p['lon'] - 30 * $s),
                'nakshatra' => $n['name'], 'pada' => $n['pada'], 'nakshatra_lord' => $n['lord'],
                'house' => ($s - $lagnaSign + 12) % 12 + 1, 'retrograde' => (bool) $p['retro'],
                'speed_deg_per_day' => round($p['speed'], 6), 'dignity' => Zodiac::dignity($p['name'], $s),
            ];
        }
        $sun = $e['planets'][0]['lon'];
        foreach ($planets as &$pp) $pp['combust'] = Analysis::combust($pp, $sun);
        unset($pp);
        $ln = Zodiac::nakshatra($lagnaLon);
        $lagna = ['lon' => round($lagnaLon, 6), 'sign' => $lagnaSign, 'sign_name' => Zodiac::SIGNS[$lagnaSign],
                  'deg_in_sign' => round($lagnaLon - 30 * $lagnaSign, 6), 'dms' => Zodiac::dms($lagnaLon - 30 * $lagnaSign),
                  'nakshatra' => $ln['name'], 'pada' => $ln['pada']];

        $vargas = [];
        foreach (Varga::SUPPORTED as $d) {
            $row = ['name' => Analysis::VARGA_NAMES['D' . $d], 'lagna' => Varga::sign($lagnaLon, $d), 'planets' => [], 'dignity' => []];
            foreach ($e['planets'] as $p) {
                $row['planets'][$p['name']] = $vs = Varga::sign($p['lon'], $d);
                $row['dignity'][$p['name']] = Zodiac::dignity($p['name'], $vs);
            }
            $vargas['D' . $d] = $row;
        }

        $cfg = $this->eph->settings();
        $moon = $this->planet($e, 'Moon');
        $dasha = Dasha::vimshottari($moon['lon'], $birth['jd_ut'], (float) $cfg['dasha_year_days']);
        $dasha['mahadasha'] = array_map(fn($md) => $this->dates($md, $birth), $dasha['mahadasha']);

        return [
            'meta' => [
                'type' => 'calculated', 'settings' => $cfg, 'engine' => $e['engine'],
                'ayanamsa_deg' => round($e['ayanamsa_deg'], 6), 'ayanamsa_dms' => Zodiac::dms($e['ayanamsa_deg']),
                'calculated_at' => gmdate('c'),
            ],
            'birth' => $birth + ['lat' => $lat, 'lon' => $lon],
            'lagna' => $lagna, 'planets' => $planets, 'vargas' => $vargas, 'dasha' => $dasha,
            'analysis' => Analysis::natal($lagna, $planets),
            'sensitivity' => $this->sensitivity($lagnaLon, (float) $e['ascendant']['deg_per_min'], $moon),
        ];
    }

    /** Current transits (gochar) relative to a natal chart. */
    public function transits(array $natal, float $jd, float $lat, float $lon): array {
        $e = $this->eph->chart($jd, $lat, $lon);
        $natalMoon = $this->natalPlanet($natal, 'Moon')['sign'];
        $rows = [];
        foreach ($e['planets'] as $p) {
            $s = Zodiac::signOf($p['lon']); $n = Zodiac::nakshatra($p['lon']);
            $rows[] = ['name' => $p['name'], 'lon' => round($p['lon'], 6), 'sign' => $s, 'sign_name' => Zodiac::SIGNS[$s],
                       'dms' => Zodiac::dms($p['lon'] - 30 * $s), 'nakshatra' => $n['name'], 'retrograde' => (bool) $p['retro'],
                       'house_from_lagna' => ($s - $natal['lagna']['sign'] + 12) % 12 + 1,
                       'house_from_moon' => ($s - $natalMoon + 12) % 12 + 1];
        }
        $sat = $rows[6]['house_from_moon']; $moonT = $rows[1]['house_from_moon'];
        return [
            'meta' => ['type' => 'calculated', 'engine' => $e['engine'], 'jd_ut' => $jd, 'utc' => gmdate('c', (int) (($jd - 2440587.5) * 86400))],
            'planets' => $rows,
            'derived' => [
                'sade_sati' => ['active' => in_array($sat, [12, 1, 2], true), 'phase' => [12 => 'rising', 1 => 'peak', 2 => 'setting'][$sat] ?? null,
                                'rule' => 'Transit Saturn in 12th, 1st or 2nd sign from natal Moon'],
                'chandrashtama' => ['active' => $moonT === 8, 'rule' => 'Transit Moon in 8th sign from natal Moon'],
                'shani_dhaiya' => ['active' => in_array($sat, [4, 8], true), 'house_from_moon' => $sat, 'rule' => 'Transit Saturn in 4th or 8th sign from natal Moon'],
                'tara' => ['index' => ($this->nakIdx($rows[1]['lon']) - $this->nakIdx($this->natalPlanet($natal, 'Moon')['lon']) + 27) % 27 % 9,
                           'rule' => 'Count from birth nakshatra to transit Moon nakshatra, modulo 9 (Janma … Param Mitra)'],
            ],
            'sade_sati_cycle' => $this->sadeSatiCycle($natalMoon, $jd, $natal['birth']),
            'dasha_now' => Dasha::current(['mahadasha' => $natal['dasha']['mahadasha']], $jd),
        ];
    }

    private function nakIdx(float $lon): int { return Zodiac::nakshatra($lon)['index']; }

    /** Sade Sati period (Saturn in 12th/1st/2nd from natal Moon) containing $jd, else the next one. Dates to the day. */
    public function sadeSatiCycle(int $moonSign, float $jd, array $birth): ?array {
        $set = [($moonSign + 11) % 12, $moonSign, ($moonSign + 1) % 12];
        $in = fn(float $t) => in_array(Zodiac::signOf($this->eph->siderealLon('Saturn', $t)), $set, true);
        $start = max($jd - 9 * 365.25, 2378497.0); $end = min($jd + 32 * 365.25, 2597640.0);
        $step = 8.0; $iv = []; $cur = null; $prev = $in($start); $pt = $start;
        $edge = function (float $a, float $b) use ($in): float { $va = $in($a); while ($b - $a > 1) { $m = ($a + $b) / 2; if ($in($m) === $va) $a = $m; else $b = $m; } return $b; };
        if ($prev) $cur = $start;
        for ($t = $start + $step; $t <= $end; $t += $step) {
            $v = $in($t);
            if ($v !== $prev) { $e = $edge($pt, $t); if ($v) $cur = $e; else { $iv[] = [$cur, $e]; $cur = null; } }
            $prev = $v; $pt = $t;
        }
        if ($cur !== null) $iv[] = [$cur, null];
        $m = [];                                            // merge retrograde gaps (< 400 days)
        foreach ($iv as $x) { if ($m && end($m)[1] !== null && $x[0] - end($m)[1] < 400) $m[count($m) - 1][1] = $x[1]; else $m[] = $x; }
        $fmt = fn($t) => $t === null ? null : TimeResolver::jdToLocal($t, $birth['tzid'], $birth['offset_source'] === 'manual' ? $birth['offset_minutes'] : null, 'Y-m-d');
        foreach ($m as [$a, $b]) {
            if ($a === $start) continue;                    // incomplete period at scan start
            if ($b === null || $b > $jd) return ['start' => $fmt($a), 'end' => $fmt($b), 'active' => $a <= $jd, 'precision_days' => 1,
                'rule' => 'Saturn first enters 12th sign from natal Moon until it finally leaves the 2nd (retrograde returns merged)'];
        }
        return null;
    }

    private function sensitivity(float $lagnaLon, float $rate, array $moon): array {
        $out = ['lagna_deg_per_min' => round($rate, 5), 'lagna' => []];
        foreach (self::SENSITIVITY_VARGAS as $d) {
            $b = Varga::boundaryDistance($lagnaLon, $d);
            $out['lagna']['D' . $d] = ['changes_if_earlier_by_min' => round($b['before'] / abs($rate), 1),
                                       'changes_if_later_by_min' => round($b['after'] / abs($rate), 1)];
        }
        $n = Zodiac::nakshatra($moon['lon']); $perMin = $moon['speed'] / 1440;
        $out['moon_nakshatra'] = ['changes_if_earlier_by_min' => round($n['fraction'] * Zodiac::NAK_SPAN / $perMin, 0),
                                  'changes_if_later_by_min' => round((1 - $n['fraction']) * Zodiac::NAK_SPAN / $perMin, 0)];
        return $out;
    }

    private function dates(array $p, array $birth): array {
        $fmt = fn($jd) => TimeResolver::jdToLocal($jd, $birth['tzid'], $birth['offset_source'] === 'manual' ? $birth['offset_minutes'] : null, 'Y-m-d');
        $p['start'] = $fmt($p['start_jd']); $p['end'] = $fmt($p['end_jd']);
        if (isset($p['antardasha'])) $p['antardasha'] = array_map(fn($a) => $this->dates($a, $birth), $p['antardasha']);
        return $p;
    }

    private function planet(array $e, string $name): array {
        foreach ($e['planets'] as $p) if ($p['name'] === $name) return $p;
        throw new CalcException("$name missing from engine output");
    }

    private function natalPlanet(array $natal, string $name): array {
        foreach ($natal['planets'] as $p) if ($p['name'] === $name) return $p;
        throw new CalcException("$name missing from kundali");
    }
}
