<?php
// Compares engine results with values copied from trusted reference software.
// php tests/compare_reference.php [path/to/reference_cases.json]   -> exit 1 on any mismatch or unfilled case
require __DIR__ . '/../src/bootstrap.php';
use App\Calc\{TimeResolver, KundaliService, PanchangService, Zodiac};

$file = $argv[1] ?? __DIR__ . '/fixtures/reference_cases.json';
$doc = json_decode(file_get_contents($file), true);
const LON_TOL_ARCSEC = 60;   // reference tools print to 1"; allow for rounding and minor model differences
const TIME_TOL_MIN = 2;
$bad = 0; $checked = 0; $todo = 0;
$signIdx = fn($v) => is_int($v) ? $v : array_search(ucfirst(strtolower(trim((string) $v))), Zodiac::SIGNS, true);
$parseLon = function ($v) {
    if (is_numeric($v)) return (float) $v;
    if (preg_match('/^(\w+)\s+(\d+)[:°\s](\d+)[:\'\s](\d+(?:\.\d+)?)/u', (string) $v, $m)) {
        $s = array_search(ucfirst(strtolower($m[1])), Zodiac::SIGNS, true);
        if ($s !== false) return $s * 30 + $m[2] + $m[3] / 60 + $m[4] / 3600;
    }
    throw new RuntimeException("Unparseable longitude: $v");
};
$report = function (bool $ok, string $what) use (&$bad, &$checked) { $checked++; if (!$ok) { $bad++; echo "MISMATCH $what\n"; } };

foreach ($doc['charts'] as $c) {
    if ($c['status'] !== 'filled') { $todo++; continue; }
    $b = $c['birth'];
    $k = (new KundaliService())->compute(TimeResolver::resolve($b['date'], $b['time'], $b['tzid'], $b['manual_offset_minutes']), $b['lat'], $b['lon']);
    $pl = []; foreach ($k['planets'] as $p) $pl[$p['name']] = $p['lon'];
    $diff = fn($a, $e) => abs(fmod($a - $e + 540, 360) - 180) * 3600;
    if ($c['expected']['lagna_lon'] !== null) { $d = $diff($k['lagna']['lon'], $parseLon($c['expected']['lagna_lon'])); $report($d <= LON_TOL_ARCSEC, "{$c['id']} lagna off by " . round($d) . '"'); }
    foreach ($c['expected']['planets'] as $n => $e) if ($e !== null) {
        $d = $diff($pl[$n], $parseLon($e)); $report($d <= LON_TOL_ARCSEC, "{$c['id']} $n off by " . round($d) . '"');
    }
    foreach (['D9', 'D10'] as $v) foreach ($c['expected'][$v] as $n => $e) if ($e !== null) {
        $got = $n === 'Lagna' ? $k['vargas'][$v]['lagna'] : $k['vargas'][$v]['planets'][$n];
        $report($got === $signIdx($e), "{$c['id']} $v $n: got " . Zodiac::SIGNS[$got] . ", reference $e");
    }
}
foreach ($doc['panchang'] as $c) {
    if ($c['status'] !== 'filled') { $todo++; continue; }
    $p = (new PanchangService())->compute($c['date'], $c['lat'], $c['lon'], $c['tzid']); $e = $c['expected'];
    $tm = fn($a, $b) => abs(strtotime($a) - strtotime($b)) / 60;
    foreach (['sunrise', 'sunset'] as $f) if ($e[$f]) $report($tm($p[$f], $e[$f]) <= TIME_TOL_MIN, "{$c['id']} $f {$p[$f]} vs {$e[$f]}");
    if ($e['tithi']) $report(stripos($e['tithi'], $p['tithi'][0]['name']) !== false, "{$c['id']} tithi {$p['tithi'][0]['name']} vs {$e['tithi']}");
    if ($e['tithi_ends']) $report($tm($p['tithi'][0]['ends'], $e['tithi_ends']) <= TIME_TOL_MIN, "{$c['id']} tithi end {$p['tithi'][0]['ends']} vs {$e['tithi_ends']}");
    if ($e['nakshatra']) $report(strcasecmp($p['nakshatra'][0]['name'], $e['nakshatra']) === 0, "{$c['id']} nakshatra {$p['nakshatra'][0]['name']} vs {$e['nakshatra']}");
    if ($e['nakshatra_ends']) $report($tm($p['nakshatra'][0]['ends'], $e['nakshatra_ends']) <= TIME_TOL_MIN, "{$c['id']} nakshatra end");
    if ($e['yoga']) $report(strcasecmp($p['yoga'][0]['name'], $e['yoga']) === 0, "{$c['id']} yoga {$p['yoga'][0]['name']} vs {$e['yoga']}");
    if ($e['karana']) $report(strcasecmp($p['karana'][0]['name'], $e['karana']) === 0, "{$c['id']} karana {$p['karana'][0]['name']} vs {$e['karana']}");
}
echo "$checked values checked, $bad mismatches, $todo cases not yet filled\n";
exit(($bad || $todo) ? 1 : 0);
