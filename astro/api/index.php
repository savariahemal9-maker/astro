<?php
// Front controller for /api/v1/*  (shared by website and mobile apps)
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use App\Api\Routes;
use App\Auth\AuthService;
use App\Calc\CalcException;
use App\Core\{ApiException, Request, Router};

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$origins = app_config()['app']['cors_origins'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', $origins, true) || in_array($origin, $origins, true)) {
    header('Access-Control-Allow-Origin: ' . (in_array('*', $origins, true) ? '*' : $origin));
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

$send = function (int $status, array $payload) { http_response_code($status); echo json_encode($payload + ['api_version' => 'v1'], JSON_UNESCAPED_UNICODE); };
try {
    $req = Request::fromGlobals('/api/v1');
    $router = new Router();
    Routes::register($router);
    [$handler, $params, $auth] = $router->match($req->method, $req->path);
    $user = $auth ? AuthService::authenticate($req->token) : [];
    $send(200, ['ok' => true, 'data' => $handler($req, $user, $params)]);
} catch (ApiException $e) {
    $send($e->status, ['ok' => false, 'error' => ['code' => $e->errCode, 'message' => $e->getMessage(), 'details' => $e->details]]);
} catch (CalcException $e) {
    error_log('[calc] ' . $e->getMessage());
    $send(503, ['ok' => false, 'error' => ['code' => 'calculation_unavailable', 'message' => 'The calculation could not be completed accurately, so no result is shown. Please try again later.', 'details' => app_config()['app']['debug'] ? ['reason' => $e->getMessage()] : []]]);
} catch (Throwable $e) {
    error_log('[api] ' . $e);
    $send(500, ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Something went wrong on our side.', 'details' => app_config()['app']['debug'] ? ['reason' => $e->getMessage()] : []]]);
}
