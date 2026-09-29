<?php
namespace App\Calc;

use App\Core\ApiException;

final class PanchangService {
    // 1-based eighth of daytime, index by weekday (Sun=0)
    private const RAHU = [8, 2, 7, 5, 6, 4, 3];
    private const YAMAGANDA = [5, 4, 3, 2, 1, 7, 6];
    private const GULIKA = [7, 6, 5, 4, 3, 2, 1];

    public function __construct(private Ephemeris $eph = new Ephemeris()) {}

    // Choghadiya: 8 equal parts of day (sunrise→sunset) and night (sunset→next sunrise)
    public const CHOG = ['Udveg', 'Char', 'Labh', 'Amrit', 'Kaal', 'Shubh', 'Rog'];
    private const CHOG_DAY = [0, 3, 6, 2, 5, 1, 4];   // start index by weekday (Sun..Sat)
    private const CHOG_NIGHT = [5, 1, 4, 0, 3, 6, 2];
    private function choghadiya(int $wd, array $e, callable $t): array {
        $q = fn($n) => in_array($n, ['Amrit', 'Shubh', 'Labh'], true) ? 'good' : ($n === 'Char' ? 'neutral' : 'bad');
        $mk = function (float $a, float $b, int $start, int $step) use ($t, $q) { $d = ($b - $a) / 8; $out = [];
            for ($i = 0; $i < 8; $i++) { $n = self::CHOG[($start + $i * $step) % 7]; $out[] = ['name' => $n, 'quality' => $q($n), 'start' => $t($a + $i * $d), 'end' => $t($a + ($i + 1) * $d)]; }
            return $out; };
        return ['day' => $mk($e['sunrise_jd'], $e['sunset_jd'], self::CHOG_DAY[$wd], 1), 'night' => $mk($e['sunset_jd'], $e['next_sunrise_jd'], self::CHOG_NIGHT[$wd], 5),
                'rule' => 'Day and night each divided into 8 equal parts; sequence starts from the weekday lord'];
    }
    /** Planet positions at sunrise for the place (whole-sign chart). */
    private function chart(float $jd, float $lat, float $lon, string $tzid): array {
        $utc = gmdate('Y-m-d\TH:i:s\Z', (int) round(($jd - 2440587.5) * 86400));
        $k = (new KundaliService($this->eph))->compute(['jd_ut' => $jd, 'utc' => $utc, 'local' => TimeResolver::jdToLocal($jd, $tzid), 'tzid' => $tzid,
            'offset_minutes' => 0, 'offset_source' => 'tzdb', 'warnings' => []], $lat, $lon);
        return ['lagna' => $k['lagna'], 'planets' => $k['planets'], 'vargas' => ['D1' => $k['vargas']['D1']]];
    }

    public function compute(string $date, float $lat, float $lon, string $tzid): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new ApiException('invalid_date', 'Date must be YYYY-MM-DD', 422);
        try { $tz = new \DateTimeZone($tzid); } catch (\Exception) { throw new ApiException('invalid_timezone', 'Unknown timezone', 422); }
        $midnight = new \DateTimeImmutable($date . ' 00:00:00', $tz);
        $jd0 = $midnight->getTimestamp() / 86400 + 2440587.5;
        $e = $this->eph->panchang($jd0, $lat, $lon);
        $t = fn(float $jd) => $jd < 0 ? null : TimeResolver::jdToLocal($jd, $tzid, null, 'Y-m-d H:i');

        $wd = (int) $midnight->format('w');
        $day = ($e['sunset_jd'] - $e['sunrise_jd']) / 8;
        $period = fn(int $k) => ['start' => $t($e['sunrise_jd'] + ($k - 1) * $day), 'end' => $t($e['sunrise_jd'] + $k * $day)];
        $seg = function (array $list, callable $name) use ($t) {
            return array_map(function ($x) use ($t, $name) {
                $n = $name($x['index']);
                return ['index' => $x['index']] + (is_array($n) ? $n : ['name' => $n]) + ['ends' => $t($x['end_jd']), 'end_jd' => $x['end_jd']];
            }, $list);
        };
        return [
            'meta' => ['type' => 'calculated', 'engine' => $e['engine'], 'settings' => $this->eph->settings(),
                       'ayanamsa_dms' => Zodiac::dms($e['ayanamsa_deg']), 'day_definition' => 'sunrise to next sunrise'],
            'date' => $date, 'location' => ['lat' => $lat, 'lon' => $lon, 'tzid' => $tzid],
            'vara' => ['index' => $wd, 'name' => Zodiac::WEEKDAYS[$wd], 'lord' => Zodiac::WEEKDAY_LORDS[$wd]],
            'sunrise' => $t($e['sunrise_jd']), 'sunset' => $t($e['sunset_jd']), 'next_sunrise' => $t($e['next_sunrise_jd']),
            'moonrise' => $t($e['moonrise_jd']), 'moonset' => $t($e['moonset_jd']),
            'tithi' => $seg($e['tithi'], fn($i) => Zodiac::tithiName($i)),
            'nakshatra' => $seg($e['nakshatra'], fn($i) => ['name' => Zodiac::NAKSHATRAS[$i], 'lord' => Zodiac::DASHA_ORDER[$i % 9]]),
            'yoga' => $seg($e['yoga'], fn($i) => Zodiac::YOGAS[$i]),
            'karana' => $seg($e['karana'], fn($i) => Zodiac::karanaName($i)),
            'sun_sign' => Zodiac::SIGNS[Zodiac::signOf($e['sun_sid_at_sunrise'])],
            'moon_sign' => Zodiac::SIGNS[Zodiac::signOf($e['moon_sid_at_sunrise'])],
            'choghadiya' => $this->choghadiya($wd, $e, $t),
            'chart' => $this->chart($e['sunrise_jd'], $lat, $lon, $tzid),
            'rahu_kaal' => $period(self::RAHU[$wd]), 'yamaganda' => $period(self::YAMAGANDA[$wd]), 'gulika' => $period(self::GULIKA[$wd]),
        ];
    }
}
