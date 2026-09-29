<?php
namespace App\Core;

final class Router {
    private array $routes = [];
    public function add(string $method, string $pattern, callable $handler, bool $auth = true): void {
        $re = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[0-9]+)', $pattern) . '$#';
        $this->routes[] = [$method, $re, $handler, $auth];
    }
    /** @return array{0:callable,1:array,2:bool} */
    public function match(string $method, string $path): array {
        $allowed = false;
        foreach ($this->routes as [$m, $re, $h, $auth]) {
            if (preg_match($re, $path, $mm)) {
                if ($m !== $method) { $allowed = true; continue; }
                $params = [];
                foreach ($mm as $k => $v) if (is_string($k)) $params[$k] = (int) $v;
                return [$h, $params, $auth];
            }
        }
        throw $allowed ? new ApiException('method_not_allowed', 'Method not allowed', 405)
                       : new ApiException('not_found', 'Endpoint not found', 404);
    }
}
