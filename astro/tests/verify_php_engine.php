<?php
// Compares the pure-PHP engine with the compiled Swiss Ephemeris engine. Needs engine/bin/astro_calc (dev machine only).
// php tests/verify_php_engine.php [samples]
require __DIR__ . '/../src/bootstrap.php';
use App\Calc\{Ephemeris, PhpEngine};
$n = (int) ($argv[1] ?? 2000); mt_srand(7);
$c = app_config(); $bin = new Ephemeris(['backend' => 'binary'] + $c['engine'], $c['calc']);
$php = new PhpEngine($c['engine']['data'], $c['calc']['node'], $c['calc']['rise']);
$d = fn($a, $b) => abs(fmod($a - $b + 540, 360) - 180) * 3600;
$max = []; $upd = function ($k, $v) use (&$max) { $max[$k] = max($max[$k] ?? 0, $v); };
for ($i = 0; $i < $n; $i++) {
    $jd = 2378497 + mt_rand() / mt_getrandmax() * (2597640 - 2378497);
    $lat = mt_rand(-6000, 6000) / 100; $lon = mt_rand(-18000, 18000) / 100;
    $a = $bin->chart($jd, $lat, $lon); $b = $php->chart($jd, $lat, $lon);
    foreach ($a['planets'] as $k => $p) $upd($p['name'], $d($p['lon'], $b['planets'][$k]['lon']));
    $upd('Lagna', $d($a['ascendant']['lon'], $b['ascendant']['lon']));
    $upd('ayanamsa', abs($a['ayanamsa_deg'] - $b['ayanamsa_deg']) * 3600);
}
$tmax = [];
for ($i = 0; $i < max(1, intdiv($n, 10)); $i++) {
    $jd = floor(2378500 + mt_rand() / mt_getrandmax() * 219000) + 0.5 - mt_rand(-12, 12) / 24;
    $lat = mt_rand(-5500, 5500) / 100; $lon = mt_rand(-18000, 18000) / 100;
    $a = $bin->panchang($jd, $lat, $lon); $b = $php->panchang($jd, $lat, $lon);
    foreach (['sunrise_jd', 'sunset_jd', 'moonrise_jd', 'moonset_jd'] as $k)
        if ($a[$k] > 0 && $b[$k] > 0) $tmax[$k] = max($tmax[$k] ?? 0, abs($a[$k] - $b[$k]) * 86400);
        elseif (($a[$k] > 0) !== ($b[$k] > 0)) $tmax[$k . '_presence'] = ($tmax[$k . '_presence'] ?? 0) + 1;
    foreach (['tithi', 'nakshatra', 'yoga', 'karana'] as $k) {
        if (array_column($a[$k], 'index') !== array_column($b[$k], 'index')) { $tmax[$k . '_index_mismatch'] = ($tmax[$k . '_index_mismatch'] ?? 0) + 1; continue; }
        foreach ($a[$k] as $j => $s) $tmax[$k] = max($tmax[$k] ?? 0, abs($s['end_jd'] - $b[$k][$j]['end_jd']) * 86400);
    }
}
$lim = ['Sun' => 1, 'Moon' => 1, 'Mars' => 3, 'Mercury' => 3, 'Jupiter' => 3, 'Venus' => 3, 'Saturn' => 3, 'Rahu' => 1, 'Ketu' => 1, 'Lagna' => 5, 'ayanamsa' => 0.01];
$tl = ['sunrise_jd' => 3, 'sunset_jd' => 3, 'moonrise_jd' => 20, 'moonset_jd' => 20, 'tithi' => 10, 'nakshatra' => 10, 'yoga' => 10, 'karana' => 10];
$fail = 0;
foreach ($max as $k => $v) { $ok = $v <= $lim[$k]; $fail += !$ok; printf("%-9s max %8.3f\"  (limit %s\")%s\n", $k, $v, $lim[$k], $ok ? '' : '  FAIL'); }
foreach ($tmax as $k => $v) { $ok = isset($tl[$k]) && $v <= $tl[$k]; $fail += !$ok; printf("%-24s %8.2f %s%s\n", $k, $v, isset($tl[$k]) ? "s (limit {$tl[$k]}s)" : 'cases', $ok ? '' : '  FAIL'); }
echo $fail ? "FAILED\n" : "PASSED ($n charts, " . max(1, intdiv($n, 10)) . " panchang days)\n"; exit($fail ? 1 : 0);
