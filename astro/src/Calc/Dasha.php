<?php
namespace App\Calc;

/** Vimshottari dasha from the Moon's sidereal longitude. Pure arithmetic on engine output. */
final class Dasha {
    public static function vimshottari(float $moonLon, float $birthJd, float $yearDays, int $levels = 2): array {
        $nak = Zodiac::nakshatra($moonLon);
        $startLord = $nak['lord'];
        $startIdx = array_search($startLord, Zodiac::DASHA_ORDER, true);
        $elapsedYears = $nak['fraction'] * Zodiac::DASHA_YEARS[$startLord];
        $t = $birthJd - $elapsedYears * $yearDays;
        $out = [];
        for ($k = 0; $k < 9; $k++) {
            $lord = Zodiac::DASHA_ORDER[($startIdx + $k) % 9];
            $len = Zodiac::DASHA_YEARS[$lord] * $yearDays;
            $md = ['lord' => $lord, 'start_jd' => $t, 'end_jd' => $t + $len];
            if ($levels > 1) $md['antardasha'] = self::sub($lord, $t, $len);
            $out[] = $md; $t += $len;
        }
        return ['moon_nakshatra' => $nak['name'], 'start_lord' => $startLord,
                'balance_years_at_birth' => Zodiac::DASHA_YEARS[$startLord] - $elapsedYears,
                'year_days' => $yearDays, 'mahadasha' => $out];
    }

    private static function sub(string $lord, float $start, float $len): array {
        $i = array_search($lord, Zodiac::DASHA_ORDER, true); $out = []; $t = $start;
        for ($k = 0; $k < 9; $k++) {
            $l = Zodiac::DASHA_ORDER[($i + $k) % 9];
            $d = $len * Zodiac::DASHA_YEARS[$l] / 120;
            $out[] = ['lord' => $l, 'start_jd' => $t, 'end_jd' => $t + $d]; $t += $d;
        }
        return $out;
    }

    public static function current(array $v, float $jd): ?array {
        foreach ($v['mahadasha'] as $md) if ($jd >= $md['start_jd'] && $jd < $md['end_jd']) {
            foreach ($md['antardasha'] ?? [] as $ad) if ($jd >= $ad['start_jd'] && $jd < $ad['end_jd'])
                return ['mahadasha' => $md['lord'], 'antardasha' => $ad['lord'], 'md_end_jd' => $md['end_jd'], 'ad_end_jd' => $ad['end_jd']];
            return ['mahadasha' => $md['lord'], 'antardasha' => null, 'md_end_jd' => $md['end_jd']];
        }
        return null;
    }
}
