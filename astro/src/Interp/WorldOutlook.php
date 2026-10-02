<?php
namespace App\Interp;

use App\Calc\{Ephemeris, PanchangService, TimeResolver, Zodiac};
use App\Core\{ApiException, Db};

/**
 * Country & World Outlook — generates astrological analysis per topic/scope/period.
 * Uses the real ephemeris engine for all planetary data; no astronomical data is invented.
 * AI (Gemini) writes the prose; rule-based fallback is used when AI is unavailable.
 */
final class WorldOutlook {
    public const DISCLAIMER = [
        'en' => 'This is a traditional Vedic astrological interpretation only — not a verified financial, weather, security or factual forecast.',
        'hi' => 'यह केवल पारंपरिक वैदिक ज्योतिष व्याख्या है — यह कोई वित्तीय, मौसम, सुरक्षा या तथ्यात्मक भविष्यवाणी नहीं है।',
        'gu' => 'આ માત્ર પારંપારિક વૈદિક જ્યોતિષ અર્થઘટન છે — આ કોઈ નાણાકીય, હવામાન, સુરક્ષા અથવા ઘટના-આધારિત આગાહી નથી।',
    ];

    public static function countries(): array {
        return Db::all('SELECT id, name, iso_code, ref_place, ref_source FROM world_countries WHERE enabled=1 ORDER BY name');
    }

    public static function topics(string $lang = 'en'): array {
        $col = in_array($lang, ['hi', 'gu'], true) ? 'label_' . $lang : 'label_en';
        return Db::all("SELECT slug, $col AS label FROM world_topics WHERE enabled=1 ORDER BY sort_order, id");
    }

    /** Returns cached or freshly generated report. */
    public static function get(string $scope, ?int $countryId, string $period, string $date, string $topic, string $lang): array {
        if (!in_array($period, ['daily', 'weekly', 'monthly', 'yearly'], true)) throw new ApiException('validation', 'Invalid period', 422);
        if (!in_array($lang, ['en', 'hi', 'gu'], true)) $lang = 'en';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new ApiException('validation', 'Invalid date', 422);
        if (!in_array($scope, ['india', 'country', 'world'], true)) throw new ApiException('validation', 'Invalid scope', 422);

        $country = self::resolveCountry($scope, $countryId);
        $countryId = $scope === 'world' ? null : (int) $country['id'];
        [$from, $to, $midDate] = self::dateRange($date, $period);

        // Cache key includes country updated_at to bust on chart/rule changes
        $cacheKey = md5("$scope|$countryId|$topic|$period|$from|$to|$lang|" . ($country['updated_at'] ?? ''));
        $row = Db::one('SELECT content, calc_json, generated_at FROM world_reports WHERE cache_key=?', [$cacheKey]);
        if ($row) return self::wrap($row['content'], $row['calc_json'], $row['generated_at'], $lang, $country, $from, $to, true);

        // Calculate planetary positions at the midpoint date
        $eph = new Ephemeris();
        $lat = (float) ($country['ref_lat'] ?? 20.5937);
        $lon = (float) ($country['ref_lon'] ?? 78.9629);
        $tzid = (string) ($country['ref_tzid'] ?? 'Asia/Kolkata');

        $midDt = new \DateTimeImmutable($midDate, new \DateTimeZone($tzid));
        $jdUt = $midDt->setTime(12, 0)->getTimestamp() / 86400 + 2440587.5;
        $transit = $eph->chart($jdUt, $lat, $lon);

        // Natal chart if country has a reference date
        $natal = null;
        if (!empty($country['ref_date'])) {
            try {
                $res = TimeResolver::resolve($country['ref_date'], $country['ref_time'] ?? '00:00:00', $tzid, null, null);
                $natal = $eph->chart($res['jd_ut'], $lat, $lon);
            } catch (\Throwable) {}
        }

        // Panchang for the midpoint date at the reference location
        try {
            $panchang = (new PanchangService())->compute($midDate, $lat, $lon, $tzid);
        } catch (\Throwable) { $panchang = null; }

        $calc = self::buildCalc($transit, $natal, $panchang, $from, $to, $country, $scope);
        $content = AiChat::enabled() ? self::aiGenerate($calc, $topic, $lang, $scope, $country) : null;
        $content ??= self::ruleFallback($calc, $topic, $lang);

        $calcJson = json_encode($calc, JSON_UNESCAPED_UNICODE);
        Db::exec('INSERT INTO world_reports (scope, country_id, topic_slug, period, date_from, date_to, lang, content, calc_json, cache_key)
                  VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE content=VALUES(content), calc_json=VALUES(calc_json), generated_at=UTC_TIMESTAMP()',
            [$scope, $countryId, $topic, $period, $from, $to, $lang, $content, $calcJson, $cacheKey]);

        return self::wrap($content, $calcJson, date('Y-m-d H:i:s'), $lang, $country, $from, $to, false);
    }

