<?php
// php tests/unit.php   — no DB needed. Exit code 1 on any failure.
require __DIR__ . '/../src/bootstrap.php';
use App\Calc\{Varga, Zodiac, Dasha, TimeResolver, Ephemeris};

$fail = 0; $n = 0;
function ok(bool $c, string $m) { global $fail, $n; $n++; if (!$c) { $fail++; echo "FAIL: $m\n"; } }
function near(float $a, float $b, float $tol, string $m) { ok(abs($a - $b) <= $tol, "$m (got $a, want $b ±$tol)"); }

// ---- Vargas: rule implementation vs independent formulations ----
for ($i = 0; $i < 20000; $i++) {
    $lon = mt_rand(0, 35999999) / 100000;
    $s = intdiv((int) floor($lon), 30);
    ok(Varga::sign($lon, 9) === ((int) floor($lon / (30 / 9))) % 12, "D9 absolute formula @$lon");
    ok(Varga::sign($lon, 27) === ((int) floor($lon / (30 / 27))) % 12, "D27 absolute formula @$lon");
    ok(Varga::sign($lon, 12) === ($s + (int) floor(fmod($lon, 30) / 2.5)) % 12, "D12 @$lon");
    ok(Varga::sign($lon, 1) === $s, "D1 @$lon");
}
// Hand-derived cases (sign index Aries=0). Derivation per Parashari rules:
//  5.0   Aries 5° (odd, movable): hora Leo; drekkana Aries; D4 Aries; D7 part1 from Aries=Taurus; D9 part1=Taurus; D10 part1=Taurus; D30 5-10 Aquarius
//  45.0  Taurus 15° (even, fixed): hora Leo; drekkana 2nd=Virgo; D4 part2=Scorpio; D7 from Scorpio part3=Aquarius; D9 from Capricorn part4=Taurus; D10 from Capricorn part5=Gemini; D30 12-20 Pisces
//  100.0 Cancer 10° (even, movable): hora Cancer; drekkana 2nd=Scorpio; D4 part1=Libra; D7 from Capricorn part2=Pisces; D9 part3=Libra; D10 from Pisces part3=Gemini; D30 5-12 Virgo
//  359.9 Pisces 29.9° (even, dual): hora Leo; drekkana 3rd=Scorpio; D4 part3=Sagittarius; D7 from Virgo part6=Pisces; D9 from Cancer part8=Pisces; D10 from Scorpio part9=Leo; D30 25-30 Scorpio
$exp = [
  [5.0,   ['D2'=>4,'D3'=>0,'D4'=>0,'D7'=>1,'D9'=>1,'D10'=>1,'D30'=>10]],
  [45.0,  ['D2'=>4,'D3'=>5,'D4'=>7,'D7'=>10,'D9'=>1,'D10'=>2,'D30'=>11]],
  [100.0, ['D2'=>3,'D3'=>7,'D4'=>6,'D7'=>11,'D9'=>6,'D10'=>2,'D30'=>5]],
  [359.9, ['D2'=>4,'D3'=>7,'D4'=>8,'D7'=>11,'D9'=>11,'D10'=>4,'D30'=>7]],
];
foreach ($exp as [$lon, $want]) foreach ($want as $d => $s) ok(Varga::sign($lon, (int) substr($d, 1)) === $s, "$d @ $lon: got " . Varga::sign($lon, (int) substr($d, 1)) . " want $s");

// ---- Nakshatra / pada ----
$nk = Zodiac::nakshatra(0.0); ok($nk['name'] === 'Ashwini' && $nk['pada'] === 1, 'Ashwini 1');
$nk = Zodiac::nakshatra(359.99); ok($nk['name'] === 'Revati' && $nk['pada'] === 4, 'Revati 4');
$nk = Zodiac::nakshatra(120.0); ok($nk['name'] === 'Magha' && $nk['pada'] === 1, 'Magha starts 120°');
// ---- Karana mapping: 60 half-tithis ----
ok(Zodiac::karanaName(0) === 'Kimstughna' && Zodiac::karanaName(1) === 'Bava' && Zodiac::karanaName(56) === 'Vishti'
   && Zodiac::karanaName(57) === 'Shakuni' && Zodiac::karanaName(59) === 'Naga', 'karana sequence');

