<?php
namespace App\Core;

final class Request {
    public function __construct(
        public string $method, public string $path, public array $query, public array $body, public ?string $token
    ) {}

    public static function fromGlobals(string $prefix): self {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $pos = strpos($uri, $prefix);
        $path = '/' . trim($pos === false ? $uri : substr($uri, $pos + strlen($prefix)), '/');
        $raw = file_get_contents('php://input') ?: '';
        $body = [];
        if ($raw !== '') {
            $body = json_decode($raw, true);
            if (!is_array($body)) throw new ApiException('invalid_json', 'Request body must be a JSON object');
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token = preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $auth, $m) ? strtolower($m[1]) : null;
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $path, $_GET, $body, $token);
    }

    public function input(string $k, mixed $default = null): mixed {
        return $this->body[$k] ?? $this->query[$k] ?? $default;
    }

    public function require(array $keys): array {
        $missing = array_values(array_filter($keys, fn($k) => $this->input($k) === null || $this->input($k) === ''));
        if ($missing) throw new ApiException('validation', 'Missing required fields', 422, ['missing' => $missing]);
        $out = [];
        foreach ($keys as $k) $out[$k] = $this->input($k);
        return $out;
    }

    public function lang(?array $user = null): string {
        $l = $this->input('lang') ?? ($user['lang'] ?? 'en');
        return in_array($l, ['en', 'hi', 'gu'], true) ? $l : 'en';
    }
}
