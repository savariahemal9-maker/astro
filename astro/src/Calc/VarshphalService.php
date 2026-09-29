<?php
namespace App\Calc;

use App\Core\ApiException;

/** Tajik Varshphal: chart for the moment the sidereal Sun returns to its natal longitude (solar return). */
final class VarshphalService {
    public function __construct(private Ephemeris $eph = new Ephemeris(), private KundaliService $ks = new KundaliService()) {}

    public function returnJd(float $natalSun, float $approx): float {
        $f = fn(float $t) => fmod($this->eph->siderealLon('Sun', $t) - $natalSun + 540, 360) - 180;
        $a = $approx - 3; $b = $approx + 3;
        if ($f($a) > 0 || $f($b) < 0) throw new CalcException('Solar return not bracketed');
        while ($b - $a > 1 / 86400) { $m = ($a + $b) / 2; if ($f($m) < 0) $a = $m; else $b = $m; }
        return ($a + $b) / 2;
    }

    /** $year = calendar year in which the return (birthday) falls. */
    public function compute(array $natal, int $year): array {
        $b = $natal['birth'];
        $birthYear = (int) substr($b['local'], 0, 4);
        $age = $year - $birthYear;
        if ($age < 1 || $age > 120) throw new ApiException('validation', 'year must be within 1–120 years after birth', 422);
        $sun = $natal['planets'][0]['lon'];
        $jd = $this->returnJd($sun, $b['jd_ut'] + $age * 365.256363);
        $next = $this->returnJd($sun, $jd + 365.256363);
        $local = fn($j) => TimeResolver::jdToLocal($j, $b['tzid'], $b['offset_source'] === 'manual' ? $b['offset_minutes'] : null, 'Y-m-d H:i');
        $utc = gmdate('Y-m-d\TH:i:s\Z', (int) round(($jd - 2440587.5) * 86400));
        $chart = $this->ks->compute(['jd_ut' => $jd, 'utc' => $utc, 'local' => $local($jd), 'tzid' => $b['tzid'], 'offset_minutes' => $b['offset_minutes'],
                                     'offset_source' => $b['offset_source'], 'warnings' => []], $b['lat'], $b['lon']);
        $muntha = ($natal['lagna']['sign'] + $age) % 12;
        return [
            'meta' => ['type' => 'calculated', 'method' => 'Tajik solar return (sidereal Sun returns to natal longitude), cast for birth place',
                       'engine' => $chart['meta']['engine'], 'settings' => $chart['meta']['settings']],
            'year' => $year, 'age' => $age, 'return_local' => $local($jd), 'return_utc' => $utc, 'valid_until_local' => $local($next),
            'lagna' => $chart['lagna'], 'planets' => $chart['planets'], 'vargas' => ['D1' => $chart['vargas']['D1']],
            'muntha' => ['sign' => $muntha, 'sign_name' => Zodiac::SIGNS[$muntha], 'house' => ($muntha - $chart['lagna']['sign'] + 12) % 12 + 1,
                         'rule' => 'Muntha = natal lagna sign advanced one sign per completed year'],
        ];
    }
}