// ---- Dasha arithmetic ----
$v = Dasha::vimshottari(0.0, 2451545.0, 365.2425);           // Moon at 0° Ashwini: full Ketu dasha from birth
ok($v['start_lord'] === 'Ketu', 'dasha start lord'); near($v['balance_years_at_birth'], 7, 1e-9, 'full Ketu balance');
near(end($v['mahadasha'])['end_jd'] - $v['mahadasha'][0]['start_jd'], 120 * 365.2425, 1e-6, '120-year cycle');
foreach ($v['mahadasha'] as $md) near(end($md['antardasha'])['end_jd'], $md['end_jd'], 1e-6, "AD sums to MD {$md['lord']}");
$v = Dasha::vimshottari(Zodiac::NAK_SPAN * 1.5, 2451545.0, 365.2425); // halfway through Bharani (Venus)
ok($v['start_lord'] === 'Venus', 'Bharani -> Venus'); near($v['balance_years_at_birth'], 10, 1e-9, 'half Venus balance');

// ---- Time resolution ----
$r = TimeResolver::resolve('1990-05-15', '14:30', 'Asia/Kolkata'); ok($r['utc'] === '1990-05-15T09:00:00Z', 'IST 1990');
$r = TimeResolver::resolve('1943-06-01', '12:00', 'Asia/Kolkata'); ok($r['offset_minutes'] === 390 && $r['warnings'], 'India war time + warning');
$r = TimeResolver::resolve('1950-01-01', '12:00', 'Asia/Kolkata', 291); ok($r['utc'] === '1950-01-01T07:09:00Z', 'manual Bombay Time offset');
$r = TimeResolver::resolve('2000-01-01', '12:00', 'UTC'); near($r['jd_ut'], 2451545.0, 1e-9, 'J2000 JD');
try { TimeResolver::resolve('2021-03-14', '02:30', 'America/New_York'); ok(false, 'DST gap must throw'); } catch (App\Core\ApiException $e) { ok($e->errCode === 'nonexistent_local_time', 'DST gap'); }
try { TimeResolver::resolve('2021-11-07', '01:30', 'America/New_York'); ok(false, 'DST overlap must throw'); } catch (App\Core\ApiException $e) { ok($e->errCode === 'ambiguous_local_time', 'DST overlap'); }
ok(TimeResolver::resolve('2021-11-07', '01:30', 'America/New_York', null, 'later')['utc'] === '2021-11-07T06:30:00Z', 'fold later');
try { TimeResolver::resolve('2023-02-29', '10:00', 'UTC'); ok(false, 'invalid date'); } catch (App\Core\ApiException $e) { ok(true, ''); }

