<?php
namespace App\I18n;

final class Lang {
    private static array $cache = [];
    public static function all(string $lang): array {
        if (!isset(self::$cache[$lang])) {
            $f = __DIR__ . '/../../lang/' . (in_array($lang, ['en', 'hi', 'gu'], true) ? $lang : 'en') . '.php';
            self::$cache[$lang] = require $f;
            $ext = preg_replace('/\.php$/', '_ext.php', $f);
            if (is_file($ext)) self::$cache[$lang] = array_replace_recursive(self::$cache[$lang], require $ext);
        }
        return self::$cache[$lang];
    }
    private static array $content = [];
    /** Long-form prediction content (lang/content_xx.php), falls back to English. */
    public static function content(string $lang, string $key): string {
        foreach ([$lang, 'en'] as $l) {
            $f = __DIR__ . "/../../lang/content_$l.php";
            if (!isset(self::$content[$l])) {
                self::$content[$l] = is_file($f) ? require $f : [];
                foreach (['content2', 'content3', 'content4'] as $x) { $f2 = __DIR__ . "/../../lang/{$x}_$l.php";
                    if (is_file($f2)) self::$content[$l] = array_replace_recursive(self::$content[$l], require $f2); }
            }
            if (($v = self::get(self::$content[$l], $key)) !== null) return $lang === 'gu' ? self::gu($v) : $v;
        }
        return '';
    }
    /** Dot-path lookup with {var} substitution; falls back to English, then to the key itself. */
    public static function t(string $lang, string $key, array $vars = []): string {
        $v = self::get(self::all($lang), $key) ?? self::get(self::all('en'), $key) ?? $key;
        foreach ($vars as $k => $x) $v = str_replace('{' . $k . '}', (string) $x, $v);
        return $lang === 'gu' ? self::gu($v) : $v;
    }
    /** Gujarati pages: write Sanskrit mantras in Gujarati script (Devanagari block → Gujarati block). */
    public static function gu(string $s): string {
        return preg_replace_callback('/[\x{0900}-\x{097F}]/u', fn($m) => mb_chr(mb_ord($m[0]) + 0x180), $s);
    }
    private static function get(array $a, string $key): ?string {
        foreach (explode('.', $key) as $p) { if (!is_array($a) || !array_key_exists($p, $a)) return null; $a = $a[$p]; }
        return is_string($a) ? $a : null;
    }
}
