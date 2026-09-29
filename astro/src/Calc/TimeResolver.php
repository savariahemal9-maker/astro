<?php
namespace App\Calc;

use App\Core\ApiException;

/**
 * Converts a local civil birth time to UT using the IANA tz database (historical rules included).
 * Refuses to guess: nonexistent (DST gap) or ambiguous (DST overlap) times must be resolved by the user.
 */
final class TimeResolver {
    public static function resolve(string $date, string $time, string $tzid, ?int $manualOffsetMin = null, ?string $fold = null): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))
            throw new ApiException('invalid_date', 'Date must be a valid YYYY-MM-DD', 422);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $time, $m))
            throw new ApiException('invalid_time', 'Time must be HH:MM or HH:MM:SS (24-hour)', 422);
        $year = (int) substr($date, 0, 4);
        if ($year < 1800 || $year > 2399) throw new ApiException('date_out_of_range', 'Supported years: 1800–2399', 422);
        $secs = (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0);
        $localAsUtc = (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp() + $secs;
        $warnings = [];

        if ($manualOffsetMin !== null) {
            if ($manualOffsetMin < -720 || $manualOffsetMin > 840) throw new ApiException('invalid_offset', 'Offset must be between -12:00 and +14:00', 422);
            $ts = $localAsUtc - $manualOffsetMin * 60; $offset = $manualOffsetMin; $source = 'manual';
            $warnings[] = ['code' => 'manual_offset', 'message' => 'UTC offset entered manually; timezone database not used.'];
        } else {
            try { $tz = new \DateTimeZone($tzid); } catch (\Exception) { throw new ApiException('invalid_timezone', 'Unknown timezone', 422); }
            $cands = [];
            foreach ($tz->getTransitions($localAsUtc - 2 * 86400, $localAsUtc + 2 * 86400) as $tr) {
                $ts = $localAsUtc - $tr['offset'];
                $actual = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->getOffset();
                if ($actual === $tr['offset']) $cands[$ts] = $tr['offset'];
            }
            if (!$cands) throw new ApiException('nonexistent_local_time',
                'This clock time did not exist on that date (clocks moved forward). Please re-check the birth time.', 422);
            ksort($cands);
            if (count($cands) > 1) {
                if (!in_array($fold, ['earlier', 'later'], true))
                    throw new ApiException('ambiguous_local_time', 'This clock time occurred twice (clocks moved back). Choose earlier or later.', 422,
                        ['options' => array_map(fn($o) => ['offset_minutes' => intdiv($o, 60)], array_values($cands))]);
                $keys = array_keys($cands); $ts = $fold === 'earlier' ? $keys[0] : end($keys);
            } else $ts = array_key_first($cands);
            $offset = intdiv($cands[$ts], 60); $source = 'tzdb';
            if ($tzid === 'Asia/Kolkata' && $year < 1956)
                $warnings[] = ['code' => 'india_historical_time', 'message' =>
                    'Before 1956 parts of India used local time zones (Bombay Time UTC+4:51 until 1955, Calcutta Time until 1948) and war time (UTC+6:30) in 1942–45. Confirm which clock the recorded birth time used; enter a manual offset if needed.'];
            elseif ($year < 1970)
                $warnings[] = ['code' => 'historical_tz', 'message' => 'Timezone records before 1970 can be incomplete for some places. Verify the UTC offset.'];
        }
        return [
            'local' => sprintf('%s %s', $date, gmdate('H:i:s', $secs)), 'tzid' => $tzid,
            'utc' => gmdate('Y-m-d\TH:i:s\Z', $ts), 'offset_minutes' => $offset, 'offset_source' => $source,
            'jd_ut' => $ts / 86400 + 2440587.5, 'warnings' => $warnings,
        ];
    }

    public static function jdToLocal(float $jd, string $tzid, ?int $fixedOffsetMin = null, string $fmt = 'Y-m-d H:i:s'): string {
        $ts = (int) round(($jd - 2440587.5) * 86400);
        $tz = $fixedOffsetMin !== null
            ? new \DateTimeZone(sprintf('%+03d:%02d', intdiv($fixedOffsetMin, 60), abs($fixedOffsetMin % 60)))
            : new \DateTimeZone($tzid);
        return (new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($fmt);
    }

    public static function nowJd(): float { return microtime(true) / 86400 + 2440587.5; }
}
