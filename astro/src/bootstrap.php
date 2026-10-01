<?php
declare(strict_types=1);
spl_autoload_register(function (string $cls) {
    if (strncmp($cls, 'App\\', 4) !== 0) return;
    $f = __DIR__ . '/' . str_replace('\\', '/', substr($cls, 4)) . '.php';
    if (is_file($f)) require $f;
});
function app_config(): array {
    static $c = null;
    if ($c === null) {
        // Live settings live outside the deployed folder (public_html/astro-config.php) so Git deploys never overwrite them.
        // A broken or incomplete live file must never take the site down: fall back to the next one.
        foreach ([dirname(__DIR__, 3) . '/astro-config.php', __DIR__ . '/../config/config.php', __DIR__ . '/../config/config.example.php'] as $x) {
            if (!is_file($x)) continue;
            try { $v = require $x; } catch (\Throwable $e) { error_log("[config] $x: " . $e->getMessage()); continue; }
            if (is_array($v) && isset($v['db']['dsn'], $v['engine']['data']) && !str_contains((string) ($v['db']['pass'] ?? ''), 'YOUR_DB_PASSWORD')) { $c = $v; break; }
            error_log("[config] $x is incomplete, skipped");
        }
    }
    return $c;
}
