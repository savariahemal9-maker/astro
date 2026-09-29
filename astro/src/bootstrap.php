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
        $f = null;
        foreach ([dirname(__DIR__, 3) . '/astro-config.php', __DIR__ . '/../config/config.php', __DIR__ . '/../config/config.example.php'] as $x) if (is_file($x)) { $f = $x; break; }
        $c = require $f;
    }
    return $c;
}
