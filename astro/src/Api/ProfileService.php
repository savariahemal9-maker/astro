<?php
namespace App\Api;

use App\Calc\{KundaliService, TimeResolver, Ephemeris};
use App\Core\{ApiException, Db};

final class ProfileService {
    public static function validate(array $in): array {
        foreach (['label', 'birth_date', 'birth_time', 'place_name', 'lat', 'lon', 'tzid'] as $k)
            if (!isset($in[$k]) || $in[$k] === '') throw new ApiException('validation', 'Missing required fields', 422, ['missing' => [$k]]);
        if (!is_numeric($in['lat']) || !is_numeric($in['lon']) || abs((float) $in['lat']) > 90 || abs((float) $in['lon']) > 180)
            throw new ApiException('validation', 'Invalid coordinates', 422, ['field' => 'lat/lon']);
        $offset = isset($in['manual_offset_minutes']) && $in['manual_offset_minutes'] !== '' ? (int) $in['manual_offset_minutes'] : null;
        $resolved = TimeResolver::resolve((string) $in['birth_date'], (string) $in['birth_time'], (string) $in['tzid'], $offset, $in['dst_fold'] ?? null);
        return [
            'label' => mb_substr(trim((string) $in['label']), 0, 120), 'gender' => in_array($in['gender'] ?? null, ['male','female','other'], true) ? $in['gender'] : null,
            'birth_date' => $in['birth_date'], 'birth_time' => substr($resolved['local'], 11), 'time_accuracy' => ($in['time_accuracy'] ?? 'exact') === 'approximate' ? 'approximate' : 'exact',
            'place_name' => mb_substr(trim((string) $in['place_name']), 0, 255), 'lat' => round((float) $in['lat'], 6), 'lon' => round((float) $in['lon'], 6),
            'tzid' => $in['tzid'], 'offset_minutes' => $resolved['offset_minutes'], 'offset_source' => $resolved['offset_source'],
            'dst_fold' => $in['dst_fold'] ?? null, 'utc_datetime' => str_replace(['T', 'Z'], [' ', ''], $resolved['utc']), 'jd_ut' => $resolved['jd_ut'],
            '_resolved' => $resolved,
        ];
    }

    public static function save(int $userId, array $in, ?int $id = null): array {
        $v = self::validate($in);
        if (($in['confirmed'] ?? false) !== true)
            throw new ApiException('confirmation_required', 'Review the resolved birth time, then resend with "confirmed": true', 409, ['resolved' => $v['_resolved']]);
        $cols = ['label','gender','birth_date','birth_time','time_accuracy','place_name','lat','lon','tzid','offset_minutes','offset_source','dst_fold','utc_datetime','jd_ut'];
        $vals = array_map(fn($c) => $v[$c], $cols);
        if ($id === null) {
            $id = Db::insert('INSERT INTO birth_profiles (user_id,' . implode(',', $cols) . ',confirmed_at) VALUES (?' . str_repeat(',?', count($cols)) . ',UTC_TIMESTAMP())',
                array_merge([$userId], $vals));
        } else {
            self::get($userId, $id);
            Db::exec('UPDATE birth_profiles SET ' . implode('=?,', $cols) . '=?, confirmed_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?', array_merge($vals, [$id, $userId]));
            Db::exec('DELETE FROM kundalis WHERE profile_id = ?', [$id]); // birth data changed: drop all derived data
        }
        return self::get($userId, $id);
    }

    public static function get(int $userId, int $id): array {
        $p = Db::one('SELECT * FROM birth_profiles WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$p) throw new ApiException('not_found', 'Chart not found', 404);
        return self::present($p);
    }

    public static function present(array $p): array {
        foreach (['id','offset_minutes'] as $k) $p[$k] = (int) $p[$k];
        foreach (['lat','lon','jd_ut'] as $k) $p[$k] = (float) $p[$k];
        unset($p['user_id']);
        return $p;
    }

    /** Resolved birth (as TimeResolver output) for a stored profile. Re-resolves to surface current warnings. */
    public static function birth(array $p): array {
        $r = TimeResolver::resolve($p['birth_date'], $p['birth_time'], $p['tzid'], $p['offset_source'] === 'manual' ? $p['offset_minutes'] : null, $p['dst_fold']);
        if (abs($r['jd_ut'] - $p['jd_ut']) > 1e-6) throw new ApiException('birth_time_changed', 'Timezone rules changed since this chart was saved. Please re-confirm the birth details.', 409);
        return $r + ['time_accuracy' => $p['time_accuracy'], 'place_name' => $p['place_name']];
    }

    /** Cached kundali (calculated data only). Returns [kundali_id, data]. */
    public static function kundali(array $p): array {
        $eph = new Ephemeris();
        $info = $eph->info()['engine'];
        $hash = hash('sha256', json_encode([KundaliService::DATA_VERSION, $eph->settings(), $p['lat'], $p['lon'], sprintf('%.8f', $p['jd_ut'])]));
        $ver = $info['version'] . '/' . $info['swisseph'];
        if ($row = Db::one('SELECT id, calc_json FROM kundalis WHERE profile_id=? AND settings_hash=? AND engine_version=?', [$p['id'], $hash, $ver]))
            return [(int) $row['id'], json_decode($row['calc_json'], true)];
        $k = (new KundaliService($eph))->compute(self::birth($p), $p['lat'], $p['lon']);
        $id = Db::insert('INSERT INTO kundalis (profile_id, settings_hash, engine_version, calc_json) VALUES (?,?,?,?)',
            [$p['id'], $hash, $ver, json_encode($k, JSON_UNESCAPED_UNICODE)]);
        return [$id, $k];
    }
}
