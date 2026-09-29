<?php
namespace App\Geo;

use App\Core\{ApiException, Db};

/** Place search (OpenStreetMap Nominatim) + timezone lookup (GeoNames, or Asia/Kolkata for India). */
final class GeoService {
    public static function search(string $q): array {
        $q = trim($q);
        if (mb_strlen($q) < 3) throw new ApiException('validation', 'Type at least 3 characters', 422);
        $key = hash('sha256', 'search|' . mb_strtolower($q));
        if ($c = Db::one('SELECT payload FROM geo_cache WHERE query_hash = ?', [$key])) return json_decode($c['payload'], true);
        $cfg = app_config()['geo'];
        // Primary: Open-Meteo geocoding (free, no key, includes timezone). Fallback: Nominatim.
        try {
            $raw = self::get('https://geocoding-api.open-meteo.com/v1/search?' . http_build_query(['name' => $q, 'count' => 10, 'language' => 'en', 'format' => 'json']), $cfg['user_agent']);
            $out = [];
            foreach (json_decode($raw, true)['results'] ?? [] as $r) {
                $out[] = ['name' => implode(', ', array_filter([$r['name'] ?? '', $r['admin2'] ?? '', $r['admin1'] ?? '', $r['country'] ?? ''], fn($x) => $x !== '')),
                          'lat' => round((float) $r['latitude'], 6), 'lon' => round((float) $r['longitude'], 6),
                          'country_code' => strtolower($r['country_code'] ?? ''), 'tzid' => $r['timezone'] ?? null];
            }
            if ($out) { Db::exec('REPLACE INTO geo_cache (query_hash, payload) VALUES (?,?)', [$key, json_encode($out, JSON_UNESCAPED_UNICODE)]); return $out; }
        } catch (ApiException) { /* try Nominatim */ }
        $raw = self::get($cfg['nominatim_url'] . '?' . http_build_query(['q' => $q, 'format' => 'jsonv2', 'addressdetails' => 1, 'limit' => 8]), $cfg['user_agent']);
        $out = [];
        foreach (json_decode($raw, true) ?: [] as $r) {
            $out[] = ['name' => $r['display_name'], 'lat' => round((float) $r['lat'], 6), 'lon' => round((float) $r['lon'], 6),
                      'country_code' => strtolower($r['address']['country_code'] ?? '')];
        }
        Db::exec('REPLACE INTO geo_cache (query_hash, payload) VALUES (?,?)', [$key, json_encode($out, JSON_UNESCAPED_UNICODE)]);
        return $out;
    }

    /** Returns an IANA tz id. Never guesses: throws if it cannot be determined. */
    public static function timezone(float $lat, float $lon, string $countryCode = ''): string {
        if ($countryCode === 'in') return 'Asia/Kolkata';
        $user = app_config()['geo']['geonames_username'];
        if ($user === '') throw new ApiException('timezone_unknown', 'Select the timezone for this place', 422, ['choose_from' => 'GET /api/v1/meta/timezones']);
        $key = hash('sha256', sprintf('tz|%.4f|%.4f', $lat, $lon));
        if ($c = Db::one('SELECT payload FROM geo_cache WHERE query_hash = ?', [$key])) return json_decode($c['payload'], true);
        $raw = self::get('https://secure.geonames.org/timezoneJSON?' . http_build_query(['lat' => $lat, 'lng' => $lon, 'username' => $user]), app_config()['geo']['user_agent']);
        $tz = json_decode($raw, true)['timezoneId'] ?? null;
        if (!$tz || !in_array($tz, \DateTimeZone::listIdentifiers(), true))
            throw new ApiException('timezone_unknown', 'Select the timezone for this place', 422);
        Db::exec('REPLACE INTO geo_cache (query_hash, payload) VALUES (?,?)', [$key, json_encode($tz)]);
        return $tz;
    }

    private static function get(string $url, string $ua): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => $ua]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($r === false || $code !== 200) throw new ApiException('geo_unavailable', 'Place lookup is unavailable right now. Try again shortly.', 503);
        return $r;
    }
}
