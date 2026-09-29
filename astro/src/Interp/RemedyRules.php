<?php
namespace App\Interp;

use App\Core\Db;

/** Looks up chart-specific remedies stored in remedy_rules (editable in admin). Never throws: falls back to built-in text. */
final class RemedyRules {
    private static ?array $rows = null;
    private static function rows(): array {
        if (self::$rows === null) { try { self::$rows = Db::all('SELECT * FROM remedy_rules ORDER BY priority DESC, id'); } catch (\Throwable) { self::$rows = []; } }
        return self::$rows;
    }
    /** @return array{text:string,source:string,status:string}|null */
    public static function forPlanet(string $planet, int $house, string $cond, string $lang): ?array {
        foreach (self::rows() as $r) if ($r['planet'] === $planet && ($r['house'] === null || (int) $r['house'] === $house) && in_array($r['cond'], ['any', $cond], true))
            return self::pick($r, $lang);
        return null;
    }
    public static function forDosha(string $dosha, string $lang): ?array {
        foreach (self::rows() as $r) if ($r['dosha'] === $dosha) return self::pick($r, $lang);
        return null;
    }
    private static function pick(array $r, string $lang): array {
        $t = $r['text_' . $lang] ?: $r['text_en'];
        return ['text' => $lang === 'gu' ? \App\I18n\Lang::gu($t) : $t, 'source' => $r['source'], 'status' => $r['status']];
    }
}
