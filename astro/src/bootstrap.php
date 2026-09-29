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
        $f = __DIR__ . '/../config/config.php';
        $c = require (is_file($f) ? $f : __DIR__ . '/../config/config.example.php');
    }
    return $c;
}