// ---- Engine vs known astronomical events (UTC, published by USNO/NASA). Tolerance 2 min. ----
$eph = new Ephemeris();
$trop = function (float $jd, string $body) use ($eph): float { foreach ($eph->chart($jd, 0, 0)['planets'] as $p) if ($p['name'] === $body) return $p['tropical_lon']; };
$findSun = function (float $jd0, float $target) use ($trop) {           // moment Sun reaches target tropical longitude
    $a = $jd0 - 1; $b = $jd0 + 1;
    $f = fn($t) => fmod($trop($t, 'Sun') - $target + 540, 360) - 180;
    while ($b - $a > 1 / 86400) { $m = ($a + $b) / 2; if ($f($m) < 0) $a = $m; else $b = $m; } return $a;
};
$findPhase = function (float $jd0, float $target) use ($trop) {         // moment Moon-Sun elongation = target
    $a = $jd0 - 0.5; $b = $jd0 + 0.5;
    $f = fn($t) => fmod($trop($t, 'Moon') - $trop($t, 'Sun') - $target + 540, 360) - 180;
    while ($b - $a > 1 / 86400) { $m = ($a + $b) / 2; if ($f($m) < 0) $a = $m; else $b = $m; } return $a;
};
$jd = fn(string $utc) => strtotime($utc . ' UTC') / 86400 + 2440587.5;
try {
    foreach ([['2024-03-20 03:06', 0], ['2024-06-20 20:51', 90], ['2024-09-22 12:44', 180], ['2024-12-21 09:20', 270]] as [$t, $L])
        near(($findSun($jd($t), $L) - $jd($t)) * 1440, 0, 2, "Sun at {$L}° vs published $t UTC (minutes)");
    foreach ([['2024-04-08 18:21', 0], ['2024-01-25 17:54', 180]] as [$t, $E])
        near(($findPhase($jd($t), $E) - $jd($t)) * 1440, 0, 2, "Moon phase {$E}° vs published $t UTC (minutes)");
    near($eph->info()['ayanamsa_deg'], 23.8571, 0.001, 'Lahiri ayanamsa at J2000 (23°51\'26")');
} catch (App\Calc\CalcException $e) { ok(false, 'engine: ' . $e->getMessage()); }


// ---- Analysis rules (synthetic charts) ----
use App\Calc\Analysis;
$mk = fn(array $lons, int $lagna) => array_map(fn($n, $l) => ['name' => $n, 'lon' => $l, 'sign' => intdiv((int) $l, 30), 'sign_name' => Zodiac::SIGNS[intdiv((int) $l, 30)],
    'house' => (intdiv((int) $l, 30) - $lagna + 12) % 12 + 1, 'retrograde' => false], array_keys($lons), $lons);
$lag = ['sign' => 0];
$pl = $mk(['Sun' => 10, 'Moon' => 40, 'Mars' => 180, 'Mercury' => 20, 'Jupiter' => 60, 'Venus' => 70, 'Saturn' => 100, 'Rahu' => 5, 'Ketu' => 185], 0);
$a = Analysis::natal($lag, $pl);
ok($a['mangal_dosha']['from']['lagna'] === ['house' => 7, 'present' => true] && $a['mangal_dosha']['present'], 'Mangal from lagna 7th (Mars 180° = Libra)');
ok($a['kaal_sarp']['present'] && $a['kaal_sarp']['type'] === 'Anant', 'Kaal Sarp (all planets 5°..185°, Rahu in 1st = Anant)');
$pl2 = $mk(['Sun' => 10, 'Moon' => 40, 'Mars' => 250, 'Mercury' => 20, 'Jupiter' => 60, 'Venus' => 70, 'Saturn' => 100, 'Rahu' => 5, 'Ketu' => 185], 0);
ok(!Analysis::natal($lag, $pl2)['kaal_sarp']['present'], 'No Kaal Sarp when Mars across the axis');
ok(Analysis::combust(['name' => 'Venus', 'lon' => 108, 'retrograde' => true], 100) && !Analysis::combust(['name' => 'Venus', 'lon' => 109, 'retrograde' => true], 100), 'Venus retro combust orb 8°');
ok(Analysis::lordships(4)['Mars'] === [4, 9], 'Leo lagna: Mars rules 4 (Scorpio) and 9 (Aries)');
// ---- Sade Sati dates for Capricorn Moon (Saturn entered sidereal Sagittarius 2017-01-26, left Aquarius 2025-03-29) ----
$ss = (new App\Calc\KundaliService())->sadeSatiCycle(9, 2458850.0, ['tzid' => 'Asia/Kolkata', 'offset_source' => 'tzdb', 'offset_minutes' => 330]);
ok(abs(strtotime($ss['start']) - strtotime('2017-01-26')) <= 2 * 86400 && abs(strtotime($ss['end']) - strtotime('2025-03-29')) <= 2 * 86400, "Sade Sati dates {$ss['start']}..{$ss['end']}");

echo ($fail ? "$fail of $n checks FAILED\n" : "All $n checks passed\n");
exit($fail ? 1 : 0);