    private static function wrap(string $content, string $calcJson, string $generatedAt, string $lang, array $country, string $from, string $to, bool $cached): array {
        return ['content' => $content, 'calc' => json_decode($calcJson, true), 'generated_at' => $generatedAt,
            'from_cache' => $cached, 'period_from' => $from, 'period_to' => $to,
            'reference' => ['place' => $country['ref_place'] ?? '', 'source' => $country['ref_source'] ?? ''],
            'disclaimer' => self::DISCLAIMER[$lang] ?? self::DISCLAIMER['en']];
    }

    private static function resolveCountry(string $scope, ?int $countryId): array {
        if ($scope === 'world') return ['id' => 0, 'name' => 'World', 'ref_lat' => 0, 'ref_lon' => 0, 'ref_tzid' => 'UTC',
            'ref_date' => null, 'ref_time' => null, 'ref_place' => 'Global Transits', 'ref_source' => 'Global planetary transits only — no fixed world birth chart is assumed.', 'updated_at' => ''];
        if ($scope === 'india') $countryId = 1;
        if (!$countryId) throw new ApiException('validation', 'country_id required', 422);
        $c = Db::one('SELECT * FROM world_countries WHERE id=? AND enabled=1', [$countryId]);
        if (!$c) throw new ApiException('not_found', 'Country not found', 404);
        return $c;
    }

    private static function dateRange(string $date, string $period): array {
        $dt = new \DateTimeImmutable($date);
        return match ($period) {
            'daily' => [$dt->format('Y-m-d'), $dt->format('Y-m-d'), $dt->format('Y-m-d')],
            'weekly' => [($m = $dt->modify('monday this week'))->format('Y-m-d'), $m->modify('+6 days')->format('Y-m-d'), $m->modify('+3 days')->format('Y-m-d')],
            'monthly' => [($f = $dt->modify('first day of this month'))->format('Y-m-d'), $dt->modify('last day of this month')->format('Y-m-d'), $f->modify('+14 days')->format('Y-m-d')],
            'yearly' => [($f = new \DateTimeImmutable($dt->format('Y') . '-01-01'))->format('Y-m-d'), $dt->format('Y') . '-12-31', $f->modify('+180 days')->format('Y-m-d')],
        };
    }

    private static function buildCalc(array $transit, ?array $natal, ?array $panchang, string $from, string $to, array $country, string $scope): array {
        $planets = [];
        foreach ($transit['planets'] ?? [] as $p) {
            $sign = (int) ($p['lon'] / 30);
            $planets[$p['name']] = ['lon' => round((float) $p['lon'], 2), 'sign' => Zodiac::SIGNS[$sign] ?? $sign,
                'retrograde' => (bool) ($p['retrograde'] ?? false), 'dignity' => $p['dignity'] ?? null, 'combust' => (bool) ($p['combust'] ?? false)];
        }
        $calc = ['scope' => $scope, 'period' => ['from' => $from, 'to' => $to], 'transit_planets' => $planets,
            'reference' => ['place' => $country['ref_place'] ?? '', 'source' => $country['ref_source'] ?? '', 'chart_date' => $country['ref_date'] ?? null]];
        if ($panchang) $calc['panchang'] = ['tithi' => $panchang['tithi'][0]['name'] ?? '', 'nakshatra' => $panchang['nakshatra'][0]['name'] ?? '',
            'yoga' => $panchang['yoga'][0]['name'] ?? '', 'vara' => $panchang['vara']['name'] ?? ''];
        if ($natal) {
            $nHouses = [];
            foreach ($natal['planets'] ?? [] as $p) $nHouses[$p['name']] = (int) ($p['lon'] / 30);
            $calc['natal'] = ['asc_sign' => Zodiac::SIGNS[$natal['ascendant_sign'] ?? 0] ?? '', 'planet_signs' => $nHouses];
        }
        return $calc;
    }

    private static function aiGenerate(array $calc, string $topic, string $lang, string $scope, array $country): ?string {
        $scopeLabel = $scope === 'world' ? 'World (global transits)' : $country['name'];
        $langName = ['en' => 'English', 'hi' => 'Hindi', 'gu' => 'Gujarati'][$lang] ?? 'English';
        $retro = array_keys(array_filter($calc['transit_planets'], fn($p) => $p['retrograde']));

        $sys = "You are a traditional Vedic astrology analyst.\n"
            . "Rules:\n"
            . "- Use ONLY the planetary data provided. Never invent astronomical positions.\n"
            . "- Write 10-15 sentences (~200 words) in $langName as prose paragraphs.\n"
            . "- Cover: overall outlook, relevant planetary factors, traditional rules linking them to the topic, supportive and challenging indications, specific time windows only where data supports them, and any conflicting signals.\n"
            . "- Do NOT fabricate price targets, exact events, weather figures, or accuracy percentages.\n"
            . "- Use the natal reference chart only to identify transit-to-natal house placements — do not invent natal positions.\n"
            . "- Return JSON only: {\"content\": \"<text>\"}\n";

        $user = "Scope: $scopeLabel\nTopic: $topic\nPeriod: {$calc['period']['from']} to {$calc['period']['to']}\n"
            . "Reference: {$calc['reference']['source']}\n"
            . "Transit planets: " . json_encode($calc['transit_planets']) . "\n"
            . ($retro ? "Retrograde: " . implode(', ', $retro) . "\n" : '')
            . (isset($calc['panchang']) ? "Panchang: " . json_encode($calc['panchang']) . "\n" : '')
            . (isset($calc['natal']) ? "Natal chart asc sign: {$calc['natal']['asc_sign']}\n" : '');

        $j = AiChat::generate($sys, $user, $lang, 0.65);
        return $j;
    }

    private static function ruleFallback(array $calc, string $topic, string $lang): string {
        $retro = array_keys(array_filter($calc['transit_planets'], fn($p) => $p['retrograde']));
        $pc = $calc['panchang'] ?? [];
        $from = $calc['period']['from']; $to = $calc['period']['to'];
        $msgs = [
            'en' => "Astrological outlook for \"$topic\" — period $from to $to. "
                . ($retro ? 'Planets currently in retrograde motion: ' . implode(', ', $retro) . '. Retrograde planets may bring delays, reversals or internalization in their significations. ' : '')
                . (isset($pc['tithi']) ? "Panchang: Tithi {$pc['tithi']}, Nakshatra {$pc['nakshatra']}, Vara {$pc['vara']}. " : '')
                . 'A full AI-generated analysis is not available at this time. The above is a basic rule-based summary only.',
            'hi' => "\"$topic\" के लिए ज्योतिषीय दृष्टिकोण — अवधि $from से $to। "
                . ($retro ? 'वक्री ग्रह: ' . implode(', ', $retro) . '। ' : '')
                . (isset($pc['tithi']) ? "पंचांग: तिथि {$pc['tithi']}, नक्षत्र {$pc['nakshatra']}। " : '')
                . 'AI-आधारित विस्तृत विश्लेषण अभी उपलब्ध नहीं है।',
            'gu' => "\"$topic\" માટે જ્યોતિષ દૃષ્ટિકોણ — $from થી $to। "
                . ($retro ? 'વક્રી ગ્રહ: ' . implode(', ', $retro) . '। ' : '')
                . (isset($pc['tithi']) ? "પંચાંગ: તિથિ {$pc['tithi']}, નક્ષત્ર {$pc['nakshatra']}। " : '')
                . 'AI-વિશ્લેષણ ઉપ્લ્બ્ધ નથી।',
        ];
        return $msgs[$lang] ?? $msgs['en'];
    }
}
